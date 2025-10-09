<?php

use App\Http\Middleware\Idempotency;
use Illuminate\Support\Facades\Route;

Route::post('/v1/notecontroller/sync-plan', [\App\Http\Controllers\V1\NoteController::class, 'plan']);
Route::post('/v1/notecontroller/upload',    [App\Http\Controllers\V1\NoteController::class, 'upload'])->middleware(Idempotency::class);
Route::get('/v1/notecontroller/download',  [App\Http\Controllers\V1\NoteController::class, 'download']);