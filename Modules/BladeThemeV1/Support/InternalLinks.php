<?php

namespace Modules\BladeThemeV1\Support;

use DOMDocument;
use DOMElement;

class InternalLinks
{
    /**
     * Tham số tracking do mạng xã hội/quảng cáo gắn vào URL khi copy link (vd dán nội dung từ
     * Facebook sang TinyMCE ra href="/?fbclid=..."). Với link nội bộ chúng chỉ tạo ra URL trùng
     * lặp của cùng 1 trang, nên bỏ đi.
     */
    private const TRACKING_PARAMS = '/^(fbclid|gclid|gbraid|wbraid|msclkid|ttclid|igshid|mc_cid|mc_eid|_aem_.*|utm_.*)$/i';

    /**
     * Chuẩn hoá link NỘI BỘ trong nội dung bài viết: bỏ "nofollow" khỏi rel, bỏ tham số
     * tracking khỏi href và bỏ target="_blank".
     *
     * Nội dung dán từ Facebook mang theo rel="nofollow noopener noreferrer" + ?fbclid=... cho cả
     * link trỏ về chính 365home.vn — SEO audit flag "outgoing internal link contains nofollow
     * attribute" (nofollow chặn Google đi theo/chuyển link equity giữa các trang của mình). Xử lý
     * lúc render thay vì bắt editor sửa tay từng bài, để cả bài cũ lẫn bài dán sau này đều đúng.
     * Link ra ngoài (domain khác) giữ nguyên rel như editor đã đặt.
     */
    public static function clean(string $html): string
    {
        if (trim($html) === '' || stripos($html, '<a') === false) {
            return $html;
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        $changed = false;

        foreach ($dom->getElementsByTagName('a') as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }

            $href = trim($link->getAttribute('href'));
            if ($href === '' || !self::isInternal($href)) {
                continue;
            }

            $cleanHref = self::stripTrackingParams($href);
            if ($cleanHref !== $href) {
                $link->setAttribute('href', $cleanHref);
                $changed = true;
            }

            // Link nội bộ không mở tab mới (SEO audit: target="_blank" trên link trong cùng site).
            if (strtolower($link->getAttribute('target')) === '_blank') {
                $link->removeAttribute('target');
                $changed = true;
            }

            if ($link->hasAttribute('rel')) {
                $rel = preg_split('/\s+/', trim($link->getAttribute('rel'))) ?: [];
                $kept = array_values(array_filter($rel, fn ($r) => $r !== '' && strtolower($r) !== 'nofollow'));
                if (count($kept) !== count(array_filter($rel))) {
                    $kept ? $link->setAttribute('rel', implode(' ', $kept)) : $link->removeAttribute('rel');
                    $changed = true;
                }
            }
        }

        if (!$changed) {
            return $html;
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            return $html;
        }

        $content = '';
        foreach ($body->childNodes as $child) {
            $content .= $dom->saveHTML($child);
        }

        return $content !== '' ? $content : $html;
    }

    private static function isInternal(string $href): bool
    {
        if (str_starts_with($href, '#')) {
            return true;
        }

        // Link tương đối ("/", "/bai-viet/...") — nhưng "//host" là link tuyệt đối không có scheme.
        if (str_starts_with($href, '/') && !str_starts_with($href, '//')) {
            return true;
        }

        $host = parse_url(str_starts_with($href, '//') ? 'https:' . $href : $href, PHP_URL_HOST);
        if (!$host) {
            return false;
        }

        $host = preg_replace('/^www\./i', '', strtolower($host));
        $appHost = preg_replace('/^www\./i', '', strtolower((string) parse_url(config('app.url'), PHP_URL_HOST)));

        return $host === $appHost || $host === '365home.vn';
    }

    private static function stripTrackingParams(string $href): string
    {
        $query = parse_url($href, PHP_URL_QUERY);
        if (!$query) {
            return $href;
        }

        $kept = array_filter(explode('&', $query), function ($pair) {
            $name = urldecode(explode('=', $pair, 2)[0]);
            return $pair !== '' && !preg_match(self::TRACKING_PARAMS, $name);
        });

        $fragment = parse_url($href, PHP_URL_FRAGMENT);
        $base = strtok($href, '?#');

        return $base
            . ($kept ? '?' . implode('&', $kept) : '')
            . ($fragment !== null && $fragment !== false ? '#' . $fragment : '');
    }
}
