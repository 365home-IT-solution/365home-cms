<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DefaultCatalogImage;
use Illuminate\Console\Command;

class FillMissingCatalogImages extends Command
{
    protected $signature = 'catalog:fill-missing-images {--dry-run : Chỉ đếm, không ghi}';

    protected $description = 'Gán ảnh mặc định cho tiện ích và dịch vụ đi kèm đang thiếu ảnh';

    public function handle(DefaultCatalogImage $images): int
    {
        $result = $images->fillMissing((bool) $this->option('dry-run'));

        $this->info(($this->option('dry-run') ? '[dry-run] ' : '') . "Tiện ích thiếu ảnh: {$result['tags']}, dịch vụ thiếu ảnh: {$result['services']}.");

        return self::SUCCESS;
    }
}
