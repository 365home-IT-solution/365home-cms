<?php

namespace App\Services;

use App\Models\TbltProvince;
use App\Models\TbltWard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

// Quy đổi 1 chuỗi địa chỉ thô đọc từ CCCD (thường theo cấu trúc CŨ "..., Xã/Phường, Quận/Huyện,
// Tỉnh/Thành phố") sang ĐÚNG mã "Mã - Tên" của mẫu tblt_vn_import.xlsx (cấu trúc MỚI 2 cấp,
// 2025) — dựa vào 2 bảng tham chiếu riêng tblt_provinces/tblt_wards đã nạp từ chính file mẫu.
//
// Vì CCCD ghi theo địa giới CŨ (3 cấp) còn mẫu yêu cầu địa giới MỚI (2 cấp), không có công thức
// suy ra máy móc 1-1 giữa tên cũ và tên mới (có xã giữ nguyên tên, có xã đổi hẳn sang tên huyện
// cũ...). Cách xử lý: thử khớp LẦN LƯỢT từng đoạn của địa chỉ (từ gần tỉnh nhất trở vào) với danh
// sách phường/xã MỚI của đúng tỉnh đã nhận diện — đoạn nào khớp tên (không dấu, bỏ tiền tố
// Phường/Xã/Quận/Huyện/Thị xã) thì dùng luôn. Không tự suy luận xa hơn (vd không tự nội suy quan hệ
// sáp nhập) — khớp được thì lấy, không khớp thì để trống cho nhân viên tự chọn tay (chọn đúng và an
// toàn hơn là đoán sai).
class TbltAddressResolver
{
    // Áp dụng SAU khi đã bỏ dấu câu (dấu chấm/phẩy...) nên "tp." trong dữ liệu gốc sẽ thành "tp".
    private const PREFIXES = [
        'thanh pho', 'tp', 'tinh',
        'phuong', 'thi tran', 'thi xa', 'quan', 'huyen', 'dac khu', 'xa',
    ];

    // CCCD CŨ (cấp trước 12/6/2025) ghi tỉnh/thành theo địa giới 63 tỉnh — nhiều tỉnh đã bị SÁP
    // NHẬP/ĐỔI TÊN hẳn (không còn tồn tại độc lập), khác với cấp phường/xã (thường tên cũ vẫn còn
    // tồn tại dưới dạng nào đó). Ánh xạ tỉnh CŨ (đã chuẩn hoá — bỏ dấu, chữ thường) → tỉnh MỚI
    // (cũng đã chuẩn hoá, khớp đúng tên trong bảng tblt_provinces sau khi bỏ tiền tố Tỉnh/TP) theo
    // đúng Nghị quyết sáp nhập tỉnh có hiệu lực 12/6/2025 (nguồn: Cổng TTĐT Chính phủ + Wikipedia
    // tiếng Việt — https://vi.wikipedia.org/wiki/Sáp_nhập_tỉnh,_thành_Việt_Nam_2025). 11 tỉnh/TP
    // KHÔNG sáp nhập (Hà Nội, Huế, Lai Châu, Điện Biên, Sơn La, Lạng Sơn, Quảng Ninh, Thanh Hóa,
    // Nghệ An, Hà Tĩnh, Cao Bằng) không cần khai báo ở đây vì tên cũ = tên mới, khớp trực tiếp.
    private const OLD_PROVINCE_ALIASES = [
        'ha giang'            => 'tuyen quang',
        'yen bai'             => 'lao cai',
        'bac kan'             => 'thai nguyen',
        'vinh phuc'           => 'phu tho',
        'hoa binh'            => 'phu tho',
        'bac giang'           => 'bac ninh',
        'thai binh'           => 'hung yen',
        'hai duong'           => 'hai phong',
        'ha nam'              => 'ninh binh',
        'nam dinh'            => 'ninh binh',
        'quang binh'          => 'quang tri',
        'quang nam'           => 'da nang',
        'kon tum'             => 'quang ngai',
        'binh dinh'           => 'gia lai',
        'phu yen'             => 'dak lak',
        'ninh thuan'          => 'khanh hoa',
        'dak nong'            => 'lam dong',
        'binh thuan'          => 'lam dong',
        'binh phuoc'          => 'dong nai',
        'ba ria vung tau'     => 'ho chi minh',
        'brvt'                => 'ho chi minh',
        'binh duong'          => 'ho chi minh',
        'long an'             => 'tay ninh',
        'tien giang'          => 'dong thap',
        'ben tre'             => 'vinh long',
        'tra vinh'            => 'vinh long',
        'soc trang'           => 'can tho',
        'hau giang'           => 'can tho',
        'bac lieu'            => 'ca mau',
        'kien giang'          => 'an giang',
        'thua thien hue'      => 'hue',
        // Viết tắt phổ biến trên CCCD/giấy tờ cũ — không phải sáp nhập, chỉ là cách viết tắt của
        // tên hiện tại (sau khi normalize() đã bỏ tiền tố "TP"/"Tỉnh", còn lại đúng các dạng này).
        'hcm'                 => 'ho chi minh',
        'sai gon'             => 'ho chi minh',
        'sg'                  => 'ho chi minh',
    ];

    // [province_code => TbltProvince], cache trong request để tránh query lặp lại.
    private ?array $provinces = null;

    public function resolveFromAddress(?string $address): array
    {
        if (blank($address)) {
            return [null, null];
        }

        $segments = array_values(array_filter(
            array_map('trim', explode(',', $address)),
            fn (string $part) => $part !== ''
        ));

        if (empty($segments)) {
            return [null, null];
        }

        $provinceSegment = array_pop($segments);
        $province        = $this->matchProvince($provinceSegment);

        if (! $province) {
            return [null, null];
        }

        // QUAN TRỌNG — đã từng thử so khớp luôn cả đoạn GẦN TỈNH NHẤT (thường là cấp huyện/quận/
        // thị xã CŨ) với danh sách phường/xã MỚI, giả định "cấp huyện cũ thường trở thành tên
        // phường/xã mới". Giả định này SAI và đã được xác minh bằng địa chỉ thật: "Thị xã Bình
        // Minh" (huyện) và "Xã Mỹ Hòa" (xã, xã thật sự đổi tên thành "Phường Cái Vồn") là 2 ĐƠN VỊ
        // KHÁC NHAU — so khớp theo tên huyện vô tình trúng 1 phường TRÙNG TÊN "Phường Bình Minh"
        // (có thật nhưng SAI, do thị xã tách ra thành NHIỀU phường/xã mới, không phải 1-1) → trả
        // về kết quả trông như đúng nhưng thực chất sai hoàn toàn — nguy hiểm hơn để trống nhiều,
        // vì nhân viên dễ tin và không kiểm tra lại. Vì vậy CHỈ so khớp đúng cấp xã/phường CŨ (đoạn
        // ngay TRƯỚC cấp huyện, tức đoạn thứ 2 từ cuối trở lên) — bỏ hẳn đoạn cấp huyện/thị xã ra
        // khỏi việc so khớp phường/xã. Không so khớp được thì để trống, không đoán mò cấp huyện.
        if (! empty($segments)) {
            array_pop($segments); // bỏ đoạn cấp huyện/quận/thị xã cũ — KHÔNG dùng để so khớp phường/xã.
        }

        $ward = null;
        foreach (array_reverse($segments) as $segment) {
            $ward = $this->matchWard($province->code, $segment);
            if ($ward) {
                break;
            }
        }

        return [
            $province->display,
            $ward?->display,
        ];
    }

    private function matchProvince(string $rawSegment): ?TbltProvince
    {
        $needle = $this->normalize($rawSegment);

        // Nếu tên tỉnh CŨ khớp bảng ánh xạ sáp nhập (hoặc chỉ là viết tắt), quy đổi trước khi so
        // khớp — vd "Hậu Giang"/"HCM" không tồn tại/không khớp trực tiếp trong 34 tỉnh mới.
        $needle = self::OLD_PROVINCE_ALIASES[$needle] ?? $needle;

        foreach ($this->allProvinces() as $province) {
            if ($this->normalize($province->name) === $needle) {
                return $province;
            }
        }

        return null;
    }

    private function matchWard(string $provinceCode, string $rawSegment): ?TbltWard
    {
        $needle = $this->normalize($rawSegment);

        if ($needle === '') {
            return null;
        }

        return Cache::rememberForever("tblt_wards_by_province_{$provinceCode}", function () use ($provinceCode) {
            return TbltWard::where('province_code', $provinceCode)->get();
        })->first(fn (TbltWard $ward) => $this->normalize($ward->name) === $needle);
    }

    private function allProvinces(): array
    {
        if ($this->provinces === null) {
            $this->provinces = Cache::rememberForever('tblt_provinces_all', fn () => TbltProvince::all())->all();
        }

        return $this->provinces;
    }

    // Bỏ dấu tiếng Việt, hạ chữ thường, bỏ tiền tố cấp hành chính (Phường/Xã/Quận/Huyện/Tỉnh/TP...)
    // để so khớp tên bất kể cách ghi/viết tắt khác nhau giữa CCCD và mẫu.
    private function normalize(string $value): string
    {
        $value = Str::of($value)->ascii()->lower()->trim()->toString();
        $value = preg_replace('/[^a-z0-9\s]/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', trim($value));

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($value, $prefix . ' ')) {
                $value = trim(substr($value, strlen($prefix) + 1));
                break;
            }
        }

        return $value;
    }
}
