<?php

declare(strict_types=1);

namespace Modules\Book\App\Filament\Resources\PriceBoardResource\Pages;

use App\Services\PriceBoardSyncService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Book\App\Filament\Resources\PriceBoardResource;
use Modules\Book\App\Filament\Resources\PriceBoardResource\Concerns\SavesPriceBoardItems;
use Modules\Product\App\Models\PriceBoard;
use Modules\Product\App\Models\PriceBoardPriceLog;

class EditPriceBoard extends EditRecord
{
    use SavesPriceBoardItems;

    protected static string $resource = PriceBoardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('history')
                ->label('Lịch sử')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->modalHeading('Lịch sử thay đổi giá')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Đóng')
                ->modalContent(fn () => view('book::filament.resources.price-board-resource.history-modal', [
                    'logs' => PriceBoardPriceLog::where('price_board_id', $this->record->id)
                        ->with(['product', 'changedByUser'])
                        ->orderByDesc('created_at')
                        ->limit(200)
                        ->get(),
                ])),
            Actions\Action::make('apply_now')
                ->label('Áp dụng ngay')
                ->icon('heroicon-o-bolt')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('Áp ngay giá của bảng này xuống các phòng đã chọn, bất kể ngày hiệu lực.')
                ->action(function () {
                    app(PriceBoardSyncService::class)->applyBoard($this->record);

                    Notification::make()->title('Đã áp dụng bảng giá')->success()->send();
                }),
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var PriceBoard $record */
        $record = $this->record;

        if ($record->isAdjustment()) {
            $data['product_ids'] = $record->items()->pluck('product_id')->toArray();

            return $data;
        }

        $data['items'] = $record->items()->with(['timeSlots', 'product'])->get()
            ->map(function ($item) {
                $style = (int) ($item->product->styles ?? 1);

                $row = [
                    'product_id'                  => $item->product_id,
                    'full_booking_discount'       => $item->full_booking_discount,
                    'bulk_discount_rules'         => $item->bulk_discount_rules ?? [],
                    'room_config_max_free_guests' => (int) ($item->room_config['max_free_guests'] ?? 2),
                    'room_config_extra_guest_fee' => (int) ($item->room_config['extra_guest_fee'] ?? 0),
                ];

                if ($style === 2) {
                    $row['price']               = $item->price;
                    $row['default_checkin']     = $item->default_checkin;
                    $row['default_checkout']    = $item->default_checkout;
                    $row['deposit_min_nights']  = $item->deposit_min_nights;
                    $row['deposit_multi_night'] = $item->deposit_multi_night;
                } else {
                    $row['roomTimeSlots'] = $item->timeSlots->map(fn ($slot) => [
                        'timeslot_id' => $slot->timeslot_id,
                        'price'       => number_format((int) $slot->price, 0, ',', '.'),
                        'over_night'  => $slot->over_night,
                        'status'      => $slot->status,
                    ])->toArray();
                }

                return $row;
            })
            ->toArray();

        $data['_room_checklist'] = collect($data['items'])->pluck('product_id')->toArray();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $isAdjustment  = ($data['pricing_mode'] ?? PriceBoard::MODE_OVERRIDE) === PriceBoard::MODE_ADJUSTMENT;
        $items         = $data['items'] ?? [];
        $rawProductIds = $data['product_ids'] ?? [];
        $productIds    = $isAdjustment ? collect($rawProductIds) : collect($items)->pluck('product_id');

        unset($data['items'], $data['product_ids']);

        $service = app(PriceBoardSyncService::class);

        try {
            $service->assertNoOverlap($record, $productIds->filter());
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            throw new Halt();
        }

        DB::transaction(function () use ($record, $data, $isAdjustment, $items, $rawProductIds, $service) {
            $record->update($data);
            $this->savePriceBoardItems($record, $isAdjustment ? ['product_ids' => $rawProductIds] : ['items' => $items]);

            $service->resyncBoardProducts($record);
        });

        return $record;
    }
}
