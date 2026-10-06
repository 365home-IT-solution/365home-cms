<?php

use App\Support\LegalDocumentFields;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// CCCD là một loại giấy tờ pháp lý (type = citizen_id) với BỘ CỘT RIÊNG cccd_* như ĐKKD / ANTT / PCCC. Danh sách cột: App\Support\LegalDocumentFields.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_legal_documents', function (Blueprint $table) {
            foreach (LegalDocumentFields::keys('citizen_id') as $key) {
                if (Schema::hasColumn('partner_legal_documents', $key)) {
                    continue;
                }
                match (true) {
                    str_ends_with($key, '_issued_at') || str_ends_with($key, '_dob') => $table->date($key)->nullable(),
                    str_ends_with($key, '_address')                                  => $table->string($key, 500)->nullable(),
                    str_ends_with($key, '_document_number')                          => $table->string($key, 100)->nullable(),
                    default                                                          => $table->string($key)->nullable(),
                };
            }
        });
    }

    public function down(): void
    {
        Schema::table('partner_legal_documents', function (Blueprint $table) {
            $table->dropColumn(array_values(array_filter(LegalDocumentFields::keys('citizen_id'), fn ($key) => Schema::hasColumn('partner_legal_documents', $key))));
        });
    }
};
