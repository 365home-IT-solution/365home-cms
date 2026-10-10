@props(['seoData'])

@php
    $gs       = app(\App\Settings\GeneralSettings::class);
    // KHÔNG bọc e() ở đây: các biến này chỉ được in ra qua {{ }} (đã tự escape) — bọc thêm e()
    // là escape 2 lần, "&" trong tiêu đề ra thành "&amp;amp;" ở og:title/twitter:title/alt.
    $ogTitle  = $seoData['seo_title']        ?? $gs->og_title        ?? '';
    $ogDesc   = $seoData['seo_description']  ?? $gs->og_description  ?? '';
    $ogType   = $seoData['og_type']            ?? $gs->og_type         ?? 'website';
    $ogLocale = $seoData['og_locale']          ?? $gs->og_locale       ?? 'vi_VN';
    $ogImage  = $seoData['og_image']           ?? ($gs->og_image ? url('/storage/' . $gs->og_image) : '');
    $canonical = $seoData['canonical_url']     ?? url()->current();

    // twitter:site / twitter:creator phải là @handle (chữ, số, gạch dưới, tối đa 15 ký tự). Cài
    // đặt chung từng bị nhập tên người ("Nguyễn An Khoa") — giá trị không phải handle hợp lệ thì
    // bỏ hẳn thẻ thay vì in ra sai.
    $twitterHandle = function (?string $value): ?string {
        $handle = ltrim(trim((string) $value), '@');
        return preg_match('/^[A-Za-z0-9_]{1,15}$/', $handle) ? '@' . $handle : null;
    };
    $twitterSite    = $twitterHandle($gs->twitter_site ?? '');
    $twitterCreator = $twitterHandle($gs->twitter_creator ?? '');
@endphp

@section('title'){{ $seoData['seo_title'] ?? $gs->og_title ?? '' }}@endsection

@section('meta')
    {{-- Basic --}}
    <meta name="description" content="{{ $ogDesc }}">
    <meta name="keywords"    content="{{ $seoData['seo_keywords'] ?? '' }}">
    @if(!empty($seoData['author_name'] ?? $gs->author ?? ''))
        <meta name="author" content="{{ $seoData['author_name'] ?? $gs->author }}">
    @endif

    {{-- Canonical --}}
    <link rel="canonical" href="{{ $canonical }}">

    {{-- Open Graph --}}
    <meta property="og:type"        content="{{ $ogType }}">
    <meta property="og:url"         content="{{ url()->current() }}">
    <meta property="og:title"       content="{{ $ogTitle }}">
    <meta property="og:description" content="{{ $ogDesc }}">
    <meta property="og:locale"      content="{{ $ogLocale }}">
    <meta property="og:site_name"   content="{{ $seoData['site_name'] ?? config('app.name') }}">
    @if($ogImage)
        <meta property="og:image" content="{{ $ogImage }}">
        @if(!empty($seoData['og_image_width']) && !empty($seoData['og_image_height']))
            <meta property="og:image:width"  content="{{ $seoData['og_image_width'] }}">
            <meta property="og:image:height" content="{{ $seoData['og_image_height'] }}">
        @endif
        {{-- og:image:alt: dùng seo_title (tiêu đề hiển thị) làm alt, cùng nguồn dữ liệu với alt
             của ảnh đại diện ở post-detail.blade.php (post->title). --}}
        <meta property="og:image:alt" content="{{ $ogTitle }}">
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
        <meta name="twitter:image:alt" content="{{ $ogTitle }}">
    @endif
    @if($twitterSite)
        <meta name="twitter:site"    content="{{ $twitterSite }}">
    @endif
    @if($twitterCreator)
        <meta name="twitter:creator" content="{{ $twitterCreator }}">
    @endif

    {{-- JSON-LD Structured Data --}}
    @if($ogType === 'article')
        @php
            // Rich Results Test flag "author" thiếu name/url khi rỗng — site chưa có trang hồ sơ
            // tác giả riêng nên dùng luôn trang chủ làm url; không có tên tác giả thật (user null
            // hoặc chưa điền fullname/name) thì quy về Organization (chính site) thay vì Person
            // tên rỗng, tránh lặp lại đúng lỗi vừa bị flag.
            $authorName = trim((string) ($seoData['author_name'] ?? ''));

            $publisher = ['@type' => 'Organization', 'name' => $seoData['site_name'] ?? config('app.name'), 'url' => url('/')];
            if (!empty($gs->brand_logo)) {
                $publisher['logo'] = ['@type' => 'ImageObject', 'url' => url('/storage/' . $gs->brand_logo)];
            }

            $schema = [
                '@context'         => 'https://schema.org',
                '@type'            => 'Article',
                'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
                'headline'         => $seoData['seo_title']      ?? '',
                'description'      => $seoData['seo_description'] ?? '',
                'inLanguage'       => 'vi-VN',
                'url'              => url()->current(),
                'author'           => $authorName !== ''
                    ? ['@type' => 'Person', 'name' => $authorName, 'url' => url('/')]
                    : ['@type' => 'Organization', 'name' => $seoData['site_name'] ?? config('app.name'), 'url' => url('/')],
                'publisher'        => $publisher,
                'datePublished'    => $seoData['article_published_time'] ?? '',
                'dateModified'     => $seoData['article_modified_time']  ?? '',
            ];
            if ($ogImage) $schema['image'] = [$ogImage];

            // KHÔNG gắn aggregateRating cho bài viết: Article không nằm trong danh sách @type Google
            // cho phép chứa aggregateRating, còn node "CreativeWorkSeries" riêng (từng dùng để lách)
            // không phản ánh đúng nội dung trang — SEO audit đánh dấu là spam markup, đã gỡ.
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
            // trợ hiện sao ngoài SERP (khác Article — bài viết không gắn aggregateRating).
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

    {{-- LodgingBusiness (con của LocalBusiness) — CHỈ gắn ở trang chủ, không lặp lại ở mọi trang
         (tránh trùng lặp schema không cần thiết). NAP (tên/địa chỉ/SĐT) PHẢI khớp chính xác với
         Google Business Profile — sai lệch dù nhỏ giữa 2 nguồn làm giảm tín hiệu local SEO thay vì
         tăng, nên copy nguyên văn từ hồ sơ GBP thật, không tự ý rút gọn/viết khác đi. "geo" là toạ
         độ thật của 254 Xuân Thủy (khớp categories.latitude/longitude của chi nhánh đó). Sau node
         thương hiệu này là 1 node LodgingBusiness cho TỪNG chi nhánh đã có toạ độ — xem
         App\Support\BranchLodgingSchema. --}}
    @if(request()->is('/'))
        @php
            $businessSchema = [
                '@context'   => 'https://schema.org',
                '@type'      => 'LodgingBusiness',
                'name'       => '365 Home Cần Thơ',
                'image'      => $ogImage ?: null,
                'url'        => url('/'),
                'telephone'  => '+84939174365',
                'email'      => '365home.cantho@gmail.com',
                'priceRange' => '$$',
                // Giờ nhận/trả phòng cố định của hình thức đặt theo ngày (xem HeroSection::$selectedBuoi).
                'checkinTime'  => '14:00',
                'checkoutTime' => '12:00',
                'address'    => [
                    '@type'           => 'PostalAddress',
                    // Tách đúng cấp hành chính (phường nằm trong streetAddress, quận = locality,
                    // tỉnh/thành = region) thay vì gộp cả 3 vào addressLocality — nội dung NAP không đổi.
                    'streetAddress'   => '254 Đường Xuân Thủy, An Bình',
                    'addressLocality' => 'Ninh Kiều',
                    'addressRegion'   => 'Cần Thơ',
                    'postalCode'      => '90000',
                    'addressCountry'  => 'VN',
                ],
                'geo' => [
                    '@type'     => 'GeoCoordinates',
                    'latitude'  => 10.0217964,
                    'longitude' => 105.7445886,
                ],
                'sameAs' => [
                    'https://www.facebook.com/365home.254xuanthuy.cantho',
                    'https://www.tiktok.com/@365.home',
                ],
            ];
            $businessSchema = array_filter($businessSchema, fn ($v) => $v !== null);
        @endphp
        <script type="application/ld+json">{!! json_encode($businessSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}</script>
        @foreach(\App\Support\BranchLodgingSchema::forHomepage() as $branchSchema)
            <script type="application/ld+json">{!! json_encode($branchSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}</script>
        @endforeach
    @endif

    {{-- LodgingBusiness của riêng chi nhánh đang xem (trang chi nhánh — renderBookingBoard()). --}}
    @if(!empty($seoData['lodging_schema']))
        <script type="application/ld+json">{!! json_encode($seoData['lodging_schema'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}</script>
    @endif

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
