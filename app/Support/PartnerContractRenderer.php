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

    public static function render(Partner $partner): string
    {
        return $partner->isMinihouse() ? self::renderMinihouse($partner) : self::renderHomestay($partner);
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
        $pctB = $rate === null ? '……%  (………………………………………)' : VietnameseNumber::percent($rateB) . '% (' . VietnameseNumber::percentWords($rateB) . ')';

        $guarantee = VietnameseNumber::format((int) $cfg['guarantee_vnd']) . ' đồng (' . VietnameseNumber::money((int) $cfg['guarantee_vnd']) . ')';
        $violationFee = VietnameseNumber::format((int) $cfg['violation_fee_vnd']) . ' đồng';
        $penalty = (int) $cfg['penalty_percent'];

        // Điều 7: thời hạn tính theo ngày hết hạn trong hồ sơ (mặc định 12 tháng).
        $termMonths = (int) $cfg['default_term_months'];
        if ($partner->contract_expires_at) {
            $termMonths = max(1, (int) round(($partner->contract_expires_at->timestamp - $signed->timestamp) / 86400 / 30.44));
        }
        $termText = $partner->contract_expires_at
            ? sprintf('%02d tháng kể từ ngày ký (đến hết ngày %s)', $termMonths, $partner->contract_expires_at->format('d/m/Y'))
            : sprintf('%02d tháng kể từ ngày ký', $termMonths);

        $p = fn (string $text, string $extra = '') => '<p style="margin:0 0 6pt;' . $extra . '">' . $text . '</p>';
        $h = fn (string $text) => '<p style="margin:6pt 0;font-weight:700;">' . $text . '</p>';
        $indent = 'text-indent:1.27cm;';

        $html = [];
        $html[] = '<div class="hd" style="font-family:' . self::FONT . ';font-size:13pt;line-height:1.5;text-align:justify;color:#000;">';
        $html[] = $p($dateLine, 'text-align:right;font-style:italic;');
        $html[] = '<p style="margin:0 0 6pt;text-align:center;font-size:18pt;font-weight:700;line-height:1.3;">HỢP ĐỒNG HỢP TÁC KINH DOANH</p>';
        $html[] = $p('Số: ' . $dots($partner->contract_code, 20), 'text-align:center;');
        $html[] = $p('- Căn cứ Bộ luật Dân sự số 91/2015/QH13 ngày 24/11/2015;', $indent);
        $html[] = $p('- Căn cứ vào Luật Thương mại số 36/2005/QH11 ngày 14/06/2005;', $indent);
        $html[] = $p('- Căn cứ vào nhu cầu và sự thỏa thuận của các bên tham gia Hợp đồng;', $indent);
        $html[] = $p("Hôm nay, {$dateWords}, các Bên gồm:", $indent);

        // BÊN A
        $html[] = $p('<strong>BÊN A: ' . e($a['name']) . '</strong>', 'margin-top:6pt;');
        $html[] = $p('Người đại diện: ' . e($a['representative']) . ' &nbsp;&nbsp;&nbsp;&nbsp; Chức vụ: ' . e($a['position']));
        $html[] = $p('Địa chỉ: ' . e($a['address']));
        $html[] = $p('Điện thoại: ' . e($a['phone']) . ' &nbsp;&nbsp;&nbsp;&nbsp; Mã số thuế: ' . e($a['tax_code']));
        $html[] = $p('Tài khoản: ' . e($a['bank_account']) . ' tại ' . e($a['bank_name']));

        // BÊN B
        $html[] = $p("<strong>BÊN B: {$legalName}</strong>", 'margin-top:6pt;');
        $html[] = $p("Người đại diện: {$repName} &nbsp;&nbsp;&nbsp;&nbsp; Chức vụ: {$position}");
        $html[] = $p("CCCD số: {$idNumber} cấp ngày {$idDate} tại {$idPlace}");
        $html[] = $p("Số điện thoại: {$phone} &nbsp;&nbsp;&nbsp;&nbsp; Mã số thuế: {$tax}");
        $html[] = $p("Cơ sở lưu trú hợp tác: {$facilityName}");
        $html[] = $p("Địa chỉ cơ sở lưu trú: {$facilityAddress}");

        // Điều 1–3
        $html[] = $h('ĐIỀU 1. MỤC ĐÍCH VÀ NGUYÊN TẮC HỢP TÁC');
        $html[] = $p('1.1. Hai bên hợp tác khai thác, vận hành và kinh doanh dịch vụ lưu trú tại cơ sở của Bên B nhằm tối ưu công suất phòng, tăng doanh thu và phát triển thương hiệu.');
        $html[] = $p('1.2. Việc hợp tác được thực hiện trên nguyên tắc tự nguyện, minh bạch, bình đẳng và cùng có lợi, không làm ảnh hưởng đến uy tín và hoạt động kinh doanh của mỗi bên.');
        $html[] = $h('ĐIỀU 2. PHẠM VI VÀ NỘI DUNG HỢP TÁC');
        $html[] = $p('2.1. Bên A trực tiếp khai thác và bán phòng trên các nền tảng của 365 Home, bao gồm: xây dựng giá bán, quản lý kênh phân phối, triển khai marketing, quản lý đặt phòng và chăm sóc khách hàng.');
        $html[] = $p('2.2. Bên A được quyền sử dụng hình ảnh, video và thông tin cơ sở lưu trú của Bên B để phục vụ hoạt động quảng bá và kinh doanh trong thời gian hợp tác.');
        $html[] = $p('2.3. Bên B cung cấp cơ sở lưu trú đủ điều kiện kinh doanh, đảm bảo tiêu chuẩn vận hành và phối hợp xử lý các vấn đề liên quan trong quá trình hoạt động. Mọi thay đổi về giá và chiến lược kinh doanh phải được thống nhất với Bên A để đạt hiệu quả tốt nhất.');
        $html[] = $h('ĐIỀU 3. MÔ HÌNH VẬN HÀNH');
        $html[] = $p('3.1. Hai bên thống nhất áp dụng mô hình vận hành do Bên A triển khai.');
        $html[] = $p('3.2. Bên A thực hiện bán phòng và cung cấp các giải pháp vận hành trên nền tảng 365 Home nhằm tối ưu hiệu quả kinh doanh. Song song đó, Bên B vẫn khai thác và cho thuê phòng theo mô hình hiện có của cơ sở.');

        // Điều 4
        $html[] = $h('ĐIỀU 4. DOANH THU, QUẢN LÝ DOANH THU VÀ THANH TOÁN');
        $html[] = $p('4.1. Doanh thu hợp tác là khoản thu từ hoạt động kinh doanh lưu trú tại cơ sở của Bên B thông qua hệ thống của Bên A.');
        $html[] = $p("4.2. Khoản tiền đảm bảo thực hiện hợp đồng: Bên B thanh toán cho Bên A số tiền {$guarantee} trước khi bắt đầu hợp tác. Khoản tiền này được hoàn trả cho Bên B khi Hợp đồng chấm dứt và hai bên hoàn tất các nghĩa vụ liên quan. Trường hợp Bên B phát sinh nghĩa vụ thanh toán hoặc bồi thường do vi phạm Hợp đồng, Bên A được quyền khấu trừ vào khoản tiền này.");
        $html[] = $p('4.3. Tỷ lệ phân chia doanh thu: Tiền khách hàng thanh toán được chuyển trực tiếp vào tài khoản của Bên B. Hai bên thực hiện đối soát doanh thu theo tỷ lệ:');
        $html[] = $p("Bên A: {$pctA}");
        $html[] = $p("Bên B: {$pctB}");
        $html[] = $p("Bên B có trách nhiệm thanh toán phần doanh thu {$pctA} thuộc Bên A trong vòng 03 ngày kể từ ngày hoàn tất đối soát.");
        $html[] = $p('4.4. Mỗi bên tự chịu trách nhiệm xuất hóa đơn và thực hiện nghĩa vụ thuế đối với phần doanh thu thuộc trách nhiệm của mình. Hai bên có trách nhiệm phối hợp cung cấp thông tin, chứng từ cần thiết để thực hiện việc đối soát và xuất hóa đơn.');

        // Điều 5
        $html[] = $h('ĐIỀU 5. VI PHẠM HỢP ĐỒNG');
        $html[] = $p('5.1. Mỗi bên được quyền khai thác nguồn khách riêng nhưng không được can thiệp, ảnh hưởng hoặc xâm phạm đến nguồn khách, hệ thống vận hành và quyền lợi hợp pháp của bên còn lại.');
        $html[] = $p('5.2. Các hành vi, vi phạm của Bên B gồm:');
        foreach ([
            '- Chậm thanh toán, cung cấp sai số liệu hoặc không thực hiện đúng nghĩa vụ thanh toán;',
            '- Tự ý khai thác, chuyển hướng hoặc tiếp nhận khách thuộc hệ thống của Bên A;',
            '- Tự ý thay đổi giá phòng, tình trạng phòng hoặc thông tin trên các kênh của Bên A;',
            '- Từ chối khách đã xác nhận đặt phòng hợp lệ hoặc gây gián đoạn hoạt động kinh doanh;',
            '- Không cập nhật chính xác tình trạng phòng dẫn đến sai lệch hoặc vượt số lượng phòng;',
            '- Tự ý sử dụng dữ liệu, nguồn khách hoặc hệ thống hợp tác mà không có sự đồng ý của Bên A.',
        ] as $line) {
            $html[] = $p($line);
        }
        $html[] = $p("- Bên B có trách nhiệm phối hợp với Bên A trong việc bán phòng, tiếp nhận và phục vụ khách theo booking đã xác nhận. Trường hợp Bên B không tuân thủ, làm ảnh hưởng đến việc nhận phòng hoặc trải nghiệm của khách do lỗi của Bên B, Bên B bị trừ {$violationFee}/lần vi phạm vào doanh thu.");
        $html[] = $p('5.3. Các hành vi, vi phạm của Bên A gồm:');
        foreach ([
            '- Không minh bạch trong quản lý, đối soát hoặc báo cáo doanh thu;',
            '- Vận hành không đảm bảo chất lượng dịch vụ, gây ảnh hưởng đến uy tín cơ sở lưu trú;',
            '- Thực hiện sai quy trình dẫn đến khiếu nại nghiêm trọng từ khách hàng.',
        ] as $line) {
            $html[] = $p($line);
        }
        $html[] = $p('5.4. Bên vi phạm có trách nhiệm khắc phục hậu quả, điều chỉnh số liệu (nếu có) và bồi thường thiệt hại thực tế phát sinh. Hai bên cam kết không tự ý khai thác hoặc sử dụng dữ liệu khách hàng của nhau nếu chưa được đồng ý bằng văn bản.');

        // Điều 6
        $html[] = $h('ĐIỀU 6. HOÀN TIỀN, KHIẾU NẠI VÀ XỬ LÝ SỰ CỐ');
        $html[] = $p('6.1. Trách nhiệm xử lý phát sinh:');
        $html[] = $p('- Trường hợp phát sinh do lỗi từ cơ sở của Bên B như phòng không đúng mô tả, không đảm bảo vệ sinh, thiết bị hư hỏng, không giao đúng phòng hoặc đúng tình trạng đã xác nhận, Bên A có quyền chủ động hỗ trợ hoặc hoàn tiền cho khách. Các khoản tiền đã hoàn cho khách hàng do lỗi của Bên B sẽ do Bên B chịu trách nhiệm thanh toán và được trừ vào phần doanh thu của Bên B trong kỳ đối soát gần nhất.');
        $html[] = $p('- Trường hợp phát sinh do lỗi vận hành của Bên A như sai booking, đặt nhầm phòng, lỗi hệ thống hoặc chăm sóc khách hàng, Bên A tự chịu trách nhiệm hoàn tiền và không khấu trừ vào doanh thu của Bên B.');
        $html[] = $p('- Trường hợp bất khả kháng hoặc khách hủy phòng vì lý do cá nhân sẽ áp dụng theo chính sách hủy phòng của Bên A.');
        $html[] = $p('6.2. Hình thức xử lý có thể bao gồm: hoàn tiền một phần, hoàn toàn bộ hoặc đổi phòng tương đương. Bên A được quyền chủ động lựa chọn phương án phù hợp nhằm đảm bảo trải nghiệm khách hàng và uy tín kinh doanh.');
        $html[] = $p('6.3. Tất cả các khoản hoàn tiền phải được ghi nhận rõ trong báo cáo doanh thu, kèm lý do và căn cứ xử lý. Bên B có quyền kiểm tra, đối chiếu thông tin khi cần thiết.');
        $html[] = $p('6.4. Trường hợp các lỗi từ phía Bên B dẫn đến hoàn tiền từ 05 lần trở lên trong 01 tháng hoặc từ 09 lần trở lên trong 03 tháng liên tiếp thì được xem là vi phạm nghiêm trọng hợp đồng. Khi đó, Bên A có quyền yêu cầu khắc phục trong vòng 05 ngày hoặc đơn phương chấm dứt hợp đồng mà không phải bồi thường.');
        $html[] = $p('6.5. Các trường hợp hoàn tiền phải có căn cứ xác định nguyên nhân như phản hồi khách hàng, hình ảnh thực tế hoặc dữ liệu hệ thống. Dữ liệu từ hệ thống quản lý booking của Bên A được sử dụng làm căn cứ đối chiếu khi có tranh chấp.');

        // Điều 7–8
        $html[] = $h('ĐIỀU 7. THỜI HẠN HỢP ĐỒNG');
        $html[] = $p("- Hợp đồng có thời hạn {$termText}. Khi hết thời hạn, nếu không bên nào thông báo chấm dứt bằng văn bản trước ít nhất 01 ngày thì hợp đồng được tự động gia hạn thêm {$cfg['default_term_months']} tháng với các điều khoản không thay đổi, trừ trường hợp các bên có thỏa thuận khác.");
        $html[] = $h('ĐIỀU 8. CHẤM DỨT HỢP ĐỒNG');
        $html[] = $p('8.1. Hợp đồng chấm dứt khi:');
        foreach ([
            '- Hai bên thỏa thuận bằng văn bản;',
            '- Một bên vi phạm nghiêm trọng nghĩa vụ và không khắc phục trong vòng 05 ngày kể từ khi nhận thông báo;',
            '- Một bên đơn phương chấm dứt hợp đồng và thông báo trước ít nhất 01 ngày;',
            '- Xảy ra sự kiện bất khả kháng kéo dài quá 07 ngày làm hợp đồng không thể tiếp tục thực hiện.',
        ] as $line) {
            $html[] = $p($line);
        }
        $html[] = $p('8.2. Khi chấm dứt hợp đồng:');
        foreach ([
            '- Hai bên hoàn tất đối soát và thanh toán trong vòng 07 ngày;',
            '- Bên A ngừng khai thác các kênh liên quan đến Bên B;',
            '- Các bên không được tiếp tục sử dụng hình ảnh, thương hiệu, dữ liệu khách hàng hoặc tài nguyên vận hành của bên còn lại nếu chưa có sự đồng ý bằng văn bản.',
        ] as $line) {
            $html[] = $p($line);
        }

        // Điều 9–10
        $html[] = $h('ĐIỀU 9. BẢO MẬT THÔNG TIN VÀ DỮ LIỆU KHÁCH HÀNG');
        $html[] = $p('9.1. Hai bên cam kết bảo mật toàn bộ thông tin liên quan đến hoạt động hợp tác như: dữ liệu khách hàng, doanh thu, giá bán, chiến lược kinh doanh, hệ thống vận hành và các thông tin nội bộ khác.');
        $html[] = $p('9.2. Không bên nào được tự ý sao chép, cung cấp, chuyển giao hoặc sử dụng dữ liệu khách hàng của bên còn lại ngoài phạm vi hợp tác nếu chưa có sự đồng ý bằng văn bản.');
        $html[] = $p('9.3. Trong và sau thời gian hợp tác, các bên không được sử dụng dữ liệu khách hàng phát sinh từ hợp tác để khai thác riêng hoặc cung cấp cho bên thứ ba.');
        $html[] = $p('9.4. Bên vi phạm nghĩa vụ bảo mật phải bồi thường toàn bộ thiệt hại thực tế phát sinh.');
        $html[] = $p('9.5. Nghĩa vụ bảo mật có hiệu lực trong vòng 02 năm kể từ ngày hợp đồng chấm dứt.');
        $html[] = $h('ĐIỀU 10. KHÔNG CẠNH TRANH');
        $html[] = $p('10.1. Trong thời gian hợp tác, các bên cam kết không thực hiện các hành vi cạnh tranh trực tiếp gây ảnh hưởng đến hoạt động kinh doanh của bên còn lại.');
        $html[] = $p('10.2. Bên B không được tự ý tiếp cận, khai thác lại hoặc chuyển đổi nguồn khách hàng do Bên A vận hành, quảng bá hoặc quản lý thông qua các kênh bán hàng thuộc hệ thống của Bên A.');
        $html[] = $p('10.3. Bên A không được sử dụng thương hiệu, hình ảnh hoặc thông tin nội bộ của Bên B cho mục đích ngoài phạm vi hợp tác nếu chưa được chấp thuận bằng văn bản.');
        $html[] = $p('10.4. Trường hợp vi phạm điều khoản này, bên vi phạm phải chấm dứt ngay hành vi vi phạm và bồi thường toàn bộ thiệt hại phát sinh cho bên còn lại.');

        // Điều 11–15
        $html[] = $h('ĐIỀU 11. SỰ KIỆN BẤT KHẢ KHÁNG');
        $html[] = $p('11.1. Sự kiện bất khả kháng gồm các trường hợp ngoài khả năng kiểm soát của các bên như: thiên tai, hỏa hoạn, dịch bệnh, chiến tranh, sự cố hệ thống, mất điện diện rộng, quyết định của cơ quan nhà nước hoặc các sự kiện khách quan khác làm một bên không thể thực hiện hợp đồng.');
        $html[] = $p('11.2. Bên gặp sự kiện bất khả kháng phải thông báo cho bên còn lại trong vòng 05 ngày và cung cấp tài liệu chứng minh phù hợp.');
        $html[] = $p('11.3. Trong thời gian xảy ra bất khả kháng, các bên phối hợp hạn chế thiệt hại và tìm biện pháp khắc phục phù hợp.');
        $html[] = $p('11.4. Nếu sự kiện bất khả kháng kéo dài quá 30 ngày liên tục làm mục đích hợp tác không thể tiếp tục, một trong hai bên có quyền chấm dứt hợp đồng mà không bị phạt vi phạm.');
        $html[] = $h('ĐIỀU 12. PHẠT VI PHẠM HỢP ĐỒNG');
        $html[] = $p("- Bên vi phạm nghĩa vụ hợp đồng gây thiệt hại phải bồi thường toàn bộ thiệt hại thực tế phát sinh. Ngoài bồi thường, bên vi phạm còn chịu phạt {$penalty}% giá trị phần nghĩa vụ bị vi phạm.");
        $html[] = $h('ĐIỀU 13. XỬ LÝ TÀI KHOẢN KHI CHẤM DỨT HỢP ĐỒNG');
        $html[] = $p('- Khi chấm dứt hợp đồng, các bên phối hợp bàn giao hoặc ngừng sử dụng các Fanpage, Website, Zalo và nền tảng vận hành theo phạm vi quản lý của từng bên.');
        $html[] = $p('- Các tài khoản do Bên A tạo lập và quản lý thuộc quyền quản trị của Bên A, trừ khi có thỏa thuận khác bằng văn bản.');
        $html[] = $p('- Bên B không được tự ý đổi mật khẩu, chuyển quyền quản trị hoặc can thiệp vào hệ thống do Bên A thiết lập.');
        $html[] = $p('- Sau khi chấm dứt hợp đồng, các bên không được sử dụng thương hiệu, hình ảnh, nội dung truyền thông hoặc dữ liệu vận hành của bên còn lại nếu chưa có sự đồng ý bằng văn bản.');
        $html[] = $h('ĐIỀU 14. GIẢI QUYẾT TRANH CHẤP');
        $html[] = $p('- Mọi tranh chấp phát sinh từ hợp đồng sẽ được ưu tiên giải quyết bằng thương lượng và hòa giải giữa các bên. Nếu không đạt thỏa thuận, tranh chấp sẽ được giải quyết tại Tòa án có thẩm quyền và quyết định của Tòa án là cuối cùng, có giá trị ràng buộc các bên.');
        $html[] = $h('ĐIỀU 15. HIỆU LỰC HỢP ĐỒNG');
        $html[] = $p('- Hợp đồng có hiệu lực kể từ ngày ký. Hợp đồng được lập thành 02 bản gốc có giá trị pháp lý như nhau, mỗi bên giữ 01 bản.');
        $html[] = $p('- Các phụ lục kèm theo (nếu có) là phần không tách rời và có giá trị pháp lý tương đương hợp đồng.');
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
    // (ĐẠI DIỆN BÊN A bên trái, ĐẠI DIỆN BÊN B bên phải — đúng mẫu) — KHÔNG đưa phần khung này vào nội dung tính hash, vì trạng thái ký
    // thay đổi theo thời gian còn nội dung hợp đồng thì cố định từ lúc tạo. Dùng chung cho: xem trước (PartnerForm), popup "Xem toàn văn",
    // trang ký công khai và xuất PDF — để mọi nơi hiển thị đồng nhất.
    public static function renderFramed(string $bodyContent, Partner $partner, ?PartnerContractVersion $version): string
    {
        $platformBox = self::renderSignatureBox(
            'ĐẠI DIỆN BÊN A',
            $version?->isPlatformSigned() ?? false,
            $version?->platformSignedBy?->fullname,
            $version?->platform_signed_at?->format('d/m/Y H:i')
        );

        // Đối tác xác nhận qua OTP (chữ ký điện tử theo Luật GDĐT), KHÔNG dùng chứng thư số PKI riêng — ghi rõ "ĐÃ XÁC NHẬN QUA OTP".
        $partnerBox = self::renderConfirmationBox(
            'ĐẠI DIỆN BÊN B',
            $version?->isPartnerConfirmed() ?? false,
            $version?->partner_signed_by_name,
            $version?->partner_confirmed_at?->format('d/m/Y H:i')
        );

        $font = self::FONT;

        return <<<HTML
            <div class="hd-wrap" style="font-family:{$font};color:#000;">
                <style>
                    @media print {
                        .hd-hint { display: none !important; }
                        .hd-sign { page-break-inside: avoid; }
                    }
                </style>
                <div style="text-align:center;margin-bottom:10pt;line-height:1.5;">
                    <div style="font-weight:700;font-size:13pt;">CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM</div>
                    <div style="font-weight:700;font-size:13pt;border-bottom:1px solid #000;display:inline-block;padding-bottom:1px;">Độc lập - Tự do - Hạnh phúc</div>
                </div>

                {$bodyContent}

                <table class="hd-sign" style="width:100%;margin-top:16pt;border-collapse:collapse;font-family:{$font};font-size:13pt;">
                    <tr>
                        <td style="width:50%;text-align:center;padding-right:8px;vertical-align:top;">{$platformBox}</td>
                        <td style="width:50%;text-align:center;padding-left:8px;vertical-align:top;">{$partnerBox}</td>
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
                <div style="font-weight:700;margin-bottom:2pt;">{$label}</div>
                <div style="font-size:11pt;font-style:italic;margin-bottom:6pt;">(Đã ký số điện tử)</div>
                <div style="border:1px solid #2563eb;padding:8px;font-size:10pt;color:#2563eb;">
                    ĐÃ KÝ SỐ BỞI: {$name}<br>THỜI GIAN: {$signedAt}
                </div>
            HTML;
        }

        return <<<HTML
            <div style="font-weight:700;margin-bottom:2pt;">{$label}</div>
            <div style="font-size:11pt;font-style:italic;margin-bottom:6pt;">(Ký, ghi rõ họ tên, đóng dấu)</div>
            <div style="height:84px;"><span class="hd-hint" style="font-size:10pt;color:#9ca3af;">Chưa ký</span></div>
        HTML;
    }

    // Dùng riêng cho phía Đối tác — xác nhận qua OTP, KHÔNG phải chữ ký số PKI nên không dùng wording "ĐÃ KÝ SỐ".
    private static function renderConfirmationBox(string $label, bool $confirmed, ?string $signerName, ?string $confirmedAt): string
    {
        if ($confirmed) {
            $name = e($signerName ?? '—');

            return <<<HTML
                <div style="font-weight:700;margin-bottom:2pt;">{$label}</div>
                <div style="font-size:11pt;font-style:italic;margin-bottom:6pt;">(Đã xác nhận qua email OTP)</div>
                <div style="border:1px solid #0369a1;padding:8px;font-size:10pt;color:#0369a1;">
                    ĐÃ XÁC NHẬN BỞI: {$name}<br>THỜI GIAN: {$confirmedAt}
                </div>
            HTML;
        }

        return <<<HTML
            <div style="font-weight:700;margin-bottom:2pt;">{$label}</div>
            <div style="font-size:11pt;font-style:italic;margin-bottom:6pt;">(Ký, ghi rõ họ tên)</div>
            <div style="height:84px;"><span class="hd-hint" style="font-size:10pt;color:#9ca3af;">Chưa xác nhận</span></div>
        HTML;
    }
}
