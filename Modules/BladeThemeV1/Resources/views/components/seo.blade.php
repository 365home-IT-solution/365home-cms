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
            // Trang phòng (room_schema, BladeThemeV1Controller::renderProductDetail): ["HotelRoom", "Product"]
            // — HotelRoom để Google hiểu đây là phòng lưu trú thuộc 1 cơ sở (containedInPlace), Product để
            // giữ được offers/aggregateRating (HotelRoom là Place, tự nó không mang offers). Trang dịch vụ
            // (mẫu giao diện) không có room_schema => Product thường như cũ.
            $room = $seoData['room_schema'] ?? null;
            $canonicalSelf = $seoData['canonical_url'] ?? url()->current();
            $schema = [
                '@context'    => 'https://schema.org',
                '@type'       => $room ? ['HotelRoom', 'Product'] : 'Product',
                'name'        => $room['name'] ?? ($seoData['seo_title'] ?? ''),
                'description' => $room['description'] ?? ($seoData['seo_description'] ?? ''),
                'url'         => $canonicalSelf,
            ];
            if ($room) {
                $schema = ['@context' => 'https://schema.org', '@type' => $schema['@type'], '@id' => $canonicalSelf . '#room'] + $schema;
                if (!empty($room['images'])) {
                    $schema['image'] = $room['images'];
                } elseif ($ogImage) {
                    $schema['image'] = [$ogImage];
                }
                if (!empty($room['occupancy'])) {
                    // room_config.max_free_guests: số khách tiêu chuẩn (thêm khách có phụ phí).
                    $schema['occupancy'] = ['@type' => 'QuantitativeValue', 'value' => (int) $room['occupancy'], 'unitText' => 'khách'];
                }
                if (!empty($room['amenities'])) {
                    $schema['amenityFeature'] = array_map(fn ($a) => ['@type' => 'LocationFeatureSpecification', 'name' => $a, 'value' => true], $room['amenities']);
                }
                if (!empty($room['contained_in'])) {
                    $schema['containedInPlace'] = $room['contained_in'];
                }
                // Địa chỉ/toạ độ của CHÍNH phòng (không lấy của chi nhánh) — phường/tỉnh theo chi nhánh.
                $branchAddress = $room['contained_in']['address'] ?? [];
                if (!empty($room['address']) || !empty($branchAddress)) {
                    $schema['address'] = array_filter([
                        '@type'           => 'PostalAddress',
                        'streetAddress'   => $room['address'] ?? ($branchAddress['streetAddress'] ?? null),
                        'addressLocality' => $branchAddress['addressLocality'] ?? null,
                        'addressRegion'   => $branchAddress['addressRegion'] ?? null,
                        'addressCountry'  => 'VN',
                    ]);
                }
                if (!empty($room['latitude']) && !empty($room['longitude'])) {
                    $schema['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => $room['latitude'], 'longitude' => $room['longitude']];
                } elseif (!empty($room['contained_in']['geo'])) {
                    $schema['geo'] = $room['contained_in']['geo'];
                }
            } elseif ($ogImage) {
                $schema['image'] = [$ogImage];
            }

            // Brand — dùng seoData trước, fallback về GeneralSettings
            $brandName = $seoData['brand_name'] ?? $gs->brand_name ?? '';
            if (!empty($brandName)) {
                $schema['brand'] = ['@type' => 'Brand', 'name' => $brandName];
            }

            // SKU làm định danh toàn cầu
            if (!empty($seoData['offer_sku'])) {
                $schema['sku'] = (string) $seoData['offer_sku'];
            }

            // Không gắn shippingDetails / hasMerchantReturnPolicy: đặt phòng không giao hàng, không đổi
            // trả qua bưu điện — thông tin sai sự thật trong schema có thể bị Google coi là spam.
            // Phòng: giá theo khung giờ → AggregateOffer (lowPrice/highPrice). Không có giá > 0 nào thì
            // bỏ hẳn offers thay vì ghi "price": "0" (Google hiện 0đ / gỡ rich result vì lệch giá).
            $offerBase = [
                'priceCurrency' => $seoData['offer_currency']   ?? 'VND',
                'availability'  => 'https://schema.org/' . ($seoData['offer_availability'] ?? 'InStock'),
                'url'           => $seoData['offer_url']        ?? url()->current(),
            ];
            // Đơn vị giá: phòng theo giờ tính theo KHUNG GIỜ (mỗi khung 1 giá), phòng theo ngày (villa,
            // products.styles = 2) tính theo ĐÊM — ghi rõ để giá không bị hiểu là giá trọn gói.
            $priceUnit = $room['price_unit'] ?? null;
            if (!empty($seoData['offer_low_price'])) {
                $schema['offers'] = ['@type' => 'AggregateOffer'] + $offerBase + [
                    'lowPrice'   => (string) $seoData['offer_low_price'],
                    'highPrice'  => (string) $seoData['offer_high_price'],
                    'offerCount' => (int) $seoData['offer_count'],
                ];
                if ($priceUnit) {
                    $schema['offers']['description'] = 'Giá mỗi ' . $priceUnit;
                }
            } elseif (!empty($seoData['offer_price']) && (float) $seoData['offer_price'] > 0) {
                $schema['offers'] = ['@type' => 'Offer'] + $offerBase + [
                    'price' => (string) $seoData['offer_price'],
                ];
                if ($priceUnit) {
                    $schema['offers']['priceSpecification'] = array_filter([
                        '@type'         => 'UnitPriceSpecification',
                        'price'         => (string) $seoData['offer_price'],
                        'priceCurrency' => $seoData['offer_currency'] ?? 'VND',
                        'unitCode'      => !empty($room['is_daily']) ? 'DAY' : null,
                        'unitText'      => $priceUnit,
                    ]);
                }
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
            // Thực thể gốc: Organization "365 Home" + WebSite, có @id cố định để các node khác (thực thể
            // chính "365 Home Cần Thơ", từng chi nhánh — App\Support\BranchLodgingSchema) nối vào qua
            // parentOrganization/publisher thay vì đứng rời rạc.
            $orgId = \App\Support\BranchLodgingSchema::ORGANIZATION_ID;
            $sameAs = [
                'https://www.facebook.com/365home.254xuanthuy.cantho',
                'https://www.tiktok.com/@365.home',
            ];
            $organizationSchema = array_filter([
                '@context' => 'https://schema.org',
                '@type'    => 'Organization',
                '@id'      => $orgId,
                'name'     => '365 Home',
                'url'      => url('/'),
                'logo'     => !empty($gs->brand_logo) ? ['@type' => 'ImageObject', 'url' => url('/storage/' . $gs->brand_logo)] : null,
                'email'    => '365home.cantho@gmail.com',
                'sameAs'   => $sameAs,
                'contactPoint' => [
                    '@type'             => 'ContactPoint',
                    'telephone'         => '+84939174365',
                    'contactType'       => 'customer service',
                    'areaServed'        => 'VN',
                    'availableLanguage' => 'Vietnamese',
                ],
            ]);
            $websiteSchema = [
                '@context'   => 'https://schema.org',
                '@type'      => 'WebSite',
                '@id'        => 'https://365home.vn/#website',
                'name'       => '365 Home',
                'url'        => url('/'),
                'inLanguage' => 'vi-VN',
                'publisher'  => ['@id' => $orgId],
            ];
            $businessSchema = [
                '@context'   => 'https://schema.org',
                '@type'      => 'LodgingBusiness',
                '@id'        => \App\Support\BranchLodgingSchema::CAN_THO_ENTITY_ID,
                'parentOrganization' => ['@id' => $orgId],
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
                'sameAs' => $sameAs,
            ];
            $businessSchema = array_filter($businessSchema, fn ($v) => $v !== null);
        @endphp
        <script type="application/ld+json">{!! json_encode($organizationSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}</script>
        <script type="application/ld+json">{!! json_encode($websiteSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}</script>
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
                    // Giải mã HTML entity (mô tả lấy từ nội dung soạn thảo có &ndash;, &ocirc;...) — JSON-LD
                    // cần chữ thật, không phải mã HTML.
                    'name'          => html_entity_decode(($seoData['video_name'] ?? '') . ' - Video', ENT_QUOTES | ENT_HTML5),
                    'description'   => html_entity_decode(html_entity_decode($seoData['video_description'] ?? $seoData['seo_description'] ?? '', ENT_QUOTES | ENT_HTML5), ENT_QUOTES | ENT_HTML5),
                    'thumbnailUrl'  => [
                        'https://img.youtube.com/vi/' . $videoId . '/maxresdefault.jpg',
                        'https://img.youtube.com/vi/' . $videoId . '/hqdefault.jpg',
                    ],
                    'uploadDate'    => $seoData['video_upload_date'] ?? now()->toIso8601String(),
                    // Không ghi contentUrl: trường này phải là file video trực tiếp (.mp4), video YouTube
                    // chỉ cần embedUrl.
                    'embedUrl'      => 'https://www.youtube.com/embed/' . $videoId,
                    'url'           => url()->current(),
                ];
            @endphp
            <script type="application/ld+json">{!! json_encode($videoSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}</script>
        @endforeach
    @endif
@endsection
