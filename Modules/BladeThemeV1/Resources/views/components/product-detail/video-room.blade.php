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
    $videoSchemaJson = json_encode([
        '@context'     => 'https://schema.org',
        '@type'        => 'VideoObject',
        'name'         => $videoSeoTitle,
        'description'  => mb_substr(strip_tags($product->short_description ?? $product->description ?? $product->name ?? ''), 0, 200),
        'contentUrl'   => $videoResolved['url'],
        'embedUrl'     => $videoResolved['embedUrl'],
        'thumbnailUrl' => $product->getFirstMediaUrl('Ảnh bìa'),
        'uploadDate'   => optional($product->created_at)->toIso8601String(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@endphp

<script type="application/ld+json">{!! $videoSchemaJson !!}</script>
@endif
