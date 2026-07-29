<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subtask;
use Illuminate\Http\Request;

class SubtaskController extends Controller
{
    // =========================
    // CREATE
    // =========================
    public function store(Request $request)
    {
        $subtask = Subtask::create([
            'task_id' => $request->task_id,
            'title' => $request->title,
            'done' => false,
            'position' => $request->position ?? 0,
            // completed_at otomatis null saat create
        ]);

        return response()->json([
            'success' => true,
            'subtask' => $subtask,
        ]);
    }

    // =========================
    // UPDATE
    // =========================
    public function update(Request $request, $id)
    {
        $subtask = Subtask::findOrFail($id);

        $updateData = [];

        if ($request->has('title')) {
            $updateData['title'] = $request->title;
        }

        if ($request->has('position')) {
            $updateData['position'] = $request->position;
        }

        // ==========================================
        // FIX: Logika Auto-Fill completed_at
        // ==========================================
        if ($request->has('done')) {
            // Ubah string boolean jadi boolean murni
            $isDone = filter_var($request->done, FILTER_VALIDATE_BOOLEAN);
            $updateData['done'] = $isDone;

            // Jika baru dicentang Selesai (sebelumnya false)
            if ($isDone && !$subtask->done) {
                $updateData['completed_at'] = now();
            }
            // Jika batal dicentang (di-uncheck jadi false)
            elseif (!$isDone) {
                $updateData['completed_at'] = null;
            }
        }

        $subtask->update($updateData);

        return response()->json([
            'success' => true,
            'subtask' => $subtask,
        ]);
    }

    // =========================
    // DELETE
    // =========================
    public function destroy($id)
    {
        $subtask = Subtask::findOrFail($id);
        $subtask->delete();

        return response()->json([
            'success' => true,
        ]);
    }
}
