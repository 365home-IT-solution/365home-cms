<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\GeneratesImagePresets;
use App\Support\ResizesOversizedImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use App\Models\Province;
use Modules\AppPage\App\Models\Banner;
use Modules\Category\Entities\Category;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

// Hạ kích thước ảnh gốc ĐÃ CÓ SẴN xuống 1440px cạnh dài (đè lên chính file, không đổi URL/DB) —
// với category/banner, sinh luôn 3 preset thumb/card/wide và điền image_width/image_height. Ảnh
// mới upload từ giờ tự làm hết việc này qua ResizeOversizedMedia/CategoryObserver/BannerObserver,
// lệnh này chỉ để dọn 1 lần cho ảnh cũ. Với product, chạy thêm `media-library:regenerate` +
// `media-library:backfill-dimensions` sau lệnh này để sinh preset + width/height (không phải việc
// của lệnh này — product qua medialibrary, không phải file anh em).
class ResizeOversizedImages extends Command
{
    protected $signature = 'images:resize-oversized {target : product|category|banner|province}';
    protected $description = 'Hạ kích thước ảnh gốc quá khổ xuống 1440px cạnh dài (đè lên file gốc)';

    public function handle(): int
    {
        match ($this->argument('target')) {
            'product'  => $this->resizeProductMedia(),
            'category' => $this->resizeCategoryImages(),
            'banner'   => $this->resizeBannerImages(),
            'province' => $this->resizeProvinceImages(),
            default    => $this->error('target phải là product, category, banner hoặc province'),
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
}
