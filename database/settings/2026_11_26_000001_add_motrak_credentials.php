<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

// Tài khoản đăng nhập Motrak để HỆ THỐNG TỰ LẤY LẠI Token khi hết hạn (POST …/auth/login). Mật khẩu mã hoá khi lưu (như Token), không bao giờ trả ra ngoài.
// token_expires_at: thời điểm hết hạn của Token LẤY QUA ĐĂNG NHẬP (null = Token nhập tay, không biết hạn).
return new class extends SettingsMigration
{
    public function up(): void
    {
        $has = fn (string $name) => DB::table('settings')->where('group', 'motrak')->where('name', $name)->exists();

        $has('username') || $this->migrator->add('motrak.username', null);
        $has('password') || $this->migrator->addEncrypted('motrak.password', null);
        $has('token_expires_at') || $this->migrator->add('motrak.token_expires_at', null);
    }
};
