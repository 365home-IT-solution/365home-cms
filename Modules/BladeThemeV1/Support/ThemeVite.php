<?php

declare(strict_types=1);

namespace Modules\BladeThemeV1\Support;

use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;

/**
 * Thẻ <script>/<link> cho build Vite RIÊNG của theme (Modules/BladeThemeV1/vite.config.js →
 * public/build-bladethemev1).
 *
 * Không dùng thẳng @vite([...], 'build-bladethemev1'): @vite luôn coi public/hot là "đang chạy dev
 * server". Herd/`npm run dev` ở thư mục gốc (vite.config.js gốc) cũng ghi public/hot, nên khi dev server
 * gốc chạy, mọi asset theme bị trỏ sang dev server gốc. Server đó không phục vụ file theme → trang mất
 * toàn bộ CSS/JS. Theme dùng file hot riêng (public/hot-bladethemev1, khai báo cùng tên ở
 * vite.config.js của module): chỉ dev server của theme mới bật chế độ dev cho asset theme.
 */
final class ThemeVite
{
    public const BUILD_DIRECTORY = 'build-bladethemev1';

    public const HOT_FILE = 'hot-bladethemev1';

    /** @param array<int, string> $entrypoints */
    public static function tags(array $entrypoints): HtmlString
    {
        $vite = app(Vite::class);
        $previousHotFile = $vite->hotFile();

        // Đổi tạm hot file trên đúng instance dùng chung (giữ nguyên useStyleTagAttributes, preload
        // đã đăng ký ở AppServiceProvider), xong trả lại để @vite của build gốc không bị ảnh hưởng.
        $vite->useHotFile(public_path(self::HOT_FILE));

        try {
            return $vite($entrypoints, self::BUILD_DIRECTORY);
        } finally {
            $vite->useHotFile($previousHotFile);
        }
    }
}
