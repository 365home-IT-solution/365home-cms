<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        $prefix = DB::getTablePrefix();
        DB::statement("ALTER TABLE {$prefix}orders MODIFY COLUMN status ENUM('pending', 'deposit', 'paid', 'failed', 'cancelled_payment', 'refunded') DEFAULT 'pending'");

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'checked_out_at')) {
                $table->timestamp('checked_out_at')->nullable()->after('checked_in_at');
            }
            if (! Schema::hasColumn('orders', 'order_status')) {
                $table->enum('order_status', ['pending', 'checked_in', 'staying', 'checked_out'])
                    ->default('pending')
                    ->after('checked_out_at');
                $table->index('order_status');
            }
        });

        DB::table('orders')->whereNotNull('checked_in_at')->update(['order_status' => 'staying']);
        DB::table('orders')->whereNotNull('checked_out_at')->update(['order_status' => 'checked_out']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'order_status')) {
                $table->dropIndex(['order_status']);
                $table->dropColumn('order_status');
            }
            if (Schema::hasColumn('orders', 'checked_out_at')) {
                $table->dropColumn('checked_out_at');
            }
        });

        $prefix = DB::getTablePrefix();
        DB::statement("ALTER TABLE {$prefix}orders MODIFY COLUMN status ENUM('pending', 'paid', 'failed', 'shipped', 'deposit') DEFAULT 'pending'");
    }
};
