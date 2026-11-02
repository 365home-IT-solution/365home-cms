<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HÌNH THỨC THANH TOÁN KHI ĐẶT PHÒNG theo đối tác: both (mặc định, như trước) | cash_only (chỉ tiền mặt tại quầy) | online_only (chỉ thanh toán online).
 * Xem App\Services\Payment\BookingPaymentPolicy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('partners') && ! Schema::hasColumn('partners', 'booking_payment_mode')) {
            Schema::table('partners', function (Blueprint $table) {
                $table->string('booking_payment_mode', 20)->default('both');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('partners') && Schema::hasColumn('partners', 'booking_payment_mode')) {
            Schema::table('partners', function (Blueprint $table) {
                $table->dropColumn('booking_payment_mode');
            });
        }
    }
};
