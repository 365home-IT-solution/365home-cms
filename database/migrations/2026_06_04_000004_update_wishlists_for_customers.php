<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishlists', function (Blueprint $table) {
            if (! Schema::hasColumn('wishlists', 'customer_id')) {
                $table->char('customer_id', 36)->nullable()->after('user_id');
                $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
                $table->unique(['customer_id', 'product_id']);
            }

            // Make user_id nullable if it isn't already
            $table->char('user_id', 36)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('wishlists', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropUnique(['customer_id', 'product_id']);
            $table->dropColumn('customer_id');
            $table->char('user_id', 36)->nullable(false)->change();
        });
    }
};
