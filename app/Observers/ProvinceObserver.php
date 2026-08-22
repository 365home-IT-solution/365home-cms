<?php

namespace App\Observers;

use App\Models\Province;
use App\Support\GeneratesImagePresets;
use App\Support\ResizesOversizedImage;
use Illuminate\Support\Facades\Storage;

class ProvinceObserver
{
    public function saved(Province $province): void
    {
        if (blank($province->image) || (! $province->wasRecentlyCreated && ! $province->wasChanged('image'))) {
            return;
        }

        $path = Storage::disk('public')->path($province->image);

        ResizesOversizedImage::apply($path);
        GeneratesImagePresets::apply($path);

        $dimensions = @getimagesize($path);
        if ($dimensions) {
            $province->updateQuietly(['image_width' => $dimensions[0], 'image_height' => $dimensions[1]]);
        }
    }
}
