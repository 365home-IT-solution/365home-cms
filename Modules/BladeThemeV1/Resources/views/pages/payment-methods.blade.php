@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData" />

@section('content')
    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')

    <div class="bg-gray-50 px-4 py-10 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-4xl">
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-gray-900">Hình thức thanh toán</h1>
                <p class="text-base text-gray-600 mt-2">
                    365 HOME hỗ trợ 2 hình thức thanh toán khi đặt phòng: quét mã QR thanh toán trực
                    tuyến ngay trên website, hoặc chuyển khoản trực tiếp qua ngân hàng. Cả hai đều
                    được xử lý nhanh chóng và an toàn, quý khách có thể chọn hình thức phù hợp nhất.
                </p>
            </div>

            <div class="space-y-8">
                <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
                    <h2 class="text-xl font-bold text-gray-900 mb-3">1. Thanh toán trực tuyến bằng mã QR</h2>
                    <p class="text-gray-700 leading-relaxed">
                        Sau khi đặt phòng thành công, quý khách sẽ nhận được thông tin thanh toán
                        trực tuyến qua QR ngay trên website. Khi thanh toán thành công, thông tin
                        booking sẽ được gửi đến số điện thoại quý khách đã đăng ký.
                    </p>
                </section>

                <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
                    <h2 class="text-xl font-bold text-gray-900 mb-4">2. Thanh toán bằng chuyển khoản ngân hàng</h2>
                    <dl class="grid grid-cols-1 sm:grid-cols-[220px_1fr] gap-x-4 gap-y-3 text-gray-700">
                        <dt class="font-semibold text-gray-900">Tên tài khoản</dt>
                        <dd>Công Ty TNHH Truyền Thông và Dịch Vụ Vận Tải Cần Thơ Express</dd>

                        <dt class="font-semibold text-gray-900">Số tài khoản</dt>
                        <dd>070150925108</dd>

                        <dt class="font-semibold text-gray-900">Nội dung chuyển khoản</dt>
                        <dd>365HOME"MÃĐẶTHÀNG" (ví dụ: 365HOME1554)</dd>

                        <dt class="font-semibold text-gray-900">Địa chỉ</dt>
                        <dd>254 Xuân Thuỷ, KDC Hồng Phát, An Bình, Cần Thơ</dd>
                    </dl>
                </section>
            </div>
        </div>
    </div>

    @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')
@endsection
