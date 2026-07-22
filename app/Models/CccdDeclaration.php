<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Payment\Entities\Order;

// Ghi chú quan trọng: bảng này CHỈ lưu dữ liệu tham chiếu nội bộ, KHÔNG tự động gửi cho ASM/dịch
// vụ công/công an — nhân viên vẫn phải tự nhập/quét CCCD vào phần mềm ASM theo đúng quy định (Luật
// Cư trú, có hiệu lực từ 01/07/2026). 'declared_at'/'declared_by' chỉ để ĐÁNH DẤU nội bộ là đã nộp
// thủ công xong, giúp theo dõi hạn (trước 23h ngày khách đến, hoặc trước 8h sáng hôm sau nếu khách
// đến sau 23h) — tránh quên dẫn tới bị phạt 2-4 triệu đồng/lần.
class CccdDeclaration extends Model
{
    protected $fillable = [
        'order_id',
        // 1 = khách chính (người đặt phòng), 2 = khách thứ 2 cùng lưu trú qua đêm — Luật Cư trú
        // yêu cầu khai báo đủ từng người khi ở qua đêm 2 khách (xem CccdDeclarationService).
        'guest_index',
        'full_name',
        'info',
        'date_of_birth',
        'gender',
        'cccd_number',
        'nationality',
        'document_type',
        'phone_number',
        'checked_in_at',
        'checked_out_at',
        'room_number',
        'stay_address',
        'reason_for_stay',
        'custom_reason',
        'current_residence',
        'residence_type',
        'province',
        'ward',
        'address_detail',
        'notes',
        'declared_at',
        'declared_by',
    ];

    protected $casts = [
        'checked_in_at'  => 'datetime',
        'checked_out_at' => 'datetime',
        'declared_at'    => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function declaredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declared_by');
    }

    public function isDeclared(): bool
    {
        return ! is_null($this->declared_at);
    }

    // Đơn ở các trạng thái này mới coi là khách THỰC SỰ sẽ đến ở (đã đặt cọc/thanh toán) — đơn
    // 'pending' có thể tự huỷ sau 15 phút nếu khách không thanh toán (xem ExpirePaymentOrders),
    // nên KHÔNG tính vào danh sách/xuất Excel dù bản ghi lưu trú đã được tạo sẵn ngay từ lúc đặt
    // phòng (tạo sớm để không mất dữ liệu CCCD nếu khách quay lại thanh toán sau).
    public const CONFIRMED_ORDER_STATUSES = ['paid', 'deposit', 'shipped'];

    public function hasConfirmedOrder(): bool
    {
        $order = $this->relationLoaded('order') ? $this->order : $this->order()->first();

        return $order && in_array($order->status, self::CONFIRMED_ORDER_STATUSES, true);
    }

    // Hạn khai báo theo đúng quy định: trước 23h NGÀY khách đến; nếu khách đến từ 23h trở đi thì
    // hạn dời sang 8h sáng hôm sau. Trả về null nếu chưa có ngày đến (chưa đủ dữ liệu để tính hạn).
    public function declarationDeadline(): ?Carbon
    {
        if (! $this->checked_in_at) {
            return null;
        }

        $checkin = $this->checked_in_at;
        $cutoff  = $checkin->copy()->setTime(23, 0);

        return $checkin->lt($cutoff)
            ? $cutoff
            : $checkin->copy()->addDay()->setTime(8, 0);
    }

    // Đã quá hạn khai báo mà VẪN CHƯA đánh dấu đã nộp — cần cảnh báo gấp.
    public function isOverdue(): bool
    {
        if ($this->isDeclared()) {
            return false;
        }

        $deadline = $this->declarationDeadline();

        return $deadline && now()->gt($deadline);
    }

    // Sắp tới hạn (trong vòng 2 giờ) mà chưa khai báo — cảnh báo nhẹ trước khi quá hạn hẳn.
    public function isDueSoon(): bool
    {
        if ($this->isDeclared() || $this->isOverdue()) {
            return false;
        }

        $deadline = $this->declarationDeadline();

        // deadline chắc chắn còn ở tương lai tại đây (đã loại isOverdue() ở trên) — diffInMinutes
        // mặc định trả giá trị TUYỆT ĐỐI nên an toàn dùng trực tiếp làm "số phút còn lại".
        return $deadline && now()->diffInMinutes($deadline) <= 120;
    }

    // Hạn khai báo (declarationDeadline()) RƠI VÀO HÔM NAY — tức là khách này BẮT BUỘC phải nộp
    // xong trong ngày hôm nay (dù ngày khách NHẬN PHÒNG có thể là hôm nay hay hôm qua, tuỳ giờ đến
    // trước/sau 23h — xem declarationDeadline()).
    public function isDeclarationDueToday(): bool
    {
        $deadline = $this->declarationDeadline();

        return (bool) $deadline?->isToday();
    }

    // "Cần khai báo hôm nay" = CHƯA khai báo, và (đã quá hạn HOẶC hạn rơi đúng hôm nay) — đây là
    // tập khách staff PHẢI xử lý trong ngày, dùng cho tab mặc định + giới hạn phạm vi xuất Excel.
    public function needsDeclarationToday(): bool
    {
        return ! $this->isDeclared() && ($this->isOverdue() || $this->isDeclarationDueToday());
    }

    // Chưa khai báo, hạn còn ở tương lai (chưa tới hôm nay) — khách đặt phòng trước, chưa tới ngày
    // phải nộp, chỉ hiển thị để theo dõi/lên kế hoạch trước, KHÔNG được xuất Excel (chưa đủ điều
    // kiện khai báo với KBTT — xem CccdDeclarationExcelExporter).
    public function isUpcomingDeclaration(): bool
    {
        return ! $this->isDeclared() && ! $this->isOverdue() && ! $this->isDeclarationDueToday();
    }

    // 2 helper tĩnh dưới đây tính trong PHP (không phải raw SQL) vì công thức hạn khai báo có rẽ
    // nhánh theo giờ đến (trước/sau 23h) — số lượng bản ghi CHƯA khai báo của 1 cơ sở lưu trú luôn
    // nhỏ (vài chục/ngày) nên load hết rồi lọc bằng đúng logic declarationDeadline() ở trên là đủ
    // nhanh, đồng thời tránh viết lại công thức rẽ nhánh 1 lần nữa bằng SQL thô (dễ lệch logic).
    public static function idsNeedingDeclarationToday(): array
    {
        return static::whereNull('declared_at')
            ->whereHas('order', fn ($q) => $q->whereIn('status', self::CONFIRMED_ORDER_STATUSES))
            ->get()
            ->filter(fn (self $d) => $d->needsDeclarationToday())
            ->pluck('id')
            ->all();
    }

    public static function idsUpcomingDeclaration(): array
    {
        return static::whereNull('declared_at')
            ->whereHas('order', fn ($q) => $q->whereIn('status', self::CONFIRMED_ORDER_STATUSES))
            ->get()
            ->filter(fn (self $d) => $d->isUpcomingDeclaration())
            ->pluck('id')
            ->all();
    }

    // Các trường THEO ĐÚNG "Nội dung thông báo" mà Luật Cư trú yêu cầu (họ tên, số định danh/hộ
    // chiếu, lý do lưu trú, thời gian lưu trú, nơi cư trú) — dùng để chặn KHÔNG cho đánh dấu "đã
    // khai báo" khi dữ liệu còn thiếu (vd CCCD chưa quét được), tránh nhân viên lỡ tay xác nhận
    // "đã nộp" trong khi thực tế chưa có đủ thông tin để nộp thật cho ASM/dịch vụ công.
    private const REQUIRED_FIELD_LABELS = [
        'full_name'         => 'Họ và tên',
        'date_of_birth'     => 'Ngày sinh',
        'gender'            => 'Giới tính',
        'nationality'       => 'Quốc tịch',
        'document_type'     => 'Loại giấy tờ',
        'cccd_number'       => 'Số giấy tờ (CCCD)',
        'checked_in_at'     => 'Ngày đến',
        'checked_out_at'    => 'Ngày đi dự kiến',
        'room_number'       => 'Số phòng',
        'stay_address'      => 'Địa chỉ lưu trú (chi nhánh)',
        'reason_for_stay'   => 'Lý do lưu trú',
        'current_residence' => 'Nơi thường trú (theo CCCD)',
        'province'          => 'Tỉnh/Thành phố',
        'ward'              => 'Phường/Xã',
    ];

    // Danh mục giá trị CHUẨN — trích nguyên văn từ mẫu chính thức "tblt_vn_import.xlsx" (sheet
    // DANH_MUC) của Bộ Công an, lưu THẲNG dạng "Mã - Tên" hiển thị để khi xuất Excel không cần
    // chuyển đổi gì thêm, khớp đúng dropdown "chọn theo Sheet [DANH_MUC]" của mẫu.
    public const GENDER_OPTIONS = [
        'M - Nam' => 'M - Nam',
        'F - Nữ'  => 'F - Nữ',
    ];

    public const DOCUMENT_TYPE_OPTIONS = [
        '1 - Thẻ CCCD'                       => '1 - Thẻ CCCD',
        '2 - Thẻ CMND'                       => '2 - Thẻ CMND',
        '3 - Giấy phép lái xe'               => '3 - Giấy phép lái xe',
        '4 - Hộ chiếu'                       => '4 - Hộ chiếu',
        '5 - Giấy khai sinh'                 => '5 - Giấy khai sinh',
        '6 - Thẻ BHYT'                       => '6 - Thẻ BHYT',
        '7 - Thông báo số định danh cá nhân' => '7 - Thông báo số định danh cá nhân',
        '8 - Thẻ Căn Cước'                   => '8 - Thẻ Căn Cước',
        '9 - Giấy Tờ Khác'                   => '9 - Giấy Tờ Khác',
    ];

    public const RESIDENCE_TYPE_OPTIONS = [
        '1 - Thường trú' => '1 - Thường trú',
        '2 - Tạm trú'    => '2 - Tạm trú',
        '3 - Khác'       => '3 - Khác',
    ];

    public const REASON_FOR_STAY_OPTIONS = [
        '1 - Du lịch'              => '1 - Du lịch',
        '2 - Công tác'             => '2 - Công tác',
        '3 - Học tập'              => '3 - Học tập',
        '4 - Thăm viếng'           => '4 - Thăm viếng',
        '5 - Hội nghị'             => '5 - Hội nghị',
        '6 - Thăm thân'            => '6 - Thăm thân',
        '7 - Từ thiện'             => '7 - Từ thiện',
        '8 - Tổ chức quốc tế'      => '8 - Tổ chức quốc tế',
        '9 - Kết hôn'              => '9 - Kết hôn',
        '10 - Lãnh sự quán'        => '10 - Lãnh sự quán',
        '11 - Viện trợ'            => '11 - Viện trợ',
        '12 - Đại sứ quán'         => '12 - Đại sứ quán',
        '13 - Định cư'             => '13 - Định cư',
        '14 - Tiếp thị'            => '14 - Tiếp thị',
        '15 - Báo chí, phóng viên' => '15 - Báo chí, phóng viên',
        '16 - Thương mại'          => '16 - Thương mại',
        '17 - Gia hạn thị thực'    => '17 - Gia hạn thị thực',
        '18 - Chữa bệnh'           => '18 - Chữa bệnh',
        '19 - Lao động'            => '19 - Lao động',
        '20 - Mục đích khác'       => '20 - Mục đích khác',
    ];

    public const NATIONALITY_DEFAULT = 'VNM - Viet Nam';

    // Trích nguyên văn đủ 205 quốc gia từ sheet DANH_MUC của mẫu tblt_vn_import.xlsx.
    public const NATIONALITY_OPTIONS = [
        'AFG - Afghanistan' => 'AFG - Afghanistan',
        'ALB - Albania' => 'ALB - Albania',
        'DZA - Algeria' => 'DZA - Algeria',
        'AND - Andorra' => 'AND - Andorra',
        'AGO - Angola' => 'AGO - Angola',
        'ATG - Antigua and Barbuda' => 'ATG - Antigua and Barbuda',
        'ARG - Argentina' => 'ARG - Argentina',
        'ARM - Armenia' => 'ARM - Armenia',
        'AUS - Australia' => 'AUS - Australia',
        'AUT - Austria' => 'AUT - Austria',
        'AZE - Azerbaijan' => 'AZE - Azerbaijan',
        'BHS - Bahamas' => 'BHS - Bahamas',
        'BHR - Bahrain' => 'BHR - Bahrain',
        'BGD - Bangladesh' => 'BGD - Bangladesh',
        'BRB - Barbados' => 'BRB - Barbados',
        'BLR - Belarus' => 'BLR - Belarus',
        'BEL - Belgium' => 'BEL - Belgium',
        'BLZ - Belize' => 'BLZ - Belize',
        'BEN - Benin' => 'BEN - Benin',
        'BMU - Bermuda' => 'BMU - Bermuda',
        'BTN - Bhutan' => 'BTN - Bhutan',
        'BOL - Bolivia' => 'BOL - Bolivia',
        'BIH - Bosnia and Herzegovina' => 'BIH - Bosnia and Herzegovina',
        'BWA - Botswana' => 'BWA - Botswana',
        'BRA - Brazil' => 'BRA - Brazil',
        'IOT - British India Ocean Territory' => 'IOT - British India Ocean Territory',
        'BRN - Bruney' => 'BRN - Bruney',
        'BGR - Bulgaria' => 'BGR - Bulgaria',
        'BFA - Burkina Faso' => 'BFA - Burkina Faso',
        'BDI - Burundi' => 'BDI - Burundi',
        'KHM - Cambodia' => 'KHM - Cambodia',
        'CMR - Cameroon' => 'CMR - Cameroon',
        'CAN - Canada' => 'CAN - Canada',
        'CPV - Cape Verde' => 'CPV - Cape Verde',
        'CAF - Central African Republic' => 'CAF - Central African Republic',
        'TCD - Chad' => 'TCD - Chad',
        'CHL - Chile' => 'CHL - Chile',
        'CHN - China' => 'CHN - China',
        'COL - Colombia' => 'COL - Colombia',
        'COM - Comoros' => 'COM - Comoros',
        'COG - Congo' => 'COG - Congo',
        'CRI - Costa Rica' => 'CRI - Costa Rica',
        'CIV - Cote d’ Ivoire' => 'CIV - Cote d’ Ivoire',
        'HRV - Croatia' => 'HRV - Croatia',
        'CUB - Cuba' => 'CUB - Cuba',
        'CYP - Cyprus' => 'CYP - Cyprus',
        'CZE - Czech Republic' => 'CZE - Czech Republic',
        'COD - Democratic Republic of the Congo' => 'COD - Democratic Republic of the Congo',
        'DNK - Denmark' => 'DNK - Denmark',
        'DJI - Djibouti' => 'DJI - Djibouti',
        'DMA - Dominica' => 'DMA - Dominica',
        'DOM - Dominican Republic' => 'DOM - Dominican Republic',
        'ECU - Ecuador' => 'ECU - Ecuador',
        'EGY - Egypt' => 'EGY - Egypt',
        'SLV - El Salvado' => 'SLV - El Salvado',
        'GNQ - Equatorial Guinea' => 'GNQ - Equatorial Guinea',
        'ERI - Eritrea' => 'ERI - Eritrea',
        'EST - Estonia' => 'EST - Estonia',
        'ETH - Ethiopia' => 'ETH - Ethiopia',
        'FJI - Fiji' => 'FJI - Fiji',
        'FIN - Finland' => 'FIN - Finland',
        'FRA - France' => 'FRA - France',
        'GAB - Gabon' => 'GAB - Gabon',
        'GMB - Gambia' => 'GMB - Gambia',
        'GEO - Georgia' => 'GEO - Georgia',
        'D - Germany' => 'D - Germany',
        'GHA - Ghana' => 'GHA - Ghana',
        'GIB - Gibraltar' => 'GIB - Gibraltar',
        'GRC - Greece' => 'GRC - Greece',
        'GRL - Greenland' => 'GRL - Greenland',
        'GRD - Grenada' => 'GRD - Grenada',
        'GTM - Guatemala' => 'GTM - Guatemala',
        'GIN - Guinea' => 'GIN - Guinea',
        'GNB - Guinea-Bissau' => 'GNB - Guinea-Bissau',
        'GUY - Guyana' => 'GUY - Guyana',
        'HTI - Haiti' => 'HTI - Haiti',
        'VAT - Holy See (Vatican City State )' => 'VAT - Holy See (Vatican City State )',
        'HND - Honduras' => 'HND - Honduras',
        'HUN - Hungary' => 'HUN - Hungary',
        'ISL - Iceland' => 'ISL - Iceland',
        'IND - India' => 'IND - India',
        'IDN - Indonesia' => 'IDN - Indonesia',
        'IRN - Iran Ilasmic Republic of' => 'IRN - Iran Ilasmic Republic of',
        'IRQ - Iraq' => 'IRQ - Iraq',
        'IRL - Ireland' => 'IRL - Ireland',
        'ISR - Israel' => 'ISR - Israel',
        'ITA - Italy' => 'ITA - Italy',
        'JAM - Jamaica' => 'JAM - Jamaica',
        'JPN - Japan' => 'JPN - Japan',
        'JOR - Jordan' => 'JOR - Jordan',
        'KAZ - Kazakhstan' => 'KAZ - Kazakhstan',
        'KEN - Kenya' => 'KEN - Kenya',
        'KIR - Kiribati' => 'KIR - Kiribati',
        'KOR - Korea (South)' => 'KOR - Korea (South)',
        'PRK - Korea Democratic Peoples Republic of' => 'PRK - Korea Democratic Peoples Republic of',
        'RKS - Kosovo' => 'RKS - Kosovo',
        'KWT - Kuwait' => 'KWT - Kuwait',
        'KGZ - Kyrgyzstan' => 'KGZ - Kyrgyzstan',
        'LAO - Lao Peoples Democratic Republic' => 'LAO - Lao Peoples Democratic Republic',
        'LVA - Latvia' => 'LVA - Latvia',
        'LBN - Lebanon' => 'LBN - Lebanon',
        'LSO - Lesotho' => 'LSO - Lesotho',
        'LBR - Liberia' => 'LBR - Liberia',
        'LBY - Libyan Arab Jamahiriya' => 'LBY - Libyan Arab Jamahiriya',
        'LIE - Liechtenstein' => 'LIE - Liechtenstein',
        'LTU - Lithuania' => 'LTU - Lithuania',
        'LUX - Luxembourg' => 'LUX - Luxembourg',
        'MKD - Macedonia' => 'MKD - Macedonia',
        'MDG - Madagascar' => 'MDG - Madagascar',
        'MWI - Malawi' => 'MWI - Malawi',
        'MYS - Malaysia' => 'MYS - Malaysia',
        'MDV - Maldives' => 'MDV - Maldives',
        'MLI - Mali' => 'MLI - Mali',
        'MLT - Malta' => 'MLT - Malta',
        'MHL - Marshall Islands' => 'MHL - Marshall Islands',
        'MRT - Mauritania' => 'MRT - Mauritania',
        'MUS - Mauritius' => 'MUS - Mauritius',
        'MEX - Mexico' => 'MEX - Mexico',
        'FSM - Micronesia' => 'FSM - Micronesia',
        'MDA - Moldova' => 'MDA - Moldova',
        'MCO - Monaco' => 'MCO - Monaco',
        'MNG - Mongolia' => 'MNG - Mongolia',
        'MNE - Montenegro' => 'MNE - Montenegro',
        'MSR - Montserrat' => 'MSR - Montserrat',
        'MAR - Morocco' => 'MAR - Morocco',
        'MOZ - Mozambique' => 'MOZ - Mozambique',
        'MMR - Myanmar' => 'MMR - Myanmar',
        'NAM - Namibia' => 'NAM - Namibia',
        'NRU - Nauru' => 'NRU - Nauru',
        'NPL - Nepal' => 'NPL - Nepal',
        'NLD - Netherland' => 'NLD - Netherland',
        'NZL - New Zealand' => 'NZL - New Zealand',
        'NIC - Nicaragua' => 'NIC - Nicaragua',
        'NER - Niger' => 'NER - Niger',
        'NGA - Nigeria' => 'NGA - Nigeria',
        'NOR - Norway' => 'NOR - Norway',
        'OMN - Oman' => 'OMN - Oman',
        'PAK - Pakistan' => 'PAK - Pakistan',
        'PLW - Palau' => 'PLW - Palau',
        'PSE - Palestine' => 'PSE - Palestine',
        'PAN - Panama' => 'PAN - Panama',
        'PNG - Papua New Guinea' => 'PNG - Papua New Guinea',
        'PRY - Paraguay' => 'PRY - Paraguay',
        'PER - Peru' => 'PER - Peru',
        'PHL - Philippines' => 'PHL - Philippines',
        'POL - Poland' => 'POL - Poland',
        'PRT - Portugal' => 'PRT - Portugal',
        'QAT - Qatar' => 'QAT - Qatar',
        'ROU - Romania' => 'ROU - Romania',
        'RUS - Russia' => 'RUS - Russia',
        'RWA - Rwanda' => 'RWA - Rwanda',
        'KNA - Saint Kitts and Nevis' => 'KNA - Saint Kitts and Nevis',
        'LCA - Saint Lucia' => 'LCA - Saint Lucia',
        'VCT - Saint Vincent and the Grenadines' => 'VCT - Saint Vincent and the Grenadines',
        'SMR - San Marino' => 'SMR - San Marino',
        'STP - Sao Tome and Principe' => 'STP - Sao Tome and Principe',
        'SAU - Saudi Arabia' => 'SAU - Saudi Arabia',
        'SC- - Scotland' => 'SC- - Scotland',
        'SEN - Senegal' => 'SEN - Senegal',
        'SRB - Serbia' => 'SRB - Serbia',
        'SYC - Seychelles' => 'SYC - Seychelles',
        'SLE - Sierra Leone' => 'SLE - Sierra Leone',
        'SGP - Singapore' => 'SGP - Singapore',
        'SVK - Slovakia' => 'SVK - Slovakia',
        'SVN - Slovenia' => 'SVN - Slovenia',
        'SLB - Solomon Islands' => 'SLB - Solomon Islands',
        'SOM - Somalia' => 'SOM - Somalia',
        'ZAF - South Africa' => 'ZAF - South Africa',
        'SSD - South Sudan' => 'SSD - South Sudan',
        'ESP - Spain' => 'ESP - Spain',
        'LKA - Sri Lanka' => 'LKA - Sri Lanka',
        'SDN - Sudan' => 'SDN - Sudan',
        'SUR - Suriname' => 'SUR - Suriname',
        'SWZ - Swaziland' => 'SWZ - Swaziland',
        'SWE - Sweden' => 'SWE - Sweden',
        'CHE - Switzerland' => 'CHE - Switzerland',
        'SYR - Syrian Arab Republic' => 'SYR - Syrian Arab Republic',
        'TWN - Taiwan' => 'TWN - Taiwan',
        'TJK - Tajikistan' => 'TJK - Tajikistan',
        'TZA - Tanzania United Republic of' => 'TZA - Tanzania United Republic of',
        'THA - Thailand' => 'THA - Thailand',
        'TLS - Timor Leste' => 'TLS - Timor Leste',
        'TGO - Togo' => 'TGO - Togo',
        'TON - Tonga' => 'TON - Tonga',
        'TTO - Trinidad and Tobago' => 'TTO - Trinidad and Tobago',
        'TUN - Tunisia' => 'TUN - Tunisia',
        'TUR - Turkey' => 'TUR - Turkey',
        'TKM - Turkmenistan' => 'TKM - Turkmenistan',
        'TUV - Tuvalu' => 'TUV - Tuvalu',
        'UGA - Uganda' => 'UGA - Uganda',
        'UKR - Ukraine' => 'UKR - Ukraine',
        'ARE - United Arab Emirates' => 'ARE - United Arab Emirates',
        'GBD - United Kingdom British Territories Citizen' => 'GBD - United Kingdom British Territories Citizen',
        'GBR - United Kingdom of Great Britain and Northern Ireland' => 'GBR - United Kingdom of Great Britain and Northern Ireland',
        'UNO - United Nations Organization' => 'UNO - United Nations Organization',
        'USA - United States of America' => 'USA - United States of America',
        'URY - Uruguay' => 'URY - Uruguay',
        'UZB - Uzbekistan' => 'UZB - Uzbekistan',
        'VUT - Vanuatu' => 'VUT - Vanuatu',
        'VEN - Venezuela' => 'VEN - Venezuela',
        'VNM - Viet Nam' => 'VNM - Viet Nam',
        'WSM - Western Samoa' => 'WSM - Western Samoa',
        'YEM - Yemen' => 'YEM - Yemen',
        'ZMB - Zambia' => 'ZMB - Zambia',
        'ZWE - Zimbabwe' => 'ZWE - Zimbabwe',
    ];

    // Danh sách tên các trường CÒN THIẾU (rỗng) — mảng rỗng nghĩa là đã đủ dữ liệu bắt buộc.
    public function missingRequiredFieldLabels(): array
    {
        $missing = [];

        foreach (self::REQUIRED_FIELD_LABELS as $field => $label) {
            if (blank($this->{$field})) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    public function isDataComplete(): bool
    {
        return empty($this->missingRequiredFieldLabels());
    }
}
