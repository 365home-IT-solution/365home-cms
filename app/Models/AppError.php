<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

// Một lỗi app tự báo (API trả lỗi, crash), gộp theo fingerprint — xem App\Http\Controllers\Api\AppErrorReportController (ghi) và
// App\Http\Controllers\Api\Admin\AppErrorController (Super Admin đọc).
class AppError extends Model
{
    // Mục nghiệp vụ của lỗi (cột 'module') => nhãn hiển thị. Thêm mục mới thì thêm cả ở MODULE_SEGMENTS.
    public const MODULES = [
        'orders'        => 'Đơn hàng',
        'payments'      => 'Thanh toán',
        'rooms'         => 'Phòng',
        'customers'     => 'Khách hàng',
        'auth'          => 'Đăng nhập',
        'chat'          => 'Tin nhắn',
        'notifications' => 'Thông báo',
        'partners'      => 'Đối tác',
        'promotions'    => 'Khuyến mãi',
        'employees'     => 'Nhân sự',
        'warehouse'     => 'Kho',
        'cameras'       => 'Camera',
        'locks'         => 'Khoá cửa',
        'other'         => 'Khác',
    ];

    // Đoạn đầu tiên có nghĩa của đường dẫn (endpoint API hoặc màn hình app) => mục.
    private const MODULE_SEGMENTS = [
        'orders'        => ['order', 'orders', 'booking', 'bookings', 'checkin', 'checkout', 'extra-charges'],
        'payments'      => ['payment', 'payments', 'payos', 'invoices', 'escrow', 'settlements'],
        'rooms'         => ['room', 'rooms', 'room-types', 'product', 'products', 'time-slots', 'services', 'tags', 'categories', 'branches', 'search'],
        'customers'     => ['customer', 'customers', 'cccd', 'cccd-declarations', 'wishlist', 'ratings', 'profile'],
        'auth'          => ['auth', 'login', 'logout', 'register'],
        'chat'          => ['chat'],
        'notifications' => ['notifications', 'push-notification', 'fcm-token'],
        'partners'      => ['partner', 'partners', 'partner-contracts', 'partner-onboarding', 'subscription'],
        'promotions'    => ['promotions', 'coupons', 'events', 'membership-tiers'],
        'employees'     => ['employees', 'departments', 'positions', 'salary-types', 'salary-templates', 'allowance-types', 'deduction-types'],
        'warehouse'     => ['warehouse'],
        'cameras'       => ['cameras', 'camera-settings'],
        'locks'         => ['ttlock', 'ttlock-card-app', 'unlock'],
    ];

    // Tiền tố không nói lên nghiệp vụ, bỏ qua khi tìm đoạn có nghĩa.
    private const MODULE_SKIPPED_SEGMENTS = ['api', 'admin', 'v1', 'v2', 'public', 'guest', 'minihouse', 'portal'];

    protected $fillable = [
        'fingerprint',
        'kind',
        'module',
        'area',
        'method',
        'endpoint',
        'status',
        'route',
        'url',
        'message',
        'detail',
        'reporter',
        'app',
        'occurrences',
        'first_seen_at',
        'last_seen_at',
        'resolved_at',
    ];

    protected $casts = [
        'app'           => 'array',
        'status'        => 'integer',
        'occurrences'   => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at'  => 'datetime',
        'resolved_at'   => 'datetime',
    ];

    /**
     * Suy ra mục nghiệp vụ từ đường dẫn: '/admin/orders/{id}' → 'orders', 'https://…/api/v1/payment/x' → 'payments',
     * màn hình '/(tabs)/room/01k9…' → 'rooms'. Không nhận ra thì 'other'.
     */
    public static function moduleFor(?string $path): string
    {
        $path = (string) (parse_url((string) $path, PHP_URL_PATH) ?: $path);

        foreach (explode('/', Str::lower($path)) as $segment) {
            // Bỏ đoạn rỗng, tiền tố chung, tham số '{id}' / ':id' / '[id]' và nhóm route '(tabs)' của app.
            if ($segment === '' || in_array($segment, self::MODULE_SKIPPED_SEGMENTS, true) || preg_match('/^[{(\[:]/', $segment)) {
                continue;
            }

            foreach (self::MODULE_SEGMENTS as $module => $segments) {
                if (in_array($segment, $segments, true)) {
                    return $module;
                }
            }

            return 'other';
        }

        return 'other';
    }

    public function isApi(): bool
    {
        return $this->kind === 'api';
    }

    public function title(): string
    {
        if ($this->isApi()) {
            return 'App báo lỗi API: ' . trim(($this->method ?? '') . ' ' . ($this->endpoint ?? $this->url ?? '')) . ' → ' . ($this->status ?? 'không phản hồi');
        }

        return 'App bị crash: ' . Str::limit((string) $this->message, 120);
    }
}
