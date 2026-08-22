<?php

declare(strict_types=1);

namespace App\Support;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

// Gói {thumb,card,wide,full,width,height} từ 1 Media của medialibrary — xem
// docs/be-image-thumbnails.md §3. Trả null cho preset chưa sinh xong (job chạy nền/chưa backfill)
// thay vì ném lỗi, để app tự lùi về thumbnail_url.
class MediaThumbnailUrls
{
    private const PRESETS = ['thumb', 'card', 'wide', 'full'];

    public static function build(?Media $media): ?array
    {
        if (! $media) {
            return null;
        }

        $urls = [];
        foreach (self::PRESETS as $preset) {
            $urls[$preset] = $media->hasGeneratedConversion($preset) ? $media->getUrl($preset) : null;
        }

        $urls['width']  = $media->getCustomProperty('width');
        $urls['height'] = $media->getCustomProperty('height');

        return $urls;
    }
}
