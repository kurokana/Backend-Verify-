<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\DocumentSyncController;
use App\Http\Controllers\Api\DocumentVerifyController;

Route::post('/documents', [DocumentSyncController::class, 'sync']);
Route::get('/verify', [DocumentVerifyController::class, 'verify']);
