<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\GameSticker;
use App\Models\GameStickerPack;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Bộ sticker GIF động có sẵn (nền trong suốt, 160×160) cho chat mini game:
 *  - Biểu cảm, Lời chào: dùng chung Tiến lên + Caro
 *  - Tiến lên / Caro: chỉ hiện trong game tương ứng
 *
 * Ảnh nằm trong mã nguồn ở public/images/games/stickers/{bộ}/NN-tên.gif (đi theo git như bộ bài có sẵn) — DB chỉ lưu đường dẫn,
 * xoá sticker / bộ trên CMS không xoá các file này (GameStickerService chỉ xoá file trong storage/).
 * Ảnh vẽ bằng resources/dev/game-stickers/gen.py.
 *
 * Chạy: php artisan db:seed --class=GameStickerSeeder — hoặc import database/sql/game_social_2026_11_16.sql (cùng dữ liệu).
 * Bộ đã có (theo key) thì bỏ qua, không ghi đè chỉnh sửa của admin.
 */
class GameStickerSeeder extends Seeder
{
    public const IMAGE_DIR = 'images/games/stickers';

    private const PACKS = [
        ['key' => 'bieu-cam', 'name' => 'Biểu cảm', 'games' => ['tien_len', 'caro'], 'sort_order' => 1],
        ['key' => 'loi-chao', 'name' => 'Lời chào', 'games' => ['tien_len', 'caro'], 'sort_order' => 2],
        ['key' => 'tien-len', 'name' => 'Tiến lên', 'games' => ['tien_len'], 'sort_order' => 3],
        ['key' => 'caro', 'name' => 'Caro', 'games' => ['caro'], 'sort_order' => 4],
    ];

    /**
     * Danh sách bộ + đường dẫn ảnh (tương đối web root), theo thứ tự số đầu tên file.
     *
     * @return array<int, array{key: string, name: string, games: string[], sort_order: int, stickers: string[]}>
     */
    public static function manifest(): array
    {
        return array_map(function (array $pack) {
            $files = glob(public_path(self::IMAGE_DIR . "/{$pack['key']}/*.gif")) ?: [];
            natsort($files);

            return $pack + ['stickers' => array_map(fn (string $f) => self::IMAGE_DIR . "/{$pack['key']}/" . basename($f), array_values($files))];
        }, self::PACKS);
    }

    public function run(): void
    {
        foreach (self::manifest() as $pack) {
            if (GameStickerPack::where('key', $pack['key'])->exists()) {
                $this->command?->line("Bỏ qua bộ \"{$pack['name']}\" (đã có).");
                continue;
            }
            if ($pack['stickers'] === []) {
                $this->command?->warn("Không thấy ảnh cho bộ \"{$pack['name']}\" trong public/" . self::IMAGE_DIR . "/{$pack['key']}.");
                continue;
            }

            DB::transaction(function () use ($pack) {
                $model = GameStickerPack::create([
                    'key' => $pack['key'], 'name' => $pack['name'], 'games' => $pack['games'], 'is_active' => true, 'sort_order' => $pack['sort_order'],
                ]);
                foreach ($pack['stickers'] as $i => $image) {
                    GameSticker::create(['pack_id' => $model->id, 'image' => $image, 'sort_order' => $i + 1]);
                }
            });
            $this->command?->info("Đã tạo bộ \"{$pack['name']}\" — " . count($pack['stickers']) . ' sticker.');
        }
    }
}
