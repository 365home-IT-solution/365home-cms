<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Enums\ImageDriver;
use Spatie\Image\Image;

// Sinh 4 bản thu nhỏ (thumb/card/medium/wide) làm file anh em cạnh ảnh gốc, không đụng vào file/URL gốc
// — dùng cho model không qua medialibrary (Category, Banner — xem docs/be-image-thumbnails.md
// §7.2 phương án b). Preset "full" KHÔNG sinh riêng: bản gốc đã bị ResizesOversizedImage hạ
// xuống ≤1440px cạnh dài ngay lúc lưu, nên bản gốc đã đóng luôn vai trò preset "full".
//
// vd "categories/01ABC.jpg" -> "categories/01ABC-thumb.avif", "categories/01ABC-card.avif", ...
class GeneratesImagePresets
{
    // "medium" lấp khoảng trống 480→1080: mobile ~412px × DPR 1.75 cần ~720px, thiếu bậc này thì
    // trình duyệt phải tải bản 1080 (Lighthouse "Improve image delivery").
    public const PRESETS = ['thumb' => 240, 'card' => 480, 'medium' => 768, 'wide' => 1080];

    public static function apply(string $absolutePath): void
    {
        if (! file_exists($absolutePath)) {
            return;
        }

        foreach (self::PRESETS as $preset => $maxLongEdge) {
            $presetPath = self::presetPath($absolutePath, $preset);
            // File tạm PHẢI giữ đuôi ".avif" ở cuối: Image::load() tự chọn Imagick nếu server có
            // ext imagick (prod có), mà ImagickDriver::save() ghi theo đuôi tên file chứ không theo
            // ->format() — tên cũ "x-wide.avif.resize-tmp-<id>" khiến prod ghi ra PNG gốc rồi đổi
            // tên thành .avif (banner 1080px nặng ~480KB thay vì ~50KB).
            $tmpPath = dirname($presetPath).DIRECTORY_SEPARATOR.'.preset-tmp-'.uniqid().'.avif';

            try {
                self::encodeAvif($absolutePath, $tmpPath, $maxLongEdge);
                // Chỉ tới đây khi file tạm đã là AVIF hợp lệ — preset cũ (nếu có) chỉ bị thay thế
                // đúng lúc này, mọi lỗi phía trên đều giữ nguyên preset cũ.
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

    // Thử GD trước (cùng driver medialibrary dùng cho ảnh phòng, IMAGE_DRIVER mặc định gd), lỗi
    // (vd PHP build GD không có AVIF) thì thử Imagick nếu server có. Driver nào cũng phải qua
    // chốt kiểm tra magic bytes: không bao giờ để file định dạng khác mang đuôi .avif nữa.
    private static function encodeAvif(string $source, string $tmpPath, int $maxLongEdge): void
    {
        $drivers = [ImageDriver::Gd];
        if (class_exists(\Imagick::class)) {
            $drivers[] = ImageDriver::Imagick;
        }

        $lastError = null;
        foreach ($drivers as $driver) {
            try {
                Image::useImageDriver($driver)
                    ->loadFile($source)
                    ->fit(Fit::Max, $maxLongEdge, $maxLongEdge)
                    ->format('avif')
                    ->quality(60)
                    ->save($tmpPath);

                if (! file_exists($tmpPath) || filesize($tmpPath) === 0) {
                    throw new \RuntimeException('File preset sinh ra rỗng');
                }
                if (! str_contains((string) file_get_contents($tmpPath, false, null, 0, 16), 'ftypavi')) {
                    throw new \RuntimeException('File preset sinh ra không phải AVIF');
                }

                return;
            } catch (\Throwable $e) {
                @unlink($tmpPath);
                $lastError = $e;
            }
        }

        throw $lastError ?? new \RuntimeException('Không có driver ảnh nào dùng được');
    }

    public static function presetPath(string $absolutePath, string $preset): string
    {
        $info = pathinfo($absolutePath);

        return $info['dirname'] . DIRECTORY_SEPARATOR . $info['filename'] . '-' . $preset . '.avif';
    }
}
