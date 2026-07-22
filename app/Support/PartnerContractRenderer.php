<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Partner;
use App\Models\PartnerContractVersion;

// Sinh toàn văn hợp đồng hợp tác từ đúng dữ liệu đối tác đang có trong hồ sơ (Người đại diện,
// Doanh nghiệp, điều khoản hoa hồng/hủy phòng...) — dùng chung cho cả PartnerForm (super_admin
// xem trong panel) và ContractSignController (đối tác xem qua link ký công khai), để đảm bảo
// 2 nơi luôn hiển thị ĐÚNG 1 nội dung duy nhất.
class PartnerContractRenderer
{
    public static function render(Partner $partner): string
    {
        $today = now()->format('d/m/Y');
        $platformName = e(config('app.name', '365home'));

        $legalName = e($partner->legal_name ?? $partner->name ?? '—');
        $representativeName = e($partner->representative_name ?? '—');
        $representativeIdNumber = e($partner->representative_id_number ?? '—');
        $taxCode = e($partner->tax_code ?? '—');
        $address = e($partner->address ?? '—');
        $phone = e($partner->phone ?? '—');
        $email = e($partner->email ?? '—');

        // commission_rate lưu dạng chuỗi tự do (vd "10" hoặc đã có sẵn "10%") — tránh lặp "%%".
        $rawRate = $partner->commission_rate;
        $commissionRate = blank($rawRate) ? '—' : e(str_contains($rawRate, '%') ? $rawRate : $rawRate . '%');
        $cancellationPolicy = nl2br(e($partner->cancellation_policy ?? 'Chưa thiết lập.'));
        $contractCode = e($partner->contract_code ?? '(chưa cấp mã)');
        $signedAt = $partner->contract_signed_at?->format('d/m/Y') ?? $today;
        $expiresAt = $partner->contract_expires_at?->format('d/m/Y') ?? '—';

        return <<<HTML
            <div style="font-family:inherit;line-height:1.7;">
                <div style="text-align:center;margin-bottom:16px;">
                    <h2 style="margin:0;font-size:1.15rem;">HỢP ĐỒNG HỢP TÁC KINH DOANH</h2>
                    <div style="font-size:0.85rem;color:#6b7280;">Số: {$contractCode} — Lập ngày {$today}</div>
                </div>

                <p><strong>BÊN A (Nền tảng):</strong> {$platformName}</p>

                <p><strong>BÊN B (Đối tác):</strong> {$legalName}<br>
                Người đại diện: {$representativeName} (CMND/CCCD: {$representativeIdNumber})<br>
                Mã số thuế: {$taxCode}<br>
                Địa chỉ: {$address}<br>
                Điện thoại: {$phone} — Email: {$email}</p>

                <p>Hai bên thống nhất ký kết hợp đồng hợp tác kinh doanh dịch vụ lưu trú với các điều khoản sau:</p>

                <p><strong>Điều 1. Tỷ lệ hoa hồng</strong><br>
                Bên B trích hoa hồng cho Bên A theo tỷ lệ: <strong>{$commissionRate}</strong> trên mỗi giao dịch phát sinh qua nền tảng.</p>

                <p><strong>Điều 2. Chính sách hủy/hoàn tiền</strong><br>
                {$cancellationPolicy}</p>

                <p><strong>Điều 3. Thời hạn hợp đồng</strong><br>
                Hợp đồng có hiệu lực từ ngày {$signedAt} đến ngày {$expiresAt}, tự động gia hạn theo thỏa thuận giữa hai bên khi hết hạn.</p>

                <p><strong>Điều 4. Cam kết chung</strong><br>
                Hai bên cam kết thực hiện đúng và đầy đủ các điều khoản trong hợp đồng này. Hợp đồng được lập thành bản điện tử,
                có giá trị pháp lý tương đương văn bản giấy theo quy định của Luật Giao dịch điện tử, được xác thực bằng
                chữ ký điện tử của người đại diện hợp pháp của mỗi bên.</p>
            </div>
        HTML;
    }

    // Bọc phần nội dung pháp lý (đã hash/lưu bất biến ở render() phía trên) bằng khung "quốc
    // hiệu" ở đầu + 2 ô ký tên ở cuối — KHÔNG đưa phần khung này vào nội dung tính hash, vì trạng
    // thái ký (đã ký/chưa ký) thay đổi theo thời gian còn nội dung hợp đồng thì phải cố định
    // ngay từ lúc tạo. Dùng chung cho: xem trước tài liệu (PartnerForm), popup "Xem toàn văn hợp
    // đồng", và trang ký công khai (contract-sign.blade.php) — để 3 nơi luôn hiển thị đồng nhất.
    public static function renderFramed(string $bodyContent, Partner $partner, ?PartnerContractVersion $version): string
    {
        $platformBox = self::renderSignatureBox(
            'ĐẠI DIỆN BÊN A (Nền tảng)',
            $version?->isPlatformSigned() ?? false,
            $version?->platformSignedBy?->fullname,
            $version?->platform_signed_at?->format('d/m/Y H:i')
        );

        // Đối tác xác nhận qua OTP (chữ ký điện tử theo Luật GDĐT), KHÔNG dùng chứng thư số PKI
        // riêng — ghi rõ "ĐÃ XÁC NHẬN QUA OTP", tránh gây hiểu nhầm là chữ ký số PKI như bên A.
        $partnerBox = self::renderConfirmationBox(
            'ĐẠI DIỆN BÊN B (Đối tác)',
            $version?->isPartnerConfirmed() ?? false,
            $version?->partner_signed_by_name,
            $version?->partner_confirmed_at?->format('d/m/Y H:i')
        );

        return <<<HTML
            <div style="font-family:inherit;">
                <div style="text-align:center;margin-bottom:18px;">
                    <div style="font-weight:700;font-size:0.95rem;">CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM</div>
                    <div style="font-size:0.85rem;border-bottom:1px solid #111827;display:inline-block;padding-bottom:2px;">Độc lập - Tự do - Hạnh phúc</div>
                </div>

                {$bodyContent}

                <table style="width:100%;margin-top:24px;border-collapse:collapse;">
                    <tr>
                        <td style="width:50%;text-align:center;padding-right:8px;vertical-align:top;">{$partnerBox}</td>
                        <td style="width:50%;text-align:center;padding-left:8px;vertical-align:top;">{$platformBox}</td>
                    </tr>
                </table>
            </div>
        HTML;
    }

    private static function renderSignatureBox(string $label, bool $signed, ?string $signerName, ?string $signedAt): string
    {
        if ($signed) {
            $name = e($signerName ?? '—');

            return <<<HTML
                <div style="font-size:0.8rem;font-weight:600;margin-bottom:8px;">{$label}</div>
                <div style="font-size:0.72rem;color:#6b7280;margin-bottom:6px;">(Đã ký số điện tử)</div>
                <div style="border:1px solid #2563eb;border-radius:6px;padding:10px;font-size:0.7rem;color:#2563eb;">
                    ĐÃ KÝ SỐ BỞI: {$name}<br>THỜI GIAN: {$signedAt}
                </div>
            HTML;
        }

        return <<<HTML
            <div style="font-size:0.8rem;font-weight:600;margin-bottom:8px;">{$label}</div>
            <div style="font-size:0.72rem;color:#9ca3af;margin-bottom:6px;">(Vui lòng ký tại đây)</div>
            <div style="border:1px dashed #d1d5db;border-radius:6px;padding:18px 10px;font-size:0.72rem;color:#9ca3af;">
                Chưa ký
            </div>
        HTML;
    }

    // Dùng riêng cho phía Đối tác — xác nhận qua OTP (chữ ký điện tử theo Luật GDĐT), KHÔNG phải
    // chữ ký số PKI, nên KHÔNG dùng chung wording "ĐÃ KÝ SỐ" như renderSignatureBox() (tránh gây
    // hiểu nhầm đối tác cũng có chứng thư số riêng).
    private static function renderConfirmationBox(string $label, bool $confirmed, ?string $signerName, ?string $confirmedAt): string
    {
        if ($confirmed) {
            $name = e($signerName ?? '—');

            return <<<HTML
                <div style="font-size:0.8rem;font-weight:600;margin-bottom:8px;">{$label}</div>
                <div style="font-size:0.72rem;color:#6b7280;margin-bottom:6px;">(Đã xác nhận qua email OTP)</div>
                <div style="border:1px solid #0369a1;border-radius:6px;padding:10px;font-size:0.7rem;color:#0369a1;">
                    ĐÃ XÁC NHẬN BỞI: {$name}<br>THỜI GIAN: {$confirmedAt}
                </div>
            HTML;
        }

        return <<<HTML
            <div style="font-size:0.8rem;font-weight:600;margin-bottom:8px;">{$label}</div>
            <div style="font-size:0.72rem;color:#9ca3af;margin-bottom:6px;">(Vui lòng xác nhận tại đây)</div>
            <div style="border:1px dashed #d1d5db;border-radius:6px;padding:18px 10px;font-size:0.72rem;color:#9ca3af;">
                Chưa xác nhận
            </div>
        HTML;
    }
}
