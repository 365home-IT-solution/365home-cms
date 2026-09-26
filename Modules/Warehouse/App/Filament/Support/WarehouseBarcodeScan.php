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
                // Bug thật đã gặp (2026-09-26): quét RẤT NHANH nhiều lần liên tiếp — trước đây ô chỉ
                // được xoá/focus lại SAU KHI server trả lời xong (trong afterStateUpdated bên dưới),
                // nên nếu quét mã tiếp theo trong lúc round-trip đó CHƯA XONG, ô này KHÔNG focus, ký
                // tự quét được rơi lung tung (thực tế rơi vào ô "Tìm kiếm" ngay bên dưới — 1 input
                // HTML thường, không wire:model) — vô tình lọc ẩn MỌI dòng theo mã vạch gõ nhầm vào đó
                // (trông như "mất vật tư vừa quét"), đồng thời bắn request dồn dập gây 429 Too Many
                // Requests. Sửa bằng hàng đợi CLIENT-SIDE: mỗi lần Enter chỉ ĐẨY mã vào hàng đợi rồi
                // xoá/focus lại ô NGAY LẬP TỨC (đồng bộ, không chờ mạng) — mã tiếp theo luôn có chỗ gõ
                // đúng; hàng đợi xử lý TUẦN TỰ từng mã một (xem afterStateUpdated), không bao giờ có 2
                // request cùng lúc.
                // Bug KHÁC phát hiện thêm (2026-09-26, sau lần vá đầu): giữ input LUÔN focus liên tục
                // (gọi .focus() ngay lập tức, không để mất focus thật lúc nào) khiến Livewire morph
                // DOM "né" khu vực xung quanh ô đang focus (tránh phá gõ dở của người dùng) — vô tình
                // khiến Repeater bên cạnh KHÔNG re-render dòng vừa thêm dù server đã xử lý đúng (số
                // lượng trong thông báo tăng đúng 6→7→8, nhưng danh sách vẫn trống trơn). Sửa bằng
                // cách để ô THẬT SỰ blur (mất focus thật, giống hệt hành vi gốc trước khi có mọi bản
                // vá) rồi lấy lại focus qua queueMicrotask — nhanh hơn microgiây so với bất kỳ máy
                // quét/người dùng nào có thể gõ thêm ký tự, vẫn đóng kín khe hở race condition, nhưng
                // KHÔNG còn giữ input "luôn focus" xuyên suốt lúc Livewire xử lý/morph DOM nữa. CHỈ đẩy
                // mã vào hàng đợi rồi gọi processNextScan ĐÚNG 1 LẦN (không tự blur() ở đây nữa) — gọi
                // blur riêng trước đó với giá trị RỖNG từng gây bắn dư 1 request/lượt quét (2 lần
                // afterStateUpdated cho đúng 1 lượt quét thật).
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
                    ->modalContent(fn () => view('warehouse::filament.forms.barcode-camera'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng')
            )
            ->afterStateUpdated(function (?string $state, Set $set, Get $get, $livewire) use ($newRowFactory, $quantityField) {
                $code = trim((string) $state);
                // Xoá ngay để ô luôn rỗng — ô hiển thị đã được xoá/focus lại phía CLIENT từ lúc bấm
                // Enter rồi (xem 'x-on:keydown.enter.prevent' ở field() phía trên), dòng này chỉ đồng
                // bộ lại state Livewire cho khớp, không còn phải "chạy đua" với round-trip nữa.
                $set('barcode_scan', null);

                $inputIdJson = json_encode(self::INPUT_ID);
                // "_mhwBusy = false" rồi gọi lại processNext ngay — nếu trong lúc round-trip này chưa
                // xong mà đã có thêm mã khác được đẩy vào hàng đợi (_mhwQueue), xử lý luôn mã KẾ TIẾP
                // mà không cần đợi người dùng thao tác gì thêm; hàng đợi rỗng thì chỉ còn việc focus
                // lại ô như cũ. KHÔNG focus lại khi đang có modal mở: focus-trap của Filament sẽ giành
                // focus về modal -> ô này blur -> gửi request Livewire -> focus lại ... lặp vô hạn,
                // nút trong modal luôn bị wire:loading vô hiệu hoá (desktop không bấm được Thêm/Huỷ
                // bỏ/X).
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

    // Dùng CHUNG cho cả 2 điểm gọi JS ở field() (Enter vừa quét xong + response server vừa xử lý xong
    // 1 mã) — định nghĩa ĐÚNG 1 LẦN trên window, idempotent (gọi lại nhiều lần không sao) để tránh
    // lệch code giữa 2 chỗ. "_mhwBusy"/"_mhwQueue" gắn TRỰC TIẾP lên chính DOM element (không dùng
    // biến window rời) — mỗi ô quét tự có hàng đợi riêng, nhiều form mở cùng lúc không đụng nhau.
    //
    // Tự CHỌN cách bắn sự kiện 'blur' theo đúng trạng thái focus THẬT của ô lúc gọi:
    //   - Ô ĐANG thật sự được focus (đường máy quét vật lý qua phím Enter) -> gọi THẬT el.blur() —
    //     để trình duyệt thật sự rời focus, tránh đúng bug Livewire morph "né" DOM xung quanh 1 input
    //     đang được giữ focus liên tục (khiến Repeater bên cạnh không re-render dù server đã xử lý
    //     đúng — xem chú thích ở field() phía trên).
    //   - Ô KHÔNG được focus (đường camera, barcode-camera.blade.php set giá trị mà chưa từng bấm vào
    //     ô) -> el.blur() KHÔNG có tác dụng gì (không phải activeElement), phải TỰ BẮN sự kiện 'blur'
    //     giả để Livewire vẫn nhận được, giữ đúng cơ chế đã xác nhận hoạt động trước đây.
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
