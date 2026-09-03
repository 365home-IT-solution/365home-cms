<?php

namespace Modules\BladeThemeV1\Support;

class VideoEmbedResolver
{
    private const RATIO_MAP = [
        '16:9' => ['width' => 'min(92vw,960px)', 'padding' => '56.25%'],
        '9:16' => ['width' => 'min(55vh,380px)', 'padding' => '177.78%'],
        '4:3'  => ['width' => 'min(85vw,800px)', 'padding' => '75%'],
    ];

    /**
     * $setting là $product->setting_video_room (url/ratio/title). Trả về null nếu không có url —
     * dùng chung cho modal video (video-room.blade.php) và player inline ở cột 1 trang chi tiết
     * phòng (product-detail.blade.php), tránh lặp lại logic parse URL → embed URL ở 2 nơi.
     */
    public static function resolve(?array $setting): ?array
    {
        $setting = $setting ?? [];
        $rawUrl  = trim($setting['url'] ?? '');

        if ($rawUrl === '') {
            return null;
        }

        $ratio    = $setting['ratio'] ?? '16:9';
        $ratioCfg = self::RATIO_MAP[$ratio] ?? self::RATIO_MAP['16:9'];

        if (preg_match('/youtube\.com\/shorts\/([\w\-]{11})/', $rawUrl, $m)) {
            $embedUrl = 'https://www.youtube.com/embed/' . $m[1] . '?autoplay=1&rel=0';
            $isEmbed  = true;
        } elseif (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([\w\-]{11})/', $rawUrl, $m)) {
            $embedUrl = 'https://www.youtube.com/embed/' . $m[1] . '?autoplay=1&rel=0';
            $isEmbed  = true;
        } elseif (str_contains($rawUrl, 'youtube.com/embed/')) {
            $embedUrl = $rawUrl;
            $isEmbed  = true;
        } elseif (preg_match('/tiktok\.com.*\/video\/(\d+)/', $rawUrl, $m)) {
            $embedUrl = 'https://www.tiktok.com/embed/v2/' . $m[1];
            $isEmbed  = true;
        } elseif (preg_match('/facebook\.com/', $rawUrl)) {
            $embedUrl = 'https://www.facebook.com/plugins/video.php?href=' . urlencode($rawUrl) . '&show_text=false&autoplay=true&width=1280';
            $isEmbed  = true;
        } else {
            // Video file trực tiếp (.mp4, .webm...)
            $embedUrl = $rawUrl;
            $isEmbed  = false;
        }

        return [
            'url'       => $rawUrl,
            'embedUrl'  => $embedUrl,
            'isEmbed'   => $isEmbed,
            'ratio'     => $ratio,
            'maxWidth'  => $ratioCfg['width'],
            'aspectPct' => $ratioCfg['padding'],
            'title'     => $setting['title'] ?? null,
        ];
    }
}
