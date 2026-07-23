<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Document;
use App\Models\DocumentSignature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DocumentSyncController extends Controller
{
    /**
     * Terima dan simpan (sync) data surat dari office ke docstore (bank surat).
     *
     * Keamanan:
     * 1. Bearer Token (DOCSTORE_API_TOKEN) — autentikasi sistem office
     * 2. X-Payload-Signature (HMAC-SHA256) — memastikan payload tidak dimanipulasi
     *
     * Setelah berhasil, mengembalikan docstore_key (UUID) yang digunakan office
     * sebagai referensi untuk print & QR code surat.
     */
    public function sync(Request $request)
    {
        // 1. Autentikasi: Bearer Token
        $token = $request->bearerToken();
        $expectedToken = config('app.docstore_api_token') ?? env('DOCSTORE_API_TOKEN');

        if (!$token || $token !== $expectedToken) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Invalid API Token'
            ], 401);
        }

        // 2. Verifikasi HMAC Payload Signature
        $hmacVerification = $this->verifyPayloadHmac($request);
        if (!$hmacVerification['valid']) {
            Log::warning('Docstore HMAC verification failed', [
                'ip' => $request->ip(),
                'reason' => $hmacVerification['reason'],
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: ' . $hmacVerification['reason']
            ], 401);
        }

        // 3. Validasi payload
        $validated = $request->validate([
            'document_type'   => 'required|string',
            'document_id'     => 'required|integer',
            'document_number' => 'required|string',
            'status'          => 'required|string',
            'content'         => 'required|array',
            'signatures'      => 'nullable|array',
            'signatures.*.signature_hash' => 'required|string',
            'signatures.*.original_data'  => 'required|string',
            'signatures.*.signature'      => 'required|string',
            'signatures.*.data_hash'      => 'nullable|string',
            'signatures.*.algorithm'      => 'nullable|string',
            'signatures.*.public_key'     => 'required|string',
            'signatures.*.signer_name'    => 'required|string',
            'signatures.*.signer_role'    => 'nullable|string',
            'signatures.*.status'         => 'required|string',
            'signatures.*.signed_at'      => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            // 4. Cek apakah dokumen sudah ada
            $existingDocument = Document::where('document_type', $validated['document_type'])
                ->where('document_id', $validated['document_id'])
                ->first();

            if ($existingDocument) {
                // Update data dokumen yang sudah ada (update konten + status)
                // docstore_key tetap sama — ini adalah key permanen untuk referensi office
                $existingDocument->update([
                    'document_number' => $validated['document_number'],
                    'status'          => $validated['status'],
                    'content'         => $validated['content'],
                    'version'         => $existingDocument->version + 1,
                    'payload_hmac'    => $hmacVerification['hmac'],
                    'synced_at'       => now(),
                ]);
                $document = $existingDocument;
            } else {
                // Buat dokumen baru dengan docstore_key UUID baru
                $document = Document::create([
                    'docstore_key'    => (string) Str::uuid(),
                    'document_type'   => $validated['document_type'],
                    'document_id'     => $validated['document_id'],
                    'document_number' => $validated['document_number'],
                    'status'          => $validated['status'],
                    'content'         => $validated['content'],
                    'version'         => 1,
                    'payload_hmac'    => $hmacVerification['hmac'],
                    'synced_at'       => now(),
                ]);
            }

            // 5. Sync signatures (append-only: hanya tambah yang baru)
            $syncedHashes = [];
            if (!empty($validated['signatures'])) {
                foreach ($validated['signatures'] as $sigData) {
                    $syncedHashes[] = $sigData['signature_hash'];

                    // Cek apakah signature sudah ada
                    $existingSig = DocumentSignature::where('signature_hash', $sigData['signature_hash'])->first();

                    if (!$existingSig) {
                        // Hanya insert — tidak pernah update signature yang sudah ada (immutable)
                        DocumentSignature::create([
                            'document_id'    => $document->id,
                            'signature_hash' => $sigData['signature_hash'],
                            'original_data'  => $sigData['original_data'],
                            'signature'      => $sigData['signature'],
                            'data_hash'      => $sigData['data_hash'] ?? null,
                            'algorithm'      => $sigData['algorithm'] ?? 'sha256',
                            'public_key'     => $sigData['public_key'],
                            'signer_name'    => $sigData['signer_name'],
                            'signer_role'    => $sigData['signer_role'] ?? null,
                            'status'         => $sigData['status'],
                            'signed_at'      => $sigData['signed_at']
                                ? date('Y-m-d H:i:s', strtotime($sigData['signed_at']))
                                : null,
                        ]);
                    } else {
                        // Update status signature yang sudah ada (status bisa berubah)
                        $existingSig->update([
                            'status'    => $sigData['status'],
                            'signed_at' => $sigData['signed_at']
                                ? date('Y-m-d H:i:s', strtotime($sigData['signed_at']))
                                : $existingSig->signed_at,
                        ]);
                    }
                }
            }

            DB::commit();

            Log::info('Docstore sync berhasil', [
                'docstore_key'    => $document->docstore_key,
                'document_type'   => $document->document_type,
                'document_id'     => $document->document_id,
                'document_number' => $document->document_number,
                'status'          => $document->status,
                'version'         => $document->version,
                'ip'              => $request->ip(),
            ]);

            return response()->json([
                'success'         => true,
                'message'         => 'Document synced successfully',
                'docstore_key'    => $document->docstore_key,
                'document_id'     => $document->id,
                'version'         => $document->version,
                'status'          => $document->status,
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error syncing document: ' . $e->getMessage(), [
                'exception' => $e,
                'payload'   => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error syncing document: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verifikasi HMAC payload dari header X-Payload-Signature.
     * HMAC dihitung dari raw request body menggunakan DOCSTORE_HMAC_SECRET.
     *
     * Jika DOCSTORE_HMAC_SECRET tidak dikonfigurasi, skip verifikasi HMAC
     * (backward compatible tapi kurang aman — hanya autentikasi Bearer Token).
     */
    protected function verifyPayloadHmac(Request $request): array
    {
        $hmacSecret = env('DOCSTORE_HMAC_SECRET');

        // Jika secret belum dikonfigurasi, skip verifikasi HMAC (log warning)
        if (empty($hmacSecret)) {
            Log::warning('DOCSTORE_HMAC_SECRET tidak dikonfigurasi — HMAC verification dilewati');
            return ['valid' => true, 'hmac' => null, 'reason' => null];
        }

        $receivedHmac = $request->header('X-Payload-Signature');
        if (empty($receivedHmac)) {
            return [
                'valid'  => false,
                'hmac'   => null,
                'reason' => 'Header X-Payload-Signature tidak ada',
            ];
        }

        // Hitung HMAC dari raw body request
        $rawBody = $request->getContent();
        $expectedHmac = hash_hmac('sha256', $rawBody, $hmacSecret);

        if (!hash_equals($expectedHmac, $receivedHmac)) {
            return [
                'valid'  => false,
                'hmac'   => null,
                'reason' => 'Payload signature tidak cocok — kemungkinan payload dimanipulasi',
            ];
        }

        return ['valid' => true, 'hmac' => $expectedHmac, 'reason' => null];
    }
}
