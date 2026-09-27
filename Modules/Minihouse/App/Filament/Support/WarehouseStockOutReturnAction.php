<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\WarehouseStockOutResource\Support;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
use Modules\Minihouse\App\Models\WarehouseStockOut;
use Modules\Minihouse\App\Models\WarehouseStockOutItem;
use Modules\Minihouse\App\Models\WarehouseStockReturn;
use Modules\Minihouse\App\Models\WarehouseStockReturnItem;

// Mirror ĐÚNG Modules\Warehouse\App\Filament\Support\WarehouseStockOutReturnAction (Home) — popup
// "Hoàn trả" gắn thẳng vào Phiếu xuất kho (row action ở danh sách), tự hiện đúng các dòng ĐÃ XUẤT của
// phiếu đang thao tác kèm số còn có thể hoàn — không cần chọn lại phòng/tìm lại dòng xuất.
class WarehouseStockOutReturnAction
{
    public static function formSchema(WarehouseStockOut $record): array
    {
        $record->loadMissing('items.item.unit');

        if ($record->items->isEmpty()) {
            return [
                Placeholder::make('empty')->hiddenLabel()->content('Phiếu này chưa có dòng hàng nào.'),
            ];
        }

        $alreadyReturned = WarehouseStockReturnItem::query()
            ->whereIn('warehouse_stock_out_item_id', $record->items->pluck('id'))
            ->selectRaw('warehouse_stock_out_item_id, SUM(quantity) as total')
            ->groupBy('warehouse_stock_out_item_id')
            ->pluck('total', 'warehouse_stock_out_item_id');

        $schema = [];

        foreach ($record->items as $line) {
            $issued    = (float) $line->quantity;
            $returned  = (float) ($alreadyReturned[$line->id] ?? 0);
            $remaining = round($issued - $returned, 2);
            $unitName  = $line->item?->unit?->name;

            $schema[] = Placeholder::make("info_{$line->id}")
                ->hiddenLabel()
                ->content(new HtmlString(sprintf(
                    '<div class="flex flex-wrap items-baseline justify-between gap-x-3 text-sm">
                        <span class="font-medium text-gray-950 dark:text-white">%s</span>
                        <span class="text-gray-500 dark:text-gray-400">Đã xuất: %s · Đã hoàn: %s · Còn có thể hoàn: %s</span>
                    </div>',
                    e($line->item?->name ?? '—'),
                    Number::format($issued, maxPrecision: 2),
                    Number::format($returned, maxPrecision: 2),
                    Number::format($remaining, maxPrecision: 2)
                )));

            $schema[] = TextInput::make("return.{$line->id}")
                ->label('Số lượng hoàn')
                ->hiddenLabel()
                ->numeric()
                ->default(0)
                ->minValue(0)
                ->maxValue(max($remaining, 0))
                ->suffix($unitName)
                ->disabled($remaining <= 0.0001)
                ->helperText($remaining <= 0.0001 ? 'Đã hoàn đủ số đã xuất.' : null)
                ->dehydrated(fn ($state) => filled($state) && (float) $state > 0);
        }

        return $schema;
    }

    public static function handle(array $data, WarehouseStockOut $record): void
    {
        $lines = collect($data['return'] ?? [])->filter(fn ($qty) => filled($qty) && (float) $qty > 0);

        if ($lines->isEmpty()) {
            Notification::make()->title('Chưa nhập số lượng hoàn nào.')->warning()->send();

            return;
        }

        DB::transaction(function () use ($lines, $record) {
            $stockReturn = WarehouseStockReturn::create([
                'building_id' => $record->building_id,
                'room_id'     => $record->room_id,
                'returned_by' => $record->issued_to,
            ]);

            foreach ($lines as $stockOutItemId => $quantity) {
                $stockOutItem = WarehouseStockOutItem::find((int) $stockOutItemId);

                if (! $stockOutItem || (int) $stockOutItem->warehouse_stock_out_id !== $record->id) {
                    continue;
                }

                $stockReturn->items()->create([
                    'warehouse_stock_out_item_id' => $stockOutItem->id,
                    'warehouse_item_id'           => $stockOutItem->warehouse_item_id,
                    'quantity'                    => $quantity,
                ]);
            }
        });

        Notification::make()->title('Đã hoàn trả kho thành công.')->success()->send();
    }
}
