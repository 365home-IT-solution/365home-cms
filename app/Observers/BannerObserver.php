<?php

namespace App\Observers;

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

        ResizesOversizedImage::apply(Storage::disk($disk)->path($banner->image));
    }
}
