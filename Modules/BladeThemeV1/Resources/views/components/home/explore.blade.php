{{-- Tóm tắt "tìm theo nhu cầu" trên trang chủ: mỗi mục 2–3 câu + link sang trang chuyên sâu. Nội dung
     đầy đủ (cẩm nang lưu trú, mẹo, an toàn tự check-in, du lịch Cần Thơ...) đã chuyển sang các trang
     đích (components/landing/*, Modules\BladeThemeV1\Support\LandingPages) để trang chủ không tự
     cạnh tranh từ khoá với trang con. Markup tối giản, không Alpine. --}}
<section class="py-8 sm:py-10 bg-white">
    <div class="w-full max-w-7xl mx-auto px-4 sm:px-6">
        <h2 class="text-2xl font-bold text-gray-900 mb-4">Tìm homestay theo nhu cầu</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <a href="{{ url('/homestay-can-tho-theo-gio') }}" class="group rounded-2xl border border-gray-100 p-5 hover:border-gray-300">
                <h3 class="text-base font-semibold text-gray-900 mb-1 group-hover:underline">Homestay Cần Thơ theo giờ</h3>
                <p class="text-sm text-gray-600 leading-relaxed">Chỉ trả tiền cho đúng số giờ bạn cần: xem lịch trống, giá từng khung giờ và đặt ngay trên điện thoại. Kèm mẹo đặt phòng tiết kiệm và kinh nghiệm đặt dịp lễ.</p>
                <span class="mt-2 inline-block text-sm font-semibold text-primary">Xem chi tiết</span>
            </a>
            <a href="{{ url('/homestay-tu-check-in-can-tho') }}" class="group rounded-2xl border border-gray-100 p-5 hover:border-gray-300">
                <h3 class="text-base font-semibold text-gray-900 mb-1 group-hover:underline">Homestay tự check-in</h3>
                <p class="text-sm text-gray-600 leading-relaxed">Thanh toán online, nhận mã và tự vào phòng bằng khoá thông minh, không cần lễ tân. Tìm hiểu cách nhận mã, mở cửa và những điều nên chuẩn bị cho lần đầu.</p>
                <span class="mt-2 inline-block text-sm font-semibold text-primary">Xem chi tiết</span>
            </a>
            <a href="{{ url('/homestay-ninh-kieu') }}" class="group rounded-2xl border border-gray-100 p-5 hover:border-gray-300">
                <h3 class="text-base font-semibold text-gray-900 mb-1 group-hover:underline">Homestay Ninh Kiều</h3>
                <p class="text-sm text-gray-600 leading-relaxed">Phòng gần trung tâm Cần Thơ, thuận tiện đi bến Ninh Kiều và chợ đêm. Kèm gợi ý di chuyển, lịch trình 2 ngày và món ngon nên thử.</p>
                <span class="mt-2 inline-block text-sm font-semibold text-primary">Xem chi tiết</span>
            </a>
        </div>

        <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm text-gray-600 leading-relaxed">
            <div class="rounded-2xl bg-gray-50 p-4">
                <h3 class="text-sm font-semibold text-gray-900 mb-1">Hướng dẫn tự check-in</h3>
                <p>Các bước tra cứu mã, mở cửa và xử lý khi gặp sự cố. <a href="{{ url('/huong-dan-tu-check-in') }}" class="font-medium text-primary hover:underline">Xem hướng dẫn</a></p>
            </div>
            <div class="rounded-2xl bg-gray-50 p-4">
                <h3 class="text-sm font-semibold text-gray-900 mb-1">Thuê phòng theo tháng</h3>
                <p>Phòng MiniHouse cho thuê dài hạn, giá rõ ràng, có nhân viên hỗ trợ xem phòng. <a href="{{ url('/minihouse') }}" class="font-medium text-primary hover:underline">Xem phòng trống</a></p>
            </div>
            <div class="rounded-2xl bg-gray-50 p-4">
                <h3 class="text-sm font-semibold text-gray-900 mb-1">Ưu đãi thành viên</h3>
                <p>Thành viên mới được tặng coupon giảm 200.000đ cho lần đặt phòng đầu tiên. Đăng ký bằng số điện thoại, mã xác thực gửi qua Zalo.</p>
            </div>
            <div class="rounded-2xl bg-gray-50 p-4">
                <h3 class="text-sm font-semibold text-gray-900 mb-1">Dành cho chủ nhà</h3>
                <p>Đưa homestay, phòng cho thuê lên 365 Home với lịch đặt phòng và mã mở khoá tự động. <a href="{{ url('/dang-ky-hop-tac') }}" class="font-medium text-primary hover:underline">Đăng ký hợp tác</a></p>
            </div>
        </div>
    </div>
</section>
