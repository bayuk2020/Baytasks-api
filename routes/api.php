<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\SubtaskController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\TelegramSettingController;
use App\Http\Controllers\Api\BoardController;
use App\Http\Controllers\Api\HabitController;
use App\Http\Controllers\Api\JournalController;
use App\Http\Controllers\Api\BookController;
use App\Http\Controllers\Api\BookNoteController;
use App\Http\Controllers\Api\Finance\AccountController;
use App\Http\Controllers\Api\Finance\TransactionController;
use App\Http\Controllers\Api\Finance\IncomeSourceController;
use App\Http\Controllers\Api\Finance\BudgetController;
use App\Http\Controllers\Api\Finance\DebtController;
use App\Http\Controllers\Api\Finance\TradeController;
use App\Http\Controllers\Api\Finance\ContactController;
use App\Http\Controllers\Api\Finance\AnalyticsController;
use App\Http\Controllers\Api\Finance\FinanceCategoryController;
use App\Http\Controllers\Api\TelegramWebhookController;
use App\Http\Controllers\Api\GoalController;
use App\Http\Controllers\Api\MilestoneController;
use App\Http\Controllers\Api\QuarterlyPlanController;

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

Route::post('/subtasks', [SubtaskController::class, 'store']);
Route::patch('/subtasks/{id}', [SubtaskController::class, 'update']);
Route::delete('/subtasks/{id}', [App\Http\Controllers\Api\TaskController::class, 'deleteSubtask']);

// =========================
// ATTACHMENTS
// =========================

Route::post('/attachments', [AttachmentController::class, 'store']);
Route::delete('/attachments/{id}', [AttachmentController::class, 'destroy']);

// =========================
// TELEGRAM SETTINGS
// =========================

Route::get('/telegram-settings', [TelegramSettingController::class, 'show']);
Route::post('/telegram-settings', [TelegramSettingController::class, 'save']);
Route::post('/tasks/reorder', [TaskController::class, 'reorder']);

// =========================
// BOARDS
// =========================

Route::get('/boards', [BoardController::class, 'index']);
Route::post('/boards', [BoardController::class, 'store']);
Route::patch('/boards/{id}', [BoardController::class, 'update']);
Route::delete('/boards/{id}', [BoardController::class, 'destroy']);
Route::post('/boards/reorder', [BoardController::class, 'reorder']);

// =========================
// HABITS
// =========================

Route::get('/habits', [HabitController::class, 'index']);
Route::post('/habits', [HabitController::class, 'store']);
Route::patch('/habits/{id}', [HabitController::class, 'update']);
Route::post('/habits/{id}/toggle', [HabitController::class, 'toggle']);
Route::delete('/habits/{id}', [HabitController::class, 'destroy']);
Route::post('/habits/{id}/archive', [HabitController::class, 'archive']);

// =========================
// JOURNAL
// =========================
Route::apiResource('journals', JournalController::class);

// =========================
// BOOKS & BOOK NOTES / LIBRARY / READING VAULT
// =========================
Route::get('/books', [BookController::class, 'index']);
Route::post('/books', [BookController::class, 'store']);
Route::put('/books/{book}', [BookController::class, 'update']);
Route::delete('/books/{book}', [BookController::class, 'destroy']);
Route::post('/books/{book}/progress', [BookController::class, 'updateProgress']);
Route::post('/books/upload-cover', [BookController::class, 'uploadCover']);
Route::post('/books/upload-pdf', [BookController::class, 'uploadPdf']);
Route::post('/book-notes', [BookNoteController::class, 'store']);
Route::put('/book-notes/{bookNote}', [BookNoteController::class, 'update']);
Route::delete('/book-notes/{bookNote}', [BookNoteController::class, 'destroy']);

// =========================
// AUTH USER
// =========================

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// =========================
// FINANCE
// =========================

Route::prefix('finance')->group(function () {
    // 2. DAFTARKAN ROUTE ANALITIK BARU DI SINI
    // Hasil jalurnya otomatis menjadi: /api/finance/analytics-data
    Route::get('analytics-data', AnalyticsController::class);

    Route::get('categories', [FinanceCategoryController::class, 'index']);

    Route::get('contacts/analytics', [ContactController::class, 'analytics']);
    Route::get('contacts/{contact}/transactions', [ContactController::class, 'transactions']);

    Route::apiResource('contacts', ContactController::class);
    Route::apiResource('accounts', AccountController::class);
    Route::apiResource('transactions', TransactionController::class);
    Route::apiResource('income-sources', IncomeSourceController::class)->except(['show']);
    Route::apiResource('budgets', BudgetController::class);
    Route::apiResource('debts', DebtController::class);
    
    Route::post('debts/{debt}/payments', [DebtController::class, 'payment']);
    Route::apiResource('trades', TradeController::class);
});


// =========================
// GOALS SYSTEM API
// =========================
Route::apiResource('goals', GoalController::class);
Route::apiResource('milestones', MilestoneController::class)->only(['store', 'update', 'destroy']);
Route::apiResource('quarterly-plans', QuarterlyPlanController::class)->only(['store', 'update']);

Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);