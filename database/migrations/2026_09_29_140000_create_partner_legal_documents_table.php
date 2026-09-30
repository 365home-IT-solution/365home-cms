<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_legal_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->string('type', 60);
            $table->string('name')->nullable();
            $table->string('document_number')->nullable();
            $table->string('issuer')->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->boolean('is_required')->default(false);
            $table->string('status', 30)->default('draft');
            $table->text('review_note')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['partner_id', 'status']);
            $table->index(['partner_id', 'type']);
        });

        Schema::table('partners', function (Blueprint $table) {
            $table->timestamp('verification_submitted_at')->nullable()->after('verification_status');
            $table->timestamp('verified_at')->nullable()->after('verification_submitted_at');
            $table->foreignUuid('verified_by')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
            $table->text('verification_note')->nullable()->after('verified_by');
        });

        Schema::table('partner_contract_versions', function (Blueprint $table) {
            $table->json('legal_document_snapshot')->nullable()->after('content_hash');
        });
    }

    public function down(): void
    {
        Schema::table('partner_contract_versions', function (Blueprint $table) {
            $table->dropColumn('legal_document_snapshot');
        });

        Schema::table('partners', function (Blueprint $table) {
            $table->dropForeign(['verified_by']);
            $table->dropColumn(['verification_submitted_at', 'verified_at', 'verified_by', 'verification_note']);
        });

        Schema::dropIfExists('partner_legal_documents');
    }
};
