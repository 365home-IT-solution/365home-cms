<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

// `media-library:regenerate` KHÔNG bắn MediaHasBeenAddedEvent, nên
// App\Listeners\StoreOriginalImageDimensions không bao giờ chạy cho media đã có sẵn — lệnh này
// điền trực tiếp width/height (custom_properties) cho những media còn thiếu. Idempotent: bỏ qua
// media đã có sẵn cả 2 property.
class BackfillMediaDimensions extends Command
{
    protected $signature = 'media-library:backfill-dimensions {modelType? : FQCN của model, vd "Modules\\Product\\App\\Models\\Product"}';

    protected $description = 'Điền width/height gốc còn thiếu vào custom_properties của media';

    public function handle(): int
    {
        $query = Media::query()->where('mime_type', 'like', 'image/%');

        if ($modelType = $this->argument('modelType')) {
            $query->where('model_type', $modelType);
        }

        $total = $query->count();
        $bar   = $this->output->createProgressBar($total);
        $done  = 0;
        $skipped = 0;

        $query->orderBy('id')->chunkById(50, function ($chunk) use ($bar, &$done, &$skipped) {
            foreach ($chunk as $media) {
                $bar->advance();

                if ($media->getCustomProperty('width') && $media->getCustomProperty('height')) {
                    $skipped++;
                    continue;
                }

                $dimensions = @getimagesize($media->getPath());

                if (! $dimensions) {
                    continue;
                }

                [$width, $height] = $dimensions;

                $media->setCustomProperty('width', $width);
                $media->setCustomProperty('height', $height);
                $media->save();
                $done++;
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Đã điền width/height cho {$done} media (bỏ qua {$skipped} đã có sẵn, tổng {$total}).");

        return self::SUCCESS;
    }
}
