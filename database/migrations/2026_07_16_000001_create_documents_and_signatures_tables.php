<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 50); // e.g. 'sp3', 'cuti'
            $table->unsignedBigInteger('document_id'); // original ID in office
            $table->string('document_number', 100)->index();
            $table->string('status', 50);
            $table->json('content'); // Contains all key-value document details
            $table->timestamps();

            $table->unique(['document_type', 'document_id']);
        });

        Schema::create('document_signatures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->string('signature_hash', 255)->unique(); // indexed
            $table->text('original_data');
            $table->text('signature'); // base64 string
            $table->text('public_key'); // PEM format public key
            $table->string('signer_name', 255);
            $table->string('signer_role', 255)->nullable();
            $table->string('status', 50);
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();

            $table->foreign('document_id')->references('id')->on('documents')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_signatures');
        Schema::dropIfExists('documents');
    }
};
