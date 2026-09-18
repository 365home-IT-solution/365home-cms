<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Support;

use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Modules\Warehouse\App\Models\WarehouseItem;

// Ô "Quét / nhập mã vạch" dùng chung cho Nhập kho + Xuất kho. Dùng ĐƯỢC NGAY cho 2 nguồn nhập liệu
// khác hẳn nhau mà không cần code riêng:
//   1. Camera điện thoại — nút hình máy ảnh mở modal quét (xem view
//      warehouse::filament.forms.barcode-camera), JS tự set giá trị + blur() ô input này.
//   2. Máy quét mã vạch vật lý (USB/Bluetooth) sau này — các máy này hoạt động như 1 BÀN PHÍM (gõ
//      ký tự thật + Enter), CHỈ CẦN ô input đang được focus là tự nhận, không cần tích hợp gì thêm —
//      "x-on:keydown.enter" bên dưới ép Enter = blur() để chốt giá trị giống hệt đường camera.
// Cả 2 đường đều hội tụ về ĐÚNG 1 chỗ: sự kiện 'blur' của input -> afterStateUpdated() bên dưới.
class WarehouseBarcodeScan
{
    public const INPUT_ID = 'warehouse-barcode-scan-input';

    /**
     * @param  \Closure(WarehouseItem $item): array<string, mixed>  $newRowFactory  Trả về mảng state
     *                                                                              cho 1 dòng MỚI của
     *                                                                              Repeater 'items' —
     *                                                                              mỗi form (nhập/
     *                                                                              xuất) có field
     *                                                                              riêng (VD 'reason'
     *                                                                              chỉ ở form xuất)
     *                                                                              nên không gộp cứng
     *                                                                              được ở đây.
     */
    public static function field(\Closure $newRowFactory): TextInput
    {
        return TextInput::make('barcode_scan')
            ->label('Quét / nhập mã vạch')
            ->placeholder('Bấm vào đây rồi quét bằng máy quét mã vạch, hoặc bấm nút camera để quét bằng điện thoại')
            ->dehydrated(false)
            // onBlur (không phải mỗi phím gõ) — máy quét vật lý gõ CỰC NHANH từng ký tự một, nếu bắn
            // request mỗi ký tự sẽ tra sai (mã vạch chưa gõ xong) và spam thông báo "không tìm thấy".
            ->live(onBlur: true)
            ->extraInputAttributes([
                'id'                          => self::INPUT_ID,
                'autocomplete'                => 'off',
                // Enter là ký tự KẾT THÚC chuẩn của hầu hết máy quét mã vạch — ép blur() để chốt giá
                // trị ngay, không cần người dùng tự bấm ra ngoài ô.
                'x-on:keydown.enter.prevent'  => '$el.blur()',
            ])
            ->suffixIcon('heroicon-o-camera')
            ->suffixAction(
                Action::make('open_barcode_camera')
                    ->icon('heroicon-o-camera')
                    ->label('Quét bằng camera')
                    ->modalHeading('Quét mã vạch bằng camera')
                    ->modalContent(fn () => view('warehouse::filament.forms.barcode-camera'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng')
            )
            ->afterStateUpdated(function (?string $state, Set $set, Get $get) use ($newRowFactory) {
                $code = trim((string) $state);
                // Xoá ngay để ô luôn rỗng, sẵn sàng cho lượt quét tiếp theo — kể cả khi không tìm
                // thấy vật tư (không để lại mã cũ gây hiểu lầm đã xử lý xong).
                $set('barcode_scan', null);

                if ($code === '') {
                    return;
                }

                self::handle($code, $set, $get, $newRowFactory);
            });
    }

    private static function handle(string $code, Set $set, Get $get, \Closure $newRowFactory): void
    {
        $branchId = $get('branch_id');

        $item = WarehouseItem::query()
            ->where('sku', $code)
            ->where('status', true)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
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

        // Đã có sẵn dòng này trong phiếu — quét lại (VD quét cùng 1 thùng nhiều lần) = CỘNG DỒN số
        // lượng thay vì tạo thêm 1 dòng trùng vật tư.
        foreach ($items as $key => $row) {
            if ((string) ($row['warehouse_item_id'] ?? '') === (string) $item->id) {
                $items[$key]['quantity'] = (float) ($row['quantity'] ?? 0) + 1;
                $set('items', $items);

                Notification::make()
                    ->title("Đã +1: {$item->name}")
                    ->body('Số lượng: ' . $items[$key]['quantity'])
                    ->success()
                    ->send();

                return;
            }
        }

        $items[(string) Str::uuid()] = $newRowFactory($item);
        $set('items', $items);

        Notification::make()
            ->title("Đã thêm: {$item->name}")
            ->success()
            ->send();
    }
}
