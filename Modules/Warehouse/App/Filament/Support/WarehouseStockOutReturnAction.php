<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Support;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
use Modules\Warehouse\App\Models\WarehouseStockOut;
use Modules\Warehouse\App\Models\WarehouseStockOutItem;
use Modules\Warehouse\App\Models\WarehouseStockReturn;
use Modules\Warehouse\App\Models\WarehouseStockReturnItem;

// Popup "Hoàn trả" GẮN THẲNG vào Phiếu xuất kho (row action ở danh sách + header action ở trang
// sửa) — THAY THẾ hoàn toàn luồng cũ (phải qua menu riêng "Phiếu hoàn trả kho", tự tìm đúng dòng
// xuất trong 1 danh sách dropdown dài của TOÀN HỆ THỐNG). Yêu cầu thực tế: "xuất kho 5 món, bấm
// hoàn trả thì hiện đúng 5 món đó, sửa số lượng trực tiếp trong popup" — vì đã đứng sẵn trên đúng 1
// phiếu xuất, không cần chọn lại phòng/tìm lại dòng xuất nào cả, chỉ còn việc nhập số lượng hoàn.
//
// Dùng CHUNG 1 class cho cả 2 nơi gắn (Table\Actions\Action ở danh sách, Actions\Action ở trang
// sửa) vì Filament v3 không cho 1 Action instance dùng ở 2 ngữ cảnh khác nhau, nhưng ->form()/
// ->action() nhận cùng chữ ký closure(array $data, WarehouseStockOut $record) nên tách logic ra
// đây, mỗi nơi chỉ new đúng loại Action rồi gọi lại 2 hàm tĩnh này.
class WarehouseStockOutReturnAction
{
    public static function formSchema(WarehouseStockOut $record): array
    {
        $record->loadMissing('items.item.unit');

        if ($record->items->isEmpty()) {
            return [
                Placeholder::make('empty')
                    ->hiddenLabel()
                    ->content('Phiếu này chưa có dòng hàng nào.'),
            ];
        }

        $alreadyReturned = WarehouseStockReturnItem::query()
            ->whereIn('warehouse_stock_out_item_id', $record->items->pluck('id'))
            ->selectRaw('warehouse_stock_out_item_id, SUM(quantity) as total')
            ->groupBy('warehouse_stock_out_item_id')
            ->pluck('total', 'warehouse_stock_out_item_id');

        $schema = [];

        foreach ($record->items as $line) {
            $issued = (float) $line->quantity;
            $returned = (float) ($alreadyReturned[$line->id] ?? 0);
            $remaining = round($issued - $returned, 2);
            $unitName = $line->item?->unit?->name;

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
        $lines = collect($data['return'] ?? [])
            ->filter(fn ($qty) => filled($qty) && (float) $qty > 0);

        if ($lines->isEmpty()) {
            Notification::make()
                ->title('Chưa nhập số lượng hoàn nào.')
                ->warning()
                ->send();

            return;
        }

        DB::transaction(function () use ($lines, $record) {
            $stockReturn = WarehouseStockReturn::create([
                'partner_id'  => $record->partner_id,
                'branch_id'   => $record->branch_id,
                'product_id'  => $record->product_id,
                'employee_id' => $record->employee_id,
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

        Notification::make()
            ->title('Đã hoàn trả kho thành công.')
            ->success()
            ->send();
    }
}
