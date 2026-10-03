<?php

namespace Modules\BladeThemeV1\Support;

class ContentMarkup
{
    /**
     * Bổ sung markup cho nội dung bài viết lúc render (editor TinyMCE không tự thêm), để cả bài cũ
     * lẫn bài viết sau này đều đúng mà không phải sửa tay từng bài:
     * - <img> chưa có loading => thêm loading="lazy" decoding="async" (ảnh trong bài đều nằm dưới
     *   ảnh đại diện — ảnh LCP — nên lazy không ảnh hưởng LCP).
     * - <table> bọc trong khung cuộn ngang, tránh bảng nhiều cột làm vỡ layout/tràn màn hình mobile.
     */
    public static function enhance(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $html = preg_replace_callback('/<img\b[^>]*>/i', function (array $m) {
            $tag = $m[0];
            $extra = '';
            if (!preg_match('/\sloading\s*=/i', $tag)) {
                $extra .= ' loading="lazy"';
            }
            if (!preg_match('/\sdecoding\s*=/i', $tag)) {
                $extra .= ' decoding="async"';
            }

            return $extra === '' ? $tag : substr($tag, 0, 4) . $extra . substr($tag, 4);
        }, $html) ?? $html;

        if (stripos($html, '<table') !== false) {
            $html = preg_replace('/<table\b/i', '<div class="post-table-scroll" style="overflow-x:auto"><table', $html) ?? $html;
            $html = preg_replace('/<\/table\s*>/i', '</table></div>', $html) ?? $html;
        }

        return $html;
    }
}
