<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('extra_charge_amount')->nullable()->after('remaining_paid_at');
            $table->unsignedBigInteger('extra_charge_payos_code')->nullable()->after('extra_charge_amount');
            $table->string('extra_charge_checkout_url')->nullable()->after('extra_charge_payos_code');
            $table->text('extra_charge_qr_code')->nullable()->after('extra_charge_checkout_url');
            $table->string('extra_charge_payment_method')->nullable()->after('extra_charge_qr_code'); // 'payos' | 'cash'
            $table->timestamp('extra_charge_paid_at')->nullable()->after('extra_charge_payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'extra_charge_amount',
                'extra_charge_payos_code',
                'extra_charge_checkout_url',
                'extra_charge_qr_code',
                'extra_charge_payment_method',
                'extra_charge_paid_at',
            ]);
        });
    }
};
