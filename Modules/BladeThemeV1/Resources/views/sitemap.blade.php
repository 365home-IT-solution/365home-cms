<?php echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>

<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">

    {{-- ==================== PAGES (từ menu) ==================== --}}
    @foreach($pageUrls as $page)
    <url>
        <loc>{{ $page['url'] }}</loc>
        <lastmod>{{ $page['lastmod'] }}</lastmod>
        <changefreq>weekly</changefreq>
        <priority>{{ $page['url'] === url('/') ? '1.0' : '0.8' }}</priority>
    </url>
    @endforeach

    {{-- ==================== BÀI VIẾT ==================== --}}
    @foreach($posts as $post)
    <url>
        <loc>{{ url('/bai-viet/' . $post->slug) }}</loc>
        <lastmod>{{ ($post->updated_at ?? $post->published_at)?->toAtomString() }}</lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.7</priority>
    </url>
    @endforeach

    {{-- ==================== PHÒNG / SẢN PHẨM ==================== --}}
    @foreach($rooms as $room)
    <url>
        <loc>{{ $room->url }}</loc>
        <lastmod>{{ $room->updated_at?->toAtomString() }}</lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.9</priority>
    </url>
    @endforeach

    {{-- ==================== MẪU GIAO DIỆN / DỊCH VỤ ==================== --}}
    @foreach($templates as $template)
    <url>
        <loc>{{ url('/mau-giao-dien/' . $template->slug) }}</loc>
        <lastmod>{{ $template->updated_at?->toAtomString() }}</lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.6</priority>
    </url>
    @endforeach

</urlset>
