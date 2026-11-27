<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

// Chuyển PayOS → Pay2S: cổng chuyển khoản mặc định (payos | pay2s; null = payos, như trước đây) và TÀI KHOẢN NGÂN HÀNG CỦA 365home nhận các khoản 365 thu hộ
// (đơn đặt phòng chưa có kênh riêng của đối tác, gói dịch vụ, ký quỹ, nộp hoa hồng đối soát, thuê xe). Token webhook của tài khoản này do Pay2S sinh riêng, lưu mã hoá.
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (DB::table('settings')->where('group', 'pay2s')->where('name', 'default_gateway')->exists()) {
            return;
        }

        $this->migrator->add('pay2s.default_gateway', null);
        $this->migrator->add('pay2s.platform_bank_code', null);
        $this->migrator->add('pay2s.platform_bank_bin', null);
        $this->migrator->add('pay2s.platform_account_number', null);
        $this->migrator->add('pay2s.platform_account_holder', null);
        $this->migrator->addEncrypted('pay2s.platform_webhook_token', null);
    }
};
