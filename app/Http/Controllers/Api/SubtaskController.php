<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Memory;
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
        $wasDone = (bool) $subtask->done;

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

        // Catat ke Memories HANYA saat transisi belum->sudah selesai, per
        // poin -- ceklis B, C, D berurutan menghasilkan 3 entri Daily Log
        // terpisah (satu per klik), bukan digabung jadi satu baris. Ini
        // pilihan sengaja: menunggu & mengelompokkan beberapa centang jadi
        // satu entri butuh window waktu/debounce yang rapuh (kapan dianggap
        // "masih dalam satu sesi"?), sementara satu entri per poin sudah
        // cukup jelas dibaca di Daily Log dan tidak butuh state tambahan.
        if ($wasDone === false && (bool) $subtask->done === true) {
            $task = $subtask->task;

            if ($task) {
                Memory::create([
                    'type' => 'task_activity',
                    'source' => 'task_board',
                    'title' => 'Subtask selesai',
                    'content' => "User menyelesaikan subtask tugas \"{$task->title}\" poin \"{$subtask->title}\"",
                    'occurred_at' => now(),
                ]);
            }
        }

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
