<?php

namespace Modules\BladeThemeV1\Support;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Str;

class TableOfContents
{
    /**
     * Parse HTML nội dung bài viết server-side: gắn id (slug tiếng Việt, không dấu, duy nhất
     * trong bài) trực tiếp vào từng thẻ heading và trả về cây mục lục lồng cấp tương ứng.
     *
     * Làm ở server thay vì JS (bản cũ) vì 2 lý do:
     * - id phải có sẵn trong HTML gốc để bot không chạy JS / trình đọc màn hình / link chia sẻ
     *   kèm #anchor vẫn hoạt động đúng ngay từ lần render đầu tiên.
     * - JS cũ tự tạo id bằng cách hạ thường + thay dấu cách, không bỏ dấu tiếng Việt lẫn ký tự
     *   đặc biệt (?, :, "...) và không chống trùng — nhiều bài ra id trùng nhau (id trùng là HTML
     *   không hợp lệ, phá cả điều hướng #anchor).
     *
     * H1 không được lấy vào mục lục: H1 dành riêng cho tiêu đề bài viết (post-detail.blade.php),
     * TinyMCE cũng đã bỏ tuỳ chọn Heading 1 ở khung nội dung — bài cũ lỡ có H1 kẹt trong content
     * vẫn không nên xuất hiện thành 1 mục lục cấp 1 riêng gây rối cây mục lục.
     *
     * @return array{content: string, items: array}
     */
    public static function build(string $html): array
    {
        $html = trim($html);

        if ($html === '') {
            return ['content' => $html, 'items' => []];
        }

        // Không dùng LIBXML_HTML_NOIMPLIED: thiếu <body> bao ngoài khiến libxml lồng nhầm heading
        // sau vào bên trong heading trước (vd <h3> thành con của <h2>) thay vì 2 thẻ anh em.
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $headings = $xpath->query('//h2 | //h3 | //h4 | //h5 | //h6');

        if ($headings === false || $headings->length === 0) {
            return ['content' => $html, 'items' => []];
        }

        $usedSlugs = [];
        $root = [];
        // Ngăn xếp giữ tham chiếu tới mảng 'children' của node vừa thêm ở mỗi cấp, để heading kế
        // tiếp (nếu sâu hơn) được lồng đúng vào bên trong node cha gần nhất thay vì rớt ra cấp 1.
        $stack = [['level' => 1, 'children' => &$root]];

        foreach ($headings as $heading) {
            if (!$heading instanceof \DOMElement) {
                continue;
            }

            $level = (int) substr($heading->nodeName, 1);
            $text = trim($heading->textContent);

            if ($text === '') {
                continue;
            }

            $slug = Str::slug($text);
            if ($slug === '') {
                $slug = 'muc-' . (count($usedSlugs) + 1);
            }

            $base = $slug;
            $i = 2;
            while (isset($usedSlugs[$slug])) {
                $slug = $base . '-' . $i++;
            }
            $usedSlugs[$slug] = true;

            $heading->setAttribute('id', $slug);

            while (count($stack) > 1 && end($stack)['level'] >= $level) {
                array_pop($stack);
            }

            $parent = &$stack[count($stack) - 1]['children'];
            $parent[] = ['level' => $level, 'text' => $text, 'id' => $slug, 'children' => []];
            $lastKey = array_key_last($parent);
            $stack[] = ['level' => $level, 'children' => &$parent[$lastKey]['children']];
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        $content = '';

        if ($body) {
            foreach ($body->childNodes as $child) {
                $content .= $dom->saveHTML($child);
            }
        }

        return ['content' => $content !== '' ? $content : $html, 'items' => $root];
    }
}
