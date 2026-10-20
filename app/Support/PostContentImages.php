<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;

// Ảnh chèn trong nội dung bài viết (TinyEditor upload thẳng vào disk public, vd
// "/storage/bbeocyb...jpg") không qua medialibrary nên không có conversion — trước đây trang bài
// viết tải nguyên file gốc (PageSpeed: JPG 1400px 143KB cho khung ~680px). Dùng lại đúng cơ chế
// preset của banner/chi nhánh (GeneratesImagePresets: file anh em "-card.avif", "-medium.avif",
// "-wide.avif" cạnh file gốc) — sinh ở PostContentImages::generate() (lúc lưu bài + lệnh
// images:post-content-presets để chạy bù), đọc ở srcsetFor() lúc render (ContentMarkup::enhance).
class PostContentImages
{
    private const SRCSET_PRESETS = ['card', 'medium', 'wide'];

    /** Đường dẫn trên disk public của mọi ảnh /storage/... trong HTML nội dung. */
    public static function paths(string $html): array
    {
        preg_match_all('/<img\b[^>]*\ssrc\s*=\s*["\']([^"\']+)["\']/i', $html, $m);

        return collect($m[1])
            ->map(fn (string $src) => self::storagePath($src))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public static function generate(string $html): int
    {
        $count = 0;
        foreach (self::paths($html) as $path) {
            $absolute = Storage::disk('public')->path($path);
            if (is_file($absolute)) {
                GeneratesImagePresets::apply($absolute);
                $count++;
            }
        }

        return $count;
    }

    /**
     * srcset cho 1 thẻ <img> trong nội dung — null nếu không phải ảnh /storage/ hoặc chưa sinh
     * preset nào (giữ nguyên thẻ như cũ). Bề rộng thật của từng preset tính từ kích thước gốc:
     * preset giới hạn CẠNH DÀI, ảnh dọc/vuông có bề rộng nhỏ hơn số trên tên preset, khai sai "w"
     * thì trình duyệt chọn bản quá nhỏ (mờ).
     */
    public static function srcsetFor(string $src): ?string
    {
        $path = self::storagePath($src);
        if ($path === null) {
            return null;
        }

        $absolute = Storage::disk('public')->path($path);
        $size = is_file($absolute) ? @getimagesize($absolute) : false;
        if (! $size || ! $size[0] || ! $size[1]) {
            return null;
        }
        [$width, $height] = $size;
        $longEdge = max($width, $height);

        $candidates = [];
        foreach (self::SRCSET_PRESETS as $preset) {
            $presetAbsolute = GeneratesImagePresets::presetPath($absolute, $preset);
            if (! is_file($presetAbsolute)) {
                continue;
            }
            $presetWidth = (int) round($width * min(1, GeneratesImagePresets::PRESETS[$preset] / $longEdge));
            $candidates[$presetWidth] = Storage::disk('public')->url(
                ltrim(str_replace('\\', '/', substr($presetAbsolute, strlen(Storage::disk('public')->path('')))), '/')
            );
        }

        if ($candidates === []) {
            return null;
        }

        // Bản gốc làm bậc lớn nhất, nếu lớn hơn mọi preset.
        if (! isset($candidates[$width]) && $width > max(array_keys($candidates))) {
            $candidates[$width] = $src;
        }
        ksort($candidates);

        return collect($candidates)->map(fn ($url, $w) => $url . ' ' . $w . 'w')->implode(', ');
    }

    /** "/storage/a/b.jpg" hoặc "https://<host>/storage/a/b.jpg" => "a/b.jpg"; ảnh ngoài => null. */
    private static function storagePath(string $src): ?string
    {
        if (! preg_match('~^(?:https?://[^/]+)?/storage/([^?#"\']+\.(?:jpe?g|png|webp))$~i', trim($src), $m)) {
            return null;
        }

        $path = rawurldecode($m[1]);

        return str_contains($path, '..') ? null : $path;
    }
}
