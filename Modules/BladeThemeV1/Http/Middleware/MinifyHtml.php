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
 * Không đụng vào <script>/<pre>/<textarea> và mọi comment HTML — Livewire dùng
 * <!--[if BLOCK]--> làm morph marker. Riêng NỘI DUNG <style> (không phải thuộc tính) được nén nhẹ
 * (bỏ comment CSS + khoảng trắng thừa) — Lighthouse "Minify CSS" (khối style của flash-sale ~21KB
 * nguồn, phần lớn là comment). Lần render lại qua Livewire morph chỉ thay text node của <style>
 * bằng bản chưa nén cùng luật CSS => không mất state Alpine.
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
            $minified = $this->dedupeTemplates($minified);
        }

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

    /**
     * Khối <template> lớn lặp lại Y HỆT nhau (vd khung chọn ngày của thanh tìm kiếm: bản desktop có
     * 2 instance hero-section, mỗi bản ~15KB template giống từng byte) — chỉ giữ nội dung ở lần đầu
     * (gắn data-tpl-src), các lần sau để rỗng + data-tpl-ref; public/js/alpine-blocks.js chép lại nội
     * dung TRƯỚC khi Alpine khởi động (defer, đứng trước livewire.js) nên Alpine thấy DOM y như cũ.
     * Mục đích: tỉ lệ text/HTML (Semrush). Livewire update không qua middleware này => vẫn trả nội
     * dung đầy đủ, khớp với DOM đã được chép.
     */
    private function dedupeTemplates(string $html): string
    {
        $minSize = 1024;
        $blocks = [];
        $offset = 0;

        // Chỉ lấy <template> NGOÀI CÙNG (đếm lồng nhau), bỏ qua phần nằm trong <script>.
        while (($start = stripos($html, '<template', $offset)) !== false) {
            $scriptOpen = strripos(substr($html, 0, $start), '<script');
            if ($scriptOpen !== false && stripos(substr($html, $scriptOpen, $start - $scriptOpen), '</script') === false) {
                $offset = $start + 9;
                continue;
            }

            if (! preg_match_all('~<template\b|</template\s*>~i', $html, $tags, PREG_OFFSET_CAPTURE, $start)) {
                break;
            }
            $depth = 0;
            $end = null;
            foreach ($tags[0] as [$tag, $pos]) {
                $depth += $tag[1] === '/' ? -1 : 1;
                if ($depth === 0) {
                    $end = $pos + strlen($tag);
                    break;
                }
            }
            if ($end === null) {
                break;
            }

            if ($end - $start >= $minSize) {
                $blocks[] = [$start, $end];
            }
            $offset = $end;
        }

        $seen = [];
        $replacements = [];
        foreach ($blocks as [$start, $end]) {
            $block = substr($html, $start, $end - $start);
            $hash = substr(md5($block), 0, 10);
            $openEnd = strpos($block, '>');
            $openTag = substr($block, 0, $openEnd);

            if (! isset($seen[$hash])) {
                $seen[$hash] = [$start, $openEnd];
                continue;
            }
            if ($seen[$hash] !== true) {
                [$firstStart, $firstOpenEnd] = $seen[$hash];
                $replacements[] = [$firstStart + $firstOpenEnd, 0, ' data-tpl-src="' . $hash . '"'];
                $seen[$hash] = true;
            }
            $replacements[] = [$start, $end - $start, $openTag . ' data-tpl-ref="' . $hash . '"></template>'];
        }

        // Thay từ cuối lên để offset phía trước không lệch.
        usort($replacements, fn ($a, $b) => $b[0] <=> $a[0]);
        foreach ($replacements as [$pos, $length, $text]) {
            $html = substr_replace($html, $text, $pos, $length);
        }

        return $html;
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
            $segment = $this->minifySegment($this->stripComments($blocks[$i]));

            if ($segment === null) {
                return null;
            }

            $out .= $segment;

            if (isset($blocks[$i + 1])) {
                $out .= match (true) {
                    strncasecmp($blocks[$i + 1], '<style', 6) === 0 => $this->minifyStyleBlock($blocks[$i + 1]),
                    (bool) preg_match('~^<script[^>]*application/ld\+json~i', $blocks[$i + 1]) => $this->minifyJsonLd($blocks[$i + 1]),
                    default => $blocks[$i + 1],
                };
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

    /**
     * Nén an toàn: chỉ bỏ comment và khoảng trắng quanh { } ; , — KHÔNG đụng dấu : (khoảng trắng
     * trước :hover trong selector "a :hover" có nghĩa) hay + - (calc(100% - 2px) bắt buộc có cách).
     */
    private function minifyStyleBlock(string $block): string
    {
        if (! preg_match('~^(<style[^>]*>)(.*)(</style\s*>)$~is', $block, $m)) {
            return $block;
        }

        $css = preg_replace('~/\*.*?\*/~s', '', $m[2]);
        $css = $css === null ? null : preg_replace('~\s+~', ' ', $css);
        $css = $css === null ? null : preg_replace('~\s*([{};,])\s*~', '$1', $css);

        return $css === null ? $block : $m[1] . trim($css) . $m[3];
    }

    /**
     * Bỏ comment HTML viết tay trong view (vd "<!-- Modal Container ... -->"). GIỮ marker của Livewire
     * (<!--[if BLOCK]><![endif]-->, <!--[if ENDBLOCK]>...) — bắt đầu bằng "[" — và comment chứa "__"
     * (marker morph khác): morph dựa vào chúng để khớp các khối @if/@foreach khi cập nhật.
     */
    private function stripComments(string $segment): string
    {
        if (! str_contains($segment, '<!--')) {
            return $segment;
        }

        return preg_replace_callback('~<!--.*?-->~s', function (array $m) {
            return str_starts_with($m[0], '<!--[') || str_contains($m[0], '__') ? $m[0] : '';
        }, $segment) ?? $segment;
    }

    /** JSON-LD in dạng pretty-print (~2.4KB khoảng trắng trên trang chủ) — decode/encode lại gọn. */
    private function minifyJsonLd(string $block): string
    {
        if (! preg_match('~^(<script[^>]*>)(.*)(</script\s*>)$~is', $block, $m)) {
            return $block;
        }

        $data = json_decode($m[2], true);
        if (! is_array($data)) {
            return $block;
        }

        return $m[1] . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . $m[3];
    }

    private function shrinkText(string $text): string
    {

        // Mọi chuỗi trắng có xuống dòng -> đúng 1 "\n"; nhiều khoảng trắng liền nhau -> 1 dấu cách.
        // Trình duyệt vốn đã gộp các chuỗi trắng này thành 1 khoảng trắng nên hiển thị không đổi.
        $text = preg_replace('~[ \t]*\r?\n\s*~', "\n", $text) ?? $text;

        return preg_replace('~[ \t]{2,}~', ' ', $text) ?? $text;
    }
}
