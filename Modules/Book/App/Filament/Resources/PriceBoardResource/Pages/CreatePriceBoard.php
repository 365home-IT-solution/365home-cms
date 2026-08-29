<?php

declare(strict_types=1);

namespace Modules\Book\App\Filament\Resources\PriceBoardResource\Pages;

use App\Services\PriceBoardSyncService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Book\App\Filament\Resources\PriceBoardResource;
use Modules\Book\App\Filament\Resources\PriceBoardResource\Concerns\SavesPriceBoardItems;
use Modules\Product\App\Models\PriceBoard;

class CreatePriceBoard extends CreateRecord
{
    use SavesPriceBoardItems;

    protected static string $resource = PriceBoardResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $isAdjustment = ($data['pricing_mode'] ?? PriceBoard::MODE_OVERRIDE) === PriceBoard::MODE_ADJUSTMENT;
        $items        = $data['items'] ?? [];
        $rawProductIds = $data['product_ids'] ?? [];
        $productIds   = $isAdjustment ? collect($rawProductIds) : collect($items)->pluck('product_id');

        unset($data['items'], $data['product_ids']);

        $service = app(PriceBoardSyncService::class);

        try {
            $service->assertNoOverlap(new PriceBoard(), $productIds->filter());
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            throw new Halt();
        }

        return DB::transaction(function () use ($data, $isAdjustment, $items, $rawProductIds, $service) {
            $board = PriceBoard::create($data);

            $this->savePriceBoardItems($board, $isAdjustment ? ['product_ids' => $rawProductIds] : ['items' => $items]);

            $service->resyncBoardProducts($board);

            return $board;
        });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
