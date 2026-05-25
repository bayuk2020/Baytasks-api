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

            'task_id' =>
                $request->task_id,

            'title' =>
                $request->title,

            'done' =>
                false,

            'position' =>
                $request->position ?? 0,
        ]);

        return response()->json([
            'success' => true,
            'subtask' => $subtask,
        ]);
    }

    // =========================
    // UPDATE
    // =========================

    public function update(
        Request $request,
        $id
    ) {

        $subtask =
            Subtask::findOrFail(
                $id
            );

        $subtask->update([

            'title' =>
                $request->title ??
                $subtask->title,

            'done' =>
                $request->done ??
                $subtask->done,

            'position' =>
                $request->position ??
                $subtask->position,
        ]);

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
        $subtask =
            Subtask::findOrFail(
                $id
            );

        $subtask->delete();

        return response()->json([
            'success' => true,
        ]);
    }
}