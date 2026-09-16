<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\GeneratesImagePresets;
use App\Support\ResizesOversizedImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use App\Models\AskUser;
use App\Models\Event;
use App\Models\Province;
use Modules\AppPage\App\Models\AppPage;
use Modules\AppPage\App\Models\Banner;
use Modules\AppPage\App\Models\PopupImage;
use Modules\Category\Entities\Category;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

// Hạ kích thước ảnh gốc ĐÃ CÓ SẴN xuống 1440px cạnh dài (đè lên chính file, không đổi URL/DB) —
// với category/banner/province/event/popup-image/app-page/ask-user, sinh luôn 3 preset
// thumb/card/wide và điền image_width/image_height (app-page và ask-user không có cột dimensions
// riêng nên bỏ qua bước đó). Ảnh mới upload từ giờ tự làm hết việc này qua
// ResizeOversizedMedia/CategoryObserver/BannerObserver/EventObserver/PopupImageObserver/
// AppPageObserver/AskUserObserver, lệnh này chỉ để dọn 1 lần cho ảnh cũ. Với product, chạy thêm
// `media-library:regenerate` + `media-library:backfill-dimensions` sau lệnh này để sinh preset +
// width/height (không phải việc của lệnh này — product qua medialibrary, không phải file anh em).
class ResizeOversizedImages extends Command
{
    protected $signature = 'images:resize-oversized {target : product|category|banner|province|event|popup-image|app-page|ask-user}';
    protected $description = 'Hạ kích thước ảnh gốc quá khổ xuống 1440px cạnh dài (đè lên file gốc)';

    public function handle(): int
    {
        match ($this->argument('target')) {
            'product'      => $this->resizeProductMedia(),
            'category'     => $this->resizeCategoryImages(),
            'banner'       => $this->resizeBannerImages(),
            'province'     => $this->resizeProvinceImages(),
            'event'        => $this->resizeEventImages(),
            'popup-image'  => $this->resizePopupImages(),
            'app-page'     => $this->resizeAppPageIcons(),
            'ask-user'     => $this->resizeAskUserImages(),
            default        => $this->error('target phải là product, category, banner, province, event, popup-image, app-page hoặc ask-user'),
        };

        return self::SUCCESS;
    }

    private function resizeProductMedia(): void
    {
        $query = Media::query()
            ->where('mime_type', 'like', 'image/%')
            ->where('model_type', 'Modules\\Product\\App\\Models\\Product');

        $bar = $this->output->createProgressBar($query->count());
        $done = 0;

        $query->orderBy('id')->chunkById(50, function ($chunk) use ($bar, &$done) {
            foreach ($chunk as $media) {
                $bar->advance();
                $path = $media->getPath();
                ResizesOversizedImage::apply($path);
                $newSize = @filesize($path);
                if ($newSize && $newSize !== $media->size) {
                    $media->size = $newSize;
                    $media->save();
                    $done++;
                }
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Đã resize {$done} ảnh phòng.");
    }

    private function resizeCategoryImages(): void
    {
        $query = Category::query()->whereNotNull('image');
        $bar = $this->output->createProgressBar($query->count());
        $done = 0;

        $query->orderBy('id')->chunkById(50, function ($chunk) use ($bar, &$done) {
            foreach ($chunk as $category) {
                $bar->advance();
                $path = Storage::disk('public')->path($category->image);
                if (file_exists($path)) {
                    $sizeBefore = filesize($path);
                    ResizesOversizedImage::apply($path);
                    if (filesize($path) !== $sizeBefore) {
                        $done++;
                    }

                    GeneratesImagePresets::apply($path);

                    if (! $category->image_width || ! $category->image_height) {
                        $dimensions = @getimagesize($path);
                        if ($dimensions) {
                            $category->updateQuietly(['image_width' => $dimensions[0], 'image_height' => $dimensions[1]]);
                        }
                    }
                }
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Đã resize {$done} ảnh chi nhánh/tỉnh.");
    }

    private function resizeBannerImages(): void
    {
        $banners = Banner::query()->whereNotNull('image')->get();
        $bar = $this->output->createProgressBar($banners->count());
        $done = 0;

        foreach ($banners as $banner) {
            $bar->advance();
            $disk = $banner->disk ?? 'public';
            $path = Storage::disk($disk)->path($banner->image);
            if (file_exists($path)) {
                $sizeBefore = filesize($path);
                ResizesOversizedImage::apply($path);
                if (filesize($path) !== $sizeBefore) {
                    $done++;
                }

                GeneratesImagePresets::apply($path);

                if (! $banner->image_width || ! $banner->image_height) {
                    $dimensions = @getimagesize($path);
                    if ($dimensions) {
                        $banner->updateQuietly(['image_width' => $dimensions[0], 'image_height' => $dimensions[1]]);
                    }
                }
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Đã resize {$done} banner.");
    }

    private function resizeProvinceImages(): void
    {
        $query = Province::query()->whereNotNull('image');
        $bar = $this->output->createProgressBar($query->count());
        $done = 0;

        $query->orderBy('id')->chunkById(50, function ($chunk) use ($bar, &$done) {
            foreach ($chunk as $province) {
                $bar->advance();
                $path = Storage::disk('public')->path($province->image);
                if (file_exists($path)) {
                    $sizeBefore = filesize($path);
                    ResizesOversizedImage::apply($path);
                    if (filesize($path) !== $sizeBefore) {
                        $done++;
                    }

                    GeneratesImagePresets::apply($path);

                    if (! $province->image_width || ! $province->image_height) {
                        $dimensions = @getimagesize($path);
                        if ($dimensions) {
                            $province->updateQuietly(['image_width' => $dimensions[0], 'image_height' => $dimensions[1]]);
                        }
                    }
                }
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Đã resize {$done} ảnh tỉnh thành.");
    }

    private function resizeEventImages(): void
    {
        $events = Event::query()->whereNotNull('image')->get();
        $bar = $this->output->createProgressBar($events->count());
        $done = 0;

        foreach ($events as $event) {
            $bar->advance();
            $disk = $event->disk ?? 'public';
            $path = Storage::disk($disk)->path($event->image);
            if (file_exists($path)) {
                $sizeBefore = filesize($path);
                ResizesOversizedImage::apply($path);
                if (filesize($path) !== $sizeBefore) {
                    $done++;
                }

                GeneratesImagePresets::apply($path);

                if (! $event->image_width || ! $event->image_height) {
                    $dimensions = @getimagesize($path);
                    if ($dimensions) {
                        $event->updateQuietly(['image_width' => $dimensions[0], 'image_height' => $dimensions[1]]);
                    }
                }
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Đã resize {$done} ảnh sự kiện.");
    }

    private function resizePopupImages(): void
    {
        $popups = PopupImage::query()->whereNotNull('image')->get();
        $bar = $this->output->createProgressBar($popups->count());
        $done = 0;

        foreach ($popups as $popup) {
            $bar->advance();
            $disk = $popup->disk ?? 'public';
            $path = Storage::disk($disk)->path($popup->image);
            if (file_exists($path)) {
                $sizeBefore = filesize($path);
                ResizesOversizedImage::apply($path);
                if (filesize($path) !== $sizeBefore) {
                    $done++;
                }

                GeneratesImagePresets::apply($path);

                if (! $popup->image_width || ! $popup->image_height) {
                    $dimensions = @getimagesize($path);
                    if ($dimensions) {
                        $popup->updateQuietly(['image_width' => $dimensions[0], 'image_height' => $dimensions[1]]);
                    }
                }
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Đã resize {$done} ảnh popup.");
    }

    // Icon của block "promotion_list" trong content (JSON builder) của AppPage — không có cột
    // image_width/image_height riêng (xem AppPageForm), nên chỉ resize + sinh preset, không điền
    // dimensions.
    private function resizeAppPageIcons(): void
    {
        $pages = AppPage::query()->get();
        $icons = [];
        foreach ($pages as $page) {
            foreach ($page->content ?? [] as $block) {
                if (($block['type'] ?? null) === 'promotion_list' && ! empty($block['data']['icon'])) {
                    $icons[] = $block['data']['icon'];
                }
            }
        }
        $icons = array_unique($icons);

        $bar = $this->output->createProgressBar(count($icons));
        $done = 0;

        foreach ($icons as $icon) {
            $bar->advance();
            $path = Storage::disk('public')->path($icon);
            if (file_exists($path)) {
                $sizeBefore = filesize($path);
                ResizesOversizedImage::apply($path);
                if (filesize($path) !== $sizeBefore) {
                    $done++;
                }

                GeneratesImagePresets::apply($path);
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Đã resize {$done} icon khuyến mãi.");
    }

    private function resizeAskUserImages(): void
    {
        $askUsers = AskUser::query()->get();
        $paths = [];
        foreach ($askUsers as $askUser) {
            foreach ($askUser->items ?? [] as $item) {
                if (! empty($item['image'])) {
                    $paths[] = $item['image'];
                }
            }
        }
        $paths = array_unique($paths);

        $bar = $this->output->createProgressBar(count($paths));
        $done = 0;

        foreach ($paths as $imagePath) {
            $bar->advance();
            $path = Storage::disk('public')->path($imagePath);
            if (file_exists($path)) {
                $sizeBefore = filesize($path);
                ResizesOversizedImage::apply($path);
                if (filesize($path) !== $sizeBefore) {
                    $done++;
                }

                GeneratesImagePresets::apply($path);
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Đã resize {$done} ảnh thông báo (ask-user).");
    }
}
