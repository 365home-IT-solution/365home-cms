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

    /**
     * Circuit breaker trong-request: sau khi Redis fail 1 lần, các lệnh cache
     * khác trong CÙNG request bỏ qua thử kết nối lại — dùng static property
     * (in-memory) thay vì file cache, vì không phụ thuộc quyền ghi file/đĩa,
     * luôn đáng tin cậy. Không cần nhớ qua nhiều request vì "connection refused"
     * trên Linux là tức thì (không tốn thời gian) khi Redis chưa chạy.
     */
    private static bool $redisDownThisRequest = false;

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
     */
    private static function remember(string $key, Closure $callback): mixed
    {
        if (self::$redisDownThisRequest) {
            return $callback();
        }

        try {
            return Cache::store('redis')->remember($key, now()->addHours(self::TTL_HOURS), $callback);
        } catch (Throwable $e) {
            self::$redisDownThisRequest = true;

            Log::warning('ThemeCache: redis store unavailable, falling back to direct query for the rest of this request', [
                'error' => $e->getMessage(),
            ]);

            return $callback();
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
        if (self::$redisDownThisRequest) {
            return;
        }

        try {
            Cache::store('redis')->forget($key);
        } catch (Throwable $e) {
            self::$redisDownThisRequest = true;

            Log::warning('ThemeCache: failed to forget cache key (redis unavailable)', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
