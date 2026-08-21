<?php

declare(strict_types=1);

namespace App\Support;

use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;

// Hạ kích thước ảnh gốc ngay tại chỗ (đè lên chính file đó, giữ nguyên định dạng/tên/URL) nếu
// cạnh dài vượt quá mức app cần — không sinh thêm bản nào khác, không đổi field API.
class ResizesOversizedImage
{
    public static function apply(string $absolutePath, int $maxLongEdge = 1440): void
    {
        if (! file_exists($absolutePath)) {
            return;
        }

        $dimensions = @getimagesize($absolutePath);

        if (! $dimensions) {
            return;
        }

        [$width, $height] = $dimensions;

        if (max($width, $height) <= $maxLongEdge) {
            return;
        }

        Image::load($absolutePath)
            ->fit(Fit::Max, $maxLongEdge, $maxLongEdge)
            ->save();
    }
}
