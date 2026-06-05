<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Habit;
use App\Models\HabitLog;
use Illuminate\Http\Request;

class HabitController extends Controller
{
    // =========================
    // GET ALL
    // =========================

    public function index()
    {
    $habits = Habit::with('logs')
        ->latest()
        ->get();

        return response()->json(
            $habits
        );
    }

    // =========================
    // CREATE
    // =========================

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:160',
        ]);

        $habit = Habit::create([
            'user_id' => 1,

            'title' => $request->title,

            'description' =>
                $request->description,

            'emoji' =>
                $request->emoji ?? '🔥',

            'color' =>
                $request->color ?? 'cyan',

            'frequency' =>
                $request->frequency ?? 'daily',

            'target' =>
                $request->target ?? 1,

            'xp_per_completion' =>
                $request->xp_per_completion ?? 25,
        ]);

        return response()->json(
            $habit
        );
    }

    // =========================
    // UPDATE
    // =========================

    public function update(
        Request $request,
        $id
    ) {
        $habit = Habit::findOrFail($id);

        $habit->update([
            'title' =>
                $request->title,

            'description' =>
                $request->description,

            'emoji' =>
                $request->emoji,

            'color' =>
                $request->color,

            'frequency' =>
                $request->frequency,

            'target' =>
                $request->target,

            'xp_per_completion' =>
                $request->xp_per_completion,

            'archived' =>
                $request->archived,
        ]);

        return response()->json(
            $habit
        );
    }

    // =========================
    // TOGGLE
    // =========================

    public function toggle($id)
    {
        $habit = Habit::findOrFail($id);

        $today = now()
            ->format('Y-m-d');

        $existing =
            HabitLog::where(
                'habit_id',
                $habit->id
            )
            ->where(
                'date',
                $today
            )
            ->first();

        // =========================
        // UNCHECK
        // =========================

        if ($existing) {

            $existing->delete();

            return response()->json([
                'completed' => false,
            ]);
        }

        // =========================
        // CHECK
        // =========================

        HabitLog::create([
            'habit_id' =>
                $habit->id,

            'date' =>
                $today,

            'completed' => true,

            'completed_at' => now(),
        ]);

        return response()->json([
            'completed' => true,
        ]);
    }

// =========================
// ARCHIVE
// =========================

public function archive($id)
{
    $habit = Habit::findOrFail($id);

    $habit->update([
        'archived' => !$habit->archived
    ]);

    return response()->json([
        'success' => true,
    ]);
}

// =========================
// DELETE
// =========================

public function destroy($id)
{
    $habit = Habit::findOrFail($id);

    $habit->delete();

    return response()->json([
        'success' => true,
    ]);
}
}