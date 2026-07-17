<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Menambah kolom data_hash (SHA256 dari original_data) dan algorithm
     * ke tabel document_signatures untuk memungkinkan verifikasi kriptografis
     * yang lebih andal tanpa harus melakukan openssl_verify secara full.
     */
    public function up(): void
    {
        Schema::table('document_signatures', function (Blueprint $table) {
            // SHA256 hash dari original_data — untuk verifikasi cepat & andal
            $table->string('data_hash', 64)->nullable()->after('signature');
            // Algoritma yang digunakan (default: sha256)
            $table->string('algorithm', 30)->default('sha256')->after('data_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_signatures', function (Blueprint $table) {
            $table->dropColumn(['data_hash', 'algorithm']);
        });
    }
};
