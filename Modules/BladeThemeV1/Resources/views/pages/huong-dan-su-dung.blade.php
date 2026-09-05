@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData" />

@section('content')
    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')

    <div class="bg-gray-50 px-4 py-10 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-4xl">
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-gray-900">Hướng dẫn sử dụng dịch vụ 365Home</h1>
                <p class="text-base text-gray-600 mt-2">
                    Các bước đặt phòng, thanh toán, huỷ đơn và xử lý phát sinh khi giao dịch tại 365Home.
                </p>
            </div>

            <div class="space-y-8">
                <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
                    <h2 class="text-xl font-bold text-gray-900 mb-4">1. Dành cho khách hàng cá nhân</h2>
                    <ol class="list-decimal list-inside space-y-2 text-gray-700 leading-relaxed">
                        <li>Tìm kiếm phòng phù hợp với yêu cầu của quý khách.</li>
                        <li>Chọn khung giờ trên bảng lịch đặt phòng, sau đó nhập họ và tên, số điện thoại, địa chỉ email, số lượng khách.</li>
                        <li>Chọn "Đặt phòng" để đến bước thanh toán.</li>
                        <li>Thanh toán bằng mã QR cùng số tiền hiển thị trên màn hình.</li>
                        <li>Thông tin của khách hàng được gửi về trung tâm xử lý dữ liệu của website.</li>
                        <li>Nhân viên 365HOME sẽ kiểm tra thông tin và gửi thông tin check-in đến quý khách qua số điện thoại đã đăng ký.</li>
                    </ol>
                </section>

                <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
                    <h2 class="text-xl font-bold text-gray-900 mb-4">2. Quy trình huỷ đơn hàng</h2>
                    <p class="text-gray-700 leading-relaxed mb-3">
                        Khách hàng liên hệ để huỷ đặt phòng với 365HOME bằng 1 trong các hình thức:
                    </p>
                    <ul class="list-disc list-inside space-y-2 text-gray-700 leading-relaxed">
                        <li>Gọi điện thoại tới số điện thoại: <a href="tel:0939174365" class="font-medium text-primary hover:underline">0939 174 365</a>.</li>
                        <li>Liên hệ với nhân viên phụ trách đặt phòng của quý khách.</li>
                    </ul>
                </section>

                <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
                    <h2 class="text-xl font-bold text-gray-900 mb-4">3. Giải quyết các phát sinh trong quá trình giao dịch</h2>
                    <ul class="list-disc list-inside space-y-2 text-gray-700 leading-relaxed">
                        <li>365HOME.VN cam kết tiếp nhận và xử lý kịp thời mọi khiếu nại phát sinh liên quan đến giao dịch trên website. Khi có tranh chấp, khách hàng vui lòng liên hệ hotline <a href="tel:0939174365" class="font-medium text-primary hover:underline">0939 174 365</a> để được hỗ trợ ngay.</li>
                        <li>Các tranh chấp giữa 365HOME.VN và thành viên sẽ được ưu tiên giải quyết bằng thương lượng; trường hợp không đạt được thoả thuận, các bên có quyền đưa vụ việc ra Toà án có thẩm quyền.</li>
                        <li>Đối với tranh chấp giữa khách hàng và nhà cung cấp dịch vụ, ban quản lý website sẽ cung cấp thông tin liên quan và tích cực hỗ trợ bảo vệ quyền lợi hợp pháp của khách hàng.</li>
                        <li>Khi giao dịch trên website, các thành viên có trách nhiệm tuân thủ đúng quy trình và hướng dẫn của 365HOME.VN.</li>
                    </ul>
                </section>
            </div>
        </div>
    </div>

    @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')
@endsection
