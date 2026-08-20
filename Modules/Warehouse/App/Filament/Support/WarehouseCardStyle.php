<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Support;

use Illuminate\Support\HtmlString;
use Modules\Warehouse\App\Models\WarehouseItem;

// Dùng chung cho MỌI Repeater dạng "lưới thẻ" trong module kho (kiểm kê, nhập kho, xuất kho, popup
// xác nhận bàn giao) — tránh lặp lại cùng 1 đoạn CSS/style ô vuông ở nhiều nơi. --primary-300/600/700
// là biến CSS THẬT của Filament (đã kiểm tra tồn tại trong theme.css đã build), không phải đoán mò.
class WarehouseCardStyle
{
    // QUAN TRỌNG: khung viền NHÌN THẤY của 1 TextInput là <div class="fi-input-wrp"> (component
    // <x-filament::input.wrapper>), do ->extraAttributes() (KHÔNG phải ->extraInputAttributes())
    // vẽ ra — div CON bên trong nó (bọc quanh input thật) luôn có class "flex-1" cố định trong core
    // Filament. Style đặt lên chính <input> (qua extraInputAttributes) KHÔNG thu nhỏ được cái khung
    // nhìn thấy — chỉ ảnh hưởng vùng gõ chữ bên trong 1 khung vẫn giãn hết cỡ (bug đã thấy qua ảnh
    // chụp thực tế: ô "vuông" hoá ra vẫn dài như input thường). Vì vậy TextInput cần ĐỦ CẢ HAI:
    // ->extraAttributes() ép kích thước khung wrapper, ->extraInputAttributes() để input thật lấp
    // đầy khung đó + canh chữ. Placeholder thì chỉ có 1 <div> duy nhất (không có input con) nên
    // dùng combinedBox() gộp chung 1 lần. Style inline (không phải class) để luôn thắng "w-full"
    // mặc định của input, không phụ thuộc thứ tự CSS biên dịch.
    private const WRAPPER_STYLE = 'width:3rem;height:3rem;flex:none 0 auto;border-radius:0.5rem;border-width:2px;overflow:hidden;';

    // "Đếm được" (kiểm kê) rộng hơn ô "Tồn HT" ~25px — vẫn cùng chiều cao (không phải ô vuông tuyệt
    // đối như Tồn HT nữa, chỉ riêng ô nhập chính mới rộng hơn 1 chút cho dễ gõ số).
    private const WIDE_WRAPPER_STYLE = 'width:4.5rem;height:3rem;flex:none 0 auto;border-radius:0.5rem;border-width:2px;overflow:hidden;';

    private const TEXT_STYLE = 'text-align:center;font-size:1rem;font-weight:700;';

    private const INPUT_FILL_STYLE = 'width:100%;height:100%;padding:0;'.self::TEXT_STYLE;

    // "Đơn giá" (nhập kho) — cao BẰNG ô "Số lượng" (3rem) nhưng KHÔNG ép vuông, vẫn chữ nhật full
    // chiều rộng cột (số tiền có thể dài, ép vuông sẽ tràn/khó đọc) — yêu cầu: "đơn giá chiều cao
    // bằng với số lượng". Không có overflow:hidden/border-width riêng như WRAPPER_STYLE vì input có
    // prefix "₫" (fi-input-wrp bọc thêm phần tử prefix bên trong, không phải input đơn thuần).
    private const TALL_RECT_STYLE = 'height:3rem;';

    // Khối <style> tô viền thẻ + tên vật tư màu primary — CHỈ áp dụng cho đúng Repeater mang class
    // $repeaterClass (mỗi form dùng 1 class riêng, không đụng lẫn nhau, không đụng repeater ở form
    // khác trong hệ thống).
    public static function styleBlock(string $repeaterClass): HtmlString
    {
        return new HtmlString("
            <style>
                .{$repeaterClass} .fi-fo-repeater-item-header h4 {
                    color: rgb(var(--primary-600));
                    font-weight: 600;
                }
                .dark .{$repeaterClass} .fi-fo-repeater-item-header h4 {
                    color: rgb(var(--primary-400));
                }
                .{$repeaterClass} .fi-fo-repeater-item {
                    border: 1.5px solid rgb(var(--primary-300));
                }
                .dark .{$repeaterClass} .fi-fo-repeater-item {
                    border-color: rgb(var(--primary-700));
                }
            </style>
        ");
    }

    // TextInput — đặt vào ->extraAttributes() (KHÔNG phải extraInputAttributes). Luôn dùng kèm
    // ::inputStyle() ở ->extraInputAttributes() của CÙNG field đó.
    public static function neutralBox(): array
    {
        return [
            'style' => self::WRAPPER_STYLE,
            'class' => 'border-gray-300 dark:border-gray-600',
        ];
    }

    // TextInput có đối chiếu đúng/sai (VD "Đếm được" so với tồn hệ thống, "Đếm lại" khi bàn giao so
    // với phiếu gốc) — tự đổi viền đỏ/nền đỏ nhạt khi lệch, viền xanh nhạt khi khớp. Đặt vào
    // ->extraAttributes(), dùng kèm ::inputStyle() ở ->extraInputAttributes().
    public static function comparedBox(bool $isDifferent): array
    {
        return [
            'style' => self::WRAPPER_STYLE,
            'class' => $isDifferent
                ? 'border-danger-400 bg-danger-50 dark:bg-danger-500/10'
                : 'border-success-300 dark:border-success-700',
        ];
    }

    // Bản RỘNG HƠN của comparedBox() — dùng riêng cho ô "Đếm được" chính (kiểm kê) theo đúng yêu
    // cầu "dài ra thêm ~25px". Vẫn dùng kèm ::inputStyle() ở ->extraInputAttributes() như bình
    // thường (input bên trong luôn width:100% lấp đầy khung, không cần biết khung rộng bao nhiêu).
    public static function comparedBoxWide(bool $isDifferent): array
    {
        return [
            'style' => self::WIDE_WRAPPER_STYLE,
            'class' => $isDifferent
                ? 'border-danger-400 bg-danger-50 dark:bg-danger-500/10'
                : 'border-success-300 dark:border-success-700',
        ];
    }

    // Đặt vào ->extraInputAttributes() — LUÔN dùng kèm neutralBox()/comparedBox() ở
    // ->extraAttributes() của cùng 1 TextInput (2 lời gọi riêng, cùng field).
    public static function inputStyle(): array
    {
        return ['style' => self::INPUT_FILL_STYLE];
    }

    // TextInput chữ nhật bình thường (có prefix/rộng đầy cột) nhưng cần cao bằng ô vuông bên cạnh —
    // chỉ đặt vào ->extraAttributes(), KHÔNG cần ::inputStyle() đi kèm (input bên trong đã tự canh
    // giữa dọc theo chiều cao khung nhờ CSS flex mặc định của Filament).
    public static function tallRectangleBox(): array
    {
        return ['style' => self::TALL_RECT_STYLE];
    }

    // Placeholder (VD "Tồn HT" chỉ đọc, "Phiếu gốc") — CHỈ có 1 <div>, không có input con, nên gộp
    // luôn kích thước + canh chữ vào 1 lần ->extraAttributes() duy nhất.
    public static function neutralBoxCombined(): array
    {
        return [
            'style' => self::WRAPPER_STYLE.self::TEXT_STYLE.'display:flex;align-items:center;justify-content:center;',
            'class' => 'border-gray-300 dark:border-gray-600',
        ];
    }

    // Tên nhóm vật tư — hàng phụ đề ngay dưới tiêu đề thẻ (tên vật tư). Bỏ hẳn icon (yêu cầu: "bỏ
    // luôn các icon và chỉnh lại chỉ tiếng việt thôi"), chỉ còn chữ thuần tiếng Việt.
    public static function itemMetaBadge(?WarehouseItem $item): ?HtmlString
    {
        if (! $item) {
            return null;
        }

        return new HtmlString(sprintf(
            '<div class="mb-2 text-xs text-gray-500 dark:text-gray-400">%s</div>',
            e($item->category?->name ?? 'Chưa phân nhóm')
        ));
    }

    // "Tồn kho" hiện tại của vật tư — tô màu theo trạng thái so với mức tối thiểu (min_quantity),
    // dùng class success/warning/danger CÓ SẴN của Filament (đã đăng ký theo GeneralSettings::
    // site_theme, tự đổi đúng sắc độ theo sáng/tối), không dùng hex cứng.
    public static function stockLevelHtml(?WarehouseItem $item): HtmlString
    {
        if (! $item) {
            return new HtmlString('—');
        }

        $quantity = (float) $item->quantity;
        $min = (float) $item->min_quantity;

        $colorClass = match (true) {
            $quantity <= 0 => 'text-danger-600 dark:text-danger-400',
            $quantity <= $min => 'text-warning-600 dark:text-warning-400',
            default => 'text-success-600 dark:text-success-400',
        };

        return new HtmlString(sprintf(
            '<span class="font-semibold %s">%s</span>',
            $colorClass,
            e(\Illuminate\Support\Number::format($quantity, maxPrecision: 2))
        ));
    }

    // Ô tìm nhanh theo tên vật tư — lọc NGAY các thẻ trong lưới khi gõ, không qua Livewire (JS
    // thuần trên input, không cần round-trip server) — hợp với phiếu kiểm kê có thể liệt kê TOÀN
    // BỘ vật tư của đối tác (hàng chục thẻ), khó dò tay từng thẻ. $repeaterClass phải khớp ĐÚNG
    // class riêng của Repeater đó (fi-warehouse-{check,stockin,stockout}-repeater) để chỉ lọc đúng
    // lưới thẻ của form này, không đụng lưới khác trên cùng trang (VD popup bàn giao).
    public static function searchBox(string $repeaterClass): HtmlString
    {
        // Khớp theo NỘI DUNG CHỮ của tiêu đề thẻ (tên vật tư) — Filament tự vẽ tên vào
        // .fi-fo-repeater-item-header h4 qua itemLabel(), cùng phần tử styleBlock() đang tô màu.
        $js = <<<'JS'
            const q = $event.target.value.trim().toLowerCase();
            document.querySelectorAll('.__CLASS__ .fi-fo-repeater-item').forEach(function (el) {
                const nameEl = el.querySelector('.fi-fo-repeater-item-header h4');
                const name = nameEl ? nameEl.textContent.toLowerCase() : '';
                el.style.display = (q === '' || name.includes(q)) ? '' : 'none';
            });
            JS;
        $js = str_replace('__CLASS__', $repeaterClass, $js);

        // Viết tay bằng class Tailwind của Filament thay vì component <x-filament::input> — chuỗi
        // HTML này được render qua Placeholder::content() dạng HtmlString (raw {!! !!}), KHÔNG đi
        // qua bước biên dịch Blade nên tag component sẽ không được nhận diện. "Nhỏ lại" = NGẮN
        // CHIỀU NGANG (width), không phải hạ chiều cao — height giữ nguyên mặc định của fi-input,
        // chỉ giới hạn width qua style inline (input HTML có "size" mặc định của trình duyệt đủ
        // mạnh để thắng class "max-w-xs" nếu không ép qua style).
        return new HtmlString(sprintf(
            '<div class="mb-4">
                <input
                    type="search"
                    placeholder="Tìm vật tư theo tên..."
                    style="width:20rem;max-width:100%%;"
                    class="fi-input block rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-white/5"
                    x-on:input.debounce.150ms="%s"
                />
            </div>',
            e($js)
        ));
    }
}
