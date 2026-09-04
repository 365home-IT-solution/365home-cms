<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_contracts', function (Blueprint $table) {
            $table->longText('contract_content')->nullable()->after('status');
            $table->string('contract_file')->nullable()->after('contract_content');
            $table->string('handover_file')->nullable()->after('contract_file');
            $table->string('deposit_receipt_file')->nullable()->after('handover_file');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_contracts', function (Blueprint $table) {
            $table->dropColumn(['contract_content', 'contract_file', 'handover_file', 'deposit_receipt_file']);
        });
    }
};
