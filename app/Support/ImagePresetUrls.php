<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;

// Phía đọc của App\Support\GeneratesImagePresets — dựng {thumb,card,wide,full,width,height} từ
// 1 path lưu trong DB (Category::$image, Banner::$image...), disk tương ứng. Preset nào chưa
// sinh xong (file anh em chưa tồn tại) thì trả null thay vì URL hỏng, để app tự lùi về image_url.
class ImagePresetUrls
{
    public static function build(?string $path, string $disk, ?int $width = null, ?int $height = null): ?array
    {
        if (blank($path)) {
            return null;
        }

        $storage = Storage::disk($disk);
        $info    = pathinfo($path);
        $dir     = ($info['dirname'] ?? '.') === '.' ? '' : $info['dirname'] . '/';

        $urls = [];
        foreach (array_keys(GeneratesImagePresets::PRESETS) as $preset) {
            $presetPath   = $dir . $info['filename'] . '-' . $preset . '.avif';
            $urls[$preset] = $storage->exists($presetPath) ? $storage->url($presetPath) : null;
        }

        $urls['full']   = $storage->url($path);
        $urls['width']  = $width;
        $urls['height'] = $height;

        return $urls;
    }
}
