<?php

namespace Modules\BladeThemeV1\Support;

/**
 * Trang đích theo nhu cầu tìm kiếm (SEO) — mỗi trang nhắm 1 cụm từ khoá, dùng lại nguyên khối danh
 * sách phòng + giá + bản đồ của trang tìm kiếm (pages/product/search.blade.php, search-results.js
 * đọc bộ lọc từ window.__searchPreset thay vì từ URL), cộng H1/giới thiệu/nội dung/FAQ riêng
 * (components/landing/{slug}.blade.php). Nội dung dài trước đây nằm ở trang chủ được chuyển về đây
 * để trang chủ không tự cạnh tranh từ khoá với trang con.
 *
 * Chỉ tạo trang khi dữ liệu thật khớp từ khoá: mọi chi nhánh homestay Cần Thơ hiện ở khu Ninh Kiều,
 * đều đặt theo giờ và tự check-in. KHÔNG tạo trang kiểu "gần bến Ninh Kiều / chợ nổi Cái Răng" vì
 * các chi nhánh cách những điểm đó vài km — tên trang như vậy là sai sự thật với khách.
 */
class LandingPages
{
    public const PAGES = [
        'homestay-can-tho-theo-gio' => [
            'type'        => 'homestay',
            'location'    => 'can-tho',
            'h1'          => 'Homestay Cần Thơ theo giờ',
            'title'       => 'Homestay Cần Thơ theo giờ, giá rẻ, đặt online | 365 HOME',
            'description' => 'Đặt homestay Cần Thơ theo giờ tại 365 Home: xem lịch trống từng khung giờ, giá hiển thị trước, thanh toán online và tự check-in bằng mã mở khoá.',
            'intro'       => 'Chọn đúng số giờ bạn cần ở các homestay 365 Home tại Ninh Kiều, Cần Thơ — xem lịch trống, giá từng khung giờ và đặt ngay trên điện thoại.',
            'keywords'    => 'homestay cần thơ theo giờ, homestay theo giờ ninh kiều, thuê phòng theo giờ cần thơ, 365 home',
        ],
        'homestay-tu-check-in-can-tho' => [
            'type'        => 'homestay',
            'location'    => 'can-tho',
            'h1'          => 'Homestay tự check-in tại Cần Thơ',
            'title'       => 'Homestay tự check-in Cần Thơ, không cần lễ tân | 365 HOME',
            'description' => 'Homestay tự check-in tại Cần Thơ: đặt và thanh toán online, nhận mã mở khoá, tự vào phòng bằng khoá thông minh trong khung giờ đã đặt, không cần lễ tân.',
            'intro'       => 'Đặt phòng, thanh toán online rồi tự vào phòng bằng mã mở khoá — không chờ lễ tân, không gửi giấy tờ, đến lúc nào trong khung giờ đã đặt cũng được.',
            'keywords'    => 'homestay tự check-in cần thơ, homestay không lễ tân, khoá thông minh, mã mở khoá, 365 home',
        ],
        'homestay-ninh-kieu' => [
            'type'        => 'homestay',
            'location'    => 'can-tho',
            'h1'          => 'Homestay Ninh Kiều, Cần Thơ',
            'title'       => 'Homestay Ninh Kiều Cần Thơ gần trung tâm | 365 HOME',
            'description' => 'Homestay khu Ninh Kiều, Cần Thơ: phòng đẹp gần trung tâm, đặt theo giờ, qua đêm hoặc theo ngày, kèm gợi ý ăn chơi, lịch trình và di chuyển khi lưu trú.',
            'intro'       => 'Các homestay 365 Home ở khu Ninh Kiều — trung tâm Cần Thơ, thuận tiện đi bến Ninh Kiều, chợ đêm và các điểm tham quan trong thành phố.',
            'keywords'    => 'homestay ninh kiều, homestay cần thơ gần trung tâm, du lịch cần thơ, 365 home',
        ],
    ];

    public static function slugs(): array
    {
        return array_keys(self::PAGES);
    }

    public static function get(string $slug): ?array
    {
        return self::PAGES[$slug] ?? null;
    }
}
