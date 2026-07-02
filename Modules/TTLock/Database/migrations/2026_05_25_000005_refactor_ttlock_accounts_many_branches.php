<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ttlock_accounts', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn(['category_id', 'is_default']);
        });

        Schema::create('ttlock_account_category', function (Blueprint $table) {
            $table->unsignedBigInteger('ttlock_account_id');
            $table->unsignedBigInteger('category_id');
            $table->primary(['ttlock_account_id', 'category_id']);
            $table->unique('category_id'); // 1 chi nhánh chỉ gán được 1 account
            $table->foreign('ttlock_account_id')->references('id')->on('ttlock_accounts')->onDelete('cascade');
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ttlock_account_category');

        Schema::table('ttlock_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable()->after('api_base');
            $table->boolean('is_default')->default(false)->after('category_id');
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('set null');
            $table->unique('category_id');
        });
    }
};
