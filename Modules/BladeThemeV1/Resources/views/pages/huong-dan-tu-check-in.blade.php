@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData" />

{{-- Nội dung chỉ nêu đúng những gì hệ thống đang làm: mã mở khoá hiện ở trang tra cứu đơn
     (livewire/search-booking.blade.php), đơn cọc chỉ có mã sau khi trả đủ, khoá thủ công có pass cổng
     + pass phòng, mở cửa qua ứng dụng trong khung 30 phút trước giờ nhận đến 30 phút sau giờ trả
     (App\Http\Controllers\Api\UnlockController::processUnlock). Đổi quy trình đó thì sửa lại đây. --}}
@php
    $checkinFaqs = [
        ['q' => 'Khi nào tôi nhận được mã mở khoá?', 'a' => 'Mã mở khoá hiện trong đơn đặt phòng ngay khi đơn đã được thanh toán đủ. Với đơn đặt cọc, mã được cấp sau khi bạn thanh toán phần tiền còn lại.'],
        ['q' => 'Tôi có thể vào phòng sớm hơn giờ nhận phòng không?', 'a' => 'Mở cửa bằng ứng dụng được phép từ 30 phút trước giờ nhận phòng. Nếu cần vào sớm hơn, vui lòng gọi hotline 0939 174 365 để nhân viên kiểm tra phòng trống và hỗ trợ.'],
        ['q' => 'Nhập mã nhưng cửa không mở thì phải làm sao?', 'a' => 'Kiểm tra lại từng chữ số, nhớ bấm phím # sau khi nhập mã và đảm bảo đang trong khung giờ đã đặt. Nếu vẫn không mở được, gọi hotline 0939 174 365 — nhân viên có thể mở cửa từ xa cho bạn.'],
        ['q' => 'Tôi quên mã mở khoá thì xem lại ở đâu?', 'a' => 'Vào trang Tra cứu đơn đặt phòng, nhập số điện thoại đã dùng khi đặt phòng để xem lại mã. Nếu đặt bằng tài khoản, mã cũng có trong mục đơn đặt phòng của tài khoản.'],
        ['q' => 'Khi trả phòng tôi cần làm gì?', 'a' => 'Mang theo đồ cá nhân, tắt các thiết bị điện, đóng cửa phòng và rời đi trước giờ trả phòng. Không cần gặp lễ tân hay trả chìa khoá.'],
    ];
@endphp

@section('content')
    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')

    <div class="bg-gray-50 px-4 py-10 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-4xl">
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-gray-900">Hướng dẫn tự check-in tại 365 Home</h1>
                <p class="text-base text-gray-600 mt-2 leading-relaxed">
                    Phòng tại 365 Home được trang bị khoá thông minh, bạn không cần chờ lễ tân hay nhận chìa khoá.
                    Chỉ cần đặt phòng, thanh toán trực tuyến, rồi dùng mã mở khoá để tự vào phòng đúng khung giờ
                    đã đặt — kể cả lúc nửa đêm hay sáng sớm.
                </p>
            </div>

            <div class="space-y-8">
                <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
                    <h2 class="text-xl font-bold text-gray-900 mb-4">1. Đặt phòng và thanh toán</h2>
                    <ol class="list-decimal list-inside space-y-2 text-gray-700 leading-relaxed">
                        <li>Chọn phòng và khung giờ phù hợp trên bảng lịch đặt phòng (theo giờ, qua đêm hoặc theo ngày).</li>
                        <li>Nhập họ tên, số điện thoại và số lượng khách, sau đó bấm "Đặt phòng".</li>
                        <li>Thanh toán bằng mã QR hoặc chuyển khoản đúng số tiền hiển thị trên màn hình.</li>
                        <li>Khi đơn được thanh toán đủ, hệ thống cấp mã mở khoá cho đơn của bạn. Đơn đặt cọc sẽ có mã sau khi thanh toán phần còn lại.</li>
                    </ol>
                </section>

                <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
                    <h2 class="text-xl font-bold text-gray-900 mb-4">2. Xem mã mở khoá</h2>
                    <p class="text-gray-700 leading-relaxed mb-3">
                        Bạn có thể xem mã mở khoá, địa chỉ phòng và bản đồ chỉ đường bất cứ lúc nào:
                    </p>
                    <ul class="list-disc list-inside space-y-2 text-gray-700 leading-relaxed">
                        <li>Tại trang <a href="{{ url('/ticket-booking') }}" class="font-medium text-primary hover:underline">Tra cứu đơn đặt phòng</a> — chỉ cần nhập số điện thoại đã dùng khi đặt phòng.</li>
                        <li>Trong mục đơn đặt phòng của tài khoản 365 Home trên website hoặc ứng dụng, nếu bạn đặt phòng khi đã đăng nhập.</li>
                    </ul>
                    <p class="text-gray-700 leading-relaxed mt-3">
                        Nên chụp màn hình hoặc ghi lại mã trước khi đến, phòng trường hợp điện thoại mất sóng tại nơi lưu trú.
                    </p>
                </section>

                <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
                    <h2 class="text-xl font-bold text-gray-900 mb-4">3. Mở cửa và nhận phòng</h2>
                    <ul class="list-disc list-inside space-y-2 text-gray-700 leading-relaxed">
                        <li><strong class="text-gray-900">Khoá điện tử có bàn phím:</strong> chạm vào bàn phím để màn hình sáng lên, nhập mã mở khoá rồi bấm phím <strong>#</strong>.</li>
                        <li><strong class="text-gray-900">Phòng có pass cổng và pass phòng:</strong> một số chi nhánh dùng 2 mật khẩu riêng — nhập pass cổng ở cửa chính, sau đó nhập pass phòng ở cửa phòng của bạn. Cả 2 mật khẩu đều hiện trong đơn đặt phòng.</li>
                        <li><strong class="text-gray-900">Mở cửa bằng ứng dụng:</strong> với phòng hỗ trợ, bạn có thể bấm mở cửa ngay trên ứng dụng 365 Home trong khoảng từ 30 phút trước giờ nhận phòng đến 30 phút sau giờ trả phòng.</li>
                    </ul>
                    <p class="text-gray-700 leading-relaxed mt-3">
                        Mã chỉ hoạt động trong khung giờ bạn đã đặt. Lần mở cửa đầu tiên được hệ thống ghi nhận là giờ nhận phòng của bạn.
                    </p>
                </section>

                <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
                    <h2 class="text-xl font-bold text-gray-900 mb-4">4. Trong thời gian lưu trú và khi trả phòng</h2>
                    <ul class="list-disc list-inside space-y-2 text-gray-700 leading-relaxed">
                        <li>Giữ kín mã mở khoá, không chia sẻ cho người không ở cùng đơn đặt phòng.</li>
                        <li>Tuân thủ <a href="{{ url('/noi-quy-va-quy-dinh') }}" class="font-medium text-primary hover:underline">nội quy và quy định</a> của chi nhánh, giữ yên lặng và giữ gìn vệ sinh chung.</li>
                        <li>Khi trả phòng, kiểm tra lại đồ cá nhân, tắt điều hoà và đèn, đóng cửa phòng và rời đi trước giờ trả phòng. Bạn không cần gặp lễ tân.</li>
                        <li>Muốn ở thêm giờ? Liên hệ hotline trước giờ trả phòng để được kiểm tra phòng trống và gia hạn.</li>
                    </ul>
                </section>

                <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
                    <h2 class="text-xl font-bold text-gray-900 mb-4">5. Gặp sự cố khi check-in?</h2>
                    <p class="text-gray-700 leading-relaxed">
                        Nếu cửa không mở, không xem được mã hoặc có bất kỳ vấn đề nào khi nhận phòng, hãy gọi ngay hotline
                        <a href="tel:0939174365" class="font-medium text-primary hover:underline">0939 174 365</a>
                        hoặc nhắn tin qua Zalo của 365 Home. Nhân viên hỗ trợ có thể kiểm tra đơn của bạn và mở cửa từ xa
                        trong trường hợp cần thiết.
                    </p>
                </section>

                <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
                    <x-bladethemev1::seo-content.faq-accordion :items="$checkinFaqs" />
                </section>
            </div>
        </div>
    </div>

    @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')
@endsection
