<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

// Công tắc giai đoạn 2 của bảng giá: mặc định null = theo .env (deploy không đổi hành vi nào cho tới khi Super Admin chọn ở CMS).
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('price_boards.phase2', null);
    }
};
