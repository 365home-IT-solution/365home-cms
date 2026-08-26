<?php

namespace App\Observers;

use App\Support\GeneratesImagePresets;
use App\Support\ResizesOversizedImage;
use Illuminate\Support\Facades\Storage;
use Modules\AppPage\App\Models\PopupImage;

class PopupImageObserver
{
    public function saved(PopupImage $popupImage): void
    {
        if (blank($popupImage->image)) {
            return;
        }

        if (! $popupImage->wasRecentlyCreated && ! $popupImage->wasChanged('image')) {
            return;
        }

        $disk = $popupImage->disk ?? 'public';
        $path = Storage::disk($disk)->path($popupImage->image);

        ResizesOversizedImage::apply($path);
        GeneratesImagePresets::apply($path);

        $dimensions = @getimagesize($path);
        if ($dimensions) {
            $popupImage->updateQuietly(['image_width' => $dimensions[0], 'image_height' => $dimensions[1]]);
        }
    }
}
