<?php

namespace Modules\Minihouse\App\Services;

// Dựng HTML "HỢP ĐỒNG THUÊ PHÒNG TRỌ" cho luồng ký điện tử (ContractDocumentService) — mở rộng từ
// mẫu giấy chuẩn đã có ở ContractContentRenderer (quốc hiệu, Bên A/B, Điều 1-7, khung ký) thêm: số
// hợp đồng, ngày/nơi ký, CCCD cấp ngày/nơi cấp 2 bên, số người ở tối đa, ngày đóng tiền, điều khoản
// riêng, bảng gia hạn, ảnh chữ ký khi có, mã tra cứu + hash rút gọn ở chân trang (xem
// docs/be-minihouse-contract-signing.md mục 3.4/4.1/9). Nhận thẳng mảng $fields (CÙNG cấu trúc với
// snapshot lưu trong ContractDocument — xem ContractDocumentService::snapshotFields()) thay vì tự
// đọc Model, để render() dùng được y hệt cho cả bản "draft" (đọc sống) lẫn bản đã "send" (đọc
// snapshot đông cứng) — không có 2 đường render lệch nhau.
class ContractDocumentRenderer
{
    /**
     * @param  array<string, mixed>  $fields  Xem ContractDocumentService::buildFields().
     * @param  array{tenant?: array{name: ?string, image_url: ?string}, owner?: array{name: ?string, image_url: ?string}}  $signatures
     */
    public static function render(array $fields, array $signatures = []): string
    {
        $g = fn (string $key, string $fallback = '...................') => filled($fields[$key] ?? null) ? e($fields[$key]) : $fallback;

        $no          = $g('no');
        $signDate    = filled($fields['sign_date'] ?? null) ? \Illuminate\Support\Carbon::parse($fields['sign_date'])->format('d/m/Y') : now()->format('d/m/Y');
        $signedPlace = $g('signed_place', $g('building_address'));

        $ownerName    = $g('owner_name');
        $ownerCccd    = $g('owner_id_card_number');
        $ownerIssued  = self::issuedLine($fields['owner_id_card_issued_date'] ?? null, $fields['owner_id_card_issued_place'] ?? null);
        $ownerAddress = $g('owner_permanent_address');
        $ownerPhone   = $g('owner_phone');

        $tenantName    = $g('tenant_name');
        $tenantCccd    = $g('tenant_id_card_number');
        $tenantIssued  = self::issuedLine($fields['tenant_id_card_issued_date'] ?? null, $fields['tenant_id_card_issued_place'] ?? null);
        $tenantAddress = $g('tenant_permanent_address');
        $tenantPhone   = $g('tenant_phone');

        $roomCode        = $g('room_code');
        $buildingName    = $g('building_name');
        $buildingAddress = $g('building_address');

        $monthlyPrice = self::money($fields['monthly_price'] ?? null);
        $depositText  = filled($fields['deposit_amount'] ?? null) && (float) $fields['deposit_amount'] > 0
            ? self::money($fields['deposit_amount'])
            : 'Không đặt cọc';

        $electricText = filled($fields['electric_unit_price'] ?? null) ? self::money($fields['electric_unit_price']) . ' / số điện' : 'Theo thực tế, thoả thuận riêng';
        $waterText    = filled($fields['water_unit_price'] ?? null) ? self::money($fields['water_unit_price']) . ' / số nước' : 'Theo thực tế, thoả thuận riêng';

        $startDate = filled($fields['start_date'] ?? null) ? \Illuminate\Support\Carbon::parse($fields['start_date'])->format('d/m/Y') : '...................';
        $endDate   = filled($fields['end_date'] ?? null) ? \Illuminate\Support\Carbon::parse($fields['end_date'])->format('d/m/Y') : 'Không xác định (thuê không thời hạn)';

        $maxOccupants     = filled($fields['max_occupants'] ?? null) ? (int) $fields['max_occupants'] : null;
        $maxOccupantsLine = $maxOccupants !== null ? " Số người ở tối đa: <strong>{$maxOccupants} người</strong>." : '';
        $paymentDay       = filled($fields['payment_day'] ?? null) ? (int) $fields['payment_day'] : null;
        $paymentDayLine   = $paymentDay !== null ? "Thanh toán chậm nhất ngày <strong>{$paymentDay}</strong> hàng tháng." : 'Thanh toán hàng tháng theo thoả thuận.';
        $extraTerms       = filled($fields['extra_terms'] ?? null) ? '<p><strong>Điều 8. Thoả thuận riêng</strong><br>' . nl2br(e($fields['extra_terms'])) . '</p>' : '';

        $renewalsHtml = self::renderRenewals($fields['renewals'] ?? []);

        $signatureBlock = self::renderSignatureBlock($ownerName, $tenantName, $signatures);

        $footer = self::renderFooter($fields['verify_code'] ?? null, $fields['final_hash'] ?? $fields['sealed_hash'] ?? null);

        return <<<HTML
            <div style="font-family:'DejaVu Sans',sans-serif;font-size:13px;color:#111827;line-height:1.7;">
                <div style="text-align:center;margin-bottom:16px;">
                    <div style="font-weight:700;">CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM</div>
                    <div style="border-bottom:1px solid #111827;display:inline-block;padding-bottom:2px;">Độc lập - Tự do - Hạnh phúc</div>
                    <h2 style="margin:16px 0 4px;font-size:1.15rem;">HỢP ĐỒNG THUÊ PHÒNG TRỌ</h2>
                    <div style="font-size:0.85rem;color:#6b7280;">Số: {$no} — Lập ngày {$signDate}, tại {$signedPlace}</div>
                </div>

                <p><strong>BÊN CHO THUÊ (BÊN A):</strong><br>
                Họ tên/Đơn vị: {$ownerName}<br>
                CCCD/MSDN: {$ownerCccd}{$ownerIssued}<br>
                Địa chỉ: {$ownerAddress}<br>
                Điện thoại: {$ownerPhone}</p>

                <p><strong>BÊN THUÊ (BÊN B):</strong><br>
                Họ tên: {$tenantName}<br>
                Số CCCD: {$tenantCccd}{$tenantIssued}<br>
                Địa chỉ thường trú: {$tenantAddress}<br>
                Điện thoại: {$tenantPhone}</p>

                <p>Hai bên thống nhất ký kết hợp đồng thuê phòng trọ với các điều khoản sau:</p>

                <p><strong>Điều 1. Đối tượng hợp đồng</strong><br>
                Bên A cho Bên B thuê phòng <strong>{$roomCode}</strong> tại toà nhà <strong>{$buildingName}</strong>,
                địa chỉ: {$buildingAddress}.{$maxOccupantsLine}</p>

                <p><strong>Điều 2. Giá thuê và phương thức thanh toán</strong><br>
                Giá thuê phòng: <strong>{$monthlyPrice} / tháng</strong>, chưa bao gồm điện/nước/phụ thu (nếu có).
                {$paymentDayLine}</p>

                <p><strong>Điều 3. Tiền đặt cọc</strong><br>
                Bên B đặt cọc cho Bên A số tiền: <strong>{$depositText}</strong>. Tiền cọc được hoàn trả khi kết thúc hợp đồng, sau khi trừ các khoản
                thiệt hại/nợ (nếu có).</p>

                <p><strong>Điều 4. Điện, nước</strong><br>
                Đơn giá điện: <strong>{$electricText}</strong><br>
                Đơn giá nước: <strong>{$waterText}</strong></p>

                <p><strong>Điều 5. Thời hạn hợp đồng</strong><br>
                Hợp đồng có hiệu lực từ ngày <strong>{$startDate}</strong> đến ngày <strong>{$endDate}</strong>.</p>
                {$renewalsHtml}

                <p><strong>Điều 6. Quyền và nghĩa vụ của các bên</strong><br>
                Bên A đảm bảo phòng cho thuê đúng hiện trạng, an toàn, không tranh chấp. Bên B có trách nhiệm sử dụng phòng đúng mục đích thuê ở,
                giữ gìn tài sản chung, thanh toán đầy đủ và đúng hạn các khoản phí nêu trên, tuân thủ nội quy của toà nhà và quy định của pháp luật
                về cư trú (khai báo tạm trú theo quy định).</p>

                <p><strong>Điều 7. Điều khoản chung</strong><br>
                Hai bên xác nhận đã đọc, hiểu rõ và đồng ý dùng CHỮ KÝ ĐIỆN TỬ (vẽ tay, xác thực bằng mã OTP gửi tới số điện thoại đã đăng ký) cho
                hợp đồng này, có giá trị pháp lý tương đương chữ ký tay theo Luật Giao dịch điện tử. Mọi tranh chấp phát sinh sẽ được hai bên thương
                lượng giải quyết trên tinh thần thiện chí; trường hợp không thoả thuận được sẽ đưa ra cơ quan có thẩm quyền giải quyết theo quy định
                của pháp luật.</p>
                {$extraTerms}

                {$signatureBlock}
                {$footer}
            </div>
        HTML;
    }

    private static function issuedLine(?string $issuedDate, ?string $issuedPlace): string
    {
        if (blank($issuedDate) && blank($issuedPlace)) {
            return '';
        }

        $date  = filled($issuedDate) ? \Illuminate\Support\Carbon::parse($issuedDate)->format('d/m/Y') : '...................';
        $place = filled($issuedPlace) ? e($issuedPlace) : '...................';

        return " — cấp ngày {$date}, nơi cấp {$place}";
    }

    /** @param array<int, array{index?: int, months?: int, from_date?: ?string, to_date?: ?string, monthly_price?: mixed}> $renewals */
    private static function renderRenewals(array $renewals): string
    {
        if (empty($renewals)) {
            return '';
        }

        $rows = collect($renewals)->map(function ($r) {
            $idx   = $r['index'] ?? '';
            $from  = filled($r['from_date'] ?? null) ? \Illuminate\Support\Carbon::parse($r['from_date'])->format('d/m/Y') : '—';
            $to    = filled($r['to_date'] ?? null) ? \Illuminate\Support\Carbon::parse($r['to_date'])->format('d/m/Y') : '—';
            $price = filled($r['monthly_price'] ?? null) ? self::money($r['monthly_price']) : '—';

            return "<tr><td style=\"padding:4px 8px;border:1px solid #d1d5db;\">{$idx}</td><td style=\"padding:4px 8px;border:1px solid #d1d5db;\">{$from}</td><td style=\"padding:4px 8px;border:1px solid #d1d5db;\">{$to}</td><td style=\"padding:4px 8px;border:1px solid #d1d5db;\">{$price}</td></tr>";
        })->implode('');

        return <<<HTML
            <p style="margin-top:10px;"><strong>Bảng gia hạn hợp đồng</strong></p>
            <table style="width:100%;border-collapse:collapse;font-size:12px;">
                <thead><tr>
                    <th style="padding:4px 8px;border:1px solid #d1d5db;text-align:left;">Lần</th>
                    <th style="padding:4px 8px;border:1px solid #d1d5db;text-align:left;">Từ ngày</th>
                    <th style="padding:4px 8px;border:1px solid #d1d5db;text-align:left;">Đến ngày</th>
                    <th style="padding:4px 8px;border:1px solid #d1d5db;text-align:left;">Giá thuê/tháng</th>
                </tr></thead>
                <tbody>{$rows}</tbody>
            </table>
        HTML;
    }

    private static function renderSignatureBlock(string $ownerName, string $tenantName, array $signatures): string
    {
        $ownerImg  = self::signatureImg($signatures['owner']['image_url'] ?? null);
        $tenantImg = self::signatureImg($signatures['tenant']['image_url'] ?? null);

        return <<<HTML
            <table style="width:100%;margin-top:36px;border-collapse:collapse;">
                <tr>
                    <td style="width:50%;text-align:center;">
                        <div style="font-weight:700;">BÊN CHO THUÊ (BÊN A)</div>
                        <div style="font-size:0.8rem;color:#6b7280;">(Ký, ghi rõ họ tên)</div>
                        {$ownerImg}
                        <div style="margin-top:6px;">{$ownerName}</div>
                    </td>
                    <td style="width:50%;text-align:center;">
                        <div style="font-weight:700;">BÊN THUÊ (BÊN B)</div>
                        <div style="font-size:0.8rem;color:#6b7280;">(Ký, ghi rõ họ tên)</div>
                        {$tenantImg}
                        <div style="margin-top:6px;">{$tenantName}</div>
                    </td>
                </tr>
            </table>
        HTML;
    }

    private static function signatureImg(?string $url): string
    {
        if (blank($url)) {
            return '<div style="height:70px;"></div>';
        }

        return '<img src="' . e($url) . '" style="height:60px;margin-top:8px;">';
    }

    private static function renderFooter(?string $verifyCode, ?string $hash): string
    {
        if (blank($verifyCode)) {
            return '';
        }

        $shortHash = filled($hash) ? substr($hash, 0, 16) : '...................';

        return '<div style="margin-top:24px;font-size:10px;color:#6b7280;text-align:center;">'
            . "Kiểm tra tính toàn vẹn tại 365home.vn/hd/{$verifyCode} — mã băm: {$shortHash}…"
            . '</div>';
    }

    private static function money(mixed $amount): string
    {
        return number_format((float) $amount, 0, ',', '.') . ' đ';
    }
}
