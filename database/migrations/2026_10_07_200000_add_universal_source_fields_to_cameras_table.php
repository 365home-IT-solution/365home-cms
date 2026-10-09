<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cameras', function (Blueprint $table): void {
            $table->string('provider', 50)->default('generic')->after('frigate_camera_name');
            $table->string('source_type', 30)->default('existing')->after('provider');
            $table->text('source_url')->nullable()->after('source_type');
            $table->boolean('managed_source')->default(false)->after('source_url');
            $table->string('external_device_id')->nullable()->after('managed_source');
            $table->json('capabilities')->nullable()->after('external_device_id');
            $table->string('connection_status', 30)->default('unknown')->after('capabilities');
            $table->text('connection_message')->nullable()->after('connection_status');
            $table->timestamp('last_checked_at')->nullable()->after('connection_message');
            $table->timestamp('last_online_at')->nullable()->after('last_checked_at');

            $table->index(['partner_id', 'provider']);
            $table->index(['branch_id', 'connection_status']);
        });
    }

    public function down(): void
    {
        Schema::table('cameras', function (Blueprint $table): void {
            $table->dropIndex(['partner_id', 'provider']);
            $table->dropIndex(['branch_id', 'connection_status']);
            $table->dropColumn([
                'provider', 'source_type', 'source_url', 'managed_source', 'external_device_id',
                'capabilities', 'connection_status', 'connection_message', 'last_checked_at', 'last_online_at',
            ]);
        });
    }
};
