<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Support;

use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Modules\Minihouse\App\Models\WarehouseItem;

// Mirror ĐÚNG Modules\Minihouse\App\Filament\Support\WarehouseBarcodeScan (Home, xem file đó để biết
// đầy đủ lý do thiết kế) — đổi "branch_id" thành "building_id". Đã mang theo NGUYÊN VẸN bản sửa lỗi
// "quét quá nhanh mất vật tư/rơi vào ô Tìm kiếm + 429 Too Many Requests" (2026-09-26): mỗi lần quét
// (vật lý lẫn camera) chỉ ĐẨY mã vào hàng đợi rồi xoá/focus ô NGAY LẬP TỨC (đồng bộ, không chờ
// mạng), xử lý TUẦN TỰ từng mã một qua window.__mhwProcessNextScan — không bao giờ có 2 request cùng
// lúc, không bao giờ có khoảng trống mất focus cho ký tự quét lạc chỗ.
class WarehouseBarcodeScan
{
    public const INPUT_ID = 'minihouse-warehouse-barcode-scan-input';

    /**
     * @param  \Closure(WarehouseItem $item, Get $get): array<string, mixed>  $newRowFactory
     * @param  string  $quantityField  'quantity' ở Nhập/Xuất kho, 'actual_quantity' ở Kiểm kê kho.
     */
    public static function field(\Closure $newRowFactory, string $quantityField = 'quantity'): TextInput
    {
        return TextInput::make('barcode_scan')
            ->label('Quét / nhập mã vạch')
            ->placeholder('Bấm vào đây rồi quét bằng máy quét mã vạch, hoặc bấm nút camera để quét bằng điện thoại')
            ->dehydrated(false)
            ->live(onBlur: true)
            ->extraInputAttributes([
                'id'                          => self::INPUT_ID,
                'autocomplete'                => 'off',
                'x-on:keydown.enter.prevent'  => self::processNextJs() . "
                    const code = \$el.value.trim();
                    \$el.value = '';
                    if (code !== '') {
                        \$el._mhwQueue = \$el._mhwQueue || [];
                        \$el._mhwQueue.push(code);
                    }
                    window.__mhwProcessNextScan(\$el);
                    queueMicrotask(() => { if (! document.querySelector('.fi-modal-open')) \$el.focus(); });
                ",
            ])
            ->suffixIcon('heroicon-o-camera')
            ->suffixAction(
                Action::make('open_barcode_camera')
                    ->icon('heroicon-o-camera')
                    ->label('Quét bằng camera')
                    ->modalHeading('Quét mã vạch bằng camera')
                    ->modalContent(fn () => view('minihouse::filament.forms.barcode-camera'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng')
            )
            ->afterStateUpdated(function (?string $state, Set $set, Get $get, $livewire) use ($newRowFactory, $quantityField) {
                $code = trim((string) $state);
                $set('barcode_scan', null);

                $inputIdJson = json_encode(self::INPUT_ID);
                $livewire->js(self::processNextJs() . "
                    const el = document.getElementById({$inputIdJson});
                    if (el) {
                        el._mhwBusy = false;
                        if (el._mhwQueue && el._mhwQueue.length > 0) {
                            window.__mhwProcessNextScan(el);
                        } else if (! document.querySelector('.fi-modal-open')) {
                            el.focus();
                        }
                    }
                ");

                if ($code === '') {
                    return;
                }

                self::handle($code, $set, $get, $newRowFactory, $quantityField);
            });
    }

    private static function processNextJs(): string
    {
        return <<<'JS'
            if (! window.__mhwProcessNextScan) {
                window.__mhwProcessNextScan = function (el) {
                    if (el._mhwBusy || ! el._mhwQueue || el._mhwQueue.length === 0) { return; }
                    el._mhwBusy = true;
                    el.value = el._mhwQueue.shift();
                    if (document.activeElement === el) {
                        el.blur();
                    } else {
                        el.dispatchEvent(new Event('blur', { bubbles: true }));
                    }
                };
            }
            JS;
    }

    private static function handle(string $code, Set $set, Get $get, \Closure $newRowFactory, string $quantityField): void
    {
        $buildingId = $get('building_id');

        $item = WarehouseItem::query()
            ->where('sku', $code)
            ->where('status', true)
            ->when($buildingId, fn ($q) => $q->where('building_id', $buildingId))
            ->first();

        if (! $item) {
            Notification::make()
                ->title("Không tìm thấy vật tư với mã: {$code}")
                ->body('Kiểm tra lại mã vạch, hoặc mã này chưa được khai báo ở "Mã SKU" của vật tư.')
                ->danger()
                ->send();

            return;
        }

        $items = $get('items') ?? [];

        foreach ($items as $key => $row) {
            if ((string) ($row['warehouse_item_id'] ?? '') === (string) $item->id) {
                $items[$key][$quantityField] = (float) ($row[$quantityField] ?? 0) + 1;

                if (array_key_exists('checked', $row)) {
                    $items[$key]['checked'] = true;
                }

                $set('items', $items);

                Notification::make()
                    ->title("Đã +1: {$item->name}")
                    ->body('Số lượng: ' . $items[$key][$quantityField])
                    ->success()
                    ->send();

                return;
            }
        }

        $items[(string) Str::uuid()] = $newRowFactory($item, $get);
        $set('items', $items);

        Notification::make()
            ->title("Đã thêm: {$item->name}")
            ->success()
            ->send();
    }
}
