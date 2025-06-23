<?php

use Illuminate\Support\Facades\Route;

Route::prefix('V1/notecontroller')->group(function () {
    Route::patch('/update', [\App\Http\Controllers\V1\NoteController::class, 'update']);
    Route::post('/create', [\App\Http\Controllers\V1\NoteController::class, 'create']);
    Route::post('/find', [\App\Http\Controllers\V1\NoteController::class, 'find']);
    Route::delete('/delete', [\App\Http\Controllers\V1\NoteController::class, 'delete']);
});