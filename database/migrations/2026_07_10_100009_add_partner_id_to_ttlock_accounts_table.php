<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ttlock_accounts', 'partner_id')) {
            return;
        }

        Schema::table('ttlock_accounts', function (Blueprint $table) {
            $table->uuid('partner_id')->nullable()->after('id');
            $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('ttlock_accounts', 'partner_id')) {
            return;
        }

        Schema::table('ttlock_accounts', function (Blueprint $table) {
            $table->dropForeign(['partner_id']);
            $table->dropColumn('partner_id');
        });
    }
};
