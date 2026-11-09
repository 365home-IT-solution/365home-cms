<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Bộ bài Tiến lên quản lý được (thêm / sửa / xoá / cho dùng) — thay danh sách cố định trong code.
// Đường dẫn ảnh lưu tương đối với thư mục public (web root): bộ có sẵn ở images/…, bộ admin upload ở storage/….
return new class extends Migration
{
    private const BUILT_IN = [
        'normal'                => 'Cổ điển',
        'con-vat-chibi'         => 'Con vật Chibi',
        'hoang-gia-chibi'       => 'Hoàng gia Chibi',
        'dark-night'            => 'Dark Night',
        'lien-minh-huyen-thoai' => 'Liên Minh Huyền Thoại',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('tien_len_card_decks')) {
            Schema::create('tien_len_card_decks', function (Blueprint $table) {
                $table->id();
                $table->string('key', 50)->unique();
                $table->string('name');
                // Chỉ bộ đang bật mới hiện cho khách chọn
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->string('back_image')->nullable();
                $table->string('preview_image')->nullable();
                // { "3S": "images/…/3S.webp", … } — lá thiếu dùng ảnh bộ mặc định
                $table->json('card_images')->nullable();
                $table->timestamps();
            });
        }

        $ranks = ['3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K', 'A', '2'];
        $sort  = 0;
        foreach (self::BUILT_IN as $key => $name) {
            if (DB::table('tien_len_card_decks')->where('key', $key)->exists()) {
                continue;
            }

            $dir   = "images/tien-len/decks/{$key}";
            $cards = [];
            foreach ($ranks as $r) {
                foreach (['S', 'C', 'D', 'H'] as $s) {
                    if (is_file(public_path("{$dir}/{$r}{$s}.webp"))) {
                        $cards[$r . $s] = "{$dir}/{$r}{$s}.webp";
                    }
                }
            }

            DB::table('tien_len_card_decks')->insert([
                'key'           => $key,
                'name'          => $name,
                'is_active'     => true,
                'sort_order'    => $sort++,
                'back_image'    => "{$dir}/back.webp",
                'preview_image' => "{$dir}/preview.webp",
                'card_images'   => json_encode($cards),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tien_len_card_decks');
    }
};
