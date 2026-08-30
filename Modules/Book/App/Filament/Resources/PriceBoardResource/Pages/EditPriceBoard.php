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
use Modules\Book\App\Filament\Resources\PriceBoardResource\Forms\PriceBoardForm;
use Modules\Product\App\Models\PriceBoard;
use Modules\Product\App\Models\PriceBoardPriceLog;

class EditPriceBoard extends EditRecord
{
    use SavesPriceBoardItems;

    protected static string $resource = PriceBoardResource::class;

    protected function getHeaderActions(): array
    {
        // Dùng biến ngoài, chia sẻ giữa before()/after() của DeleteAction bên dưới — before() phải
        // chụp lại danh sách phòng gắn với bảng TRƯỚC khi xoá (xoá xong là mất, cascade theo
        // PriceBoardItem), after() mới dùng lại để tính lại giá đúng cho từng phòng đó.
        $affectedProducts = collect();

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
            // Tính lại giá ĐÚNG cho mọi phòng trong bảng SAU khi xoá (yêu cầu người dùng — trước đây
            // cố ý không khôi phục, nay đổi lại). Gọi lại đúng logic "bảng nào thắng" tiêu chuẩn thay
            // vì tự ý ghi thẳng baseline của bảng vừa xoá — tránh ghi đè nhầm lên giá của 1 bảng KHÁC
            // đang thật sự active cho cùng phòng đó.
            Actions\DeleteAction::make()
                ->before(function () use (&$affectedProducts) {
                    $affectedProducts = app(PriceBoardSyncService::class)->productsAffectedByBoard($this->record);
                })
                ->after(function () use (&$affectedProducts) {
                    app(PriceBoardSyncService::class)->resyncProductsAfterBoardDeleted($affectedProducts);
                }),
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

        $data['items'] = PriceBoardForm::buildItemsFromBoard($record);
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
