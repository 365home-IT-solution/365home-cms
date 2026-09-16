<?php

namespace App\Observers;

use App\Support\GeneratesImagePresets;
use App\Support\ResizesOversizedImage;
use Illuminate\Support\Facades\Storage;
use Modules\AppPage\App\Models\AppPage;

// content là builder JSON nhiều loại block (heading/banner/room_list/...) — chỉ block
// "promotion_list" có ảnh riêng (field "icon", xem AppPageForm). Không biết chính xác block nào
// vừa đổi icon nên duyệt lại toàn bộ mỗi lần content thay đổi, giống AskUserObserver.
class AppPageObserver
{
    public function saved(AppPage $appPage): void
    {
        if (! $appPage->wasRecentlyCreated && ! $appPage->wasChanged('content')) {
            return;
        }

        foreach ($appPage->content ?? [] as $block) {
            if (($block['type'] ?? null) !== 'promotion_list' || empty($block['data']['icon'])) {
                continue;
            }

            $path = Storage::disk('public')->path($block['data']['icon']);

            ResizesOversizedImage::apply($path);
            GeneratesImagePresets::apply($path);
        }
    }
}
