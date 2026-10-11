<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

// Cách nền tảng (Bên A) ký hợp đồng đối tác Homestay: 'auto' (mặc định — ký tự động qua nhà cung cấp chữ ký số từ xa như MISA eSign, VNPT SmartCA, đúng như trước đây) hoặc 'usb' (người có USB token ký trên máy rồi tải file lên).
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('signing.platform_sign_method', 'auto');
    }
};
