<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Support;

use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Illuminate\Support\Collection;
use Modules\Warehouse\App\Models\WarehouseStockCheck;
use Modules\Warehouse\App\Models\WarehouseStockIn;
use Modules\Warehouse\App\Models\WarehouseStockOut;
use Modules\Warehouse\App\Support\WarehousePdfRenderer;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Điểm dùng chung cho action "In phiếu" ở cả 3 Resource (header action trên trang sửa + table row
// action ở danh sách) — tránh lặp lại 2 lần logic load quan hệ + render PDF cho mỗi loại phiếu.
class WarehousePrinter
{
    public static function stockIn(WarehouseStockIn $stockIn): StreamedResponse
    {
        $stockIn->loadMissing(['items.item.unit', 'supplier', 'partner', 'creator']);

        $html = view('warehouse::pdf.stock-in', [
            'stockIn'     => $stockIn,
            'creatorName' => CurrentUserDisplay::forUser($stockIn->creator),
        ])->render();

        return static::download($html, $stockIn->code);
    }

    public static function stockOut(WarehouseStockOut $stockOut): StreamedResponse
    {
        $stockOut->loadMissing(['items.item.unit', 'employee', 'room', 'partner', 'creator']);

        $html = view('warehouse::pdf.stock-out', [
            'stockOut'    => $stockOut,
            'creatorName' => CurrentUserDisplay::forUser($stockOut->creator),
        ])->render();

        return static::download($html, $stockOut->code);
    }

    public static function stockCheck(WarehouseStockCheck $stockCheck): StreamedResponse
    {
        $stockCheck->loadMissing(['items.item.unit', 'partner', 'creator']);

        $html = view('warehouse::pdf.stock-check', [
            'stockCheck'  => $stockCheck,
            'creatorName' => CurrentUserDisplay::forUser($stockCheck->creator),
        ])->render();

        return static::download($html, $stockCheck->code);
    }

    // "In danh sách tồn kho" — in TOÀN BỘ vật tư hiện có kèm số lượng tồn hiện tại (không phải 1
    // phiếu cụ thể). super_admin xem được vật tư của NHIỀU đối tác cùng lúc (BelongsToPartner không
    // lọc gì với super_admin) nên hiện thêm cột "Đối tác" để phân biệt; user thường chỉ thấy đúng 1
    // đối tác của mình nên bỏ cột đó cho gọn.
    public static function itemList(Collection $items, bool $showPartnerColumn): StreamedResponse
    {
        $items->loadMissing(['category', 'unit', 'partner']);

        $partnerName = $showPartnerColumn ? null : $items->first()?->partner?->name;

        $html = view('warehouse::pdf.item-list', [
            'items'             => $items,
            'showPartnerColumn' => $showPartnerColumn,
            'partnerName'       => $partnerName,
        ])->render();

        return static::download($html, 'danh-sach-ton-kho-' . now()->format('Ymd-His'));
    }

    // "In mã QR" — mỗi vật tư 1 thẻ có QR encode ĐÚNG giá trị "sku" (khớp chính xác với cách
    // WarehouseBarcodeScan tra vật tư khi quét — xem WarehouseBarcodeScan::handle()), dùng để in ra
    // giấy làm thẻ test quét (camera điện thoại/máy quét mã vạch) khi chưa có tem mã vạch thật của
    // NCC dán sẵn trên hàng. Bỏ qua vật tư chưa có SKU vì không có gì để encode.
    public static function qrCodes(Collection $items, int $copies = 1): StreamedResponse
    {
        $items = $items->filter(fn ($item) => filled($item->sku))->values();
        $copies = max(1, min($copies, 500));

        $qrImages = [];
        foreach ($items as $item) {
            $qrImages[$item->id] = static::qrPng((string) $item->sku);
        }

        // 1 bản = thẻ lớn (1 mã/hàng, có tên); nhiều bản = tem nhỏ xếp lưới để in ra cắt dán.
        $view = $copies > 1 ? 'warehouse::pdf.qr-stickers' : 'warehouse::pdf.qr-codes';

        $html = view($view, [
            'items'    => $items,
            'qrImages' => $qrImages,
            'copies'   => $copies,
        ])->render();

        return static::download($html, 'ma-qr-vat-tu-' . now()->format('Ymd-His'));
    }

    // PNG base64 của mã QR encode đúng chuỗi $text (SKU) — dùng cho PDF in và ô xem trước ở form vật tư.
    public static function qrPng(string $text): string
    {
        // 400px thật (hiện nhỏ/to qua CSS) — đủ nét khi in ra giấy.
        $writer = new Writer(new GDLibRenderer(400, 4));

        return base64_encode($writer->writeString($text));
    }

    protected static function download(string $html, string $code): StreamedResponse
    {
        $pdf = WarehousePdfRenderer::render($html);

        return response()->streamDownload(
            fn () => print ($pdf),
            "{$code}.pdf",
            ['Content-Type' => 'application/pdf']
        );
    }
}
