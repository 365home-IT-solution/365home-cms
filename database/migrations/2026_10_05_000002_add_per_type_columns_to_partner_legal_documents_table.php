<?php

use App\Support\LegalDocumentFields;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// MỖI LOẠI giấy tờ (ĐKKD / ANTT / PCCC) có BỘ CỘT RIÊNG, kể cả ô trùng tên (số, ngày cấp, nơi cấp): dkkd_* / antt_* / pccc_*.
// Thay cho cột JSON `extra` (migration 000001) — không loại nào còn dùng chung cột với loại khác.
// 3 cột chung cũ (document_number, issuer, issued_at) giữ lại làm bản tóm tắt, tự chép từ cột riêng khi lưu. Danh sách cột: App\Support\LegalDocumentFields.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_legal_documents', function (Blueprint $table) {
            foreach (LegalDocumentFields::allKeys() as $key) {
                if (Schema::hasColumn('partner_legal_documents', $key)) {
                    continue;
                }
                match (true) {
                    str_ends_with($key, '_issued_at')                                           => $table->date($key)->nullable(),
                    str_ends_with($key, '_business_lines')                                      => $table->text($key)->nullable(),
                    str_ends_with($key, '_address')                                             => $table->string($key, 500)->nullable(),
                    str_ends_with($key, '_document_number')                                     => $table->string($key, 100)->nullable(),
                    str_ends_with($key, '_id_number') || str_ends_with($key, '_phone')          => $table->string($key, 20)->nullable(),
                    default                                                                     => $table->string($key)->nullable(),
                };
            }
        });

        // Giấy tờ đã có: chép số / ngày cấp / nơi cấp từ cột chung sang cột riêng của đúng loại, và các ô đã lưu trong `extra` (nếu có).
        $hasExtra = Schema::hasColumn('partner_legal_documents', 'extra');
        DB::table('partner_legal_documents')->whereIn('type', LegalDocumentFields::types())->orderBy('id')->each(function ($row) use ($hasExtra) {
            $values = [];
            foreach (LegalDocumentFields::SUMMARY as $name) {
                $values[LegalDocumentFields::key($row->type, $name)] = $row->{$name};
            }
            foreach ($hasExtra ? (json_decode((string) $row->extra, true) ?: []) : [] as $name => $value) {
                $key = LegalDocumentFields::key($row->type, (string) $name);
                if (in_array($key, LegalDocumentFields::keys($row->type), true)) {
                    $values[$key] = $value;
                }
            }
            DB::table('partner_legal_documents')->where('id', $row->id)->update($values);
        });

        if ($hasExtra) {
            Schema::table('partner_legal_documents', fn (Blueprint $table) => $table->dropColumn('extra'));
        }
    }

    public function down(): void
    {
        Schema::table('partner_legal_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('partner_legal_documents', 'extra')) {
                $table->json('extra')->nullable()->after('expires_at');
            }
            $table->dropColumn(array_values(array_filter(LegalDocumentFields::allKeys(), fn ($key) => Schema::hasColumn('partner_legal_documents', $key))));
        });
    }
};
