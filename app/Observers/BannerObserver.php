<?php

namespace App\Observers;

use App\Support\GeneratesImagePresets;
use App\Support\ResizesOversizedImage;
use Illuminate\Support\Facades\Storage;
use Modules\AppPage\App\Models\Banner;

class BannerObserver
{
    public function saved(Banner $banner): void
    {
        if (blank($banner->image)) {
            return;
        }

        if (! $banner->wasRecentlyCreated && ! $banner->wasChanged('image')) {
            return;
        }

        $disk = $banner->disk ?? 'public';
        $path = Storage::disk($disk)->path($banner->image);

        ResizesOversizedImage::apply($path);
        GeneratesImagePresets::apply($path);

        $dimensions = @getimagesize($path);
        if ($dimensions) {
            $banner->updateQuietly(['image_width' => $dimensions[0], 'image_height' => $dimensions[1]]);
        }
    }
}
