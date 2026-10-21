{{-- Nội dung trang đích /homestay-ninh-kieu (xem Modules\BladeThemeV1\Support\LandingPages) — chuyển từ khối
     cẩm nang trang chủ cũ (components/home/guide + travel-guide) về đúng trang nhắm từ khoá này.
     Chỉ nêu dữ kiện đúng với hệ thống; đổi chính sách đặt/check-in thì sửa lại đây. --}}
@props(['roomCount' => 0])
@php
    $landingFaqs = [
        ['q' => 'Homestay 365 Home ở Ninh Kiều nằm ở những đâu?', 'a' => 'Các chi nhánh homestay hiện có tại khu Ninh Kiều, Cần Thơ, gồm khu vực đường Xuân Thủy (phường An Bình), đường Trần Nam Phú và đường 30 Tháng 4. Danh sách phòng và vị trí trên bản đồ ở đầu trang này.'],
        ['q' => 'Có đặt nhiều phòng cho cả nhóm được không?', 'a' => 'Được. Đặt lần lượt từng phòng trên lịch của cùng một chi nhánh, hoặc gọi hotline 0939 174 365 để nhân viên kiểm tra phòng trống và sắp xếp cho cả nhóm.'],
        ['q' => 'Có phòng cho thuê theo tháng ở Cần Thơ không?', 'a' => 'Có. Mục MiniHouse liệt kê các phòng đang trống cho thuê theo tháng, kèm giá thuê, diện tích và vị trí trên bản đồ.'],
        ['q' => 'Nhận và trả phòng khi đặt theo ngày lúc mấy giờ?', 'a' => 'Khi đặt theo ngày, bạn nhận phòng từ 14:00 và trả phòng trước 12:00 trưa của ngày trả phòng.'],
    ];
@endphp
<section class="py-8 sm:py-10 bg-white">
    <div class="w-full max-w-7xl mx-auto px-4 sm:px-6">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-x-10 gap-y-8 text-gray-600 leading-relaxed">
            <article>
                <h2 class="text-xl font-bold text-gray-900 mb-2">Chọn chi nhánh bằng bản đồ</h2>
                <p class="mb-2">
                    Trang tìm kiếm phòng có bản đồ hiển thị vị trí các chi nhánh cùng mức giá, nên bạn có thể chọn nơi ở
                    gần điểm cần đến nhất: gần chỗ làm việc, bệnh viện, trường học hay khu vui chơi. Rê chuột hoặc chạm
                    vào từng điểm trên bản đồ để xem nhanh các phòng của chi nhánh đó.
                </p>
                <p>
                    Mỗi chi nhánh có trang riêng với địa chỉ cụ thể, danh sách phòng và lịch trống theo từng ngày. Sau
                    khi đặt, liên kết bản đồ chỉ đường cũng có sẵn trong đơn đặt phòng để bạn mở ngay trên điện thoại khi
                    di chuyển.
                </p>
            </article>
            <article>
                <h2 class="text-xl font-bold text-gray-900 mb-2">Khám phá Cần Thơ khi lưu trú</h2>
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
                <h2 class="text-xl font-bold text-gray-900 mb-2">Đi Cần Thơ: di chuyển và thời điểm đẹp</h2>
                <p class="mb-2">
                    Cần Thơ là thành phố lớn nhất miền Tây Nam Bộ, cách TP. Hồ Chí Minh khoảng 170 km. Từ TP. Hồ Chí
                    Minh, bạn có thể đi xe khách giường nằm khoảng 3 đến 4 tiếng, tự lái xe qua cầu Cần Thơ, hoặc bay
                    thẳng đến sân bay quốc tế Cần Thơ, cách trung tâm Ninh Kiều khoảng 10 km.
                </p>
                <p class="mb-2">
                    Khí hậu Cần Thơ nóng ấm quanh năm, chia làm mùa khô và mùa mưa. Từ khoảng tháng 5 đến tháng 8 là
                    mùa trái cây chín rộ trong các nhà vườn, còn khoảng tháng 9 đến tháng 11 là mùa nước nổi, thời điểm
                    sông nước miền Tây đầy đặn và nhiều món ăn theo mùa nhất.
                </p>
                <p>
                    Trong nội thành, xe máy, taxi và xe công nghệ đều dễ gọi. Ở gần trung tâm Ninh Kiều giúp bạn đi bộ
                    được ra bến sông buổi tối và tiết kiệm thời gian di chuyển giữa các điểm tham quan.
                </p>
            </article>
            <article>
                <h2 class="text-xl font-bold text-gray-900 mb-2">Gợi ý lịch trình 2 ngày ở Cần Thơ</h2>
                <p class="mb-2">
                    <strong class="text-gray-900">Ngày 1:</strong> nhận phòng, nghỉ ngơi buổi trưa rồi ghé nhà cổ Bình
                    Thủy, một ngôi nhà cổ kiến trúc giao thoa Đông Tây nổi tiếng của thành phố. Chiều tối dạo bến Ninh
                    Kiều, ghé chùa Ông ngay bên bờ sông, đi bộ qua cầu đi bộ và ăn tối ở chợ đêm hoặc trên tàu du lịch
                    dọc sông Hậu.
                </p>
                <p class="mb-2">
                    <strong class="text-gray-900">Ngày 2:</strong> dậy sớm đi thuyền ra chợ nổi Cái Răng từ khoảng 5 đến
                    7 giờ sáng, lúc ghe xuồng mua bán tấp nập nhất, ăn sáng hủ tiếu ngay trên sông. Sau đó ghé các lò hủ
                    tiếu, vườn trái cây ở Phong Điền hoặc Thiền viện Trúc Lâm Phương Nam trước khi về trả phòng.
                </p>
                <p>
                    Đặc sản nên thử gồm bánh xèo, lẩu mắm, nem nướng Cái Răng, bánh tét lá cẩm và các loại trái cây
                    miệt vườn theo mùa như sầu riêng, chôm chôm, măng cụt.
                </p>
            </article>
            <article>
                <h2 class="text-xl font-bold text-gray-900 mb-2">Ăn gì ở Cần Thơ?</h2>
                <p class="mb-2">
                    Ẩm thực là một trong những lý do đáng để ghé Cần Thơ. Vài món nên thử khi lưu trú gần trung tâm:
                </p>
                <ul class="list-disc pl-5 space-y-1 mb-2">
                    <li><strong class="text-gray-900">Hủ tiếu:</strong> sợi hủ tiếu dai mềm, nước lèo ngọt thanh từ xương, ăn sáng trên ghe ở chợ nổi Cái Răng là trải nghiệm rất riêng.</li>
                    <li><strong class="text-gray-900">Bánh xèo:</strong> vỏ bánh giòn vàng, nhân tôm thịt giá, cuốn cùng rau rừng và chấm nước mắm chua ngọt.</li>
                    <li><strong class="text-gray-900">Lẩu mắm:</strong> nồi lẩu đậm vị mắm miền Tây ăn cùng hàng chục loại rau đồng, hợp cho nhóm bạn và gia đình.</li>
                    <li><strong class="text-gray-900">Nem nướng Cái Răng:</strong> nem nướng thơm, cuốn bánh tráng với rau sống, dưa leo, chấm nước sốt sệt đặc trưng.</li>
                    <li><strong class="text-gray-900">Bánh tét lá cẩm:</strong> nếp nhuộm tím tự nhiên từ lá cẩm, là món quà mang về được nhiều người chọn.</li>
                    <li><strong class="text-gray-900">Trái cây miệt vườn:</strong> tuỳ mùa có sầu riêng, chôm chôm, măng cụt, dâu Hạ Châu, có thể mua ngay tại vườn hoặc ở chợ.</li>
                </ul>
                <p>
                    Buổi tối, khu chợ đêm và các con đường quanh bến Ninh Kiều có rất nhiều quán ăn vặt, chè, nước mía,
                    là lựa chọn tiện lợi khi bạn ở gần trung tâm và muốn đi bộ ăn uống sau giờ nhận phòng.
                </p>
            </article>
            <article>
                <h2 class="text-xl font-bold text-gray-900 mb-2">Đặt phòng cho nhóm và gia đình</h2>
                <p class="mb-2">
                    Đi theo nhóm, hãy ưu tiên villa hoặc căn hộ có nhiều phòng ngủ và không gian sinh hoạt chung. Mỗi
                    phòng đều ghi rõ số khách tối đa trong trang chi tiết, hãy chọn số khách đúng thực tế khi tìm kiếm
                    để chỉ thấy những phòng phù hợp.
                </p>
                <p>
                    Nếu nhóm cần nhiều phòng tại cùng một chi nhánh, bạn có thể đặt lần lượt từng phòng trên lịch của
                    chi nhánh đó, hoặc gọi hotline <a href="tel:0939174365" class="text-primary hover:underline">0939 174 365</a>
                    để nhân viên kiểm tra phòng trống và hỗ trợ sắp xếp cho cả nhóm ở gần nhau.
                </p>
            </article>
            <article>
                <h2 class="text-xl font-bold text-gray-900 mb-2">Đi Cần Thơ cùng trẻ nhỏ</h2>
                <p>
                    Đi cùng con nhỏ, hãy chọn phòng rộng hoặc căn hộ có nhiều chỗ ngủ, ưu tiên khung qua đêm hoặc theo
                    ngày để cả nhà có thời gian nghỉ trưa thoải mái. Các hoạt động hợp với trẻ em gồm đi tàu ngắm sông
                    Hậu buổi tối, tham quan vườn trái cây, xem cá lóc bay ở Cồn Sơn và dạo công viên ven bến Ninh Kiều.
                    Nên mang theo áo khoác mỏng cho các chuyến đi thuyền sáng sớm và kem chống nắng cho buổi trưa.
                </p>
            </article>
            <article>
                <h2 class="text-xl font-bold text-gray-900 mb-2">Thuê phòng dài hạn với MiniHouse</h2>
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
            <article class="lg:col-span-2">
                <h2 class="text-xl font-bold text-gray-900 mb-2">Những điểm tham quan nên ghé</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-10">
                    <ul class="list-disc pl-5 space-y-1">
                        <li><strong class="text-gray-900">Bến Ninh Kiều:</strong> biểu tượng của Cần Thơ bên sông Hậu, đẹp nhất lúc chiều tối khi đèn trên cầu đi bộ và tàu du lịch được thắp sáng.</li>
                        <li><strong class="text-gray-900">Chùa Ông (Quảng Triệu Hội Quán):</strong> ngôi chùa của người Hoa với kiến trúc chạm khắc tinh xảo, nằm ngay cạnh bến Ninh Kiều.</li>
                        <li><strong class="text-gray-900">Chợ nổi Cái Răng:</strong> chợ trên sông nổi tiếng của miền Tây, ghe xuồng treo hàng trên cây bẹo để báo món bán, nên đến từ sáng sớm.</li>
                        <li><strong class="text-gray-900">Nhà cổ Bình Thủy:</strong> ngôi nhà hơn một trăm năm tuổi kết hợp kiến trúc Pháp và Việt, từng là bối cảnh của nhiều bộ phim.</li>
                    </ul>
                    <ul class="list-disc pl-5 space-y-1">
                        <li><strong class="text-gray-900">Thiền viện Trúc Lâm Phương Nam:</strong> quần thể chùa rộng, nhiều cây xanh và hồ nước, không gian yên bình để tham quan và chụp ảnh.</li>
                        <li><strong class="text-gray-900">Vườn trái cây Phong Điền:</strong> tham quan, hái và ăn trái cây tại vườn theo mùa, kết hợp đi xuồng trên kênh rạch.</li>
                        <li><strong class="text-gray-900">Vườn cò Bằng Lăng:</strong> nơi hàng nghìn con cò về trú ngụ, đẹp nhất vào lúc chiều muộn khi cò bay về tổ.</li>
                        <li><strong class="text-gray-900">Cồn Sơn:</strong> cù lao giữa sông Hậu với các mô hình du lịch nhà vườn, xem cá lóc bay và thưởng thức món quê.</li>
                    </ul>
                </div>
                <p class="mt-3">
                    Phần lớn các điểm trên đều đi được trong ngày từ khu Ninh Kiều. Ở theo giờ hoặc qua đêm tại một
                    chi nhánh gần trung tâm giúp bạn có chỗ nghỉ trưa, tắm rửa và cất đồ giữa các chặng tham quan, thay
                    vì phải thuê phòng trọn ngày.
                </p>
            </article>
        </div>

        <nav class="mt-8 flex flex-wrap gap-2" aria-label="Xem thêm">
                <a href="{{ url('/homestay-can-tho-theo-gio') }}" class="rounded-full border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:border-gray-500">Homestay Cần Thơ theo giờ</a>
                <a href="{{ url('/homestay-tu-check-in-can-tho') }}" class="rounded-full border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:border-gray-500">Homestay tự check-in tại Cần Thơ</a>
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
