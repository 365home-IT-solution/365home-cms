{{--
 * VIDEO PHÒNG — SEO schema
 * Chỉnh URL, tỉ lệ, tiêu đề SEO → vào admin (Filament) > Sản phẩm > Video phòng
 *
 * Việc hiển thị/phát video giờ nằm trong lightbox chung của gallery (video là slide đầu tiên,
 * xem product-detail.blade.php) — file này chỉ còn phát ra JSON-LD VideoObject cho SEO.
 *
 * Biến nhận vào (từ @include):
 *   $product  — Eloquent Product model (bắt buộc)
--}}
@php
    $videoSetting  = is_array($product->setting_video_room) ? $product->setting_video_room : [];
    $videoResolved = \Modules\BladeThemeV1\Support\VideoEmbedResolver::resolve($videoSetting);
    $videoSeoTitle = $videoSetting['title'] ?? ($product->name . ' - Video phòng');
@endphp

@if($videoResolved)
@php
    // Nội dung soạn thảo lưu dạng HTML entity (&ndash;, &ocirc;...) — giải mã (2 lần, phòng trường hợp
    // bị mã hoá kép) để JSON-LD chứa chữ thật. contentUrl chỉ dành cho FILE video trực tiếp (.mp4...);
    // video YouTube/TikTok chỉ cần embedUrl, ghi link trang xem (youtu.be/...) vào contentUrl là sai.
    $videoDecode = fn ($t) => trim(html_entity_decode(html_entity_decode(strip_tags((string) $t), ENT_QUOTES | ENT_HTML5), ENT_QUOTES | ENT_HTML5));
    $videoIsFile = (bool) preg_match('/\.(mp4|webm|mov|m4v)(\?|#|$)/i', (string) ($videoResolved['url'] ?? ''));
    $videoSchemaJson = json_encode(array_filter([
        '@context'     => 'https://schema.org',
        '@type'        => 'VideoObject',
        'name'         => $videoDecode($videoSeoTitle),
        'description'  => mb_substr($videoDecode($product->short_description ?: ($product->description ?: $product->name)), 0, 200),
        'contentUrl'   => $videoIsFile ? $videoResolved['url'] : null,
        'embedUrl'     => $videoResolved['embedUrl'],
        'thumbnailUrl' => $product->getFirstMediaUrl('Ảnh bìa'),
        'uploadDate'   => optional($product->created_at)->toIso8601String(),
    ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@endphp

<script type="application/ld+json">{!! $videoSchemaJson !!}</script>
@endif
