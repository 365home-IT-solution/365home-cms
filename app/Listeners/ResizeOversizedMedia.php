<?php

namespace App\Listeners;

use App\Support\ResizesOversizedImage;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;

class ResizeOversizedMedia
{
    public function handle(MediaHasBeenAddedEvent $event): void
    {
        $media = $event->media;

        if (! str_starts_with((string) $media->mime_type, 'image/')) {
            return;
        }

        $path = $media->getPath();

        ResizesOversizedImage::apply($path);

        $newSize = @filesize($path);
        if ($newSize && $newSize !== $media->size) {
            $media->size = $newSize;
            $media->save();
        }
    }
}
