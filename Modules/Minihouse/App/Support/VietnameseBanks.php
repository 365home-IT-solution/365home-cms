<?php

namespace Modules\Minihouse\App\Support;

// Danh sách ngân hàng/ví theo chuẩn Napas/VietQR — lấy từ API công khai https://api.vietqr.io/v2/banks
// (chốt cứng thành mảng tĩnh ngày 07/09/2026, KHÔNG gọi API mỗi lần dùng, vì danh sách này gần như
// không đổi và cần chọn được cả khi không có mạng). 'bin' LÀ khoá của mảng — dùng trực tiếp trong URL
// ảnh QR https://img.vietqr.io/image/{bin}-{soTaiKhoan}-{template}.png (xem
// Modules\Minihouse\App\Services\VietQrService) — không cho nhập tay để tránh gõ sai mã khiến khách
// quét QR không ra đúng ngân hàng.
class VietnameseBanks
{
    private const BANKS = [
            '970415' => ['short' => 'VietinBank', 'name' => 'Ngân hàng TMCP Công thương Việt Nam', 'code' => 'ICB'],
            '970436' => ['short' => 'Vietcombank', 'name' => 'Ngân hàng TMCP Ngoại Thương Việt Nam', 'code' => 'VCB'],
            '970418' => ['short' => 'BIDV', 'name' => 'Ngân hàng TMCP Đầu tư và Phát triển Việt Nam', 'code' => 'BIDV'],
            '970405' => ['short' => 'Agribank', 'name' => 'Ngân hàng Nông nghiệp và Phát triển Nông thôn Việt Nam', 'code' => 'VBA'],
            '970448' => ['short' => 'OCB', 'name' => 'Ngân hàng TMCP Phương Đông', 'code' => 'OCB'],
            '970422' => ['short' => 'MBBank', 'name' => 'Ngân hàng TMCP Quân đội', 'code' => 'MB'],
            '970407' => ['short' => 'Techcombank', 'name' => 'Ngân hàng TMCP Kỹ thương Việt Nam', 'code' => 'TCB'],
            '970416' => ['short' => 'ACB', 'name' => 'Ngân hàng TMCP Á Châu', 'code' => 'ACB'],
            '970432' => ['short' => 'VPBank', 'name' => 'Ngân hàng TMCP Việt Nam Thịnh Vượng', 'code' => 'VPB'],
            '970423' => ['short' => 'TPBank', 'name' => 'Ngân hàng TMCP Tiên Phong', 'code' => 'TPB'],
            '970403' => ['short' => 'Sacombank', 'name' => 'Ngân hàng TMCP Sài Gòn Thương Tín', 'code' => 'STB'],
            '970437' => ['short' => 'HDBank', 'name' => 'Ngân hàng TMCP Phát triển Thành phố Hồ Chí Minh', 'code' => 'HDB'],
            '970454' => ['short' => 'VietCapitalBank', 'name' => 'Ngân hàng TMCP Bản Việt', 'code' => 'VCCB'],
            '970429' => ['short' => 'SCB', 'name' => 'Ngân hàng TMCP Sài Gòn', 'code' => 'SCB'],
            '970441' => ['short' => 'VIB', 'name' => 'Ngân hàng TMCP Quốc tế Việt Nam', 'code' => 'VIB'],
            '970443' => ['short' => 'SHB', 'name' => 'Ngân hàng TMCP Sài Gòn - Hà Nội', 'code' => 'SHB'],
            '970431' => ['short' => 'Eximbank', 'name' => 'Ngân hàng TMCP Xuất Nhập khẩu Việt Nam', 'code' => 'EIB'],
            '970426' => ['short' => 'MSB', 'name' => 'Ngân hàng TMCP Hàng Hải Việt Nam', 'code' => 'MSB'],
            '546034' => ['short' => 'CAKE', 'name' => 'TMCP Việt Nam Thịnh Vượng - Ngân hàng số CAKE by VPBank', 'code' => 'CAKE'],
            '546035' => ['short' => 'Ubank', 'name' => 'TMCP Việt Nam Thịnh Vượng - Ngân hàng số Ubank by VPBank', 'code' => 'Ubank'],
            '971005' => ['short' => 'ViettelMoney', 'name' => 'Tổng Công ty Dịch vụ số Viettel - Chi nhánh tập đoàn công nghiệp viễn thông Quân Đội', 'code' => 'VTLMONEY'],
            '963388' => ['short' => 'Timo', 'name' => 'Ngân hàng số Timo by Ban Viet Bank (Timo by Ban Viet Bank)', 'code' => 'TIMO'],
            '971011' => ['short' => 'VNPTMoney', 'name' => 'VNPT Money', 'code' => 'VNPTMONEY'],
            '970400' => ['short' => 'SaigonBank', 'name' => 'Ngân hàng TMCP Sài Gòn Công Thương', 'code' => 'SGICB'],
            '970409' => ['short' => 'BacABank', 'name' => 'Ngân hàng TMCP Bắc Á', 'code' => 'BAB'],
            '971025' => ['short' => 'MoMo', 'name' => 'CTCP Dịch Vụ Di Động Trực Tuyến', 'code' => 'momo'],
            '971133' => ['short' => 'PVcomBank Pay', 'name' => 'Ngân hàng TMCP Đại Chúng Việt Nam Ngân hàng số', 'code' => 'PVDB'],
            '970412' => ['short' => 'PVcomBank', 'name' => 'Ngân hàng TMCP Đại Chúng Việt Nam', 'code' => 'PVCB'],
            '970414' => ['short' => 'MBV', 'name' => 'Ngân hàng TNHH MTV Việt Nam Hiện Đại', 'code' => 'MBV'],
            '970419' => ['short' => 'NCB', 'name' => 'Ngân hàng TMCP Quốc Dân', 'code' => 'NCB'],
            '970424' => ['short' => 'ShinhanBank', 'name' => 'Ngân hàng TNHH MTV Shinhan Việt Nam', 'code' => 'SHBVN'],
            '970425' => ['short' => 'ABBANK', 'name' => 'Ngân hàng TMCP An Bình', 'code' => 'ABB'],
            '970427' => ['short' => 'VietABank', 'name' => 'Ngân hàng TMCP Việt Á', 'code' => 'VAB'],
            '970428' => ['short' => 'NamABank', 'name' => 'Ngân hàng TMCP Nam Á', 'code' => 'NAB'],
            '970430' => ['short' => 'PGBank', 'name' => 'Ngân hàng TMCP Thịnh vượng và Phát triển', 'code' => 'PGB'],
            '970433' => ['short' => 'VietBank', 'name' => 'Ngân hàng TMCP Việt Nam Thương Tín', 'code' => 'VIETBANK'],
            '970438' => ['short' => 'BaoVietBank', 'name' => 'Ngân hàng TMCP Bảo Việt', 'code' => 'BVB'],
            '970440' => ['short' => 'SeABank', 'name' => 'Ngân hàng TMCP Đông Nam Á', 'code' => 'SEAB'],
            '970446' => ['short' => 'COOPBANK', 'name' => 'Ngân hàng Hợp tác xã Việt Nam', 'code' => 'COOPBANK'],
            '970449' => ['short' => 'LPBank', 'name' => 'Ngân hàng TMCP Lộc Phát Việt Nam', 'code' => 'LPB'],
            '970452' => ['short' => 'KienLongBank', 'name' => 'Ngân hàng TMCP Kiên Long', 'code' => 'KLB'],
            '668888' => ['short' => 'KBank', 'name' => 'Ngân hàng Đại chúng TNHH Kasikornbank', 'code' => 'KBank'],
            '977777' => ['short' => 'MAFC', 'name' => 'Công ty Tài chính TNHH MTV Mirae Asset (Việt Nam) ', 'code' => 'MAFC'],
            '970442' => ['short' => 'HongLeong', 'name' => 'Ngân hàng TNHH MTV Hong Leong Việt Nam', 'code' => 'HLBVN'],
            '970467' => ['short' => 'KEBHANAHN', 'name' => 'Ngân hàng KEB Hana – Chi nhánh Hà Nội', 'code' => 'KEBHANAHN'],
            '970466' => ['short' => 'KEBHanaHCM', 'name' => 'Ngân hàng KEB Hana – Chi nhánh Thành phố Hồ Chí Minh', 'code' => 'KEBHANAHCM'],
            '533948' => ['short' => 'Citibank', 'name' => 'Ngân hàng Citibank, N.A. - Chi nhánh Hà Nội', 'code' => 'CITIBANK'],
            '970444' => ['short' => 'CBBank', 'name' => 'Ngân hàng Thương mại TNHH MTV Xây dựng Việt Nam', 'code' => 'CBB'],
            '422589' => ['short' => 'CIMB', 'name' => 'Ngân hàng TNHH MTV CIMB Việt Nam', 'code' => 'CIMB'],
            '796500' => ['short' => 'DBSBank', 'name' => 'DBS Bank Ltd - Chi nhánh Thành phố Hồ Chí Minh', 'code' => 'DBS'],
            '970406' => ['short' => 'Vikki', 'name' => 'Ngân hàng TNHH MTV Số Vikki', 'code' => 'Vikki'],
            '999888' => ['short' => 'VBSP', 'name' => 'Ngân hàng Chính sách Xã hội', 'code' => 'VBSP'],
            '970408' => ['short' => 'GPBank', 'name' => 'Ngân hàng Thương mại TNHH MTV Dầu Khí Toàn Cầu', 'code' => 'GPB'],
            '970463' => ['short' => 'KookminHCM', 'name' => 'Ngân hàng Kookmin - Chi nhánh Thành phố Hồ Chí Minh', 'code' => 'KBHCM'],
            '970462' => ['short' => 'KookminHN', 'name' => 'Ngân hàng Kookmin - Chi nhánh Hà Nội', 'code' => 'KBHN'],
            '970457' => ['short' => 'Woori', 'name' => 'Ngân hàng TNHH MTV Woori Việt Nam', 'code' => 'WVN'],
            '970421' => ['short' => 'VRB', 'name' => 'Ngân hàng Liên doanh Việt - Nga', 'code' => 'VRB'],
            '458761' => ['short' => 'HSBC', 'name' => 'Ngân hàng TNHH MTV HSBC (Việt Nam)', 'code' => 'HSBC'],
            '970455' => ['short' => 'IBKHN', 'name' => 'Ngân hàng Công nghiệp Hàn Quốc - Chi nhánh Hà Nội', 'code' => 'IBK - HN'],
            '970456' => ['short' => 'IBKHCM', 'name' => 'Ngân hàng Công nghiệp Hàn Quốc - Chi nhánh TP. Hồ Chí Minh', 'code' => 'IBK - HCM'],
            '970434' => ['short' => 'IndovinaBank', 'name' => 'Ngân hàng TNHH Indovina', 'code' => 'IVB'],
            '970458' => ['short' => 'UnitedOverseas', 'name' => 'Ngân hàng United Overseas - Chi nhánh TP. Hồ Chí Minh', 'code' => 'UOB'],
            '801011' => ['short' => 'Nonghyup', 'name' => 'Ngân hàng Nonghyup - Chi nhánh Hà Nội', 'code' => 'NHB HN'],
            '970410' => ['short' => 'StandardChartered', 'name' => 'Ngân hàng TNHH MTV Standard Chartered Bank Việt Nam', 'code' => 'SCVN'],
            '970439' => ['short' => 'PublicBank', 'name' => 'Ngân hàng TNHH MTV Public Việt Nam', 'code' => 'PBVN'],
    ];

    /**
     * @return array<string, string> bin => "Tên ngắn — Tên đầy đủ", dùng thẳng cho Select::options().
     */
    public static function options(): array
    {
        return collect(self::BANKS)
            ->sortBy('short')
            ->map(fn (array $bank) => $bank['short'] . ' — ' . $bank['name'])
            ->all();
    }

    public static function shortName(?string $bin): ?string
    {
        return self::BANKS[$bin]['short'] ?? null;
    }

    public static function exists(?string $bin): bool
    {
        return $bin !== null && isset(self::BANKS[$bin]);
    }
}
