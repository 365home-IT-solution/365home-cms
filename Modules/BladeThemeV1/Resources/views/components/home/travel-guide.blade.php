{{-- Khối chữ thứ 2 của trang chủ (cùng mục đích với components/home/guide.blade.php: tỉ lệ text/HTML,
     Semrush tính SỐ KÝ TỰ chữ / số byte HTML). Phần "An toàn" chỉ nêu đúng những gì hệ thống làm: mã
     gắn với từng đơn và hết hiệu lực sau thời gian đặt (access-codes:auto-delete-expired), có ghi
     nhận lịch sử mở cửa (LockRecordCallbackController), chủ nhà khoá khẩn cấp được quyền mở cửa qua
     ứng dụng (products.emergency_locked_at). Phần du lịch là thông tin chung về Cần Thơ, không gắn
     cam kết dịch vụ nào của 365 Home. Markup tối giản, không Alpine. --}}
<section class="py-8 sm:py-10 bg-gray-50">
    <div class="w-full max-w-7xl mx-auto px-4 sm:px-6">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-x-10 gap-y-8 text-gray-600 leading-relaxed">
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
                <h2 class="text-xl font-bold text-gray-900 mb-2">Đi Cần Thơ cùng trẻ nhỏ</h2>
                <p>
                    Đi cùng con nhỏ, hãy chọn phòng rộng hoặc căn hộ có nhiều chỗ ngủ, ưu tiên khung qua đêm hoặc theo
                    ngày để cả nhà có thời gian nghỉ trưa thoải mái. Các hoạt động hợp với trẻ em gồm đi tàu ngắm sông
                    Hậu buổi tối, tham quan vườn trái cây, xem cá lóc bay ở Cồn Sơn và dạo công viên ven bến Ninh Kiều.
                    Nên mang theo áo khoác mỏng cho các chuyến đi thuyền sáng sớm và kem chống nắng cho buổi trưa.
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
    </div>
</section>
