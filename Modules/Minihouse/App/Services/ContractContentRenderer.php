<?php

namespace Modules\Minihouse\App\Services;

use Modules\Minihouse\App\Models\Contract;

// Sinh toàn văn "Nội dung hợp đồng" (RichEditor::make('contract_content')) từ ĐÚNG dữ liệu đang có
// trên hợp đồng (Phòng/Toà nhà, Khách thuê + người ở cùng, giá thuê, đơn giá điện/nước, phụ thu
// định kỳ...) — nút "Cập nhật nội dung hợp đồng" ở EditContract gọi render() rồi ghi thẳng vào
// contract_content; nhân viên vẫn sửa tay thêm được sau đó trong RichEditor (bấm cập nhật lại sẽ
// ghi đè toàn bộ, kể cả phần đã sửa tay — có confirm trước khi bấm).
//
// KHÔNG có hồ sơ "Bên cho thuê" (chủ nhà/doanh nghiệp) trong hệ thống — khác Partner (App\Support\
// PartnerContractRenderer, đối tác là doanh nghiệp có đầy đủ hồ sơ pháp lý) — để trống dạng
// "..................." cho phần này, nhân viên tự điền tay 1 lần rồi các lần "Cập nhật" sau vẫn
// mất phần đã điền (ghi đè toàn bộ) — nhược điểm đã biết, chấp nhận được vì thông tin chủ nhà hầu
// như không đổi giữa các hợp đồng, điền lại nhanh.
class ContractContentRenderer
{
    public static function render(Contract $contract): string
    {
        $contract->loadMissing(['room.building', 'tenant', 'occupantEntries.tenant', 'surcharges']);

        $room     = $contract->room;
        $building = $room?->building;
        $tenant   = $contract->tenant;

        $today     = now()->format('d/m/Y');
        $startDate = $contract->start_date?->format('d/m/Y') ?? '...................';
        $endDate   = $contract->end_date?->format('d/m/Y') ?? 'Không xác định (thuê không thời hạn)';

        $buildingName    = e($building?->name ?? '...................');
        $buildingAddress = e(trim(implode(', ', array_filter([$building?->address, $building?->ward, $building?->province]))) ?: '...................');
        $roomCode        = e($room?->code ?? '...................');
        $roomArea        = $room?->area ? e($room->area) . ' m²' : '...................';

        $tenantName    = e($tenant?->fullname ?? '...................');
        $tenantCccd    = e($tenant?->id_card_number ?? '...................');
        $tenantDob     = $tenant?->date_of_birth?->format('d/m/Y') ?? '...................';
        $tenantAddress = e($tenant?->permanent_address ?? '...................');
        $tenantPhone   = e($tenant?->phone ?? '...................');

        $monthlyPrice = self::money($contract->monthly_price);
        $depositText  = filled($contract->deposit_amount)
            ? self::money($contract->deposit_amount)
            : 'Không đặt cọc';

        $electricPrice = $contract->electric_unit_price ?: $building?->electric_unit_price;
        $waterPrice    = $contract->water_unit_price ?: $building?->water_unit_price;
        $electricText  = filled($electricPrice) ? self::money($electricPrice) . ' / số điện' : 'Theo thực tế, thoả thuận riêng';
        $waterText     = filled($waterPrice) ? self::money($waterPrice) . ' / số nước' : 'Theo thực tế, thoả thuận riêng';

        $occupantsHtml = self::renderOccupants($contract);
        $surchargesHtml = self::renderSurcharges($contract);

        return <<<HTML
            <div style="font-family:inherit;line-height:1.7;">
                <div style="text-align:center;margin-bottom:16px;">
                    <div style="font-weight:700;">CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM</div>
                    <div style="border-bottom:1px solid #111827;display:inline-block;padding-bottom:2px;">Độc lập - Tự do - Hạnh phúc</div>
                    <h2 style="margin:16px 0 4px;font-size:1.15rem;">HỢP ĐỒNG THUÊ PHÒNG TRỌ</h2>
                    <div style="font-size:0.85rem;color:#6b7280;">Lập ngày {$today}</div>
                </div>

                <p><strong>BÊN CHO THUÊ (BÊN A):</strong><br>
                Họ tên/Đơn vị: ...................<br>
                CCCD/MSDN: ...................<br>
                Địa chỉ: ...................<br>
                Điện thoại: ...................</p>

                <p><strong>BÊN THUÊ (BÊN B):</strong><br>
                Họ tên: {$tenantName}<br>
                Ngày sinh: {$tenantDob}<br>
                Số CCCD: {$tenantCccd}<br>
                Địa chỉ thường trú: {$tenantAddress}<br>
                Điện thoại: {$tenantPhone}</p>

                {$occupantsHtml}

                <p>Hai bên thống nhất ký kết hợp đồng thuê phòng trọ với các điều khoản sau:</p>

                <p><strong>Điều 1. Đối tượng hợp đồng</strong><br>
                Bên A cho Bên B thuê phòng <strong>{$roomCode}</strong> (diện tích {$roomArea}) tại toà nhà <strong>{$buildingName}</strong>,
                địa chỉ: {$buildingAddress}.</p>

                <p><strong>Điều 2. Giá thuê và phương thức thanh toán</strong><br>
                Giá thuê phòng: <strong>{$monthlyPrice} / tháng</strong>, thanh toán hàng tháng, chưa bao gồm điện/nước/phụ thu (nếu có).</p>

                <p><strong>Điều 3. Tiền đặt cọc</strong><br>
                Bên B đặt cọc cho Bên A số tiền: <strong>{$depositText}</strong>. Tiền cọc được hoàn trả khi kết thúc hợp đồng, sau khi trừ các khoản
                thiệt hại/nợ (nếu có) theo biên bản bàn giao lúc trả phòng.</p>

                <p><strong>Điều 4. Điện, nước và phụ thu</strong><br>
                Đơn giá điện: <strong>{$electricText}</strong><br>
                Đơn giá nước: <strong>{$waterText}</strong>
                {$surchargesHtml}
                </p>

                <p><strong>Điều 5. Thời hạn hợp đồng</strong><br>
                Hợp đồng có hiệu lực từ ngày <strong>{$startDate}</strong> đến ngày <strong>{$endDate}</strong>.</p>

                <p><strong>Điều 6. Quyền và nghĩa vụ của các bên</strong><br>
                Bên A đảm bảo phòng cho thuê đúng hiện trạng, an toàn, không tranh chấp. Bên B có trách nhiệm sử dụng phòng đúng mục đích thuê ở,
                giữ gìn tài sản chung, thanh toán đầy đủ và đúng hạn các khoản phí nêu trên, tuân thủ nội quy của toà nhà và quy định của pháp luật
                về cư trú (khai báo tạm trú theo quy định).</p>

                <p><strong>Điều 7. Điều khoản chung</strong><br>
                Hợp đồng có thể được sửa đổi, bổ sung khi có sự đồng ý bằng văn bản của cả hai bên. Mọi tranh chấp phát sinh sẽ được hai bên
                thương lượng giải quyết trên tinh thần thiện chí; trường hợp không thoả thuận được sẽ đưa ra cơ quan có thẩm quyền giải quyết
                theo quy định của pháp luật.</p>

                <p>Hợp đồng này được lập thành 02 (hai) bản có giá trị pháp lý như nhau, mỗi bên giữ 01 (một) bản.</p>
            </div>
        HTML;
    }

    // Bọc phần nội dung (render() ở trên) bằng khung chữ ký 2 bên — CHỈ dùng lúc xuất PDF/in, KHÔNG
    // lưu vào contract_content (contract_content chỉ chứa phần nội dung thuần, tránh 2 nơi hiển thị
    // [form RichEditor + bản in] bị lệch khung/chữ ký).
    public static function renderPrintable(Contract $contract): string
    {
        $body = self::render($contract);

        return <<<HTML
            <div style="font-family:'DejaVu Sans',sans-serif;font-size:13px;color:#111827;">
                {$body}

                <table style="width:100%;margin-top:36px;border-collapse:collapse;">
                    <tr>
                        <td style="width:50%;text-align:center;">
                            <div style="font-weight:700;">BÊN CHO THUÊ (BÊN A)</div>
                            <div style="font-size:0.8rem;color:#6b7280;margin-bottom:70px;">(Ký, ghi rõ họ tên)</div>
                        </td>
                        <td style="width:50%;text-align:center;">
                            <div style="font-weight:700;">BÊN THUÊ (BÊN B)</div>
                            <div style="font-size:0.8rem;color:#6b7280;margin-bottom:70px;">(Ký, ghi rõ họ tên)</div>
                        </td>
                    </tr>
                </table>
            </div>
        HTML;
    }

    // Đủ thông tin từng người ở cùng như 1 "Bên B phụ" — cùng mức chi tiết với BÊN THUÊ chính
    // (họ tên/ngày sinh/CCCD/địa chỉ thường trú/điện thoại), thêm quan hệ với người đứng tên —
    // đúng mức cần thiết để hợp đồng giấy có căn cứ xác định ai đang thực sự ở cùng, phục vụ cả đối
    // chiếu khi khai báo tạm trú.
    private static function renderOccupants(Contract $contract): string
    {
        $occupants = $contract->occupantEntries;

        if ($occupants->isEmpty()) {
            return '';
        }

        $blocks = $occupants->map(function ($entry, $index) {
            $tenant = $entry->tenant;
            $stt    = $index + 1;

            $name         = e($tenant?->fullname ?? '—');
            $dob          = $tenant?->date_of_birth?->format('d/m/Y') ?? '—';
            $cccd         = e($tenant?->id_card_number ?? '—');
            $address      = e($tenant?->permanent_address ?? '—');
            $phone        = e($tenant?->phone ?? '—');
            $relationship = e($entry->relationship_to_primary ?: '—');

            return <<<HTML
                <li style="margin-bottom:6px;">
                    {$stt}. <strong>{$name}</strong> — Ngày sinh: {$dob} — CCCD: {$cccd}<br>
                    Địa chỉ thường trú: {$address} — Điện thoại: {$phone} — Quan hệ với Bên B: {$relationship}
                </li>
            HTML;
        })->implode('');

        return "<p><strong>Người ở cùng (Bên B đăng ký cho ở cùng):</strong></p><ul style=\"margin-top:-8px;padding-left:20px;\">{$blocks}</ul>";
    }

    private static function renderSurcharges(Contract $contract): string
    {
        $surcharges = $contract->surcharges;

        if ($surcharges->isEmpty()) {
            return '';
        }

        $rows = $surcharges->map(function ($surcharge) {
            $name   = e($surcharge->name);
            $amount = self::money($surcharge->amount);

            return "<li>{$name}: {$amount} / tháng</li>";
        })->implode('');

        return "<br>Phụ thu định kỳ hàng tháng:<ul style=\"margin-top:-8px;\">{$rows}</ul>";
    }

    private static function money(mixed $amount): string
    {
        return number_format((float) $amount, 0, ',', '.') . ' đ';
    }
}
