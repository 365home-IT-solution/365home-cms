<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Công tắc BẮT BUỘC đồng ý Điều khoản khi đăng ký, theo từng loại: Homestay TẮT (đăng ký như trước), MiniHouse BẬT.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terms_switches', function (Blueprint $table) {
            $table->string('type', 40)->primary();   // partner_homestay | partner_minihouse
            $table->boolean('required')->default(false);
            $table->timestamps();
        });

        $now = now();
        DB::table('terms_switches')->insert([
            ['type' => 'partner_homestay', 'required' => false, 'created_at' => $now, 'updated_at' => $now],
            ['type' => 'partner_minihouse', 'required' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('terms_switches');
    }
};
