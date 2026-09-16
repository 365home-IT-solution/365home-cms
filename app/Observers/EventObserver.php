<?php

namespace App\Observers;

use App\Models\Event;
use App\Support\GeneratesImagePresets;
use App\Support\ResizesOversizedImage;
use Illuminate\Support\Facades\Storage;

class EventObserver
{
    public function saved(Event $event): void
    {
        if (blank($event->image)) {
            return;
        }

        if (! $event->wasRecentlyCreated && ! $event->wasChanged('image')) {
            return;
        }

        $disk = $event->disk ?? 'public';
        $path = Storage::disk($disk)->path($event->image);

        ResizesOversizedImage::apply($path);
        GeneratesImagePresets::apply($path);

        $dimensions = @getimagesize($path);
        if ($dimensions) {
            $event->updateQuietly(['image_width' => $dimensions[0], 'image_height' => $dimensions[1]]);
        }
    }
}
