<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\DocumentSyncController;
use App\Http\Controllers\Api\DocumentVerifyController;
use App\Http\Controllers\Api\DocumentRetrieveController;

// === Sync endpoint (dari office → docstore) ===
// POST /api/documents — office mengirim data surat untuk disimpan di bank surat
Route::post('/documents', [DocumentSyncController::class, 'sync']);

// === Verify endpoint (dari verify app — scan QR signature hash) ===
// GET /api/verify?hash={signature_hash}
Route::get('/verify', [DocumentVerifyController::class, 'verify']);

// === Retrieve endpoints (office print + verify app scan QR docstore_key) ===
// GET /api/documents/{docstore_key} — ambil data surat by docstore_key (publik & privat)
Route::get('/documents/{docstoreKey}', [DocumentRetrieveController::class, 'show'])
    ->where('docstoreKey', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');

// GET /api/documents — list semua surat (untuk audit/laporan, privat — Bearer Token)
Route::get('/documents', [DocumentRetrieveController::class, 'index']);
