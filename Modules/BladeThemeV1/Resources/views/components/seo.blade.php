@props(['seoData'])

@php
    $gs       = app(\App\Settings\GeneralSettings::class);
    $ogTitle  = e($seoData['seo_title']        ?? $gs->og_title        ?? '');
    $ogDesc   = e($seoData['seo_description']  ?? $gs->og_description  ?? '');
    $ogType   = $seoData['og_type']            ?? $gs->og_type         ?? 'website';
    $ogLocale = $seoData['og_locale']          ?? $gs->og_locale       ?? 'vi_VN';
    $ogImage  = $seoData['og_image']           ?? ($gs->og_image ? url('/storage/' . $gs->og_image) : '');
    $canonical = $seoData['canonical_url']     ?? url()->current();
@endphp

@section('title'){{ $seoData['seo_title'] ?? $gs->og_title ?? '' }}@endsection

@section('meta')
    {{-- Basic --}}
    <meta name="description" content="{{ $ogDesc }}">
    <meta name="keywords"    content="{{ e($seoData['seo_keywords'] ?? '') }}">
    @if(!empty($seoData['author_name'] ?? $gs->author ?? ''))
        <meta name="author" content="{{ e($seoData['author_name'] ?? $gs->author) }}">
    @endif

    {{-- Canonical --}}
    <link rel="canonical" href="{{ $canonical }}">

    {{-- Open Graph --}}
    <meta property="og:type"        content="{{ $ogType }}">
    <meta property="og:url"         content="{{ url()->current() }}">
    <meta property="og:title"       content="{{ $ogTitle }}">
    <meta property="og:description" content="{{ $ogDesc }}">
    <meta property="og:locale"      content="{{ $ogLocale }}">
    @if($ogImage)
        <meta property="og:image" content="{{ $ogImage }}">
    @endif

    {{-- Article --}}
    @if(!empty($seoData['article_published_time']))
        <meta property="article:published_time" content="{{ $seoData['article_published_time'] }}">
    @endif
    @if(!empty($seoData['article_modified_time']))
        <meta property="article:modified_time" content="{{ $seoData['article_modified_time'] }}">
    @endif

    {{-- Twitter --}}
    <meta name="twitter:card"        content="{{ $gs->twitter_card ?? 'summary_large_image' }}">
    <meta name="twitter:url"         content="{{ url()->current() }}">
    <meta name="twitter:title"       content="{{ $ogTitle }}">
    <meta name="twitter:description" content="{{ $ogDesc }}">
    @if($ogImage)
        <meta name="twitter:image" content="{{ $ogImage }}">
    @endif
    @if($gs->twitter_site ?? '')
        <meta name="twitter:site"    content="{{ $gs->twitter_site }}">
    @endif
    @if($gs->twitter_creator ?? '')
        <meta name="twitter:creator" content="{{ $gs->twitter_creator }}">
    @endif

    {{-- JSON-LD Structured Data --}}
    @if($ogType === 'article')
        @php
            // Rich Results Test flag "author" thiếu name/url khi rỗng — site chưa có trang hồ sơ
            // tác giả riêng nên dùng luôn trang chủ làm url; không có tên tác giả thật (user null
            // hoặc chưa điền fullname/name) thì quy về Organization (chính site) thay vì Person
            // tên rỗng, tránh lặp lại đúng lỗi vừa bị flag.
            $authorName = trim((string) ($seoData['author_name'] ?? ''));

            $schema = [
                '@context'      => 'https://schema.org',
                '@type'         => 'Article',
                'headline'      => $seoData['seo_title']      ?? '',
                'description'   => $seoData['seo_description'] ?? '',
                'url'           => url()->current(),
                'author'        => $authorName !== ''
                    ? ['@type' => 'Person', 'name' => $authorName, 'url' => url('/')]
                    : ['@type' => 'Organization', 'name' => $seoData['site_name'] ?? config('app.name'), 'url' => url('/')],
                'publisher'     => ['@type' => 'Organization', 'name' => $seoData['site_name'] ?? config('app.name'), 'url' => url('/')],
                'datePublished' => $seoData['article_published_time'] ?? '',
                'dateModified'  => $seoData['article_modified_time']  ?? '',
            ];
            if ($ogImage) $schema['image'] = $ogImage;

            // Chỉ gắn khi đã có bình chọn thật (post_ratings) — Google từ chối AggregateRating
            // 0 sao/rỗng. LƯU Ý: chính sách review rich-result của Google hiện chỉ áp dụng cho
            // Product/Recipe/LocalBusiness..., KHÔNG áp dụng cho Article/BlogPosting — gắn đúng
            // schema này vẫn có khả năng Google không hiển thị sao ngoài kết quả tìm kiếm, hoặc
            // hiển thị một thời gian rồi ngừng nếu chính sách siết lại. Đã trao đổi rủi ro này với
            // yêu cầu nghiệp vụ trước khi thêm.
            if (!empty($seoData['rating_count'])) {
                $schema['aggregateRating'] = [
                    '@type'       => 'AggregateRating',
                    'ratingValue' => (string) $seoData['rating_average'],
                    'ratingCount' => (int) $seoData['rating_count'],
                    'bestRating'  => '5',
                    'worstRating' => '1',
                ];
            }
        @endphp

    @elseif($ogType === 'product')
        @php
            $schema = [
                '@context'    => 'https://schema.org',
                '@type'       => 'Product',
                'name'        => $seoData['seo_title']       ?? '',
                'description' => $seoData['seo_description'] ?? '',
                'url'         => url()->current(),
            ];
            if ($ogImage) $schema['image'] = [$ogImage];

            // Brand — dùng seoData trước, fallback về GeneralSettings
            $brandName = $seoData['brand_name'] ?? $gs->brand_name ?? '';
            if (!empty($brandName)) {
                $schema['brand'] = ['@type' => 'Brand', 'name' => $brandName];
            }

            // SKU làm định danh toàn cầu
            if (!empty($seoData['offer_sku'])) {
                $schema['sku'] = (string) $seoData['offer_sku'];
            }

            if (isset($seoData['offer_price'])) {
                $schema['offers'] = [
                    '@type'         => 'Offer',
                    'priceCurrency' => $seoData['offer_currency']   ?? 'VND',
                    'price'         => (string) $seoData['offer_price'],
                    'availability'  => 'https://schema.org/' . ($seoData['offer_availability'] ?? 'InStock'),
                    'url'           => $seoData['offer_url']        ?? url()->current(),
                    'shippingDetails' => [
                        '@type'               => 'OfferShippingDetails',
                        'shippingRate'        => [
                            '@type'    => 'MonetaryAmount',
                            'value'    => '0',
                            'currency' => 'VND',
                        ],
                        'shippingDestination' => [
                            '@type'          => 'DefinedRegion',
                            'addressCountry' => 'VN',
                        ],
                        'deliveryTime' => [
                            '@type'        => 'ShippingDeliveryTime',
                            'handlingTime' => [
                                '@type'    => 'QuantitativeValue',
                                'minValue' => 0,
                                'maxValue' => 1,
                                'unitCode' => 'DAY',
                            ],
                            'transitTime' => [
                                '@type'    => 'QuantitativeValue',
                                'minValue' => 1,
                                'maxValue' => 3,
                                'unitCode' => 'DAY',
                            ],
                        ],
                    ],
                    'hasMerchantReturnPolicy' => [
                        '@type'                => 'MerchantReturnPolicy',
                        'applicableCountry'    => 'VN',
                        'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
                        'merchantReturnDays'   => 7,
                        'returnMethod'         => 'https://schema.org/ReturnByMail',
                        'returnFees'           => 'https://schema.org/FreeReturn',
                    ],
                ];
            }

            // Chỉ gắn khi có đánh giá thật (RoomRating) — Product là loại được Google chính thức hỗ
            // trợ hiện sao ngoài SERP (khác Article), không cần lo chính sách như AggregateRating ở
            // schema Article phía trên.
            if (!empty($seoData['rating_count'])) {
                $schema['aggregateRating'] = [
                    '@type'       => 'AggregateRating',
                    'ratingValue' => (string) $seoData['rating_average'],
                    'ratingCount' => (int) $seoData['rating_count'],
                    'bestRating'  => '5',
                    'worstRating' => '1',
                ];
            }

            if (!empty($seoData['reviews'])) {
                $schema['review'] = array_map(fn ($r) => [
                    '@type'         => 'Review',
                    'author'        => ['@type' => 'Person', 'name' => $r['author']],
                    'reviewRating'  => [
                        '@type'       => 'Rating',
                        'ratingValue' => (string) $r['star'],
                        'bestRating'  => '5',
                        'worstRating' => '1',
                    ],
                    'reviewBody'    => $r['comment'] ?? '',
                    'datePublished' => $r['date'] ?? '',
                ], $seoData['reviews']);
            }
        @endphp

    @else
        @php
            $schema = [
                '@context'    => 'https://schema.org',
                '@type'       => 'WebPage',
                'name'        => $seoData['seo_title']       ?? '',
                'description' => $seoData['seo_description'] ?? '',
                'url'         => url()->current(),
            ];
            if ($ogImage) $schema['image'] = $ogImage;
        @endphp
    @endif

    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}</script>

    {{-- VideoObject schema cho từng YouTube video embed trên trang --}}
    @if(!empty($seoData['video_ids']))
        @foreach($seoData['video_ids'] as $videoId)
            @php
                $videoSchema = [
                    '@context'      => 'https://schema.org',
                    '@type'         => 'VideoObject',
                    'name'          => ($seoData['video_name'] ?? '') . ' - Video',
                    'description'   => $seoData['video_description'] ?? $seoData['seo_description'] ?? '',
                    'thumbnailUrl'  => [
                        'https://img.youtube.com/vi/' . $videoId . '/maxresdefault.jpg',
                        'https://img.youtube.com/vi/' . $videoId . '/hqdefault.jpg',
                    ],
                    'uploadDate'    => $seoData['video_upload_date'] ?? now()->toIso8601String(),
                    'contentUrl'    => 'https://www.youtube.com/watch?v=' . $videoId,
                    'embedUrl'      => 'https://www.youtube.com/embed/' . $videoId,
                    'url'           => url()->current(),
                ];
            @endphp
            <script type="application/ld+json">{!! json_encode($videoSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}</script>
        @endforeach
    @endif
@endsection
