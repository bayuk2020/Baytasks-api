<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\SubtaskController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\TelegramSettingController;
use App\Http\Controllers\Api\BoardController;
use App\Http\Controllers\Api\HabitController;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| BayTasks API
|
*/

// =========================
// TEST ROUTE
// =========================

Route::get('/test', function () {
    return response()->json([
        'success' => true,
        'message' => 'BayTasks API connected',
    ]);
});

// =========================
// TASK CRUD
// =========================

Route::get('/tasks', [TaskController::class, 'index']);

Route::get('/tasks/{id}', [TaskController::class, 'show']);

Route::post('/tasks', [TaskController::class, 'store']);

Route::patch('/tasks/{id}', [TaskController::class, 'update']);

Route::delete('/tasks/{id}', [TaskController::class, 'destroy']);


// =========================
// SUBTASKS
// =========================

Route::post(
    '/subtasks',
    [SubtaskController::class, 'store']
);

Route::patch(
    '/subtasks/{id}',
    [SubtaskController::class, 'update']
);

Route::delete(
    '/subtasks/{id}',
    [SubtaskController::class, 'destroy']
);

// =========================
// ATTACHMENTS
// =========================

Route::post(
    '/attachments',
    [AttachmentController::class, 'store']
);

Route::delete(
    '/attachments/{id}',
    [AttachmentController::class, 'destroy']
);

// =========================
// TELEGRAM SETTINGS
// =========================

Route::get(
    '/telegram-settings',
    [TelegramSettingController::class, 'show']
);

Route::post(
    '/telegram-settings',
    [TelegramSettingController::class, 'save']
);

Route::post(
    '/tasks/reorder',
    [TaskController::class, 'reorder']
);

// =========================
// BOARDS
// =========================

Route::get(
    '/boards',
    [BoardController::class, 'index']
);

Route::post(
    '/boards',
    [BoardController::class, 'store']
);

Route::patch(
    '/boards/{id}',
    [BoardController::class, 'update']
);

Route::delete(
    '/boards/{id}',
    [BoardController::class, 'destroy']
);

Route::post(
    '/boards/reorder',
    [BoardController::class, 'reorder']
);

// =========================
// HABITS
// =========================

Route::get(
    '/habits',
    [HabitController::class, 'index']
);

Route::post(
    '/habits',
    [HabitController::class, 'store']
);

Route::patch(
    '/habits/{id}',
    [HabitController::class, 'update']
);

Route::post(
    '/habits/{id}/toggle',
    [HabitController::class, 'toggle']
);

Route::delete(
    '/habits/{id}',
    [HabitController::class, 'destroy']
);

Route::post(
    '/habits/{id}/archive',
    [HabitController::class, 'archive']
);

// =========================
// AUTH USER
// =========================

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});