<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;

// Sinh 3 bản thu nhỏ (thumb/card/wide) làm file anh em cạnh ảnh gốc, không đụng vào file/URL gốc
// — dùng cho model không qua medialibrary (Category, Banner — xem docs/be-image-thumbnails.md
// §7.2 phương án b). Preset "full" KHÔNG sinh riêng: bản gốc đã bị ResizesOversizedImage hạ
// xuống ≤1440px cạnh dài ngay lúc lưu, nên bản gốc đã đóng luôn vai trò preset "full".
//
// vd "categories/01ABC.jpg" -> "categories/01ABC-thumb.avif", "categories/01ABC-card.avif", ...
class GeneratesImagePresets
{
    public const PRESETS = ['thumb' => 240, 'card' => 480, 'wide' => 1080];

    public static function apply(string $absolutePath): void
    {
        if (! file_exists($absolutePath)) {
            return;
        }

        foreach (self::PRESETS as $preset => $maxLongEdge) {
            $presetPath = self::presetPath($absolutePath, $preset);
            $tmpPath = $presetPath.'.resize-tmp-'.uniqid();

            try {
                Image::load($absolutePath)
                    ->fit(Fit::Max, $maxLongEdge, $maxLongEdge)
                    ->format('avif')
                    ->quality(72)
                    ->save($tmpPath);

                if (! file_exists($tmpPath) || filesize($tmpPath) === 0) {
                    throw new \RuntimeException('File preset sinh ra rỗng');
                }

                rename($tmpPath, $presetPath);
            } catch (\Throwable $e) {
                @unlink($tmpPath);
                // Nguồn hỏng thì đọc lần nào cũng lỗi giống nhau — dừng luôn, không thử tiếp 2
                // preset còn lại. Không được để 1 ảnh hỏng làm crash cả lệnh backfill hàng trăm ảnh.
                Log::warning('GeneratesImagePresets: không đọc được ảnh, bỏ qua', [
                    'path'  => $absolutePath,
                    'error' => $e->getMessage(),
                ]);

                return;
            }
        }
    }

    public static function presetPath(string $absolutePath, string $preset): string
    {
        $info = pathinfo($absolutePath);

        return $info['dirname'] . DIRECTORY_SEPARATOR . $info['filename'] . '-' . $preset . '.avif';
    }
}
