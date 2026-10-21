{{-- Nội dung trang đích /homestay-tu-check-in-can-tho (xem Modules\BladeThemeV1\Support\LandingPages) — chuyển từ khối
     cẩm nang trang chủ cũ (components/home/guide + travel-guide) về đúng trang nhắm từ khoá này.
     Chỉ nêu dữ kiện đúng với hệ thống; đổi chính sách đặt/check-in thì sửa lại đây. --}}
@props(['roomCount' => 0])
@php
    $landingFaqs = [
        ['q' => 'Khi nào tôi nhận được mã mở khoá?', 'a' => 'Mã mở khoá được cấp ngay khi đơn đã thanh toán đủ. Với đơn đặt cọc, mã được cấp sau khi bạn thanh toán phần tiền còn lại.'],
        ['q' => 'Xem lại mã mở khoá ở đâu?', 'a' => 'Tại trang Tra cứu đơn đặt phòng, chỉ cần nhập số điện thoại đã dùng khi đặt; hoặc trong mục đơn đặt phòng của tài khoản 365 Home.'],
        ['q' => 'Có vào phòng sớm hơn giờ nhận phòng được không?', 'a' => 'Mở cửa bằng ứng dụng được phép từ 30 phút trước giờ nhận phòng. Cần vào sớm hơn, hãy gọi hotline 0939 174 365 để được kiểm tra phòng trống.'],
        ['q' => 'Nhập mã nhưng cửa không mở thì làm sao?', 'a' => 'Kiểm tra lại từng chữ số, bấm phím # sau khi nhập và đảm bảo đang trong khung giờ đã đặt. Vẫn không được, hãy gọi hotline 0939 174 365 để nhân viên hỗ trợ ngay.'],
    ];
@endphp
<section class="py-8 sm:py-10 bg-white">
    <div class="w-full max-w-7xl mx-auto px-4 sm:px-6">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-x-10 gap-y-8 text-gray-600 leading-relaxed">
            <article>
                <h2 class="text-xl font-bold text-gray-900 mb-2">Thanh toán, đặt cọc và nhận mã mở khoá</h2>
                <p class="mb-2">
                    Đơn đặt phòng được thanh toán trực tuyến bằng mã QR hoặc chuyển khoản ngân hàng, đúng số tiền hiển
                    thị trên màn hình. Khi đơn đã được thanh toán đủ, hệ thống cấp mã mở khoá cho đơn của bạn.
                </p>
                <p class="mb-2">
                    Với những phòng cho phép đặt cọc, bạn có thể thanh toán trước một phần để giữ phòng. Mã mở khoá sẽ
                    được cấp sau khi bạn thanh toán nốt phần tiền còn lại, vì vậy hãy hoàn tất thanh toán trước giờ
                    nhận phòng để không phải chờ.
                </p>
                <p>
                    Mọi đơn đều có thể xem lại tại trang
                    <a href="{{ url('/ticket-booking') }}" class="text-primary hover:underline">Tra cứu đơn đặt phòng</a>
                    chỉ bằng số điện thoại đã dùng khi đặt: mã mở khoá, giờ nhận và trả phòng, địa chỉ chi nhánh kèm
                    bản đồ chỉ đường và số tiền còn lại nếu có.
                </p>
            </article>
            <article>
                <h2 class="text-xl font-bold text-gray-900 mb-2">Đến nơi và tự check-in</h2>
                <p class="mb-2">
                    Tại chi nhánh, bạn chỉ cần nhập mã mở khoá trên bàn phím khoá điện tử rồi bấm phím #. Một số chi
                    nhánh dùng hai mật khẩu riêng cho cổng chính và cửa phòng, cả hai đều có sẵn trong đơn đặt phòng.
                    Với phòng hỗ trợ, bạn còn có thể bấm mở cửa ngay trên ứng dụng 365 Home trong khoảng từ 30 phút
                    trước giờ nhận phòng đến 30 phút sau giờ trả phòng.
                </p>
                <p>
                    Nên lưu lại mã trước khi đến để không bị gián đoạn nếu mất sóng điện thoại. Hướng dẫn chi tiết từng
                    bước có tại trang
                    <a href="{{ url('/huong-dan-tu-check-in') }}" class="text-primary hover:underline">Hướng dẫn tự check-in</a>.
                    Nếu cửa không mở hoặc gặp bất kỳ sự cố nào, hãy gọi hotline
                    <a href="tel:0939174365" class="text-primary hover:underline">0939 174 365</a> để nhân viên hỗ trợ ngay.
                </p>
            </article>
            <article>
                <h2 class="text-xl font-bold text-gray-900 mb-2">Tự check-in có an toàn không?</h2>
                <p class="mb-2">
                    Không có lễ tân không có nghĩa là không có ai quản lý. Mô hình tự check-in của 365 Home được thiết
                    kế để vừa riêng tư cho khách, vừa kiểm soát chặt chẽ cho chủ nhà:
                </p>
                <ul class="list-disc pl-5 space-y-1 mb-2">
                    <li>Mỗi đơn đặt phòng có mã mở khoá riêng, chỉ dùng được trong thời gian đã đặt và tự hết hiệu lực sau đó.</li>
                    <li>Mã chỉ được cấp khi đơn đã thanh toán đủ, người chưa thanh toán không thể vào phòng.</li>
                    <li>Hệ thống ghi nhận lịch sử mở cửa, giúp chủ nhà và nhân viên biết phòng đã được nhận, trả lúc nào.</li>
                    <li>Khi cần, chủ nhà có thể khoá khẩn cấp quyền mở cửa qua ứng dụng và hỗ trợ khách trực tiếp.</li>
                </ul>
                <p>
                    Với khách, điều này có nghĩa là bạn không phải đưa giấy tờ cho người lạ giữ, không phải giải thích
                    với ai khi đến muộn, và căn phòng chỉ mở cho đúng người đã đặt trong đúng khung giờ của bạn.
                </p>
            </article>
            <article>
                <h2 class="text-xl font-bold text-gray-900 mb-2">Lần đầu ở homestay tự check-in? Chuẩn bị gì</h2>
                <ul class="list-disc pl-5 space-y-1">
                    <li><strong class="text-gray-900">Lưu mã mở khoá và địa chỉ:</strong> chụp màn hình đơn đặt phòng, gồm mã, giờ nhận phòng và bản đồ chỉ đường, phòng khi điện thoại mất sóng.</li>
                    <li><strong class="text-gray-900">Xem trước đường đi:</strong> mở bản đồ từ đơn đặt phòng trước khi xuất phát, đặc biệt nếu chi nhánh nằm trong hẻm hoặc bạn đến lần đầu vào buổi tối.</li>
                    <li><strong class="text-gray-900">Sạc đầy điện thoại:</strong> nếu muốn mở cửa bằng ứng dụng hoặc cần gọi hỗ trợ khi đến nơi.</li>
                    <li><strong class="text-gray-900">Đến trong khung giờ đã đặt:</strong> mã chỉ dùng được trong thời gian của đơn. Nếu biết mình sẽ đến sớm hoặc muộn, hãy báo trước qua hotline.</li>
                    <li><strong class="text-gray-900">Kiểm tra phòng khi vừa vào:</strong> nếu có gì chưa ổn về vệ sinh hay thiết bị, hãy báo ngay để được xử lý kịp thời trong thời gian lưu trú.</li>
                </ul>
            </article>
            <article>
                <h2 class="text-xl font-bold text-gray-900 mb-2">Trong thời gian lưu trú và khi trả phòng</h2>
                <ul class="list-disc pl-5 space-y-1">
                    <li>Giữ kín mã mở khoá, không chia sẻ cho người không ở cùng đơn đặt phòng.</li>
                    <li>Giữ yên lặng và giữ gìn vệ sinh chung, đặc biệt vào buổi tối khi các phòng khác đang nghỉ.</li>
                    <li>Tuân thủ <a href="{{ url('/noi-quy-va-quy-dinh') }}" class="text-primary hover:underline">nội quy và quy định</a> của từng chi nhánh.</li>
                    <li>Khi trả phòng, kiểm tra lại đồ cá nhân, tắt điều hoà và đèn, đóng cửa phòng rồi rời đi trước giờ trả phòng, không cần gặp lễ tân.</li>
                    <li>Muốn ở thêm giờ, hãy liên hệ hotline trước giờ trả phòng để được kiểm tra phòng trống và gia hạn.</li>
                </ul>
                <p class="mt-2">
                    Sau khi trả phòng, bạn có thể để lại đánh giá cho phòng đã ở. Đánh giá thật của khách giúp những
                    người đặt sau chọn phòng dễ hơn và giúp chi nhánh cải thiện chất lượng phục vụ.
                </p>
            </article>
            <article>
                <h2 class="text-xl font-bold text-gray-900 mb-2">Liên hệ hỗ trợ khi cần</h2>
                <p class="mb-2">
                    Dù quy trình đặt và nhận phòng đều tự động, bạn vẫn luôn có người hỗ trợ. Các kênh liên hệ của 365
                    Home gồm hotline <a href="tel:0939174365" class="text-primary hover:underline">0939 174 365</a>, chat
                    Zalo, chat Messenger và email 365home.cantho@gmail.com, hiển thị ở góc màn hình trên mọi trang.
                </p>
                <p>
                    Khi liên hệ, hãy cung cấp số điện thoại đã dùng để đặt phòng để nhân viên tra cứu đơn nhanh hơn. Với
                    các vấn đề gấp trong lúc nhận phòng như không mở được cửa, gọi hotline là cách nhanh nhất để được hỗ
                    trợ.
                </p>
            </article>
            <article>
                <h2 class="text-xl font-bold text-gray-900 mb-2">Đặt phòng nhanh hơn với ứng dụng 365 Home</h2>
                <p class="mb-2">
                    Ứng dụng 365 Home có trên App Store và Google Play, dùng chung tài khoản với website. Trên ứng dụng,
                    bạn có thể tìm phòng theo khu vực, xem lịch trống, đặt và thanh toán chỉ trong vài bước, theo dõi
                    toàn bộ đơn đặt phòng và xem lại mã mở khoá bất cứ lúc nào.
                </p>
                <p>
                    Với những phòng hỗ trợ, ứng dụng còn cho phép mở cửa ngay trên điện thoại trong thời gian lưu trú.
                    Bạn cũng có thể lưu các phòng yêu thích để đặt lại lần sau và nhận thông báo về đơn đặt phòng, ưu đãi
                    mới mà không cần mở website.
                </p>
            </article>
            <article>
                <h2 class="text-xl font-bold text-gray-900 mb-2">Mỗi phòng một phong cách riêng</h2>
                <p class="mb-2">
                    Khác với phòng khách sạn thường giống nhau, nhiều phòng homestay của 365 Home được thiết kế theo chủ
                    đề riêng và đặt tên theo phong cách đó, ví dụ các phòng Velvet, Gallery, Lumen và Midnight tại chi
                    nhánh 254 Xuân Thủy. Có phòng tông ấm với chất liệu nhung mềm, có phòng như một góc triển lãm nhỏ,
                    có phòng nhiều ánh sáng tự nhiên, có phòng tông tối trầm lắng cho những buổi tối thư giãn.
                </p>
                <p>
                    Trước khi đặt, hãy lướt qua album ảnh của từng phòng để chọn không gian hợp gu: chụp ảnh kỷ niệm,
                    xem phim cùng nhau, làm việc yên tĩnh hay đơn giản là ngủ một giấc thật ngon. Ảnh trên website là
                    ảnh chụp thực tế tại phòng, kèm danh sách tiện nghi cụ thể để bạn biết trước mình sẽ có những gì.
                </p>
            </article>
            <article class="lg:col-span-2">
                <h2 class="text-xl font-bold text-gray-900 mb-2">Homestay tự check-in khác gì nhà nghỉ truyền thống?</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-10">
                    <p class="mb-2">
                        Ở nhà nghỉ truyền thống, bạn thường phải đến quầy lễ tân, gửi giấy tờ, chờ nhân viên kiểm tra
                        phòng trống rồi mới nhận chìa khoá. Giá phòng nhiều khi chỉ biết khi đến nơi, và nếu đến vào giờ
                        khuya có thể không còn ai trực để nhận khách. Mọi việc phụ thuộc vào người đang trực quầy.
                    </p>
                    <p class="mb-2">
                        Với homestay tự check-in trên 365 Home, toàn bộ bước đó được làm trước trên điện thoại: bạn xem
                        ảnh thật, xem giá từng khung giờ, chọn phòng và thanh toán trước. Khi đến nơi chỉ cần nhập mã là
                        vào phòng, không chờ đợi, không phải trao đổi với ai, và luôn biết chính xác mình đã trả bao
                        nhiêu tiền cho khoảng thời gian nào.
                    </p>
                </div>
                <p>
                    Điều đó không có nghĩa là bạn phải tự xoay xở một mình: đội ngũ hỗ trợ của 365 Home luôn có thể liên
                    hệ qua hotline và Zalo trong suốt thời gian lưu trú, từ lúc tìm đường đến chi nhánh cho tới khi trả
                    phòng.
                </p>
            </article>
        </div>

        <nav class="mt-8 flex flex-wrap gap-2" aria-label="Xem thêm">
                <a href="{{ url('/homestay-can-tho-theo-gio') }}" class="rounded-full border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:border-gray-500">Homestay Cần Thơ theo giờ</a>
                <a href="{{ url('/homestay-ninh-kieu') }}" class="rounded-full border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:border-gray-500">Homestay Ninh Kiều, Cần Thơ</a>
                <a href="{{ url('/huong-dan-tu-check-in') }}" class="rounded-full border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:border-gray-500">Hướng dẫn tự check-in</a>
                <a href="{{ url('/') }}" class="rounded-full border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:border-gray-500">Trang chủ 365 Home</a>
        </nav>
    </div>
</section>

<section class="py-6 sm:py-8 bg-gray-50">
    <div class="max-w-3xl mx-auto px-4 sm:px-6">
        <x-bladethemev1::seo-content.faq-accordion :items="$landingFaqs" />
    </div>
</section>
