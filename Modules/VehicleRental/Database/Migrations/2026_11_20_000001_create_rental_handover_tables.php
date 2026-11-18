<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Giao/nhận xe tại quầy: giấy tờ (KYC) của khách, biên bản giao + nhận (ảnh, odometer, mức xăng), phụ phí phát sinh, cọc
// thế chấp và tất toán. customer_id / staff là UUID (customers.id, users.id) nên không có FK.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_vehicle_documents', function (Blueprint $table) {
            $table->id();
            $table->string('customer_id', 36)->comment('customers.id (UUID)');
            $table->string('full_name', 150)->nullable();
            $table->string('id_number', 30)->nullable()->comment('Số CCCD');
            $table->string('license_number', 30)->nullable();
            $table->string('license_class', 10)->nullable()->comment('Hạng GPLX: A1, A2, B1, B2...');
            $table->date('license_expires_on')->nullable();
            $table->string('id_front')->nullable();
            $table->string('id_back')->nullable();
            $table->string('license_front')->nullable();
            $table->string('license_back')->nullable();
            $table->string('selfie')->nullable();
            $table->string('status', 20)->default('pending')->comment('pending | approved | rejected');
            $table->string('reject_reason')->nullable();
            $table->string('reviewed_by', 36)->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique('customer_id', 'rvd_customer_uq');
            $table->index('status', 'rvd_status_idx');
        });

        Schema::create('rental_vehicle_handovers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->foreign('booking_id', 'rvh_booking_fk')->references('id')->on('rental_vehicle_bookings')->cascadeOnDelete();
            $table->string('type', 10)->comment('pickup | return');
            $table->unsignedInteger('odometer_km');
            $table->unsignedTinyInteger('fuel_level')->nullable()->comment('% xăng/pin 0-100');
            $table->json('photos')->nullable();
            $table->text('notes')->nullable();
            $table->string('staff_id', 36)->nullable();
            $table->dateTime('handled_at');
            $table->timestamps();

            $table->unique(['booking_id', 'type'], 'rvh_booking_type_uq');
        });

        Schema::create('rental_vehicle_charges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->foreign('booking_id', 'rvc_booking_fk')->references('id')->on('rental_vehicle_bookings')->cascadeOnDelete();
            $table->string('type', 20)->comment('late | km | fuel | cleaning | damage | other');
            $table->string('label', 150);
            $table->unsignedInteger('amount');
            $table->text('note')->nullable();
            $table->json('photos')->nullable();
            $table->string('created_by', 36)->nullable();
            $table->timestamps();

            $table->index('booking_id', 'rvc_booking_idx');
        });

        Schema::table('rental_vehicle_bookings', function (Blueprint $table) {
            $table->unsignedInteger('deposit_collected_amount')->default(0)->after('refunded_at')->comment('Cọc thực thu tại quầy');
            $table->dateTime('deposit_collected_at')->nullable()->after('deposit_collected_amount');
            $table->unsignedInteger('deposit_deducted_amount')->default(0)->after('deposit_collected_at');
            $table->unsignedInteger('deposit_refund_amount')->default(0)->after('deposit_deducted_amount');
            $table->unsignedInteger('extra_due_amount')->default(0)->after('deposit_refund_amount')->comment('Phụ phí vượt quá cọc, khách trả thêm tại quầy');
            $table->unsignedInteger('km_driven')->nullable()->after('extra_due_amount');
            $table->dateTime('settled_at')->nullable()->after('km_driven');
        });

        // Vai trò nhân viên quầy thuê xe: xem đơn, duyệt giấy tờ, giao/nhận/tất toán (không sửa đội xe, giá).
        if (Schema::hasTable('roles')) {
            \App\Models\Role::query()->firstOrCreate(['name' => 'Nhân viên thuê xe', 'guard_name' => 'web']);
        }
    }

    public function down(): void
    {
        Schema::table('rental_vehicle_bookings', function (Blueprint $table) {
            $table->dropColumn([
                'deposit_collected_amount', 'deposit_collected_at', 'deposit_deducted_amount', 'deposit_refund_amount',
                'extra_due_amount', 'km_driven', 'settled_at',
            ]);
        });
        Schema::dropIfExists('rental_vehicle_charges');
        Schema::dropIfExists('rental_vehicle_handovers');
        Schema::dropIfExists('rental_vehicle_documents');
    }
};
