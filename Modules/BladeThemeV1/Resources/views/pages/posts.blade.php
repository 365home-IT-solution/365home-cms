@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData" />

@section('content')
    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')

    <div class="min-h-screen bg-gray-50 px-4 py-6 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            {{-- Giới thiệu theo danh mục (?danh-muc=, xem BladeThemeV1Controller::postsPage()) — trang lọc
                 trước đây chỉ có lưới bài viết, Semrush báo "low word count". Danh mục chưa có đoạn
                 riêng ở đây thì dùng đoạn chung theo tên danh mục. --}}
            @php
                $categoryIntros = [
                    'homestay' => 'Homestay là lựa chọn được nhiều bạn trẻ và gia đình yêu thích nhờ không gian riêng tư, ấm cúng và giá hợp lý. Chuyên mục này tổng hợp kinh nghiệm chọn homestay, gợi ý địa điểm đẹp, mẹo đặt phòng theo giờ, qua đêm hoặc theo ngày, cùng hướng dẫn tự check-in bằng khoá thông minh tại các chi nhánh 365 Home.',
                    'minihouse' => 'MiniHouse là mô hình phòng cho thuê theo tháng của 365 Home, phù hợp với sinh viên, người đi làm và người cần chỗ ở dài hạn. Chuyên mục này chia sẻ kinh nghiệm tìm phòng trọ, những điều cần kiểm tra trước khi ký hợp đồng, cách tính chi phí điện nước hàng tháng và tin tức về các toà nhà MiniHouse mới.',
                    'khách sạn' => 'Chuyên mục khách sạn tổng hợp kinh nghiệm chọn khách sạn phù hợp nhu cầu và ngân sách, so sánh khách sạn với homestay, mẹo đặt phòng trực tuyến để có giá tốt, cùng các bài viết giới thiệu điểm lưu trú và ưu đãi đặt phòng mới nhất trên 365 Home.',
                ];
                $categoryKey = $category ? mb_strtolower($category) : null;
                $categoryIntro = $categoryKey
                    ? ($categoryIntros[$categoryKey] ?? 'Tổng hợp các bài viết, kinh nghiệm và tin tức mới nhất về ' . $categoryLabel . ' từ 365 Home — giúp bạn chọn chỗ ở phù hợp, đặt phòng nhanh và có chuyến đi trọn vẹn hơn.')
                    : null;
            @endphp
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-900">{{ $categoryLabel ? 'Bài viết về ' . $categoryLabel : 'Tin tức' }}</h1>
                @if ($categoryIntro)
                    <p class="text-sm text-gray-600 mt-2 max-w-3xl leading-relaxed">{{ $categoryIntro }}</p>
                    <a href="{{ url('/bai-viet') }}" class="inline-block mt-2 text-sm font-medium text-primary hover:underline">Xem tất cả bài viết</a>
                @else
                    <p class="text-sm text-gray-500">Cập nhật những thông tin mới nhất từ 365 Home.</p>
                @endif
            </div>

            @livewire('bladethemev1::post-page', [
                'config' => [
                    'component' => [
                        'show_sidebar' => false,
                        'columns' => 4,
                        'number-post' => 9,
                        'show_pagination' => true,
                        'location-sidebar' => '',
                        'new_post' => '0',
                        'post_category' => '0',
                        'link_social' => '',
                    ],
                ],
            ], key('posts-page'))

            <section class="mt-10 bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8 max-w-4xl">
                <h2 class="text-xl font-bold text-gray-900 mb-3">Về chuyên mục tin tức của 365 Home</h2>
                <p class="text-gray-700 leading-relaxed mb-3">
                    365 Home là nền tảng đặt phòng homestay, khách sạn và phòng cho thuê theo tháng (MiniHouse) với mô hình
                    tự check-in: khách đặt phòng, thanh toán trực tuyến và dùng mã mở khoá để tự vào phòng, không cần lễ tân.
                    Chuyên mục tin tức là nơi chúng tôi chia sẻ những kinh nghiệm thực tế giúp bạn lưu trú thuận tiện hơn.
                </p>
                <ul class="list-disc list-inside space-y-2 text-gray-700 leading-relaxed">
                    <li><strong class="text-gray-900">Kinh nghiệm du lịch và lưu trú:</strong> gợi ý điểm đến, chọn chỗ ở phù hợp với lịch trình và ngân sách.</li>
                    <li><strong class="text-gray-900">Hướng dẫn đặt phòng:</strong> cách đặt phòng theo giờ, qua đêm, theo ngày và thanh toán bằng mã QR.</li>
                    <li><strong class="text-gray-900">Thuê phòng dài hạn:</strong> lưu ý khi tìm phòng trọ, ký hợp đồng và quản lý chi phí hàng tháng.</li>
                    <li><strong class="text-gray-900">Ưu đãi và tin mới:</strong> chương trình khuyến mãi, chi nhánh mới và các cập nhật dịch vụ của 365 Home.</li>
                </ul>
                <p class="text-gray-700 leading-relaxed mt-3">
                    Cần hỗ trợ đặt phòng? Xem <a href="{{ url('/huong-dan-su-dung') }}" class="font-medium text-primary hover:underline">hướng dẫn sử dụng</a>,
                    <a href="{{ url('/huong-dan-tu-check-in') }}" class="font-medium text-primary hover:underline">hướng dẫn tự check-in</a>
                    hoặc gọi hotline <a href="tel:0939174365" class="font-medium text-primary hover:underline">0939 174 365</a>.
                </p>
            </section>
        </div>
    </div>

    @livewire('bladethemev1::footer')
@endsection
