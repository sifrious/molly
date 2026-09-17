<?php

use Illuminate\Support\Facades\Route;
use Sifrious\Molly\Http\LocalUi;
use Sifrious\Molly\Http\RunController;
use Sifrious\Molly\Http\TaskConnectionController;
use Sifrious\Molly\Http\TaskController;

Route::middleware(['web', LocalUi::class])->prefix(config('molly.ui.prefix', 'molly'))->name('molly.')->group(function (): void {
    Route::get('/', [TaskController::class, 'index'])->name('tasks.index');
    Route::get('/tasks/create', [TaskController::class, 'create'])->name('tasks.create');
    Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::get('/tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
    Route::get('/tasks/{task}/connections', [TaskConnectionController::class, 'show'])->name('tasks.connections');
    Route::post('/tasks/{task}/connections', [TaskConnectionController::class, 'link'])->name('tasks.connections.link');
    Route::post('/tasks/{task}/connections/refresh', [TaskConnectionController::class, 'refresh'])->name('tasks.connections.refresh');
    Route::post('/tasks/{task}/name', [TaskController::class, 'name'])->name('tasks.name');
    Route::post('/tasks/{task}/start', [TaskController::class, 'start'])->name('tasks.start');
    Route::post('/tasks/{task}/retry', [TaskController::class, 'retry'])->name('tasks.retry');
    Route::post('/tasks/{task}/stop', [TaskController::class, 'stop'])->name('tasks.stop');
    Route::get('/runs/{run}', [RunController::class, 'show'])->name('runs.show');
});
