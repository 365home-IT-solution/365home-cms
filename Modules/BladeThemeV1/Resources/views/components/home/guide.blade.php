{{-- Cẩm nang lưu trú trên trang chủ — khối chữ dài, markup tối giản (ít class, không Alpine) để nâng
     tỉ lệ text/HTML (Semrush ~0.04, mục tiêu 0.1) bằng nội dung thật có ích, không phải chữ độn.
     Chỉ nêu dữ kiện đang đúng trong hệ thống: mã mở khoá chỉ cấp khi đơn đã thanh toán đủ (đơn cọc
     cấp sau khi trả phần còn lại — livewire/search-booking.blade.php), tra cứu đơn bằng số điện thoại,
     mở cửa qua ứng dụng trong khung 30 phút trước giờ nhận đến 30 phút sau giờ trả
     (Api\UnlockController), ưu đãi thành viên mới 200.000đ (thẻ "Lần đầu khám phá"), MiniHouse cho
     thuê theo tháng + điều kiện đối tác (BladeThemeV1Controller::partnerOnboardingPage). Không nêu giá
     phòng cụ thể vì mỗi phòng cấu hình khác nhau. Đổi chính sách nào ở trên thì sửa lại đây. --}}
<section class="py-8 sm:py-10 bg-white">
    <div class="w-full max-w-7xl mx-auto px-4 sm:px-6 home-guide">
        <h2 class="text-2xl font-bold text-gray-900 mb-2">Cẩm nang lưu trú cùng 365 Home</h2>
        <p class="text-gray-600 leading-relaxed mb-6 max-w-4xl">
            Dù bạn cần một chỗ nghỉ vài tiếng giữa ngày, một đêm riêng tư cuối tuần hay một căn phòng để ở lâu
            dài, những ghi chú dưới đây giúp bạn chọn đúng loại phòng, đặt nhanh, thanh toán an toàn và tận dụng
            tốt nhất thời gian lưu trú tại các chi nhánh 365 Home.
        </p>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-x-10 gap-y-6 text-gray-600 leading-relaxed">
            <article>
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Chọn phòng theo đúng nhu cầu</h3>
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
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Đọc lịch đặt phòng và chọn khung giờ</h3>
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
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Thanh toán, đặt cọc và nhận mã mở khoá</h3>
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
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Đến nơi và tự check-in</h3>
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
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Trong thời gian lưu trú và khi trả phòng</h3>
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
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Ưu đãi cho thành viên</h3>
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

            <article>
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Thuê phòng dài hạn với MiniHouse</h3>
                <p class="mb-2">
                    Nếu bạn là sinh viên, người đi làm hoặc cần chỗ ở trong nhiều tháng, mục
                    <a href="{{ url('/minihouse') }}" class="text-primary hover:underline">MiniHouse</a> liệt kê các phòng
                    đang trống cho thuê theo tháng, kèm giá thuê, diện tích, hình ảnh và vị trí trên bản đồ.
                </p>
                <p>
                    Chọn phòng phù hợp và để lại số điện thoại, nhân viên 365 Home sẽ liên hệ tư vấn, hẹn lịch xem phòng
                    trực tiếp và hỗ trợ bạn trong suốt quá trình thuê, từ ký hợp đồng đến thanh toán tiền thuê hàng
                    tháng. Giá hiển thị là tiền thuê phòng, chưa gồm điện nước và phí dịch vụ khác nếu có.
                </p>
            </article>

            <article>
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Khám phá Cần Thơ khi lưu trú</h3>
                <p class="mb-2">
                    Các chi nhánh 365 Home tại Cần Thơ nằm ở quận Ninh Kiều, khu vực trung tâm của thành phố, rất thuận
                    tiện để đi dạo và khám phá:
                </p>
                <ul class="list-disc pl-5 space-y-1">
                    <li><strong class="text-gray-900">Bến Ninh Kiều và cầu đi bộ:</strong> điểm tản bộ buổi tối nổi tiếng bên sông Hậu, nhiều quán cà phê và tàu du lịch trên sông.</li>
                    <li><strong class="text-gray-900">Chợ đêm Ninh Kiều:</strong> ẩm thực đường phố miền Tây và đặc sản để mua làm quà.</li>
                    <li><strong class="text-gray-900">Chợ nổi Cái Răng:</strong> nên đi từ sáng sớm bằng thuyền để xem cảnh mua bán trên sông, nét văn hoá đặc trưng của miền Tây.</li>
                    <li><strong class="text-gray-900">Ẩm thực:</strong> bánh xèo, lẩu mắm, hủ tiếu, bánh cống và các loại trái cây miệt vườn theo mùa.</li>
                </ul>
                <p class="mt-2">
                    Ở theo giờ hoặc qua đêm gần trung tâm giúp bạn chủ động nghỉ ngơi giữa các điểm tham quan mà không
                    phải trả tiền phòng cả ngày.
                </p>
            </article>

            <article>
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Lần đầu ở homestay tự check-in? Chuẩn bị gì</h3>
                <ul class="list-disc pl-5 space-y-1">
                    <li><strong class="text-gray-900">Lưu mã mở khoá và địa chỉ:</strong> chụp màn hình đơn đặt phòng, gồm mã, giờ nhận phòng và bản đồ chỉ đường, phòng khi điện thoại mất sóng.</li>
                    <li><strong class="text-gray-900">Xem trước đường đi:</strong> mở bản đồ từ đơn đặt phòng trước khi xuất phát, đặc biệt nếu chi nhánh nằm trong hẻm hoặc bạn đến lần đầu vào buổi tối.</li>
                    <li><strong class="text-gray-900">Sạc đầy điện thoại:</strong> nếu muốn mở cửa bằng ứng dụng hoặc cần gọi hỗ trợ khi đến nơi.</li>
                    <li><strong class="text-gray-900">Đến trong khung giờ đã đặt:</strong> mã chỉ dùng được trong thời gian của đơn. Nếu biết mình sẽ đến sớm hoặc muộn, hãy báo trước qua hotline.</li>
                    <li><strong class="text-gray-900">Kiểm tra phòng khi vừa vào:</strong> nếu có gì chưa ổn về vệ sinh hay thiết bị, hãy báo ngay để được xử lý kịp thời trong thời gian lưu trú.</li>
                </ul>
            </article>

            <article>
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Đặt phòng nhanh hơn với ứng dụng 365 Home</h3>
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

            <article class="lg:col-span-2">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Dành cho chủ nhà muốn hợp tác</h3>
                <p class="mb-2">
                    Bạn đang có homestay, căn hộ hoặc phòng cho thuê theo tháng và muốn quản lý đặt phòng hiệu quả hơn?
                    365 Home cung cấp hệ thống đặt phòng trực tuyến với lịch phòng theo khung giờ, thanh toán trực tuyến,
                    mã mở khoá tự động cho khách và công cụ quản lý đơn, doanh thu dành cho chủ nhà.
                </p>
                <p>
                    Thủ tục hợp tác làm hoàn toàn trực tuyến tại trang
                    <a href="{{ url('/dang-ky-hop-tac') }}" class="text-primary hover:underline">Đăng ký hợp tác</a>:
                    nộp giấy tờ pháp lý, ký hợp đồng trực tuyến và nhận tài khoản quản trị sau khi được duyệt. Với
                    MiniHouse, 365 Home không thu hoa hồng, áp dụng gói 199.000đ/tháng và miễn phí 6 tháng đầu.
                </p>
                <p class="mt-2">
                    Sau khi được duyệt, chủ nhà tự cấu hình phòng, giá theo từng khung giờ hoặc theo ngày, khuyến mãi và
                    lịch trống; theo dõi đơn đặt phòng, tình trạng nhận và trả phòng cùng doanh thu theo tháng trên trang
                    quản trị. Khách đặt và thanh toán trực tuyến, nhận mã mở khoá tự động, nên chủ nhà không cần túc trực
                    để giao chìa khoá hay thu tiền tại chỗ, phù hợp cả với người chỉ có một vài phòng cho thuê.
                </p>
            </article>
        </div>
    </div>
</section>
