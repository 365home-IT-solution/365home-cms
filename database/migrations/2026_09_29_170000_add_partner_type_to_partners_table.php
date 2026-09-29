<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Minihouse\App\Support\HomestayBridge;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('partners', 'partner_type')) {
            Schema::table('partners', function (Blueprint $table) {
                $table->string('partner_type', 20)->default('homestay')->after('name')->index();
            });
        }

        DB::table('partners')->where('id', HomestayBridge::PARTNER_ID)->update(['partner_type' => 'minihouse']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('partners', 'partner_type')) {
            Schema::table('partners', fn (Blueprint $table) => $table->dropColumn('partner_type'));
        }
    }
};
