{{-- Nội dung trang đích /homestay-can-tho-theo-gio (xem Modules\BladeThemeV1\Support\LandingPages) — chuyển từ khối
     cẩm nang trang chủ cũ (components/home/guide + travel-guide) về đúng trang nhắm từ khoá này.
     Chỉ nêu dữ kiện đúng với hệ thống; đổi chính sách đặt/check-in thì sửa lại đây. --}}
@props(['roomCount' => 0])
@php
    $landingFaqs = [
        ['q' => 'Giá homestay theo giờ ở 365 Home được tính thế nào?', 'a' => 'Mỗi khung giờ trên lịch đặt phòng có giá riêng, hiển thị ngay trên ô khung giờ. Bạn chọn bao nhiêu khung thì tổng tiền được cộng sẵn và hiển thị trước khi thanh toán.'],
        ['q' => 'Có đặt nhiều khung giờ liền nhau trong cùng một phòng được không?', 'a' => 'Được. Chọn liên tiếp các khung giờ còn trống của cùng một phòng trên lịch để ở lâu hơn, tổng tiền tự cộng trước khi bạn xác nhận.'],
        ['q' => 'Muốn ở qua đêm thì chọn thế nào?', 'a' => 'Chọn khung giờ có biểu tượng mặt trăng (Qua đêm) của phòng muốn ở: nhận phòng buổi tối và trả phòng vào sáng hôm sau.'],
        ['q' => 'Có cần gọi điện giữ phòng trước không?', 'a' => 'Không cần. Toàn bộ việc xem lịch trống, đặt và thanh toán đều làm trực tuyến; đơn đã thanh toán đủ sẽ có mã mở khoá để tự check-in.'],
    ];
@endphp
<section class="py-8 sm:py-10 bg-white">
    <div class="w-full max-w-7xl mx-auto px-4 sm:px-6">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-x-10 gap-y-8 text-gray-600 leading-relaxed">
            <article>
                <h2 class="text-xl font-bold text-gray-900 mb-2">Chọn phòng theo đúng nhu cầu</h2>
                <p class="mb-2">
                    Mỗi chuyến đi có một nhu cầu khác nhau, và chọn đúng loại phòng ngay từ đầu giúp bạn tiết kiệm
                    cả thời gian lẫn chi phí.
                </p>
                <ul class="list-disc pl-5 space-y-1">
                    <li><strong class="text-gray-900">Nghỉ ngắn trong ngày, công tác:</strong> đặt theo giờ một phòng homestay hoặc nhà nghỉ gần nơi làm việc, chỉ trả tiền cho đúng số giờ bạn cần.</li>
                    <li><strong class="text-gray-900">Cặp đôi, kỷ niệm, cuối tuần:</strong> chọn homestay có phong cách trang trí riêng và đặt khung qua đêm để có trọn buổi tối và buổi sáng thong thả.</li>
                    <li><strong class="text-gray-900">Nhóm bạn, gia đình:</strong> villa hoặc căn hộ có không gian chung rộng rãi, nhiều phòng ngủ, thuận tiện sinh hoạt cùng nhau.</li>
                    <li><strong class="text-gray-900">Ở dài ngày, theo tháng:</strong> căn hộ hoặc phòng MiniHouse cho thuê theo tháng, giá rõ ràng và có nhân viên hỗ trợ trong suốt thời gian thuê.</li>
                </ul>
                <p class="mt-2">
                    Trước khi đặt, hãy xem kỹ hình ảnh, danh sách tiện nghi và đánh giá của những khách đã ở. Thông tin
                    này nằm ngay trong trang chi tiết của từng phòng.
                </p>
            </article>
            <article>
                <h2 class="text-xl font-bold text-gray-900 mb-2">Đọc lịch đặt phòng và chọn khung giờ</h2>
                <p class="mb-2">
                    Lịch đặt phòng của mỗi chi nhánh hiển thị từng phòng cùng các khung giờ trong ngày, mỗi khung giờ
                    có giá riêng. Khung còn trống có thể bấm chọn, khung đã có người đặt hoặc đã qua giờ được tô màu
                    khác và không chọn được, nên bạn không lo đặt nhầm.
                </p>
                <p class="mb-2">
                    Bạn có thể chọn nhiều khung giờ liền nhau trong cùng một phòng để ở lâu hơn, tổng tiền được cộng
                    sẵn trước khi thanh toán. Một số khung giờ có nhãn khuyến mãi riêng, giá ưu đãi đã được tính vào số
                    tiền hiển thị. Muốn ở qua đêm, chọn khung giờ có biểu tượng mặt trăng; muốn ở nhiều ngày, chọn ngày
                    nhận và ngày trả trên thanh tìm kiếm.
                </p>
                <p>
                    Mẹo nhỏ: những khung giờ cuối tuần, ngày lễ thường hết nhanh, nên đặt sớm vài ngày để có nhiều lựa
                    chọn phòng hơn.
                </p>
            </article>
            <article>
                <h2 class="text-xl font-bold text-gray-900 mb-2">Mẹo đặt phòng tiết kiệm</h2>
                <p class="mb-2">
                    Cùng một căn phòng, cách bạn đặt sẽ quyết định số tiền phải trả. Vài nguyên tắc đơn giản dưới đây
                    giúp bạn luôn chọn được phương án hợp lý nhất:
                </p>
                <ul class="list-disc pl-5 space-y-1 mb-2">
                    <li><strong class="text-gray-900">Chỉ cần nghỉ vài tiếng:</strong> đặt theo giờ là rẻ nhất, bạn chỉ trả cho đúng những khung giờ đã chọn thay vì trả tiền cả ngày.</li>
                    <li><strong class="text-gray-900">Đến buổi tối, rời đi buổi sáng:</strong> khung qua đêm thường có lợi hơn so với cộng nhiều khung giờ lẻ, lại không phải canh giờ trả phòng giữa đêm.</li>
                    <li><strong class="text-gray-900">Ở từ hai đêm trở lên:</strong> đặt theo ngày trên thanh tìm kiếm, nhận phòng từ 14:00 và trả phòng trước 12:00 trưa ngày trả, để có trọn thời gian nghỉ ngơi.</li>
                    <li><strong class="text-gray-900">Ở nhiều tuần hoặc nhiều tháng:</strong> cân nhắc thuê theo tháng qua MiniHouse, chi phí mỗi đêm thấp hơn nhiều so với đặt từng ngày.</li>
                </ul>
                <p>
                    Ngoài ra, hãy để ý những khung giờ có nhãn khuyến mãi trên lịch đặt phòng, dùng coupon dành cho
                    thành viên mới ở lần đặt đầu tiên và đặt sớm vào dịp cuối tuần, ngày lễ, khi phòng đẹp thường được
                    đặt hết trước vài ngày.
                </p>
            </article>
            <article>
                <h2 class="text-xl font-bold text-gray-900 mb-2">Đặt phòng dịp lễ, Tết và cuối tuần</h2>
                <p class="mb-2">
                    Những dịp nghỉ lễ dài, Tết Nguyên đán và các tối thứ Sáu, thứ Bảy là lúc nhu cầu phòng tại Cần Thơ
                    tăng cao nhất. Phòng có thiết kế đẹp và khung qua đêm thường được đặt hết từ nhiều ngày trước, giá
                    một số khung giờ cũng có thể khác ngày thường tuỳ cấu hình của từng chi nhánh.
                </p>
                <p>
                    Nếu đã có kế hoạch, hãy đặt sớm và thanh toán ngay để giữ phòng. Trước khi xác nhận, kiểm tra lại
                    ngày, giờ nhận phòng và tổng tiền trên màn hình thanh toán. Trong những ngày cao điểm, đến đúng khung
                    giờ đã đặt và lưu sẵn mã mở khoá giúp bạn nhận phòng nhanh mà không phải chờ.
                </p>
            </article>
            <article>
                <h2 class="text-xl font-bold text-gray-900 mb-2">Lưu trú khi đi công tác, học tập</h2>
                <p class="mb-2">
                    Nhiều khách đến Cần Thơ để công tác, khám chữa bệnh, thi cử hoặc làm thủ tục và chỉ cần một chỗ
                    nghỉ yên tĩnh trong thời gian ngắn. Với nhu cầu này, đặt theo giờ hoặc qua đêm gần trung tâm là lựa
                    chọn tiện nhất: bạn chủ động giờ đến, giờ đi theo lịch làm việc, không phụ thuộc giờ mở cửa quầy lễ
                    tân.
                </p>
                <p>
                    Nếu cần ở lại cả tháng trong thời gian thực tập, học khoá ngắn hạn hay làm việc theo dự án, hãy xem
                    các phòng MiniHouse cho thuê theo tháng. Phòng có giá thuê rõ ràng, có nhân viên hỗ trợ trong suốt
                    thời gian ở và bạn có thể hẹn xem phòng trực tiếp trước khi quyết định.
                </p>
            </article>
            <article>
                <h2 class="text-xl font-bold text-gray-900 mb-2">Đi một mình, nghỉ chân giữa hành trình</h2>
                <p>
                    Nếu bạn đi một mình hoặc chỉ ghé Cần Thơ trên đường về các tỉnh miền Tây, một phòng nhỏ đặt theo giờ
                    là đủ để tắm rửa, sạc pin và ngủ một giấc trước khi đi tiếp. Mô hình tự check-in giúp bạn chủ động
                    hoàn toàn: không cần gọi trước, không lo đến trễ, chỉ cần đặt trên điện thoại khi còn đang trên xe
                    và nhập mã khi tới nơi.
                </p>
            </article>
            <article>
                <h2 class="text-xl font-bold text-gray-900 mb-2">Khi kế hoạch thay đổi</h2>
                <p class="mb-2">
                    Lịch trình đôi khi thay đổi vào phút chót. Chính sách huỷ và đổi lịch có thể khác nhau theo từng loại
                    phòng và thời điểm đặt, được hiển thị trong bước đặt phòng trước khi thanh toán, vì vậy hãy đọc kỹ
                    phần này trước khi xác nhận đơn.
                </p>
                <p>
                    Nếu cần huỷ hoặc dời giờ, hãy liên hệ hotline càng sớm càng tốt. Báo sớm giúp chi nhánh sắp xếp lại
                    phòng và tăng khả năng bạn được đổi sang khung giờ khác phù hợp, thay vì để đơn hết hạn mà không sử
                    dụng.
                </p>
            </article>
            <article>
                <h2 class="text-xl font-bold text-gray-900 mb-2">Ưu đãi cho thành viên</h2>
                <p class="mb-2">
                    Đăng ký tài khoản 365 Home bằng số điện thoại, mã xác thực được gửi qua Zalo. Thành viên mới được
                    tặng coupon giảm 200.000đ cho lần đặt phòng đầu tiên.
                </p>
                <p>
                    Ngoài ra, các mã giảm giá và chương trình khuyến mãi theo từng thời điểm được hiển thị ở mục ưu đãi
                    trên trang chủ và trong ứng dụng. Đặt phòng khi đã đăng nhập còn giúp bạn xem lại toàn bộ lịch sử
                    đơn, lưu các phòng yêu thích và nhận thông báo về đơn đặt phòng ngay trên điện thoại.
                </p>
                <p class="mt-2">
                    Mỗi mã giảm giá có điều kiện áp dụng riêng như thời hạn, giá trị đơn tối thiểu hoặc phòng được áp dụng, được
                    ghi rõ khi bạn chọn mã ở bước thanh toán. Số tiền giảm được trừ trực tiếp vào tổng tiền trước khi bạn
                    xác nhận, nên bạn luôn thấy chính xác số tiền cuối cùng phải trả.
                </p>
            </article>
        </div>

        <nav class="mt-8 flex flex-wrap gap-2" aria-label="Xem thêm">
                <a href="{{ url('/homestay-tu-check-in-can-tho') }}" class="rounded-full border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:border-gray-500">Homestay tự check-in tại Cần Thơ</a>
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
