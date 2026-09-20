<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (DB::table('settings')->where('group', 'invoice')->where('name', 'invoice_type_code')->exists()) {
            return;
        }

        $this->migrator->add('invoice.invoice_type_code', null);
    }
};
