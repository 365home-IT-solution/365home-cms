<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

// Cài đặt thanh toán thẻ Pay2S (chỉ chi nhánh của 365home). Mặc định TẮT và chế độ thử (sandbox) BẬT.
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (DB::table('settings')->where('group', 'pay2s_card')->where('name', 'enabled')->exists()) {
            return;
        }

        $this->migrator->add('pay2s_card.enabled', false);
        $this->migrator->add('pay2s_card.partner_code', null);
        $this->migrator->addEncrypted('pay2s_card.access_key', null);
        $this->migrator->addEncrypted('pay2s_card.secret_key', null);
        $this->migrator->add('pay2s_card.connection_id', null);
        $this->migrator->add('pay2s_card.sandbox_only', true);
        $this->migrator->add('pay2s_card.merchant_origin', null);
        $this->migrator->add('pay2s_card.api_url', null);
        $this->migrator->add('pay2s_card.category_ids', []);
    }
};
