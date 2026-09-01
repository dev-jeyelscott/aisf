<?php

use App\Http\Controllers\AgentInstructionDefaultController;
use App\Http\Controllers\GithubWebhookController;
use App\Http\Controllers\ProjectAgentController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectSkillController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\WorkRequestController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');
Route::get('agent-defaults', [AgentInstructionDefaultController::class, 'index'])->name('agent-defaults.index');
Route::put('agent-defaults', [AgentInstructionDefaultController::class, 'update'])->name('agent-defaults.update');

Route::resource('projects', ProjectController::class)->except('destroy');
Route::resource('projects.agents', ProjectAgentController::class)->only(['index', 'edit', 'update']);
Route::resource('projects.skills', ProjectSkillController::class)->except(['show', 'create']);
Route::post('projects/{project}/work-requests', [WorkRequestController::class, 'store'])->name('projects.work-requests.store');
Route::post('projects/{project}/work-requests/{work_request}/retry', [WorkRequestController::class, 'retry'])->name('projects.work-requests.retry');
Route::get('projects/{project}/tasks/{task}', [TaskController::class, 'show'])->name('projects.tasks.show');
Route::post('projects/{project}/tasks/{task}/run', [TaskController::class, 'run'])->name('projects.tasks.run');
Route::post('projects/{project}/tasks/{task}/retry', [TaskController::class, 'retry'])->name('projects.tasks.retry');
Route::post('webhooks/github/{project}', GithubWebhookController::class)->name('webhooks.github');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
