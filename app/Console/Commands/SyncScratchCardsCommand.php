<?php

namespace App\Console\Commands;

use App\Services\CollectionService;
use App\Services\ReferralService;
use App\Services\ScratchCardService;
use Illuminate\Console\Command;

/**
 * Safety-net cho các mini game: sinh thẻ cào / nhận mảnh ghép / thưởng giới thiệu cho đơn đã trả phòng mà hook ở Order bỏ sót, và đánh dấu thẻ quá hạn cào là expired.
 */
class SyncScratchCardsCommand extends Command
{
    protected $signature   = 'scratch-cards:sync {--days=3 : Quét bù các đơn trả phòng trong N ngày gần nhất}';
    protected $description = 'Sinh thẻ cào còn thiếu, đánh dấu thẻ quá hạn và nhận bù mảnh ghép cho đơn đã trả phòng';

    public function handle(ScratchCardService $service, CollectionService $collections, ReferralService $referrals): int
    {
        [$issued, $expired] = $service->sync((int) $this->option('days'));
        $pieces = $collections->sync((int) $this->option('days'));
        $referrals->sync((int) $this->option('days'));

        $this->info("Đã sinh {$issued} thẻ cào, đánh dấu {$expired} thẻ hết hạn, nhận bù {$pieces} mảnh ghép.");

        return self::SUCCESS;
    }
}
