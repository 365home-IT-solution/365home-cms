<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

// Chữ ký số nhiều nhà cung cấp: chọn riêng cho Homestay (signing.provider có sẵn) và MiniHouse, thêm hồ sơ MISA eSign và "nhà cung cấp khác chuẩn CSC".
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (DB::table('settings')->where('group', 'signing')->where('name', 'minihouse_provider')->exists()) {
            return;
        }

        $this->migrator->add('signing.minihouse_provider', null);
        $this->migrator->add('signing.minihouse_pki_enabled', false);

        foreach (['misa', 'csc'] as $profile) {
            $this->migrator->add("signing.{$profile}_label", null);
            $this->migrator->add("signing.{$profile}_base_url", null);
            $this->migrator->add("signing.{$profile}_grant_type", null);
            $this->migrator->addEncrypted("signing.{$profile}_client_id", null);
            $this->migrator->addEncrypted("signing.{$profile}_client_secret", null);
            $this->migrator->addEncrypted("signing.{$profile}_username", null);
            $this->migrator->addEncrypted("signing.{$profile}_password", null);
            $this->migrator->addEncrypted("signing.{$profile}_credential_id", null);
            $this->migrator->addEncrypted("signing.{$profile}_pin", null);
        }
    }
};
