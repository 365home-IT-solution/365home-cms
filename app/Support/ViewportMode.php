<?php

declare(strict_types=1);

namespace App\Support;

// Nhiều khối giao diện có 2 bản riêng cho mobile và desktop (form tìm kiếm ở header, khung tìm kiếm
// đè banner, lịch đặt phòng...) — trước đây in cả 2 vào HTML rồi ẩn 1 bản bằng CSS (lg:hidden /
// hidden lg:block), mỗi bản nặng hàng chục KB. Lớp này cho biết request hiện tại cần in bản nào:
//
//   'mobile'  — điện thoại: chỉ in bản mobile
//   'desktop' — máy tính: chỉ in bản desktop
//   'both'    — tablet / thiết bị có thể đổi qua lại mốc 1024px (xoay máy, thu nhỏ cửa sổ): in cả 2
//               như cũ để đổi bằng CSS, không cần tải lại trang
//
// Nguồn: cookie COOKIE do trình duyệt tự đặt (script đầu <head> ở layouts/master.blade.php đo bề
// rộng màn hình THẬT) — có cookie thì luôn tin cookie; chưa có (lượt vào đầu tiên, bot) thì đoán
// theo User-Agent. Script đó cũng tự sửa khi server đoán sai: đặt cookie rồi tải lại trang 1 lần.
class ViewportMode
{
    public const COOKIE = 'vp_mode';

    public const MOBILE = 'mobile';
    public const DESKTOP = 'desktop';
    public const BOTH = 'both';

    private static ?string $resolved = null;

    public static function current(): string
    {
        return self::$resolved ??= self::resolve();
    }

    public static function rendersMobile(): bool
    {
        return self::current() !== self::DESKTOP;
    }

    public static function rendersDesktop(): bool
    {
        return self::current() !== self::MOBILE;
    }

    private static function resolve(): string
    {
        // Đọc thẳng $_COOKIE: cookie này do JS đặt (không mã hoá) nên request()->cookie() — đi qua
        // EncryptCookies — sẽ trả null.
        $cookie = $_COOKIE[self::COOKIE] ?? null;
        if (in_array($cookie, [self::MOBILE, self::DESKTOP, self::BOTH], true)) {
            return $cookie;
        }

        $request = request();
        $userAgent = (string) $request->userAgent();

        // Tablet khai báo rõ trong UA (iPad đời cũ, Android không có "Mobile") — bề rộng có thể ở
        // 2 phía mốc 1024px tuỳ chiều xoay.
        if (preg_match('/iPad|Tablet|PlayBook|Silk/i', $userAgent) === 1
            || (stripos($userAgent, 'Android') !== false && stripos($userAgent, 'Mobi') === false)) {
            return self::BOTH;
        }

        if ($request->header('Sec-CH-UA-Mobile') === '?1'
            || preg_match('/Mobi|Android|iPhone|iPod|Windows Phone/i', $userAgent) === 1) {
            return self::MOBILE;
        }

        return self::DESKTOP;
    }
}
