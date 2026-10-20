@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData"/>

@section('content')

    {{-- Không thêm <h1> ở đây: livewire/search-booking.blade.php đã có H1 hiển thị "Tra cứu đơn đặt
         phòng" — 2 thẻ H1 trên cùng 1 trang. --}}

    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')

    @livewire('bladethemev1::search-booking')

    {{-- Nội dung tĩnh bên dưới ô tra cứu — trước đây trang chỉ có form, Semrush báo "low word count"
         (~170 từ, gần hết là header/footer). Chỉ mô tả những gì kết quả tra cứu thật sự hiển thị
         (xem livewire/search-booking.blade.php). --}}
    @php
        $lookupFaqs = [
            ['q' => 'Tôi cần thông tin gì để tra cứu đơn đặt phòng?', 'a' => 'Chỉ cần số điện thoại bạn đã nhập khi đặt phòng. Bạn không cần đăng nhập hay nhớ mã đơn.'],
            ['q' => 'Vì sao đơn của tôi chưa có mã mở khoá?', 'a' => 'Mã mở khoá chỉ được cấp khi đơn đã thanh toán đủ. Nếu bạn đặt cọc, mã sẽ hiện sau khi thanh toán phần tiền còn lại.'],
            ['q' => 'Tra cứu không thấy đơn thì phải làm sao?', 'a' => 'Kiểm tra lại số điện thoại (đúng số đã dùng khi đặt phòng). Trang chỉ hiện đơn đã thanh toán hoặc đã đặt cọc — đơn chưa thanh toán sẽ không xuất hiện. Nếu vẫn không thấy, vui lòng gọi hotline 0939 174 365 để nhân viên kiểm tra giúp.'],
            ['q' => 'Tôi muốn huỷ hoặc đổi giờ đặt phòng?', 'a' => 'Vui lòng gọi hotline 0939 174 365 hoặc liên hệ nhân viên phụ trách đặt phòng của bạn để được hỗ trợ huỷ hoặc đổi lịch.'],
        ];
    @endphp
    <div class="bg-gray-50 px-4 py-10 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-4xl space-y-8">
            <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
                <h2 class="text-xl font-bold text-gray-900 mb-4">Cách tra cứu đơn đặt phòng</h2>
                <ol class="list-decimal list-inside space-y-2 text-gray-700 leading-relaxed">
                    <li>Nhập số điện thoại bạn đã dùng khi đặt phòng vào ô phía trên.</li>
                    <li>Bấm tra cứu để xem các đơn đã thanh toán hoặc đã đặt cọc gắn với số điện thoại đó (tối đa 10 đơn gần nhất).</li>
                    <li>Mỗi đơn hiện đầy đủ thông tin nhận phòng và mã mở khoá — không cần đăng nhập.</li>
                </ol>
            </section>

            <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
                <h2 class="text-xl font-bold text-gray-900 mb-4">Thông tin bạn sẽ thấy trong đơn</h2>
                <ul class="list-disc list-inside space-y-2 text-gray-700 leading-relaxed">
                    <li><strong class="text-gray-900">Mã mở khoá</strong> để tự check-in bằng khoá thông minh, hoặc pass cổng và pass phòng với chi nhánh dùng khoá mật khẩu.</li>
                    <li><strong class="text-gray-900">Giờ nhận phòng và trả phòng</strong> của từng phòng trong đơn.</li>
                    <li><strong class="text-gray-900">Địa chỉ chi nhánh</strong> kèm liên kết bản đồ chỉ đường.</li>
                    <li><strong class="text-gray-900">Trạng thái thanh toán</strong>: đã thanh toán đủ hoặc đã đặt cọc, cùng số tiền còn lại cần thanh toán nếu có.</li>
                    <li>Liên kết tới <a href="{{ url('/noi-quy-va-quy-dinh') }}" class="font-medium text-primary hover:underline">nội quy</a> và <a href="{{ url('/huong-dan-tu-check-in') }}" class="font-medium text-primary hover:underline">hướng dẫn tự check-in</a>.</li>
                </ul>
                <p class="text-gray-700 leading-relaxed mt-3">
                    365 Home vận hành theo mô hình tự check-in: bạn không cần gặp lễ tân, chỉ cần đến đúng giờ và dùng mã
                    trong đơn để vào phòng. Hãy lưu lại mã trước khi đến để không bị gián đoạn nếu mất kết nối mạng.
                </p>
            </section>

            <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
                <x-bladethemev1::seo-content.faq-accordion :items="$lookupFaqs" />
            </section>
        </div>
    </div>

    @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')
@endsection
