<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Kiểm tra tính hợp lệ của dữ liệu đọc từ QR CCCD (cccd_data).
 *
 * 6 số đầu của số CCCD 12 chữ số (vd 087204016918):
 *   - 3 số đầu (087) = mã tỉnh nơi đăng ký khai sinh (Thông tư 07/2016/TT-BCA) → Đồng Tháp
 *   - số thứ 4  (2)  = thế kỷ sinh + giới tính: 0/1 = thế kỷ 20 nam/nữ, 2/3 = thế kỷ 21, ...
 *   - số 5–6   (04)  = 2 số cuối năm sinh → 2004
 * Đối chiếu từng phần với ngày sinh + giới tính trong chính QR để loại QR tự tạo/bịa số.
 */
class CccdIdentity
{
    // Dưới 16 tuổi (< 16) → không được đặt/lưu trú: người đặt chính ở MỌI loại khung (khung giờ
    // ngày, khung qua đêm, theo ngày); người đi cùng (chỉ thu CCCD khi có qua đêm).
    public const MIN_AGE = 16;

    public const PROVINCES = [
        '001' => 'Hà Nội', '002' => 'Hà Giang', '004' => 'Cao Bằng', '006' => 'Bắc Kạn',
        '008' => 'Tuyên Quang', '010' => 'Lào Cai', '011' => 'Điện Biên', '012' => 'Lai Châu',
        '014' => 'Sơn La', '015' => 'Yên Bái', '017' => 'Hòa Bình', '019' => 'Thái Nguyên',
        '020' => 'Lạng Sơn', '022' => 'Quảng Ninh', '024' => 'Bắc Giang', '025' => 'Phú Thọ',
        '026' => 'Vĩnh Phúc', '027' => 'Bắc Ninh', '030' => 'Hải Dương', '031' => 'Hải Phòng',
        '033' => 'Hưng Yên', '034' => 'Thái Bình', '035' => 'Hà Nam', '036' => 'Nam Định',
        '037' => 'Ninh Bình', '038' => 'Thanh Hóa', '040' => 'Nghệ An', '042' => 'Hà Tĩnh',
        '044' => 'Quảng Bình', '045' => 'Quảng Trị', '046' => 'Thừa Thiên Huế', '048' => 'Đà Nẵng',
        '049' => 'Quảng Nam', '051' => 'Quảng Ngãi', '052' => 'Bình Định', '054' => 'Phú Yên',
        '056' => 'Khánh Hòa', '058' => 'Ninh Thuận', '060' => 'Bình Thuận', '062' => 'Kon Tum',
        '064' => 'Gia Lai', '066' => 'Đắk Lắk', '067' => 'Đắk Nông', '068' => 'Lâm Đồng',
        '070' => 'Bình Phước', '072' => 'Tây Ninh', '074' => 'Bình Dương', '075' => 'Đồng Nai',
        '077' => 'Bà Rịa - Vũng Tàu', '079' => 'TP. Hồ Chí Minh', '080' => 'Long An', '082' => 'Tiền Giang',
        '083' => 'Bến Tre', '084' => 'Trà Vinh', '086' => 'Vĩnh Long', '087' => 'Đồng Tháp',
        '089' => 'An Giang', '091' => 'Kiên Giang', '092' => 'Cần Thơ', '093' => 'Hậu Giang',
        '094' => 'Sóc Trăng', '095' => 'Bạc Liêu', '096' => 'Cà Mau',
    ];

    /**
     * Trả về thông báo lỗi (tiếng Việt, hiển thị cho khách) hoặc null nếu dữ liệu hợp lệ.
     */
    public static function validate(?array $data, bool $requireQr = true): ?string
    {
        if (! $data || ($requireQr && ($data['source'] ?? null) !== 'qr')) {
            return 'Không đọc được mã QR trên CCCD. Vui lòng chụp rõ nét mặt có mã QR, không chụp lại màn hình.';
        }

        $number = (string) ($data['cccd'] ?? '');
        $name   = trim((string) ($data['full_name'] ?? ''));
        $dob    = self::parseDate($data['dob'] ?? null);

        if (! preg_match('/^\d{12}$/', $number) || $name === '' || ! $dob) {
            return 'Mã QR không chứa đủ thông tin CCCD. Vui lòng chụp lại mặt có mã QR của CCCD gắn chip.';
        }

        if ($dob->isFuture() || $dob->age > 120) {
            return 'Ngày sinh trên CCCD không hợp lệ.';
        }

        // 3 số đầu — mã tỉnh nơi đăng ký khai sinh.
        if (! self::birthProvince($number)) {
            return 'Số CCCD không hợp lệ: 3 số đầu (' . substr($number, 0, 3) . ') không phải mã tỉnh/thành phố.';
        }

        // Số thứ 4 — thế kỷ sinh (0/1 → 19xx, 2/3 → 20xx, 4/5 → 21xx...).
        $centuryGender = (int) $number[3];
        if (intdiv($centuryGender, 2) + 19 !== intdiv($dob->year, 100)) {
            return "Số CCCD không hợp lệ: số thứ 4 ({$centuryGender}) không khớp thế kỷ của năm sinh {$dob->year}.";
        }

        // Số 5–6 — 2 số cuối năm sinh.
        if (substr($number, 4, 2) !== $dob->format('y')) {
            return 'Số CCCD không hợp lệ: số thứ 5–6 (' . substr($number, 4, 2) . ") không khớp năm sinh {$dob->year}.";
        }

        // Số thứ 4 chẵn = nam, lẻ = nữ.
        $gender = Str::lower(Str::ascii((string) ($data['gender'] ?? '')));
        if ($gender !== '' && str_starts_with($gender, 'nu') !== ($centuryGender % 2 === 1)) {
            return "Số CCCD không hợp lệ: số thứ 4 ({$centuryGender}) không khớp giới tính {$data['gender']}.";
        }

        $issued = self::parseDate($data['issued_date'] ?? null);
        if ($issued && ($issued->isFuture() || $issued->lt($dob))) {
            return 'Ngày cấp CCCD không hợp lệ.';
        }

        return null;
    }

    public static function birthProvince(string $number): ?string
    {
        return self::PROVINCES[substr($number, 0, 3)] ?? null;
    }

    /**
     * Tuổi tròn tại ngày $on (mặc định hôm nay) — dùng ngày nhận phòng để xét điều kiện lưu trú.
     */
    public static function ageOn(?array $data, ?CarbonInterface $on = null): ?int
    {
        $dob = self::parseDate($data['dob'] ?? null);

        return $dob ? (int) $dob->diffInYears(($on ?? Carbon::today())->copy()->startOfDay(), true) : null;
    }

    /**
     * Lỗi độ tuổi cho 1 khách (dưới MIN_AGE), null nếu đạt. Tuổi tính tại ngày $on.
     */
    public static function ageError(?array $data, bool $isBooker, ?CarbonInterface $on = null, string $who = 'Người đặt phòng'): ?string
    {
        $age = self::ageOn($data, $on);

        if ($age === null) {
            return "{$who}: không xác định được ngày sinh trên CCCD.";
        }

        if ($age < self::MIN_AGE) {
            return $isBooker
                ? "{$who} chưa đủ " . self::MIN_AGE . ' tuổi — không thể đặt phòng. Vui lòng liên hệ trực tiếp để được hỗ trợ.'
                : "{$who} chưa đủ " . self::MIN_AGE . ' tuổi — theo quy định không được lưu trú qua đêm.';
        }

        return null;
    }

    /**
     * 2 bản cccd_data có phải cùng 1 người: trùng số CCCD, HOẶC trùng họ tên (bỏ dấu/hoa-thường/
     * khoảng trắng) + ngày sinh — chặn dùng lại thông tin 1 người cho nhiều khách trong 1 đơn.
     */
    public static function samePerson(array $a, array $b): bool
    {
        $numberA = trim((string) ($a['cccd'] ?? ''));
        if ($numberA !== '' && $numberA === trim((string) ($b['cccd'] ?? ''))) {
            return true;
        }

        $nameA = self::normalizeName($a['full_name'] ?? '');

        return $nameA !== ''
            && $nameA === self::normalizeName($b['full_name'] ?? '')
            && filled($a['dob'] ?? null)
            && ($a['dob'] ?? null) === ($b['dob'] ?? null);
    }

    private static function normalizeName(mixed $name): string
    {
        return Str::upper(trim((string) preg_replace('/\s+/', ' ', Str::ascii((string) $name))));
    }

    private static function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('#^\d{2}/\d{2}/\d{4}$#', $value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!d/m/Y', $value);
        } catch (\Throwable) {
            return null;
        }

        // createFromFormat tự "tràn" ngày không tồn tại (31/02 → 03/03) — loại luôn.
        return $date && $date->format('d/m/Y') === $value ? $date : null;
    }
}
