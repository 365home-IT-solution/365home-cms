<?php

namespace Modules\BladeThemeV1\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Co gọn khoảng trắng GIỮA các thẻ (thụt lề, dòng trống) của HTML trang khách hàng.
 *
 * Blade in nguyên indent của template ra HTML — ~20% dung lượng trang chủ chỉ là khoảng trắng,
 * kéo tỷ lệ text/HTML xuống dưới ngưỡng 10% của Semrush.
 *
 * QUAN TRỌNG — vì sao KHÔNG đụng vào bên trong thẻ (thuộc tính): middleware này chỉ minify được
 * HTML lần tải đầu, còn HTML ở các lần cập nhật sau (wire:click, $refresh...) do chính component
 * Livewire render ra, không đi qua đây. Nếu thuộc tính (nhất là x-data nhiều dòng) khác nhau giữa
 * 2 bản, morph thấy attribute đổi và Alpine khởi tạo lại x-data => mất hết state (đã gặp: ô giờ
 * đang chọn biến mất sau khi component re-render). Khoảng trắng giữa các thẻ thì an toàn: mỗi chỗ
 * vẫn còn ít nhất 1 ký tự trắng nên cây DOM (kể cả text node) giữ nguyên cấu trúc, morph chỉ chỉnh
 * nội dung của text node trắng.
 *
 * Không đụng vào <script>/<style>/<pre>/<textarea> và mọi comment HTML — Livewire dùng
 * <!--[if BLOCK]--> làm morph marker.
 */
class MinifyHtml
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $response = $next($request);

        if (! $this->shouldMinify($request, $response)) {
            return $response;
        }

        $minified = $this->minify($response->getContent());

        if ($minified !== null) {
            $response->setContent($minified);
        }

        return $response;
    }

    private function shouldMinify(Request $request, SymfonyResponse $response): bool
    {
        // Chỉ Illuminate\Http\Response thường — bỏ qua redirect/stream/file/JSON.
        if (! $response instanceof Response || $response->getStatusCode() !== 200) {
            return false;
        }

        if (! $request->isMethod('GET') || $request->headers->has('X-Livewire')) {
            return false;
        }

        return str_contains((string) $response->headers->get('Content-Type'), 'text/html');
    }

    /** Trả về null nếu regex lỗi (vượt backtrack/JIT limit) để giữ nguyên HTML gốc. */
    private function minify(string $html): ?string
    {
        // Khối giữ nguyên hoàn toàn. Với PREG_SPLIT_DELIM_CAPTURE mỗi lần khớp sinh 3 phần tử:
        // [text, khối, tên thẻ] — tên thẻ (nhóm 2) bỏ đi.
        $blocks = preg_split(
            '~(<(script|style|pre|textarea)\b[^>]*>.*?</\2\s*>)~is',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        if ($blocks === false) {
            return null;
        }

        $out = '';

        for ($i = 0, $n = count($blocks); $i < $n; $i += 3) {
            $segment = $this->minifySegment($blocks[$i]);

            if ($segment === null) {
                return null;
            }

            $out .= $segment;

            if (isset($blocks[$i + 1])) {
                $out .= $blocks[$i + 1];
            }
        }

        return $out === '' ? null : $out;
    }

    /**
     * Tách đoạn thành thẻ (giữ nguyên — nhận diện chuỗi trong nháy nên thuộc tính chứa dấu > như
     * "a => b" hay "x > 1" không cắt nhầm) và text giữa các thẻ (co gọn khoảng trắng).
     */
    private function minifySegment(string $segment): ?string
    {
        $pieces = preg_split(
            '~(<[a-zA-Z/!?](?>"[^"]*"|\'[^\']*\'|[^>"\'])*+>)~',
            $segment,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        if ($pieces === false) {
            return null;
        }

        // Với DELIM_CAPTURE, phần tử lẻ là thẻ, phần tử chẵn là text giữa các thẻ.
        foreach ($pieces as $index => $piece) {
            if ($index % 2 === 0) {
                $pieces[$index] = $this->shrinkText($piece);
            }
        }

        return implode('', $pieces);
    }

    private function shrinkText(string $text): string
    {
        // Mọi chuỗi trắng có xuống dòng -> đúng 1 "\n"; nhiều khoảng trắng liền nhau -> 1 dấu cách.
        // Trình duyệt vốn đã gộp các chuỗi trắng này thành 1 khoảng trắng nên hiển thị không đổi.
        $text = preg_replace('~[ \t]*\r?\n\s*~', "\n", $text) ?? $text;

        return preg_replace('~[ \t]{2,}~', ' ', $text) ?? $text;
    }
}
