<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Document;
use Illuminate\Support\Facades\Log;

class DocumentRetrieveController extends Controller
{
    /**
     * Ambil data surat berdasarkan docstore_key.
     *
     * Endpoint ini digunakan oleh:
     * 1. Office — untuk menarik data saat user hendak print surat
     * 2. Verify App — saat user scan QR yang berisi docstore_key (UUID)
     *
     * Data yang dikembalikan adalah data immutable dari bank surat.
     *
     * GET /api/documents/{docstore_key}
     */
    public function show(Request $request, string $docstoreKey)
    {
        // Autentikasi: Bearer Token (untuk akses dari office)
        // atau akses publik (dari verify app via scan QR)
        $isPublicAccess = !$request->bearerToken();

        if (!$isPublicAccess) {
            $token = $request->bearerToken();
            $expectedToken = config('app.docstore_api_token') ?? env('DOCSTORE_API_TOKEN');

            if ($token !== $expectedToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Invalid API Token'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }
        }

        $document = Document::with('signatures')
            ->where('docstore_key', $docstoreKey)
            ->first();

        if (!$document) {
            return response()->json([
                'success' => false,
                'message' => 'Dokumen tidak ditemukan di bank surat.',
                'is_valid' => false,
            ], 404)->header('Access-Control-Allow-Origin', '*');
        }

        $manualSigners = [];

        $allSignatures = $document->signatures->map(function ($sig) use (&$manualSigners) {
            $statusLower = strtolower($sig->status ?? '');
            $isManual = in_array($statusLower, ['manual', 'approved manual', 'disetujui manual']);

            if ($isManual) {
                $manualSigners[] = [
                    'signer_name' => $sig->signer_name,
                    'signer_role' => $sig->signer_role,
                    'signed_at'   => $sig->signed_at ? $sig->signed_at->toIso8601String() : null,
                ];
            }

            return [
                'signer_name'    => $sig->signer_name,
                'signer_role'    => $sig->signer_role,
                'status'         => $sig->status,
                'is_manual'      => $isManual,
                'signed_at'      => $sig->signed_at ? $sig->signed_at->toIso8601String() : null,
                'signature_hash' => $sig->signature_hash,
            ];
        });

        $docStatusLower = strtolower($document->status ?? '');
        $isDocManual = in_array($docStatusLower, ['manual', 'approved manual', 'disetujui manual']) || !empty($manualSigners);

        // Tentukan status verifikasi
        if ($isDocManual) {
            $verificationStatus = 'DISETUJUI MANUAL';
            $verificationDetail = 'docstore_manual_approved';
            $isValid = true;
        } elseif ($docStatusLower === 'approved') {
            $verificationStatus = 'VALID';
            $verificationDetail = 'docstore_verified';
            $isValid = true;
        } elseif (in_array($docStatusLower, ['rejected', 'ditolak', 'tidak disetujui'])) {
            $verificationStatus = 'DITOLAK';
            $verificationDetail = 'docstore_rejected';
            $isValid = false;
        } else {
            $verificationStatus = 'PROSES';
            $verificationDetail = 'docstore_in_process';
            $isValid = false;
        }

        Log::info('Document retrieved from docstore', [
            'docstore_key' => $docstoreKey,
            'type'         => $document->document_type,
            'number'       => $document->document_number,
            'status'       => $document->status,
            'is_manual'    => $isDocManual,
            'is_public'    => $isPublicAccess,
            'ip'           => $request->ip(),
        ]);

        return response()->json([
            'success'             => true,
            'is_valid'            => $isValid,
            'is_manual'           => $isDocManual,
            'manual_signers'      => $manualSigners,
            'verification_status' => $verificationStatus,
            'verification_detail' => $verificationDetail,
            'cryptographic_error' => null,
            'meta' => [
                'docstore_key' => $document->docstore_key,
                'version'      => $document->version,
                'synced_at'    => $document->synced_at?->toIso8601String(),
            ],
            'document' => [
                'id'      => $document->document_id,
                'type'    => $document->document_type,
                'number'  => $document->document_number,
                'status'  => $document->status,
                'content' => $document->content,
            ],
            'scanned_signature' => $allSignatures->first() ?? [
                'signer_name'    => 'Sistem',
                'signer_role'    => 'Bank Surat',
                'status'         => $document->status,
                'is_manual'      => $isDocManual,
                'signed_at'      => $document->synced_at?->toIso8601String(),
                'signature_hash' => $document->docstore_key,
            ],
            'all_signatures' => $allSignatures,
        ], 200)->header('Access-Control-Allow-Origin', '*');
    }

    /**
     * List semua surat dari docstore (untuk keperluan audit/laporan).
     *
     * GET /api/documents?type={type}&status={status}&page={page}&per_page={per_page}
     */
    public function index(Request $request)
    {
        // Autentikasi: wajib Bearer Token (audit hanya untuk internal)
        $token = $request->bearerToken();
        $expectedToken = config('app.docstore_api_token') ?? env('DOCSTORE_API_TOKEN');

        if (!$token || $token !== $expectedToken) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Invalid API Token'
            ], 401);
        }

        $query = Document::with('signatures')
            ->orderBy('synced_at', 'desc');

        // Filter opsional
        if ($request->has('type') && $request->type !== 'all') {
            $query->where('document_type', $request->type);
        }

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('document_number', 'like', "%{$search}%")
                    ->orWhere('docstore_key', 'like', "%{$search}%");
            });
        }

        // Date range filter
        if ($request->has('date_from')) {
            $query->whereDate('synced_at', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->whereDate('synced_at', '<=', $request->date_to);
        }

        $perPage = min((int) $request->get('per_page', 25), 100);
        $documents = $query->paginate($perPage);

        $items = $documents->getCollection()->map(function ($doc) {
            $manualSigners = [];
            $signatures = $doc->signatures->map(function ($sig) use (&$manualSigners) {
                $statusLower = strtolower($sig->status ?? '');
                $isManual = in_array($statusLower, ['manual', 'approved manual', 'disetujui manual']);

                if ($isManual) {
                    $manualSigners[] = [
                        'signer_name' => $sig->signer_name,
                        'signer_role' => $sig->signer_role,
                        'signed_at'   => $sig->signed_at ? $sig->signed_at->toIso8601String() : null,
                    ];
                }

                return [
                    'signer_name' => $sig->signer_name,
                    'signer_role' => $sig->signer_role,
                    'status'      => $sig->status,
                    'is_manual'   => $isManual,
                    'signed_at'   => $sig->signed_at ? $sig->signed_at->toIso8601String() : null,
                ];
            });

            $docStatusLower = strtolower($doc->status ?? '');
            $isDocManual = in_array($docStatusLower, ['manual', 'approved manual', 'disetujui manual']) || !empty($manualSigners);

            return [
                'docstore_key'    => $doc->docstore_key,
                'document_type'   => $doc->document_type,
                'document_id'     => $doc->document_id,
                'document_number' => $doc->document_number,
                'status'          => $doc->status,
                'is_manual'       => $isDocManual,
                'manual_signers'  => $manualSigners,
                'version'         => $doc->version,
                'synced_at'       => $doc->synced_at?->toIso8601String(),
                'content'         => $doc->content,
                'signatures'      => $signatures,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $items,
            'meta'    => [
                'current_page' => $documents->currentPage(),
                'last_page'    => $documents->lastPage(),
                'per_page'     => $documents->perPage(),
                'total'        => $documents->total(),
            ],
        ], 200);
    }
}
