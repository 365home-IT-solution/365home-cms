@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData" />

@section('content')
    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')

    <div class="bg-gray-50 px-4 py-10 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-4xl">
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-gray-900">Chính sách bảo mật thông tin</h1>
                <p class="text-base text-gray-600 mt-2">
                    Trước khi tiến hành đặt hàng hoặc sử dụng dịch vụ, bạn bắt buộc phải đồng ý với chính
                    sách bảo vệ thông tin cá nhân của chúng tôi. Vui lòng đọc kỹ nội dung dưới đây.
                </p>
            </div>

            <div class="space-y-8">
                <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
                    <h2 class="text-xl font-bold text-gray-900 mb-4">1. Mục đích thu thập thông tin cá nhân</h2>
                    <p class="text-gray-700 leading-relaxed mb-3">
                        Chúng tôi chỉ thu thập thông tin cá nhân của khách hàng với mục đích sau đây:
                    </p>
                    <ul class="space-y-3 text-gray-700 leading-relaxed">
                        <li><strong class="text-gray-900">1.1 Xác nhận danh tính:</strong> Chúng tôi có thể yêu cầu thông tin cá nhân như tên, địa chỉ, số điện thoại và địa chỉ email để xác nhận danh tính của khách hàng và đảm bảo tính chính xác trong quá trình giao dịch và cung cấp dịch vụ.</li>
                        <li><strong class="text-gray-900">1.2 Cải thiện trải nghiệm khách hàng:</strong> Thông tin cá nhân được sử dụng để cung cấp dịch vụ tốt hơn cho khách hàng, bao gồm hỗ trợ khách hàng, tư vấn sản phẩm và cung cấp thông tin liên quan.</li>
                        <li><strong class="text-gray-900">1.3 Tuỳ chỉnh trải nghiệm:</strong> Chúng tôi có thể sử dụng thông tin cá nhân để tuỳ chỉnh và cá nhân hoá trải nghiệm mua sắm của khách hàng trên website của chúng tôi, bao gồm cung cấp thông tin sản phẩm, ưu đãi hoặc gợi ý phù hợp.</li>
                    </ul>
                </section>

                <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
                    <h2 class="text-xl font-bold text-gray-900 mb-4">2. Phạm vi sử dụng thông tin</h2>
                    <p class="text-gray-700 leading-relaxed mb-3">
                        Chúng tôi cam kết bảo mật tuyệt đối thông tin cá nhân của khách hàng và chỉ sử
                        dụng trong phạm vi cần thiết nhằm cung cấp dịch vụ và đáp ứng yêu cầu hợp pháp.
                        Mọi thông tin sẽ không được chia sẻ, tiết lộ hay chuyển giao cho bên thứ ba nếu
                        không có sự đồng ý của khách hàng, trừ trường hợp theo quy định pháp luật hoặc để
                        bảo vệ quyền lợi, tài sản và an ninh của các bên liên quan.
                    </p>
                    <p class="text-gray-700 leading-relaxed">
                        Chúng tôi áp dụng các biện pháp bảo mật phù hợp và tuân thủ đầy đủ các quy định
                        hiện hành về bảo vệ dữ liệu cá nhân.
                    </p>
                </section>

                <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
                    <h2 class="text-xl font-bold text-gray-900 mb-4">3. Những người hoặc tổ chức có thể được tiếp cận với thông tin</h2>
                    <p class="text-gray-700 leading-relaxed mb-3">
                        Chúng tôi cam kết không chia sẻ, bán, hoặc tiết lộ thông tin cá nhân của khách
                        hàng cho bất kỳ bên thứ ba nào ngoại trừ những trường hợp sau đây:
                    </p>
                    <ul class="space-y-3 text-gray-700 leading-relaxed">
                        <li><strong class="text-gray-900">3.1 Đối tác dịch vụ:</strong> Chúng tôi có thể chia sẻ thông tin cá nhân với các đối tác dịch vụ hỗ trợ chúng tôi trong việc cung cấp sản phẩm và dịch vụ cho khách hàng. Tuy nhiên, các đối tác này phải tuân thủ các quy định về bảo mật thông tin và chỉ được sử dụng thông tin cá nhân cho mục đích cụ thể đã được đề ra.</li>
                        <li><strong class="text-gray-900">3.2 Tuân thủ pháp luật:</strong> Chúng tôi có thể tiết lộ thông tin cá nhân khi được yêu cầu từ các cơ quan chức năng thực thi pháp luật nhằm tuân thủ các quy định, quy trình pháp lý.</li>
                    </ul>
                </section>

                <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
                    <h2 class="text-xl font-bold text-gray-900 mb-4">4. Thời gian lưu trữ thông tin</h2>
                    <p class="text-gray-700 leading-relaxed">
                        Thông tin cá nhân của khách hàng được lưu trữ trong suốt thời gian sử dụng dịch
                        vụ và chỉ trong phạm vi cần thiết cho mục đích thu thập. Khi không còn cần thiết,
                        chúng tôi sẽ tiến hành xoá hoặc ẩn thông tin theo quy định nhằm đảm bảo quyền
                        riêng tư của khách hàng.
                    </p>
                </section>

                <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
                    <h2 class="text-xl font-bold text-gray-900 mb-4">5. Thông tin đơn vị thu thập và quản lý thông tin cá nhân</h2>
                    <p class="text-gray-700 leading-relaxed mb-4">
                        Nếu có bất kỳ câu hỏi hoặc cần hỗ trợ gì về hoạt động thu thập, xử lý thông tin
                        liên quan đến khách hàng, xin vui lòng liên hệ trực tiếp, điện thoại hoặc email
                        của chúng tôi theo thông tin dưới đây:
                    </p>
                    <dl class="grid grid-cols-1 sm:grid-cols-[220px_1fr] gap-x-4 gap-y-3 text-gray-700">
                        <dt class="font-semibold text-gray-900">Tên công ty</dt>
                        <dd>365HOME</dd>

                        <dt class="font-semibold text-gray-900">Địa chỉ</dt>
                        <dd>254 Xuân Thuỷ, KDC Hồng Phát, An Bình, Cần Thơ</dd>

                        <dt class="font-semibold text-gray-900">Số điện thoại</dt>
                        <dd><a href="tel:0939174365" class="font-medium text-primary hover:underline">0939 174 365</a></dd>

                        <dt class="font-semibold text-gray-900">Email</dt>
                        <dd><a href="mailto:365home.cantho@gmail.com" class="font-medium text-primary hover:underline">365home.cantho@gmail.com</a></dd>
                    </dl>
                </section>

                <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
                    <h2 class="text-xl font-bold text-gray-900 mb-4">6. Quản lý dữ liệu cá nhân</h2>
                    <p class="text-gray-700 leading-relaxed">
                        Khách hàng được quyền kiểm tra, thay đổi hoặc điều chỉnh thông tin cá nhân của
                        mình bất cứ lúc nào bằng cách đăng nhập vào hệ thống hoặc gửi yêu cầu hỗ trợ qua
                        thông tin liên hệ chính thức của chúng tôi.
                    </p>
                </section>
            </div>
        </div>
    </div>

    @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')
@endsection
