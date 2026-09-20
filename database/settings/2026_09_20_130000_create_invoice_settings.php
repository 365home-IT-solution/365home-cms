<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (DB::table('settings')->where('group', 'invoice')->where('name', 'enabled')->exists()) {
            return;
        }

        $this->migrator->add('invoice.enabled', false);
        $this->migrator->add('invoice.provider', 'misa');
        $this->migrator->add('invoice.base_url', null);
        $this->migrator->addEncrypted('invoice.app_id', null);
        $this->migrator->addEncrypted('invoice.tax_code', null);
        $this->migrator->addEncrypted('invoice.username', null);
        $this->migrator->addEncrypted('invoice.password', null);
        $this->migrator->add('invoice.invoice_template_code', null);
        $this->migrator->add('invoice.invoice_series', null);
        $this->migrator->add('invoice.default_vat_rate', 8.0);
        $this->migrator->add('invoice.signing_mode', null);
        $this->migrator->addEncrypted('invoice.hsm_endpoint', null);
    }
};
