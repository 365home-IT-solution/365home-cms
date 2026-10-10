<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use App\Models\User;
use App\Services\AdminNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * POST /api/app-error-reports — app (khách lẫn admin) tự báo lỗi gặp phải (API trả lỗi, crash) để
 * Super Admin biết ngay qua thông báo admin (type 'app_error', xem App\Services\AdminNotificationService).
 *
 * Công khai vì khách vãng lai cũng gặp lỗi; có Bearer token thì ghi nhận đúng người gửi theo token
 * (KHÔNG tin field 'user' trong body — chỉ dùng làm thông tin tham khảo khi không có token).
 *
 * Quy ước với app: luôn trả 202 khi body hợp lệ, kể cả khi gửi thông báo thất bại — app coi 5xx là
 * "giữ lại, 30 giây sau gửi lại" nên lỗi phía thông báo mà trả 5xx sẽ làm cùng 1 lỗi bị báo lặp.
 *
 * Chống dội thông báo (app đã tự gộp theo từng máy, nhưng 1 lỗi server thì MỌI máy cùng báo):
 *   - Cùng 1 lỗi (api: method + endpoint + status; crash: area + message) chỉ thông báo 1 lần mỗi
 *     NOTIFY_INTERVAL giây trên toàn hệ thống.
 *   - Tối đa MAX_NOTIFICATIONS_PER_HOUR thông báo/giờ cho mọi lỗi cộng lại — endpoint công khai nên
 *     ai cũng có thể bịa lỗi khác nhau liên tục để làm ngập chuông thông báo của admin.
 * Bản ghi nào cũng được ghi log đầy đủ, kể cả khi không thông báo.
 */
class AppErrorReportController extends Controller
{
    private const NOTIFY_INTERVAL = 600;

    private const MAX_NOTIFICATIONS_PER_HOUR = 30;

    public function __construct(private AdminNotificationService $notifications) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'app'                 => 'nullable|array',
            'app.platform'        => 'nullable|string|max:20',
            'app.os_version'      => 'nullable|string|max:50',
            'app.version'         => 'nullable|string|max:50',
            'app.runtime_version' => 'nullable|string|max:50',
            'app.update_id'       => 'nullable|string|max:100',
            'app.channel'         => 'nullable|string|max:50',
            'user'                => 'nullable|array',
            'user.id'             => 'nullable',
            'user.role'           => 'nullable|string|max:50',
            'reports'             => 'required|array|min:1|max:20',
            'reports.*.kind'      => 'required|string|max:20',
            'reports.*.area'      => 'nullable|string|max:50',
            'reports.*.route'     => 'nullable|string',
            'reports.*.method'    => 'nullable|string|max:10',
            'reports.*.endpoint'  => 'nullable|string',
            'reports.*.url'       => 'nullable|string',
            'reports.*.status'    => 'nullable|integer',
            'reports.*.message'   => 'nullable|string',
            'reports.*.detail'    => 'nullable|string',
            'reports.*.count'     => 'nullable|integer|min:1',
            'reports.*.first_at'  => 'nullable|string|max:40',
            'reports.*.last_at'   => 'nullable|string|max:40',
        ]);

        $app      = $validated['app'] ?? [];
        $reporter = $this->reporter($validated['user'] ?? []);

        foreach ($validated['reports'] as $report) {
            $report = $this->normalize($report);

            Log::warning('App error report', ['report' => $report, 'app' => $app, 'reporter' => $reporter, 'ip' => $request->ip()]);

            try {
                $this->notifyAdmins($report, $app, $reporter);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json(['ok' => true], 202);
    }

    /** Người gửi: theo token nếu có (khách hoặc nhân viên), không thì theo thông tin app tự khai. */
    private function reporter(array $claimed): array
    {
        $user = auth('sanctum')->user();

        if ($user instanceof User) {
            return ['type' => 'staff', 'id' => $user->getKey(), 'label' => 'Nhân viên ' . ($user->fullname ?: $user->email) . " (#{$user->getKey()})"];
        }

        if ($user instanceof Customer) {
            return ['type' => 'customer', 'id' => $user->getKey(), 'label' => 'Khách ' . ($user->fullname ?: $user->phone) . " (#{$user->getKey()})"];
        }

        if (filled($claimed['id'] ?? null)) {
            $id   = Str::limit((string) $claimed['id'], 40, '');
            $role = $claimed['role'] ?? 'user';

            return ['type' => 'claimed', 'id' => $id, 'label' => "{$role} #{$id} (app tự khai, không có token hợp lệ)"];
        }

        return ['type' => 'guest', 'id' => null, 'label' => 'Khách vãng lai'];
    }

    /** Cắt ngắn các field tự do — body công khai nên không để 1 request nhét cả MB vào log/thông báo. */
    private function normalize(array $report): array
    {
        return [
            'kind'     => Str::lower($report['kind']),
            'area'     => $report['area'] ?? null,
            'route'    => $this->limit($report['route'] ?? null, 255),
            'method'   => isset($report['method']) ? Str::upper($report['method']) : null,
            'endpoint' => $this->limit($report['endpoint'] ?? null, 255),
            'url'      => $this->limit($report['url'] ?? null, 500),
            'status'   => $report['status'] ?? null,
            'message'  => $this->limit($report['message'] ?? null, 500),
            'detail'   => $this->limit($report['detail'] ?? null, 4000),
            'count'    => (int) ($report['count'] ?? 1),
            'first_at' => $report['first_at'] ?? null,
            'last_at'  => $report['last_at'] ?? null,
        ];
    }

    private function limit(?string $value, int $max): ?string
    {
        return $value === null ? null : Str::limit($value, $max);
    }

    private function notifyAdmins(array $report, array $app, array $reporter): void
    {
        $fingerprint = $report['kind'] === 'api'
            ? implode('|', ['api', $report['method'], $report['endpoint'] ?? $report['url'], $report['status']])
            : implode('|', [$report['kind'], $report['area'], $report['message']]);

        if (! Cache::add('app-error-notify:' . sha1($fingerprint), true, self::NOTIFY_INTERVAL)) {
            return;
        }

        if (! RateLimiter::attempt('app-error-notify', self::MAX_NOTIFICATIONS_PER_HOUR, fn () => true, 3600)) {
            return;
        }

        $isApi   = $report['kind'] === 'api';
        $summary = $isApi
            ? trim(($report['method'] ?? '') . ' ' . ($report['endpoint'] ?? $report['url'] ?? '')) . ' → ' . ($report['status'] ?? 'không phản hồi')
            : Str::limit((string) $report['message'], 120);

        $lines = array_filter([
            $isApi ? $report['message'] : null,
            $report['route'] ? 'Màn hình: ' . $report['route'] : null,
            'Người gặp: ' . $reporter['label'] . ($report['count'] > 1 ? " — {$report['count']} lần" : ''),
            'App: ' . trim(($app['platform'] ?? '?') . ' ' . ($app['os_version'] ?? '') . ' · v' . ($app['version'] ?? '?') . ' · ' . ($app['channel'] ?? '?')),
        ]);

        $this->notifications->notify(
            User::role(config('filament-shield.super_admin.name'))->get(),
            ($isApi ? 'App báo lỗi API: ' : 'App bị crash: ') . $summary,
            implode("\n", $lines),
            [
                'type'     => 'app_error',
                'kind'     => $report['kind'],
                'area'     => $report['area'],
                'route'    => $report['route'],
                'endpoint' => $report['endpoint'],
                'status'   => $report['status'],
            ],
            'heroicon-o-bug-ant',
            'danger',
        );
    }
}
