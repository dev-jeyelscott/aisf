<?php

use App\Http\Controllers\ProjectAgentController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectSkillController;
use App\Http\Controllers\QaReviewController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\WorkRequestController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::resource('projects', ProjectController::class)->except('destroy');
Route::resource('projects.agents', ProjectAgentController::class)->only(['index', 'edit', 'update']);
Route::resource('projects.skills', ProjectSkillController::class)->except(['show', 'create']);
Route::post('projects/{project}/work-requests', [WorkRequestController::class, 'store'])->name('projects.work-requests.store');
Route::post('projects/{project}/tasks/{task}/start', [TaskController::class, 'start'])->name('projects.tasks.start');
Route::post('projects/{project}/tasks/{task}/resume', [TaskController::class, 'resume'])->name('projects.tasks.resume');
Route::post('projects/{project}/tasks/{task}/qa-reviews', [QaReviewController::class, 'store'])->name('projects.tasks.qa-reviews.store');
Route::post('projects/{project}/tasks/{task}/qa-reviews/confirm-browser-check', [QaReviewController::class, 'confirmBrowserCheck'])->name('projects.tasks.qa-reviews.confirm-browser-check');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
