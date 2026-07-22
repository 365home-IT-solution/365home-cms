<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            // ── Người đại diện ──────────────────────────────────────────────
            if (! Schema::hasColumn('partners', 'representative_name')) {
                $table->string('representative_name')->nullable()->after('name');
            }
            if (! Schema::hasColumn('partners', 'representative_dob')) {
                $table->date('representative_dob')->nullable()->after('representative_name');
            }
            if (! Schema::hasColumn('partners', 'representative_id_number')) {
                $table->string('representative_id_number')->nullable()->after('representative_dob');
            }
            if (! Schema::hasColumn('partners', 'representative_phone_secondary')) {
                $table->string('representative_phone_secondary')->nullable()->after('phone');
            }

            // ── Doanh nghiệp ─────────────────────────────────────────────────
            if (! Schema::hasColumn('partners', 'legal_name')) {
                $table->string('legal_name')->nullable()->after('representative_id_number');
            }
            if (! Schema::hasColumn('partners', 'business_license_date')) {
                $table->date('business_license_date')->nullable()->after('tax_code');
            }
            if (! Schema::hasColumn('partners', 'business_license_issuer')) {
                $table->string('business_license_issuer')->nullable()->after('business_license_date');
            }

            // ── Tài chính ────────────────────────────────────────────────────
            if (! Schema::hasColumn('partners', 'bank_name')) {
                $table->string('bank_name')->nullable();
            }
            if (! Schema::hasColumn('partners', 'bank_branch')) {
                $table->string('bank_branch')->nullable();
            }
            if (! Schema::hasColumn('partners', 'bank_account_number')) {
                $table->string('bank_account_number')->nullable();
            }
            if (! Schema::hasColumn('partners', 'bank_account_holder')) {
                $table->string('bank_account_holder')->nullable();
            }
            if (! Schema::hasColumn('partners', 'momo_phone')) {
                $table->string('momo_phone')->nullable();
            }
            if (! Schema::hasColumn('partners', 'zalopay_id')) {
                $table->string('zalopay_id')->nullable();
            }
            if (! Schema::hasColumn('partners', 'vnpay_id')) {
                $table->string('vnpay_id')->nullable();
            }
            if (! Schema::hasColumn('partners', 'paypal_email')) {
                $table->string('paypal_email')->nullable();
            }
            if (! Schema::hasColumn('partners', 'wise_account')) {
                $table->string('wise_account')->nullable();
            }
            if (! Schema::hasColumn('partners', 'swift_code')) {
                $table->string('swift_code')->nullable();
            }
            if (! Schema::hasColumn('partners', 'payment_cycle')) {
                // weekly | biweekly | monthly
                $table->string('payment_cycle', 20)->default('biweekly');
            }

            // ── Xác minh & vận hành ──────────────────────────────────────────
            if (! Schema::hasColumn('partners', 'verification_status')) {
                // pending | approved | suspended | rejected
                $table->string('verification_status', 20)->default('pending')->after('status');
            }

            // ── Hợp đồng ─────────────────────────────────────────────────────
            if (! Schema::hasColumn('partners', 'contract_code')) {
                $table->string('contract_code')->nullable();
            }
            if (! Schema::hasColumn('partners', 'contract_type')) {
                // e_contract | paper
                $table->string('contract_type', 20)->nullable();
            }
            if (! Schema::hasColumn('partners', 'contract_status')) {
                // draft | pending | active | expired | terminated
                $table->string('contract_status', 20)->default('draft');
            }
            if (! Schema::hasColumn('partners', 'contract_signed_at')) {
                $table->date('contract_signed_at')->nullable();
            }
            if (! Schema::hasColumn('partners', 'contract_expires_at')) {
                $table->date('contract_expires_at')->nullable();
            }
            if (! Schema::hasColumn('partners', 'commission_rate')) {
                $table->string('commission_rate')->nullable();
            }
            if (! Schema::hasColumn('partners', 'cancellation_policy')) {
                $table->text('cancellation_policy')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $columns = [
                'representative_name', 'representative_dob', 'representative_id_number',
                'representative_phone_secondary', 'legal_name', 'business_license_date',
                'business_license_issuer', 'bank_name', 'bank_branch', 'bank_account_number',
                'bank_account_holder', 'momo_phone', 'zalopay_id', 'vnpay_id', 'paypal_email',
                'wise_account', 'swift_code', 'payment_cycle', 'verification_status',
                'contract_code', 'contract_type', 'contract_status', 'contract_signed_at',
                'contract_expires_at', 'commission_rate', 'cancellation_policy',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('partners', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
