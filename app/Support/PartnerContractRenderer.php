<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Partner;
use App\Models\PartnerContractVersion;
use Carbon\Carbon;
use Modules\Category\Entities\Category;

// Sinh toàn văn hợp đồng hợp tác từ đúng dữ liệu đối tác đang có trong hồ sơ — dùng chung cho PartnerForm (super_admin xem
// trong panel), ContractSignController (đối tác xem qua link ký công khai) và ContractPdfRenderer (xuất PDF/in), để mọi nơi
// luôn hiển thị ĐÚNG 1 nội dung duy nhất.
//
// HOMESTAY: mẫu "HỢP ĐỒNG 365 HOME" (15 điều) — A4, Times New Roman 13pt, giãn dòng 1,5, căn đều 2 bên, lề trái 3cm/phải 1,5cm/
// trên-dưới 2cm khi in (xem ContractPdfRenderer + CSS in ở trang ký). Bên A lấy từ config/contract.php; Bên B từ hồ sơ đối tác.
// MINIHOUSE: luồng hợp đồng đang ẨN (chỉ mua gói) — giữ bản mẫu cũ ở renderMinihouse() phòng khi bật lại.
class PartnerContractRenderer
{
    public const FONT = "'Times New Roman',Times,serif";

    // ĐỊNH DẠNG theo ĐÚNG mẫu Word "HỢP ĐỒNG 365 HOME" (đo từ chính file Word xuất PDF): Times New Roman 13pt, "giãn dòng 1,5 dòng" của Word
    // = 22,4pt cho chữ 13pt (20,7pt cho chữ 12pt, 31pt cho chữ 18pt), cách đoạn 6pt, căn đều hai bên. Dùng đơn vị pt tuyệt đối để trình duyệt/PDF
    // cho cùng khoảng cách như Word.
    public const LH13 = '22.4pt';

    public const LH12 = '20.7pt';

    public const LH18 = '31pt';

    /** $kind = 'addendum': phụ lục "Ký quỹ và thanh toán" cho đối tác Homestay đã ký hợp đồng mẫu cũ (xem renderAddendum()). */
    public static function render(Partner $partner, string $kind = 'contract'): string
    {
        if ($kind === 'addendum' && ! $partner->isMinihouse()) {
            return self::renderAddendum($partner);
        }

        return $partner->isMinihouse() ? self::renderMinihouse($partner) : self::renderHomestay($partner);
    }

    /** Số tháng của thời hạn hợp đồng (Điều 7) giữa mốc bắt đầu và ngày hết hạn, làm tròn theo tháng, tối thiểu 1. */
    public static function termMonths(\DateTimeInterface $expiresAt, \DateTimeInterface $from): int
    {
        return max(1, (int) round(($expiresAt->getTimestamp() - $from->getTimestamp()) / 86400 / 30.44));
    }

    private static function renderHomestay(Partner $partner): string
    {
        $cfg = config('contract');
        $a = $cfg['platform'];
        $signed = $partner->contract_signed_at ?? now();
        $dateLine = $cfg['place'] . ', ngày ' . $signed->format('d') . ' tháng ' . $signed->format('m') . ' năm ' . $signed->format('Y');
        $dateWords = 'ngày ' . $signed->format('d') . ' tháng ' . $signed->format('m') . ' năm ' . $signed->format('Y');

        $dots = fn (?string $v, int $n = 18) => filled($v) ? e(trim((string) $v)) : str_repeat('…', $n);
        $upper = fn (?string $v) => filled($v) ? e(mb_strtoupper(trim((string) $v))) : null;

        $legalName = $upper($partner->legal_name ?: $partner->name) ?? str_repeat('…', 30);
        $repName = $upper($partner->representative_name) ?? str_repeat('…', 20);
        $position = e($partner->representative_position ?: 'Chủ cơ sở');
        $idNumber = $dots($partner->representative_id_number, 14);
        $idDate = $partner->representative_id_issued_at?->format('d/m/Y') ?? str_repeat('…', 12);
        $idPlace = e($partner->representative_id_issued_place ?: $cfg['id_issued_place']);
        $phone = $dots($partner->phone, 20);
        $tax = $dots($partner->tax_code, 16);

        $facility = Category::query()->where('partner_id', $partner->id)->whereNull('parent_id')->where('category_type', 'product')->orderBy('name')->first();
        $facilityName = e($facility?->name ?: ($partner->name ?: $partner->legal_name ?: str_repeat('…', 30)));
        $facilityAddress = e((string) ($facility?->address ?: $partner->address ?: str_repeat('…', 30)));

        // Điều 4.3: Bên A hưởng tỷ lệ commission_rate, Bên B phần còn lại.
        $rate = app(\App\Services\PartnerContractWorkflowService::class)->commissionValue($partner->commission_rate);
        $rateA = $rate ?? 0.0;
        $rateB = 100.0 - $rateA;
        $pctA = $rate === null ? '……%  (………………………………………)' : VietnameseNumber::percent($rateA) . '% (' . VietnameseNumber::percentWords($rateA) . ')';
        $pctB = $rate === null ? '……%  (………………………………………)' : VietnameseNumber::percent($rateB) . '% &nbsp;(' . VietnameseNumber::percentWords($rateB) . ')';

        $cycleText = match ((string) $partner->payment_cycle) {
            'weekly' => 'hằng tuần',
            'monthly' => 'hằng tháng',
            default => 'hai tuần một lần (nửa tháng)',
        };
        $overdueDays = sprintf('%02d', (int) config('settlement.overdue_days', 3));
        $violationFee = VietnameseNumber::format((int) $cfg['violation_fee_vnd']) . ' đồng';
        $penalty = (int) $cfg['penalty_percent'];

        // Điều 7: thời hạn = SỐ THÁNG kể từ ngày Hợp đồng có hiệu lực (ngày Bên A ký xác nhận — Điều 15), không in ngày hết hạn cụ thể:
        // đối tác có thể ký trước, Bên A ký sau, nên ngày hết hạn chỉ xác định được lúc Bên A ký (PartnerContractWorkflowService::platformSign
        // tính lại contract_expires_at = ngày Bên A ký + đúng số tháng này). Số tháng suy từ ngày hết hạn trong hồ sơ (mặc định 12 tháng).
        $termMonths = $partner->contract_expires_at ? self::termMonths($partner->contract_expires_at, $signed) : (int) $cfg['default_term_months'];
        $termText = sprintf('%02d tháng kể từ ngày Hợp đồng có hiệu lực', $termMonths);

        $lh = self::LH13;
        $p = fn (string $text, string $extra = '') => '<p style="margin:0 0 6pt;page-break-inside:avoid;line-height:' . $lh . ';' . $extra . '">' . $text . '</p>';
        $h = fn (string $text) => '<p style="margin:6pt 0;page-break-inside:avoid;line-height:' . $lh . ';font-weight:700;">' . $text . '</p>';
        $indent = 'text-indent:36pt;';
        $b = fn (string $text) => '<strong>' . $text . '</strong>';
        // Hai ô cùng một dòng (Word dùng tab): ô trái rộng $w pt, ô phải bắt đầu đúng vị trí tab.
        $cols = fn (string $left, string $right, float $w) => '<table style="width:100%;border-collapse:collapse;margin:0 0 6pt;page-break-inside:avoid;"><tr><td style="width:' . $w . 'pt;padding:0;line-height:' . $lh . ';vertical-align:top;">' . $left
            . '</td><td style="padding:0;line-height:' . $lh . ';vertical-align:top;">' . $right . '</td></tr></table>';
        // Mục liệt kê có chấm tròn (•): chấm ở lề 18pt, chữ ở 36pt như mẫu.
        $bullet = fn (string $text) => '<p style="margin:0 0 6pt 36pt;page-break-inside:avoid;text-indent:-18pt;line-height:' . $lh . ';"><span style="font-size:14.5pt;position:relative;top:2pt;">&bull;</span><span style="display:inline-block;width:13pt;"></span>' . $text . '</p>';

        $html = [];
        $html[] = '<div class="hd" style="font-family:' . self::FONT . ';font-size:13pt;line-height:' . $lh . ';text-align:justify;color:#000;">';
        $html[] = $p($dateLine, 'text-align:right;font-style:italic;');
        $html[] = '<p style="margin:0 0 6pt;padding-top:5.9pt;padding-left:0.95pt;text-indent:36pt;text-align:center;font-size:18pt;font-weight:700;line-height:' . self::LH18 . ';">HỢP ĐỒNG HỢP TÁC KINH DOANH</p>';
        $html[] = '<p style="margin:0 0 6pt;padding-top:10.2pt;padding-left:0.95pt;text-indent:36pt;text-align:center;font-weight:700;line-height:' . $lh . ';">Số: ' . $dots($partner->contract_code, 20) . '</p>';
        foreach ([
            '- Căn cứ Bộ luật Dân sự số 91/2015/QH13 ngày 24/11/2015;',
            '- Căn cứ vào Luật Thương mại số 36/2005/QH11 ngày 14/06/2005;',
            '- Căn cứ vào nhu cầu và sự thỏa thuận của các bên tham gia Hợp đồng;',
        ] as $line) {
            $html[] = '<p style="margin:0 0 6pt;page-break-inside:avoid;font-size:12pt;font-style:italic;line-height:' . self::LH12 . ';' . $indent . '">' . $line . '</p>';
        }
        $html[] = $p("Hôm nay, {$dateWords}, các Bên gồm:", $indent);

        // BÊN A (thông tin cố định theo config/contract.php) — họ tên và chức vụ in đậm như mẫu
        $html[] = $p($b('BÊN A: ' . e($a['name'])));
        $html[] = $cols('Người đại diện: ' . $b(e($a['representative'])), 'Chức vụ: ' . $b(e($a['position'])), 248.5);
        $html[] = $p('Địa chỉ: ' . e($a['address']));
        $html[] = $cols('Điện thoại: ' . e($a['phone']), 'Mã số thuế: ' . e($a['tax_code']), 245.3);
        $html[] = $p('Tài khoản: ' . e($a['bank_account']) . ' &nbsp;tại ' . e($a['bank_name']));

        // BÊN B (từ hồ sơ đối tác)
        $html[] = $p($b("BÊN B: {$legalName}"));
        $html[] = $cols("Người đại diện: {$b($repName)}", "Chức vụ: {$b($position)}", 248.5);
        $html[] = $p("CCCD số: {$idNumber} cấp ngày {$idDate} tại {$idPlace}");
        $html[] = $cols("Số điện thoại: {$phone}", "Mã số thuế: {$tax}", 245.3);
        $html[] = $p("Cơ sở lưu trú hợp tác: {$facilityName}");
        $html[] = $p("Địa chỉ cơ sở lưu trú: {$facilityAddress}");

        // THÂN HỢP ĐỒNG: chép NGUYÊN VĂN từ hai mẫu chuẩn của chủ nền tảng (xem App\Support\PartnerContractTemplateText) — mẫu CT cho cơ sở trong Thành phố
        // Cần Thơ, mẫu VT+ĐL cho cơ sở ngoài Cần Thơ. Hệ thống chỉ điền các chỗ trống (tỷ lệ, tiền bảo đảm, mức khấu trừ, mức phạt, thời hạn); không thêm bớt chữ.
        $template = $partner->contractTemplate() === Partner::TEMPLATE_CT ? PartnerContractTemplateText::CT : PartnerContractTemplateText::VT_DL;
        $fill = [
            '{PCT_A}' => $rate === null ? '……%  (………………………………………)' : VietnameseNumber::percent($rateA) . '% (' . VietnameseNumber::percentWords($rateA) . ')',
            '{PCT_B}' => $rate === null ? '……%  (………………………………………)' : VietnameseNumber::percent($rateB) . '% (' . VietnameseNumber::percentWords($rateB) . ')',
            '{RATE_A}' => $rate === null ? '……%' : VietnameseNumber::percent($rateA) . '%',
            '{DEPOSIT}' => VietnameseNumber::format(ContractTerms::guaranteeVnd()) . ' đồng (' . VietnameseNumber::money(ContractTerms::guaranteeVnd()) . ')',
            '{VIOLATION}' => $violationFee,
            '{PENALTY}' => (string) $penalty,
            '{TERM}' => (string) $termMonths,
            '{RENEW}' => (string) (int) $cfg['default_term_months'],
        ];

        $first = true;
        foreach ($template as [$kind, $text]) {
            $text = strtr(e($text), $fill);

            if ($kind === 'h') {
                // Mẫu bắt đầu Điều 1 ở đầu trang 2 (sau 1 đoạn trống cuối trang 1) → ngắt trang cố định để mọi bản đều giống mẫu.
                $html[] = $first
                    ? '<p style="margin:0 0 6pt;page-break-before:always;line-height:' . $lh . ';font-weight:700;">' . $text . '</p>'
                    : $h($text);
                $first = false;
            } elseif ($kind === 'b') {
                $html[] = $bullet($text);
            } else {
                $html[] = $p($text);
            }
        }

        $html[] = '</div>';

        return implode("\n", $html);
    }

    /**
     * Điều "KÝ QUỸ" (hợp đồng mẫu mới — Điều 5) hoặc nội dung tương ứng của phụ lục cho đối tác đã ký hợp đồng cũ: mức tối thiểu,
     * nạp, số dư thấp, các trường hợp được trừ, quy trình khiếu nại, hoàn ký quỹ. Con số lấy từ config/escrow.php (đề xuất, đổi qua
     * .env) và từ mức Super Admin đặt cho đối tác (partners.escrow_min_amount).
     *
     * @return string[] các đoạn HTML
     */
    private static function escrowArticle(Partner $partner, int $no, \Closure $p, \Closure $h, \Closure $bullet, bool $trial = false): array
    {
        $min = (int) ($partner->escrow_min_amount ?: app(\App\Services\EscrowService::class)->suggestedMinAmount($partner));
        $minText = VietnameseNumber::format($min) . ' đồng (' . VietnameseNumber::money($min) . ')';
        $grace = (int) config('escrow.initial_grace_days');
        $topup = (int) config('escrow.topup_days');
        $suspend = (int) config('escrow.suspend_below_percent');
        $respond = (int) config('escrow.deduction_response_days');
        $overdue = (int) config('settlement.overdue_days', 3);

        return [
            $h("ĐIỀU {$no}. KÝ QUỸ ĐẢM BẢO NGHĨA VỤ CỦA BÊN B"),
            $p("{$no}.1. Mức ký quỹ: Bên B ký quỹ cho Bên A khoản tiền tối thiểu {$minText}. Khi Bên B bổ sung phòng kinh doanh làm mức ký quỹ tăng, Bên A thông báo để Bên B nạp bù, không khoá bán ngay."),
            $p("{$no}.2. Nạp ký quỹ: Bên B nạp qua mã QR PayOS do Bên A cung cấp (tiền ký quỹ về tài khoản của Bên A, không về tài khoản của Bên B) hoặc chuyển khoản ngoài hệ thống để Bên A ghi nhận kèm chứng từ. " . ($trial ? 'Bên B được miễn nạp ký quỹ trong thời gian ưu đãi quy định tại Điều 4.5 và phải nạp đủ mức tối thiểu trước khi hết thời gian ưu đãi; ' : 'Bên B mới hợp tác phải nạp đủ mức tối thiểu trước khi mở bán trực tuyến; ') . "Bên B đang hoạt động có {$grace} ngày kể từ ngày Hợp đồng/phụ lục có hiệu lực để nạp đủ."),
            $p("{$no}.3. Sổ ký quỹ: số dư ký quỹ chỉ thay đổi qua bút toán, mỗi bút toán ghi rõ số tiền, lý do, đơn/biên bản liên quan và chứng từ; Bên B xem được sổ ký quỹ trong ứng dụng. Ký quỹ không tính lãi và Bên B không được tự dùng ký quỹ để trả hoa hồng hằng kỳ — Bên A chỉ trừ ký quỹ khi quá hạn hoặc thuộc các trường hợp tại khoản {$no}.5."),
            $p("{$no}.4. Số dư thấp: khi số dư thấp hơn mức tối thiểu, Bên A cảnh báo và yêu cầu nạp bù trong {$topup} ngày; khi số dư dưới {$suspend}% mức tối thiểu hoặc quá hạn nạp bù, Bên A tạm ngưng bán trực tuyến cho Bên B (phòng ẩn khỏi tìm kiếm, không nhận đơn mới; đơn đã đặt vẫn được phục vụ). Số tiền trừ vượt quá số dư là công nợ của Bên B và được bù khi Bên B nạp thêm."),
            $p("{$no}.5. Các trường hợp Bên A được trừ ký quỹ:"),
            $bullet("Hoa hồng kỳ đối soát quá hạn {$overdue} ngày làm việc chưa nộp;"),
            $bullet('Khách được hoàn tiền (huỷ hợp lệ, phòng không như mô tả, Bên B huỷ đơn đã thanh toán) mà Bên B không hoàn trong 24 giờ — Bên A hoàn thay rồi trừ khoản đã ứng;'),
            $bullet('Bên B huỷ/không giao phòng đơn đã xác nhận (quá số phòng, đóng cửa): phạt bằng 100% tiền đơn hoặc chi phí Bên A chuyển khách sang chỗ khác, lấy số lớn hơn;'),
            $bullet('Bồi thường thiệt hại cho khách do lỗi cơ sở (an ninh, tài sản của khách, vệ sinh nghiêm trọng) khi đã có kết luận;'),
            $bullet('Khiếu nại/hoàn giao dịch (chargeback) từ cổng thanh toán liên quan đến đơn của Bên B;'),
            $bullet('Vi phạm Hợp đồng đã quy định mức phạt (bán phòng ngoài giá cam kết, lách hoa hồng — nhận khách của Bên A rồi bảo khách thanh toán ngoài, tạo đơn giả…);'),
            $bullet('Khoản khác hai bên thoả thuận bằng văn bản.'),
            $p("{$no}.6. Quy trình trừ: trừ hoa hồng quá hạn được thực hiện tự động theo số liệu đã đối soát. Mọi khoản trừ khác: (a) Bên A lập đề xuất trừ nêu số tiền, lý do, chứng từ; số dư chưa đổi nhưng khoản đó được tạm giữ; (b) Bên B nhận thông báo (ứng dụng, email) và có {$respond} ngày để đồng ý hoặc khiếu nại kèm lý do, chứng từ — hết hạn không phản hồi được xem là đồng ý; (c) có khiếu nại thì Bên A xem xét và giữ, giảm hoặc huỷ đề xuất, ghi rõ kết luận; (d) chốt thì Bên A ghi bút toán trừ và thông báo số dư mới. Trường hợp khẩn (khách đang bị bỏ rơi, cần hoàn tiền ngay), Bên A được trừ ngay; Bên B vẫn có quyền khiếu nại sau và khoản chênh lệch (nếu có) được hoàn lại bằng bút toán đảo."),
            $p("{$no}.7. Hoàn ký quỹ: khi Hợp đồng chấm dứt, Bên A ngừng bán, chờ hoàn tất các đơn đang phục vụ và 30 ngày tiếp nhận khiếu nại, quyết toán công nợ cuối cùng rồi hoàn phần còn lại về tài khoản ngân hàng Bên B đã đăng ký."),
        ];
    }

    /**
     * PHỤ LỤC "Ký quỹ và thanh toán" cho đối tác đã ký hợp đồng mẫu cũ (365home thu hộ): sửa điều thanh toán và thêm điều Ký quỹ. Ký bằng đúng
     * luồng OTP hiện có; chỉ khi phụ lục có hiệu lực (Bên A ký) đối tác mới chuyển sang luồng tiền đặt phòng về thẳng tài khoản của mình.
     */
    public static function renderAddendum(Partner $partner): string
    {
        $cfg = config('contract');
        $a = $cfg['platform'];
        $now = now();
        $lh = self::LH13;
        $p = fn (string $text, string $extra = '') => '<p style="margin:0 0 6pt;page-break-inside:avoid;line-height:' . $lh . ';' . $extra . '">' . $text . '</p>';
        $h = fn (string $text) => '<p style="margin:6pt 0;page-break-inside:avoid;line-height:' . $lh . ';font-weight:700;">' . $text . '</p>';
        $bullet = fn (string $text) => '<p style="margin:0 0 6pt 36pt;page-break-inside:avoid;text-indent:-18pt;line-height:' . $lh . ';">&bull;&nbsp;&nbsp;' . $text . '</p>';

        $legalName = e(mb_strtoupper((string) ($partner->legal_name ?: $partner->name)));
        $rate = app(\App\Services\PartnerContractWorkflowService::class)->commissionValue($partner->commission_rate);
        $pctA = $rate === null ? '……%' : VietnameseNumber::percent($rate) . '%';
        $cycleText = match ((string) $partner->payment_cycle) {
            'weekly' => 'hằng tuần',
            'monthly' => 'hằng tháng',
            default => 'hai tuần một lần (nửa tháng)',
        };
        $overdueDays = sprintf('%02d', (int) config('settlement.overdue_days', 3));
        $contractNo = e((string) ($partner->contract_code ?: '………'));

        $html = [];
        $html[] = '<div class="hd" style="font-family:' . self::FONT . ';font-size:13pt;line-height:' . $lh . ';text-align:justify;color:#000;">';
        $html[] = $p($cfg['place'] . ', ngày ' . $now->format('d') . ' tháng ' . $now->format('m') . ' năm ' . $now->format('Y'), 'text-align:right;font-style:italic;');
        $html[] = '<p style="margin:0 0 6pt;text-align:center;font-size:18pt;font-weight:700;line-height:' . self::LH18 . ';">PHỤ LỤC HỢP ĐỒNG</p>';
        $html[] = '<p style="margin:0 0 6pt;text-align:center;font-weight:700;line-height:' . $lh . ';">Ký quỹ và thanh toán doanh thu — kèm Hợp đồng hợp tác kinh doanh số: ' . $contractNo . '</p>';
        $html[] = $p('Căn cứ Hợp đồng hợp tác kinh doanh đã ký giữa hai bên; hai bên thống nhất ký Phụ lục này như sau:', 'text-indent:36pt;');
        $html[] = $p('<strong>BÊN A: ' . e($a['name']) . '</strong> — đại diện: ' . e($a['representative']) . ', chức vụ: ' . e($a['position']) . '.');
        $html[] = $p("<strong>BÊN B: {$legalName}</strong> — đại diện: " . e(mb_strtoupper((string) $partner->representative_name)) . '.');

        $html[] = $h('ĐIỀU 1. SỬA ĐỔI CÁCH THANH TOÁN DOANH THU');
        $html[] = $p('1.1. Kể từ ngày Phụ lục có hiệu lực, tiền khách hàng thanh toán trực tuyến được chuyển trực tiếp vào tài khoản PayOS của Bên B (thanh toán tại quầy do Bên B thu); trước ngày đó Bên A tiếp tục thu hộ theo Hợp đồng.');
        $html[] = $p("1.2. Hoa hồng của Bên A là {$pctA} doanh thu của từng đơn đã hoàn thành (sau khi trừ khuyến mãi do Bên B chịu, trước khi trừ khuyến mãi do Bên A chịu). Hai bên đối soát {$cycleText}; Bên B nộp hoa hồng trong vòng {$overdueDays} ngày kể từ ngày Bên A gửi bảng đối soát, quá hạn Bên A tự trừ vào ký quỹ.");
        $html[] = $p('1.3. Khuyến mãi do Bên A tài trợ được Bên A bù lại cho Bên B bằng cách trừ vào hoa hồng phải nộp trong cùng kỳ đối soát (phần bù vượt hoa hồng được Bên A chi trả cho Bên B), không trừ vào ký quỹ. Bên A xuất hóa đơn giá trị gia tăng đối với phần hoa hồng theo từng kỳ; khoản ký quỹ không phải doanh thu nên không xuất hóa đơn.');
        $html[] = $p('1.4. Khoản tiền đảm bảo thực hiện hợp đồng nêu tại Hợp đồng (nếu có) được chuyển thành một phần ký quỹ theo Điều 2 Phụ lục này.');
        $html[] = $p('1.5. Các khoản tiền hoàn cho khách do lỗi của Bên B do Bên B hoàn trong vòng 24 giờ; quá thời hạn đó, Bên A hoàn thay cho khách và được trừ khoản đã ứng vào ký quỹ của Bên B.');

        foreach (self::escrowArticle($partner, 2, $p, $h, $bullet) as $line) {
            $html[] = $line;
        }

        $html[] = $h('ĐIỀU 3. HIỆU LỰC');
        $html[] = $p('Phụ lục có hiệu lực kể từ ngày Bên A ký xác nhận, sau khi Bên B xác nhận bằng mã OTP gửi về email đã đăng ký, và là phần không tách rời của Hợp đồng. Các nội dung khác của Hợp đồng không thay đổi.');
        $html[] = '</div>';

        return implode("\n", $html);
    }

    // Bản mẫu CŨ cho MiniHouse (luồng hợp đồng MiniHouse đang ẩn — xem config/partner_flow.php). Giữ nguyên để bật lại khi cần.
    private static function renderMinihouse(Partner $partner): string
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

                <p>Hai bên thống nhất ký kết hợp đồng hợp tác kinh doanh dịch vụ quản lý và vận hành nhà cho thuê dài hạn MiniHouse với các điều khoản sau:</p>

                <p><strong>Điều 1. Phí dịch vụ</strong><br>Bên A <strong>không thu hoa hồng</strong> trên các giao dịch của Bên B. Bên B chỉ thanh toán phí gói dịch vụ quản lý MiniHouse theo bảng giá hiện hành của Bên A (thanh toán theo kỳ 1, 3, 6, 9 hoặc 12 tháng).</p>

                <p><strong>Điều 2. Chính sách vận hành, chấm dứt hợp tác và xử lý công nợ</strong><br>
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

    // Bọc phần nội dung pháp lý (đã hash/lưu bất biến ở render() phía trên) bằng khung "quốc hiệu - tiêu ngữ" ở đầu + 2 ô ký tên ở cuối
    // (ĐẠI DIỆN BÊN A bên trái, ĐẠI DIỆN BÊN B bên phải, mỗi ô chiếm một nửa chiều rộng — đúng mẫu) — KHÔNG đưa phần khung này vào nội dung tính hash, vì trạng thái ký
    // thay đổi theo thời gian còn nội dung hợp đồng thì cố định từ lúc tạo. Dùng chung cho: xem trước (PartnerForm), popup "Xem toàn văn",
    // trang ký công khai và xuất PDF — để mọi nơi hiển thị đồng nhất.
    public static function renderFramed(string $bodyContent, Partner $partner, ?PartnerContractVersion $version): string
    {
        // Bên A ký bằng cách tải PDF về ký (USB / CKS): hiển thị đúng như hình chữ ký trong file — tên chủ thể chứng thư số và thời gian ký ghi trong chữ ký.
        // Ký tự động (hệ thống ký): bên ký là CÔNG TY (Bên A), không phải tài khoản nhân viên thao tác.
        $platformCert = (array) ($version?->platform_signature_certificate ?? []);
        $platformSignerName = ($version?->platform_sign_method === 'usb' && filled($platformCert['cert_subject'] ?? null))
            ? trim((string) $platformCert['cert_subject'] . (filled($platformCert['cert_org'] ?? null) ? ' — ' . $platformCert['cert_org'] : ''))
            : (string) config('contract.platform.name');
        $platformBox = self::renderSignatureBox(
            'ĐẠI DIỆN BÊN A',
            $version?->isPlatformSigned() ?? false,
            $platformSignerName,
            $version?->platform_signed_at?->format('d/m/Y H:i')
        );

        // Đối tác xác nhận qua OTP (chữ ký điện tử theo Luật GDĐT), KHÔNG dùng chứng thư số PKI riêng — ghi rõ "ĐÃ XÁC NHẬN QUA OTP".
        // Đối tác ký bằng chữ ký số (CKS) thì hiển thị đúng là "ĐÃ KÝ SỐ".
        $partnerBox = ($version?->isPartnerSignedByCks() ?? false)
            ? self::renderSignatureBox('ĐẠI DIỆN BÊN B', true, $version->partner_signed_by_name, $version->partner_confirmed_at?->format('d/m/Y H:i'))
            : self::renderConfirmationBox(
                'ĐẠI DIỆN BÊN B',
                $version?->isPartnerConfirmed() ?? false,
                $version?->partner_signed_by_name,
                $version?->partner_confirmed_at?->format('d/m/Y H:i')
            );

        $font = self::FONT;
        $lh = self::LH13;

        // Quốc hiệu / tiêu ngữ: chữ đậm 13pt căn giữa, có đường kẻ dưới tiêu ngữ, cách phần sau một dòng trống (đúng mẫu).
        return <<<HTML
            <div class="hd-wrap" style="font-family:{$font};color:#000;">
                <style>
                    @media print {
                        .hd-hint { display: none !important; }
                        .hd-sign { page-break-inside: avoid; }
                    }
                </style>
                <div style="text-align:center;padding-left:0.95pt;font-weight:700;font-size:13pt;line-height:{$lh};height:{$lh};">CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM</div>
                <div style="position:relative;text-align:center;padding-left:0.95pt;font-weight:700;font-size:13pt;line-height:{$lh};height:{$lh};">Độc lập - Tự do - Hạnh phúc<div style="position:absolute;left:50%;margin-left:-79.7pt;width:165pt;top:29.3pt;border-top:0.75pt solid #000;"></div></div>
                <div style="height:{$lh};line-height:{$lh};">&nbsp;</div>

                {$bodyContent}

                <div style="page-break-before:always;"></div>
                <table class="hd-sign" style="width:100%;border-collapse:collapse;table-layout:fixed;font-family:{$font};font-size:13pt;page-break-inside:avoid;">
                    <tr>
                        <td style="width:50%;height:133.6pt;vertical-align:top;text-align:center;padding:0 8pt 0 0;">{$platformBox}</td>
                        <td style="width:50%;height:133.6pt;vertical-align:top;text-align:center;padding:0 0 0 8pt;">{$partnerBox}</td>
                    </tr>
                </table>
            </div>
        HTML;
    }

    private static function renderSignatureBox(string $label, bool $signed, ?string $signerName, ?string $signedAt): string
    {
        $lh = self::LH13;
        $labelHtml = '<p style="margin:6pt 0;padding-left:0.95pt;text-align:center;font-weight:700;line-height:' . $lh . ';">' . $label . '</p>';

        if ($signed) {
            $name = e($signerName ?? '—');

            return $labelHtml . <<<HTML
                <div style="border:1px solid #2563eb;padding:8px;min-height:84pt;font-size:10pt;line-height:1.4;color:#2563eb;">
                    ĐÃ KÝ SỐ BỞI: {$name}<br>THỜI GIAN: {$signedAt}
                </div>
            HTML;
        }

        // Chưa ký: ô trống có khung nét đứt (cùng kích thước ô đã ký) — vùng để đặt hình chữ ký số khi ký.
        return $labelHtml . '<div style="border:1px dashed #9ca3af;min-height:100pt;"></div>';
    }

    // Dùng riêng cho phía Đối tác — xác nhận qua OTP, KHÔNG phải chữ ký số PKI nên không dùng wording "ĐÃ KÝ SỐ".
    private static function renderConfirmationBox(string $label, bool $confirmed, ?string $signerName, ?string $confirmedAt): string
    {
        $lh = self::LH13;
        $labelHtml = '<p style="margin:6pt 0;padding-left:0.95pt;text-align:center;font-weight:700;line-height:' . $lh . ';">' . $label . '</p>';

        if ($confirmed) {
            $name = e($signerName ?? '—');

            return $labelHtml . <<<HTML
                <div style="border:1px solid #0369a1;padding:8px;min-height:84pt;font-size:10pt;line-height:1.4;color:#0369a1;">
                    ĐÃ XÁC NHẬN QUA OTP BỞI: {$name}<br>THỜI GIAN: {$confirmedAt}
                </div>
            HTML;
        }

        // Chưa ký: ô trống có khung nét đứt (cùng kích thước ô đã ký) — vùng để đặt hình chữ ký số khi ký.
        return $labelHtml . '<div style="border:1px dashed #9ca3af;min-height:100pt;"></div>';
    }
}
