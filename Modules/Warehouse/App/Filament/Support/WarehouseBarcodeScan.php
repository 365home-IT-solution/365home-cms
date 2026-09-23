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
     * @param  \Closure(WarehouseItem $item, Get $get): array<string, mixed>  $newRowFactory  Trả về
     *                                                                              mảng state cho 1
     *                                                                              dòng MỚI của
     *                                                                              Repeater 'items' —
     *                                                                              mỗi form (nhập/
     *                                                                              xuất) có field
     *                                                                              riêng (VD 'reason'
     *                                                                              chỉ ở form xuất)
     *                                                                              nên không gộp cứng
     *                                                                              được ở đây. Nhận
     *                                                                              thêm $get để đọc
     *                                                                              field khác của
     *                                                                              form (VD Xuất kho
     *                                                                              đọc 'default_reason'
     *                                                                              đặt sẵn 1 lần thay
     *                                                                              vì bắt chọn tay
     *                                                                              từng dòng quét).
     * @param  string  $quantityField  Tên field bị +1 khi quét trúng 1 dòng ĐÃ CÓ sẵn — 'quantity' ở
     *                                 Nhập/Xuất kho, 'actual_quantity' ("Đếm được") ở Kiểm kê kho (nơi
     *                                 mọi dòng đã được liệt kê sẵn từ đầu, quét chỉ để tăng đếm chứ
     *                                 không có field 'quantity' nào cả).
     */
    public static function field(\Closure $newRowFactory, string $quantityField = 'quantity'): TextInput
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
            ->afterStateUpdated(function (?string $state, Set $set, Get $get, $livewire) use ($newRowFactory, $quantityField) {
                $code = trim((string) $state);
                // Xoá ngay để ô luôn rỗng, sẵn sàng cho lượt quét tiếp theo — kể cả khi không tìm
                // thấy vật tư (không để lại mã cũ gây hiểu lầm đã xử lý xong).
                $set('barcode_scan', null);

                // Focus LẠI ô ngay sau khi round-trip xử lý xong (dù thành công hay báo lỗi "không
                // tìm thấy") — .blur() lúc chốt giá trị đã cố ý bỏ focus, cần trả lại NGAY để máy
                // quét vật lý/camera quét được món tiếp theo liên tục, không phải bấm chuột lại vào ô
                // giữa mỗi lần quét.
                //
                // PHẢI tự xoá value="" bằng JS ở đây, KHÔNG dựa vào $set('barcode_scan', null) ở
                // trên tự đồng bộ xuống DOM — Livewire cố ý KHÔNG ghi đè value của 1 input ĐANG được
                // focus khi morph lại DOM (tránh phá gõ dở của người dùng), mà dòng focus() ngay bên
                // dưới lại focus LẠI CHÍNH ô này trước khi morph kịp áp dụng giá trị mới → mã cũ vẫn
                // còn nguyên trong ô dù server đã coi là đã xử lý xong. Lần blur KẾ TIẾP (kể cả không
                // chủ động quét gì) vô tình gửi lại ĐÚNG mã cũ đó lần nữa, xử lý trùng — đã xác nhận
                // thực tế qua ảnh chụp (báo "không tìm thấy" 2 lần cho đúng 1 lượt quét).
                $inputIdJson = json_encode(self::INPUT_ID);
                // KHÔNG focus lại khi đang có modal mở: focus-trap của Filament sẽ giành focus về
                // modal -> ô này blur -> gửi request Livewire -> focus lại ... lặp vô hạn, nút trong
                // modal luôn bị wire:loading vô hiệu hoá (desktop không bấm được Thêm/Huỷ bỏ/X).
                $livewire->js("const el = document.getElementById({$inputIdJson}); if (el) { el.value = ''; if (! document.querySelector('.fi-modal-open')) { el.focus(); } }");

                if ($code === '') {
                    return;
                }

                self::handle($code, $set, $get, $newRowFactory, $quantityField);
            });
    }

    private static function handle(string $code, Set $set, Get $get, \Closure $newRowFactory, string $quantityField): void
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
                $items[$key][$quantityField] = (float) ($row[$quantityField] ?? 0) + 1;
                // Đánh dấu "Đã kiểm" nếu dòng này có field 'checked' (chỉ Kiểm kê kho có — Nhập/Xuất
                // kho không dùng field này, array_key_exists tránh thêm key thừa vô nghĩa ở đó).
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
