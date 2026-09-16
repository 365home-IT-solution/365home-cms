<?php

namespace App\Observers;

use App\Models\AskUser;
use App\Support\GeneratesImagePresets;
use App\Support\ResizesOversizedImage;
use Illuminate\Support\Facades\Storage;

// items là mảng JSON [{image, title}, ...] (không phải cột ảnh riêng như Banner/Event) nên không
// biết chính xác item nào vừa đổi ảnh — duyệt lại toàn bộ item mỗi lần items thay đổi. Ảnh nhỏ, ít
// khi sửa nên không đáng lo hiệu năng.
class AskUserObserver
{
    public function saved(AskUser $askUser): void
    {
        if (! $askUser->wasRecentlyCreated && ! $askUser->wasChanged('items')) {
            return;
        }

        foreach ($askUser->items ?? [] as $item) {
            if (empty($item['image'])) {
                continue;
            }

            $path = Storage::disk('public')->path($item['image']);

            ResizesOversizedImage::apply($path);
            GeneratesImagePresets::apply($path);
        }
    }
}
