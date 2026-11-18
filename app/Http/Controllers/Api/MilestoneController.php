<?php

namespace App\Http\Controllers\Api; // <-- Namespace folder Api

use App\Http\Controllers\Controller;
use App\Models\Milestone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MilestoneController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'goal_id'      => 'required|integer',
            'name'         => 'required|string|max:255',
            'target_value' => 'numeric',
            'current_value'=> 'numeric',
            'due_date'     => 'nullable|date_format:Y-m-d',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $goalId = (int) $request->input('goal_id');

        // Milestone baru selalu ditaruh di urutan paling bawah. Tanpa ini
        // sort_order-nya 0 dan item baru malah melompat ke atas daftar.
        $sortOrder = $request->has('sort_order')
            ? (int) $request->input('sort_order')
            : ((int) Milestone::where('goal_id', $goalId)->max('sort_order')) + 1;

        $milestone = Milestone::create([
            'goal_id'       => $goalId,
            'name'          => $request->input('name'),
            'target_value'  => $request->input('target_value', 0),
            'current_value' => $request->input('current_value', 0),
            'due_date'      => $request->input('due_date'),
            'weight'        => $request->input('weight', 1),
            'completed'     => $request->input('completed', 0),
            'sort_order'    => $sortOrder,
        ]);

        return response()->json([
            'message'   => 'Milestone created successfully!',
            'milestone' => $milestone
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $milestone = Milestone::find($id);

        if (!$milestone) {
            return response()->json(['message' => 'Milestone not found'], 404);
        }

        // Sebelumnya `update()` langsung menelan $request->only(...) tanpa
        // validasi sama sekali, jadi nama kosong atau tanggal ngawur bisa masuk
        // dan baru ketahuan waktu dibaca lagi. `sometimes` dipakai supaya
        // partial update tetap jalan: field yang tidak dikirim tidak diubah.
        $validator = Validator::make($request->all(), [
            'name'          => 'sometimes|required|string|max:255',
            'target_value'  => 'sometimes|nullable|numeric|min:0',
            'current_value' => 'sometimes|nullable|numeric|min:0',
            'due_date'      => 'sometimes|nullable|date_format:Y-m-d',
            'weight'        => 'sometimes|nullable|integer|min:1',
            'completed'     => 'sometimes|boolean',
            'sort_order'    => 'sometimes|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $milestone->update($validator->validated());

        return response()->json([
            'message'   => 'Milestone updated successfully!',
            'milestone' => $milestone->fresh()
        ]);
    }

    /**
     * Simpan urutan baru hasil drag & drop.
     *
     * Dikirim sekaligus satu goal (bukan satu PATCH per baris) karena
     * memindahkan satu item menggeser posisi semua item di bawahnya — kalau
     * dikirim satu-satu, urutan di layar dan di database bisa berbeda saat ada
     * request yang gagal di tengah jalan.
     */
    public function reorder(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'goal_id'  => 'required|integer',
            'ids'      => 'required|array|min:1',
            'ids.*'    => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $goalId = (int) $request->input('goal_id');
        $ids = array_map('intval', $request->input('ids'));

        // Hanya id yang benar-benar milik goal ini yang boleh diurut ulang,
        // supaya request dari luar tidak bisa mengacak milestone goal lain.
        $owned = Milestone::where('goal_id', $goalId)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        $missing = array_values(array_diff($ids, $owned));

        if ($missing !== []) {
            return response()->json([
                'message' => 'Sebagian milestone bukan milik goal ini atau tidak ditemukan.',
                'invalid_ids' => $missing,
            ], 422);
        }

        DB::transaction(function () use ($ids, $goalId) {
            foreach ($ids as $position => $milestoneId) {
                Milestone::where('id', $milestoneId)
                    ->where('goal_id', $goalId)
                    ->update(['sort_order' => $position + 1]);
            }
        });

        return response()->json([
            'message' => 'Urutan milestone berhasil disimpan.',
            'milestones' => Milestone::where('goal_id', $goalId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'name', 'sort_order']),
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $milestone = Milestone::find($id);

        if (!$milestone) {
            return response()->json(['message' => 'Milestone not found'], 404);
        }

        $goalId = $milestone->goal_id;
        $milestone->delete();

        // Rapatkan kembali nomor urutnya supaya tidak ada lubang (1,2,4,5...).
        // Bukan sekadar kosmetik: posisi baru hasil drag & drop dihitung dari
        // indeks array di frontend, jadi lubang bisa bikin item "meloncat".
        $remaining = Milestone::where('goal_id', $goalId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id');

        DB::transaction(function () use ($remaining) {
            foreach ($remaining as $position => $milestoneId) {
                Milestone::where('id', $milestoneId)->update(['sort_order' => $position + 1]);
            }
        });

        return response()->json(['message' => 'Milestone deleted permanently!']);
    }
}
