<?php

namespace App\Listeners;

use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;

// Lưu width/height của ảnh gốc vào custom_properties để app đặt aspectRatio trước khi ảnh tải
// xong (layout không nhảy) — xem docs/be-image-thumbnails.md §3. Phải chạy SAU
// ResizeOversizedMedia (xem thứ tự trong EventServiceProvider) để lấy đúng kích thước đã hạ, vì
// đó mới là kích thước app thực sự nhận được.
class StoreOriginalImageDimensions
{
    public function handle(MediaHasBeenAddedEvent $event): void
    {
        $media = $event->media;

        if (! str_starts_with((string) $media->mime_type, 'image/')) {
            return;
        }

        $dimensions = @getimagesize($media->getPath());

        if (! $dimensions) {
            return;
        }

        [$width, $height] = $dimensions;

        $media->setCustomProperty('width', $width);
        $media->setCustomProperty('height', $height);
        $media->save();
    }
}
