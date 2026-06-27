<?php

namespace Modules\BladeThemeV1\Support;

use App\Settings\GeneralSettings;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Menu\Entities\Menu;
use Throwable;

class ThemeCache
{
    public const GENERAL_SETTINGS_KEY = 'bladethemev1:general_settings';
    public const MENU_ROUTES_KEY = 'bladethemev1:menu:routes';
    public const MENU_HEADER_KEY = 'bladethemev1:menu:header';
    public const MENU_BY_ID_PREFIX = 'bladethemev1:menu:by_id:';
    public const TTL_HOURS = 6;

    /** Circuit breaker: sau khi Redis fail 1 lần, bỏ qua thử kết nối lại trong khoảng này. */
    private const REDIS_DOWN_FLAG = 'bladethemev1:redis_down';
    private const CIRCUIT_BREAKER_SECONDS = 30;

    public static function generalSettings(): GeneralSettings
    {
        return self::remember(self::GENERAL_SETTINGS_KEY, fn () => app(GeneralSettings::class));
    }

    public static function menuById(int $menuId): mixed
    {
        return self::remember(self::MENU_BY_ID_PREFIX . $menuId, fn () => Menu::find($menuId)?->menuItems);
    }

    public static function menuForHeader(): ?Menu
    {
        return self::remember(self::MENU_HEADER_KEY, function () {
            return Menu::query()
                ->with([
                    'menuItems' => function ($query) {
                        $query->whereNull('parent_id')
                            ->orderBy('order')
                            ->with(['children' => function ($query) {
                                $query->orderBy('order')
                                    ->with(['children' => function ($query) {
                                        $query->orderBy('order');
                                    }]);
                            }]);
                    },
                    'locations' => function ($query) {
                        $query->where('location', 'header');
                    }
                ])
                ->whereHas('locations', function ($query) {
                    $query->where('location', 'header');
                })
                ->where('is_visible', true)
                ->first();
        });
    }

    public static function menuForRoutes(): Collection
    {
        return self::remember(self::MENU_ROUTES_KEY, function () {
            try {
                return Menu::query()
                    ->with([
                        'menuItems' => function ($query) {
                            $query->whereNull('parent_id')
                                ->orderBy('order')
                                ->with(['children' => function ($query) {
                                    $query->orderBy('order')
                                        ->with(['children' => function ($query) {
                                            $query->orderBy('order');
                                        }]);
                                }]);
                        },
                        'locations'
                    ])
                    ->where('is_visible', true)
                    ->get();
            } catch (Throwable $e) {
                return collect();
            }
        });
    }

    /**
     * Dùng store "redis" riêng (config/cache.php) thay vì store mặc định của app —
     * để cache hệ thống (session, plugin khác...) không bị kéo theo phụ thuộc Redis.
     * Nếu Redis lỗi/chưa cài (vd vừa deploy lên VPS chưa kịp setup), fallback tính
     * trực tiếp từ DB thay vì throw lỗi 500 ra toàn site.
     *
     * Circuit breaker: nếu vừa phát hiện Redis down, bỏ qua thử kết nối trong
     * CIRCUIT_BREAKER_SECONDS — tránh mỗi cache key trong request đều phải chờ
     * timeout kết nối riêng (1 request có thể gọi 4-5 key khác nhau).
     */
    private static function remember(string $key, Closure $callback): mixed
    {
        if (self::isRedisMarkedDown()) {
            return $callback();
        }

        try {
            return Cache::store('redis')->remember($key, now()->addHours(self::TTL_HOURS), $callback);
        } catch (Throwable $e) {
            self::markRedisDown($e);

            return $callback();
        }
    }

    private static function isRedisMarkedDown(): bool
    {
        try {
            return Cache::store('file')->has(self::REDIS_DOWN_FLAG);
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function markRedisDown(Throwable $e): void
    {
        Log::warning('ThemeCache: redis store unavailable, falling back to direct query for ' . self::CIRCUIT_BREAKER_SECONDS . 's', [
            'error' => $e->getMessage(),
        ]);

        try {
            Cache::store('file')->put(self::REDIS_DOWN_FLAG, true, self::CIRCUIT_BREAKER_SECONDS);
        } catch (Throwable $e) {
            // file cache cũng lỗi (vd storage/framework/cache không writable) — bỏ qua,
            // lần gọi kế tiếp sẽ lại tự thử Redis và fail nhanh nhờ connection timeout.
        }
    }

    public static function flushAll(): void
    {
        self::safeForget(self::GENERAL_SETTINGS_KEY);
        self::safeForget(self::MENU_ROUTES_KEY);
        self::safeForget(self::MENU_HEADER_KEY);
    }

    public static function flushMenuById(int $menuId): void
    {
        self::safeForget(self::MENU_BY_ID_PREFIX . $menuId);
    }

    private static function safeForget(string $key): void
    {
        if (self::isRedisMarkedDown()) {
            return;
        }

        try {
            Cache::store('redis')->forget($key);
        } catch (Throwable $e) {
            self::markRedisDown($e);
        }
    }
}
