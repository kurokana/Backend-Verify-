<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tambah kolom versioning ke tabel documents untuk mendukung:
     * - docstore_key: UUID unik yang diberikan ke office sebagai referensi print & verify
     * - version: nomor versi dokumen (setiap sync menghasilkan increment)
     * - payload_hmac: HMAC dari payload yang diterima dari office (untuk audit)
     * - synced_at: waktu terakhir sync diterima dari office
     *
     * Kolom docstore_key inilah yang di-embed pada QR code surat tercetak,
     * sehingga scan QR → langsung tarik data dari docstore (source of truth).
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // UUID unik per dokumen — digunakan sebagai referensi di office & QR
            $table->string('docstore_key', 36)->nullable()->unique()->after('id');
            // Versi dokumen — increment setiap sync baru dari office
            $table->unsignedInteger('version')->default(1)->after('status');
            // HMAC SHA-256 dari seluruh payload yang diterima — untuk audit trail
            $table->string('payload_hmac', 64)->nullable()->after('version');
            // Timestamp terakhir sync dari office
            $table->timestamp('synced_at')->nullable()->after('payload_hmac');
        });

        // Populate docstore_key untuk record yang sudah ada
        DB::statement('UPDATE documents SET docstore_key = UUID() WHERE docstore_key IS NULL');
        
        // Setelah populate, buat kolom NOT NULL
        Schema::table('documents', function (Blueprint $table) {
            $table->string('docstore_key', 36)->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['docstore_key', 'version', 'payload_hmac', 'synced_at']);
        });
    }
};
