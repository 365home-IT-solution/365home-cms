<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

// TÁCH cấu hình chữ ký số MiniHouse khỏi Homestay: nhóm settings riêng 'minihouse_signing' (nhà cung cấp, công tắc ký số, thông tin từng nhà cung cấp).
// Giá trị đã nhập trước đó ở nhóm 'signing' (minihouse_provider, minihouse_pki_enabled) được chuyển sang nhóm mới rồi xoá khỏi Homestay.
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (DB::table('settings')->where('group', 'minihouse_signing')->where('name', 'provider')->exists()) {
            return;
        }

        $old = fn (string $name) => DB::table('settings')->where('group', 'signing')->where('name', $name)->value('payload');
        $oldProvider = $old('minihouse_provider') !== null ? json_decode((string) $old('minihouse_provider'), true) : null;
        $oldEnabled = $old('minihouse_pki_enabled') !== null ? (bool) json_decode((string) $old('minihouse_pki_enabled'), true) : false;

        $this->migrator->add('minihouse_signing.provider', $oldProvider);
        $this->migrator->add('minihouse_signing.pki_enabled', $oldEnabled);
        $this->migrator->add('minihouse_signing.base_url', null);
        foreach (['client_id', 'client_secret', 'subscriber_user_id', 'subscriber_password'] as $key) {
            $this->migrator->addEncrypted("minihouse_signing.{$key}", null);
        }
        foreach (['misa', 'csc'] as $profile) {
            foreach (['label', 'base_url', 'grant_type'] as $key) {
                $this->migrator->add("minihouse_signing.{$profile}_{$key}", null);
            }
            foreach (['client_id', 'client_secret', 'username', 'password', 'credential_id', 'pin'] as $key) {
                $this->migrator->addEncrypted("minihouse_signing.{$profile}_{$key}", null);
            }
        }

        DB::table('settings')->where('group', 'signing')->whereIn('name', ['minihouse_provider', 'minihouse_pki_enabled'])->delete();
    }
};
