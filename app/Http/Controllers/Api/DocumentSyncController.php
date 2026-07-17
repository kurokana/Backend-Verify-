<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Document;
use App\Models\DocumentSignature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DocumentSyncController extends Controller
{
    public function sync(Request $request)
    {
        // 1. Authenticate using Bearer Token
        $token = $request->bearerToken();
        $expectedToken = config('app.docstore_api_token') ?? env('DOCSTORE_API_TOKEN');

        if (!$token || $token !== $expectedToken) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Invalid API Token'
            ], 401);
        }

        // 2. Validate payload
        $validated = $request->validate([
            'document_type' => 'required|string',
            'document_id' => 'required|integer',
            'document_number' => 'required|string',
            'status' => 'required|string',
            'content' => 'required|array',
            'signatures' => 'nullable|array',
            'signatures.*.signature_hash' => 'required|string',
            'signatures.*.original_data' => 'required|string',
            'signatures.*.signature' => 'required|string',
            'signatures.*.data_hash' => 'nullable|string',
            'signatures.*.algorithm' => 'nullable|string',
            'signatures.*.public_key' => 'required|string',
            'signatures.*.signer_name' => 'required|string',
            'signatures.*.signer_role' => 'nullable|string',
            'signatures.*.status' => 'required|string',
            'signatures.*.signed_at' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            // 3. Upsert document
            $document = Document::updateOrCreate(
                [
                    'document_type' => $validated['document_type'],
                    'document_id' => $validated['document_id']
                ],
                [
                    'document_number' => $validated['document_number'],
                    'status' => $validated['status'],
                    'content' => $validated['content']
                ]
            );

            // 4. Sync signatures
            $syncedHashes = [];
            if (!empty($validated['signatures'])) {
                foreach ($validated['signatures'] as $sigData) {
                    $syncedHashes[] = $sigData['signature_hash'];
                    DocumentSignature::updateOrCreate(
                        [
                            'signature_hash' => $sigData['signature_hash']
                        ],
                        [
                            'document_id' => $document->id,
                            'original_data' => $sigData['original_data'],
                            'signature' => $sigData['signature'],
                            'data_hash' => $sigData['data_hash'] ?? null,
                            'algorithm' => $sigData['algorithm'] ?? 'sha256',
                            'public_key' => $sigData['public_key'],
                            'signer_name' => $sigData['signer_name'],
                            'signer_role' => $sigData['signer_role'] ?? null,
                            'status' => $sigData['status'],
                            'signed_at' => $sigData['signed_at'] ? date('Y-m-d H:i:s', strtotime($sigData['signed_at'])) : null
                        ]
                    );
                }
            }

            // Delete signatures that are no longer associated with this document (avoid duplication)
            DocumentSignature::where('document_id', $document->id)
                ->whereNotIn('signature_hash', $syncedHashes)
                ->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Document synced successfully',
                'document_id' => $document->id
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error syncing document: ' . $e->getMessage(), [
                'exception' => $e,
                'payload' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error syncing document: ' . $e->getMessage()
            ], 500);
        }
    }
}
