<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Post\Entities\Post;

class Article02CanThoTwoDaysOneNightSeeder extends Seeder
{
    public function run(): void
    {
        $content = <<<'HTML'
<p><strong>Cần Thơ 2N1Đ</strong> là lịch trình vừa đủ để người lần đầu cảm nhận nhịp sống Tây Đô: sáng lênh đênh giữa chợ nổi, trưa thưởng thức món miền Tây, chiều đi miệt vườn và tối dạo bờ sông Hậu. Kế hoạch dưới đây ưu tiên đường đi gọn, thời gian nghỉ hợp lý và những trải nghiệm đặc trưng, phù hợp cho cặp đôi, nhóm bạn hoặc gia đình muốn đổi gió cuối tuần.</p>
<p>Điểm quan trọng của chuyến đi là ngủ một đêm gần trung tâm Ninh Kiều và dành buổi sáng ngày thứ hai cho chợ nổi Cái Răng. Chợ hoạt động sớm; cổng thông tin Du lịch Cần Thơ gợi ý khung 5–7 giờ là thời điểm lý tưởng. Vì vậy, đừng xếp chợ nổi vào buổi trưa hoặc chiều chỉ để “check-in”, bởi lúc ấy hoạt động giao thương đã thưa hơn nhiều.</p>

<h2>Lịch trình Cần Thơ 2N1Đ tổng quan</h2>
<ul>
<li><strong>Ngày 1:</strong> đến Cần Thơ – ăn trưa – nhận phòng – nhà cổ Bình Thủy hoặc Cồn Sơn – Bến Ninh Kiều – chợ đêm và ẩm thực địa phương.</li>
<li><strong>Ngày 2:</strong> chợ nổi Cái Răng – ăn sáng trên sông – lò hủ tiếu hoặc vườn trái cây – trả phòng – mua đặc sản và trở về.</li>
</ul>
<p>Nếu xuất phát từ Thành phố Hồ Chí Minh, bạn nên chọn chuyến xe đêm muộn hoặc chuyến sáng sớm để có mặt tại Cần Thơ trước 10 giờ. Người tự lái cần chủ động thời gian nghỉ và kiểm tra lộ trình trước ngày đi. Với khách ở xa, có thể bay đến Cần Thơ rồi bắt taxi về khu Ninh Kiều. Lịch trình này không nhồi quá nhiều điểm, nhờ vậy bạn vẫn có thời gian ăn uống, nghỉ trưa và xử lý tình huống thời tiết.</p>
<figure><img src="/images/posts/can-tho-2-ngay-1-dem/can-tho-2-ngay-1-dem-hero.avif" alt="Cần Thơ 2N1Đ bên bờ sông Hậu vào buổi sớm" loading="lazy" width="1440" height="960"><figcaption>Bờ sông Hậu là điểm mở đầu nhẹ nhàng cho hành trình khám phá Tây Đô.</figcaption></figure>

<h2>Ngày 1 của chuyến Cần Thơ 2N1Đ</h2>
<h3>09:30–11:30: Đến trung tâm và gửi hành lý</h3>
<p>Sau khi đến bến xe hoặc sân bay, hãy di chuyển về khu Ninh Kiều, gửi hành lý tại nơi lưu trú rồi nghỉ ngắn. Chọn khách sạn trong bán kính khoảng 1–2 km quanh bến giúp bạn dễ đi bộ vào buổi tối và thuận tiện ra bến tàu sáng hôm sau. Khi đặt phòng, cần hỏi rõ lễ tân về giờ nhận phòng, dịch vụ giữ hành lý và phương án gọi xe lúc 4 giờ 30 sáng.</p>
<p>Đừng vội bắt đầu bằng một điểm tham quan xa. Sau hành trình dài, một bữa trưa vừa phải với bánh cống, nem nướng, bún cá hoặc cơm gia đình sẽ giúp cơ thể thích nghi tốt hơn. Nếu đi cùng trẻ nhỏ hay người lớn tuổi, hãy dành thêm 30–45 phút nghỉ trưa trước khi tiếp tục.</p>

<h3>13:30–15:30: Chọn nhà cổ Bình Thủy hoặc Cồn Sơn</h3>
<p>Nhà cổ Bình Thủy phù hợp với người thích kiến trúc, câu chuyện gia đình Nam Bộ và chụp ảnh. Không gian cổ kính tạo sự đối lập thú vị với khu trung tâm hiện đại. Trước khi đi, nên kiểm tra giờ đón khách trong ngày. Hãy ăn mặc lịch sự, giữ trật tự và tôn trọng khu vực sinh hoạt riêng.</p>
<p>Cồn Sơn là lựa chọn giàu trải nghiệm hơn cho gia đình hoặc nhóm bạn. Du khách có thể đi bè cá, vào vườn, xem làm bánh dân gian hay dùng bữa miệt vườn tùy gói. Vì hoạt động phụ thuộc hộ dân và mùa trái cây, bạn nên liên hệ trước thay vì kỳ vọng lúc nào cũng có đủ mọi loại quả. Cổng thông tin du lịch thành phố cũng giới thiệu Cồn Sơn với các hoạt động vườn cây, chèo xuồng, làm bánh và trải nghiệm đời sống địa phương.</p>
<p>Chỉ nên chọn một trong hai điểm cho buổi chiều. Cố ghép cả nhà cổ và Cồn Sơn dễ khiến lịch bị gấp, tốn thời gian di chuyển và không còn sức cho buổi tối. Nếu ưu tiên một chuyến đi chậm rãi, nhà cổ là phương án gọn; nếu muốn tương tác và đi cùng trẻ, Cồn Sơn thường đáng dành nhiều thời gian hơn.</p>

<h3>16:00–21:30: Ngắm hoàng hôn và ăn tối</h3>
<p>Quay lại khách sạn, nhận phòng rồi ra Bến Ninh Kiều, đi dọc công viên ven sông, ngắm cầu đi bộ và ánh sáng đổi màu trên sông Hậu. Đây cũng là lúc phù hợp để xác nhận vé tàu chợ nổi, vị trí lên tàu, số điện thoại người phụ trách và loại áo phao cho sáng hôm sau.</p>
<p>Buổi tối là lúc thưởng thức ẩm thực mà không cần chạy theo danh sách quá dài. Một nhóm 3–4 người có thể gọi cá lóc nướng trui, lẩu mắm hoặc lẩu cá theo mùa để chia sẻ; khách đi một mình nên chọn nem nướng, bánh xèo, bún hoặc món phần nhỏ. Hỏi giá trước khi gọi, đặc biệt với hải sản và món tính theo trọng lượng.</p>
<figure><img src="/images/posts/can-tho-2-ngay-1-dem/am-thuc-ben-ninh-kieu.avif" alt="Ẩm thực trong lịch trình Cần Thơ 2N1Đ tại Bến Ninh Kiều" loading="lazy" width="1440" height="960"><figcaption>Buổi tối nên dành cho món địa phương và một vòng đi bộ ven sông.</figcaption></figure>
<p>Sau bữa tối, hãy đi bộ quanh bến, chợ đêm và cầu đi bộ Ninh Kiều. Ưu tiên quan sát, ăn món vừa bụng và về nghỉ trước 22 giờ. Chuẩn bị quần áo nhẹ, giày chống trượt, thuốc say tàu nếu cần và đặt hai báo thức, vì ngày thứ hai bắt đầu rất sớm.</p>

<h2>Ngày 2: Chợ nổi trong hành trình Cần Thơ 2N1Đ</h2>
<h3>04:45–05:15: Có mặt tại bến tàu</h3>
<p>Thức dậy, uống ít nước và mang theo áo khoác mỏng. Nếu đi từ Bến Ninh Kiều, thời gian đường thủy đến chợ thường khoảng 30 phút, vì vậy khởi hành lúc trời còn tối là hợp lý. Hãy chọn tàu có áo phao, số khách đúng quy định và người lái cung cấp thông tin rõ ràng. Không đứng dậy đột ngột, không ngồi lên mạn tàu và luôn làm theo hướng dẫn an toàn.</p>
<p>Cần Thơ 2N1Đ sẽ đáng nhớ nhất khi bạn quan sát chợ như một không gian sinh kế thay vì sân khấu du lịch. Giữ khoảng cách với ghe buôn, xin phép trước khi chụp chân dung, không xả rác và hạn chế mặc cả với món ăn giá trị nhỏ. Nếu trời mưa lớn hoặc có cảnh báo thời tiết xấu, nên đổi kế hoạch thay vì cố đi cho đủ lịch.</p>

<h3>05:30–07:30: Khám phá chợ nổi Cái Răng</h3>
<p>Chợ nổi Cái Răng nổi bật với ghe hàng, nông sản, đồ ăn sáng và “cây bẹo” dùng để giới thiệu mặt hàng. Trong ánh bình minh, bạn có thể thấy nhịp mua bán đan xen với các tàu du lịch. Theo Du lịch Cần Thơ, văn hóa chợ nổi Cái Răng đã được đưa vào danh mục di sản văn hóa phi vật thể quốc gia từ năm 2016.</p>
<figure><img src="/images/posts/can-tho-2-ngay-1-dem/cho-noi-cai-rang.avif" alt="Cần Thơ 2N1Đ tham quan chợ nổi Cái Răng lúc bình minh" loading="lazy" width="1440" height="960"><figcaption>Khung 5–7 giờ sáng thường cho trải nghiệm chợ nổi trọn vẹn hơn.</figcaption></figure>
<p>Hãy thử một tô hủ tiếu, bún riêu hoặc ly cà phê từ ghe bán đồ ăn, nhưng cần giữ chắc tô và nhờ người lái hỗ trợ khi ghe cập sát. Người dễ say sóng có thể ăn nhẹ trước, chọn phần nhỏ và tránh nhìn điện thoại quá lâu. Khoảng 60–90 phút tại khu chợ là đủ để quan sát, chụp ảnh và ăn sáng mà không gây mệt.</p>

<h3>07:30–09:30: Lò hủ tiếu hoặc vườn trái cây</h3>
<p>Nhiều tuyến tàu kết hợp điểm làm hủ tiếu hoặc vườn cây. Trước khi đặt, hỏi rõ điểm dừng có nằm trong giá hay thu phí riêng. Ở lò hủ tiếu, bạn có thể tìm hiểu các công đoạn làm bánh, nhưng nên xem đây là trải nghiệm bổ sung. Đừng mua hàng chỉ vì bị thúc giục; sản phẩm phù hợp mới nên mang về.</p>
<p>Nếu chọn vườn trái cây, hãy hỏi loại quả đang vào mùa và quy định hái tại vườn. Không tự ý bẻ cành hoặc mang quả ra ngoài khi chưa thanh toán. Trải nghiệm vườn đẹp nhất nằm ở không khí xanh mát, câu chuyện canh tác và bữa ăn dân dã, chứ không chỉ ở số lượng trái cây có thể ăn.</p>
<figure><img src="/images/posts/can-tho-2-ngay-1-dem/vuon-trai-cay-can-tho.avif" alt="Vườn trái cây trong chuyến Cần Thơ 2N1Đ" loading="lazy" width="1440" height="960"><figcaption>Trái cây thay đổi theo mùa; nên hỏi nhà vườn trước khi đến.</figcaption></figure>

<h3>10:00–13:30: Trả phòng và kết thúc</h3>
<p>Về khách sạn tắm rửa, nghỉ ngắn rồi trả phòng đúng giờ. Nếu còn thời gian, dùng bữa trưa gần nơi lưu trú để tránh di chuyển vòng. Bạn có thể mua bánh tét lá cẩm, ca cao, khô hoặc quà địa phương tại nơi có nhãn mác và ngày sử dụng rõ ràng. Với đồ cần giữ lạnh, hãy cân nhắc thời gian về nhà trước khi mua.</p>
<p>Kết thúc lịch trình khoảng đầu giờ chiều giúp người đi xe khách không quá mệt và người tự lái còn đủ thời gian về trước tối. Nếu chuyến về khởi hành muộn, hãy gửi hành lý tại khách sạn rồi ghé bảo tàng hoặc quán cà phê, thay vì thêm một điểm xa khiến bạn có nguy cơ trễ xe.</p>

<h2>Chi phí Cần Thơ 2N1Đ dự kiến</h2>
<p>Ngân sách thay đổi theo nơi xuất phát, ngày đi, loại phòng và cách thuê tàu. Các khoảng dưới đây dành cho một người khi đi nhóm hai đến bốn người, chưa gồm vé máy bay và mua sắm. Giá thực tế có thể cao hơn vào cuối tuần, lễ Tết hoặc khi chọn dịch vụ riêng.</p>
<ul>
<li><strong>Di chuyển khứ hồi:</strong> khoảng 300.000–700.000 đồng nếu đi xe khách từ khu vực Thành phố Hồ Chí Minh; tự lái cần cộng nhiên liệu, phí đường và gửi xe.</li>
<li><strong>Lưu trú một đêm:</strong> khoảng 300.000–800.000 đồng/người tùy hạng phòng và số người ở chung.</li>
<li><strong>Ăn uống:</strong> khoảng 350.000–700.000 đồng/người cho hai ngày nếu ưu tiên món địa phương.</li>
<li><strong>Tàu và tham quan:</strong> khoảng 250.000–700.000 đồng/người, phụ thuộc tàu ghép hay riêng và số điểm dừng.</li>
<li><strong>Đi lại nội thành và dự phòng:</strong> khoảng 250.000–500.000 đồng/người.</li>
</ul>
<p>Như vậy, ngân sách hợp lý thường vào khoảng 1,45–3,4 triệu đồng/người chưa gồm vé máy bay. Đây không phải bảng giá niêm yết. Trước ngày đi, hãy yêu cầu nơi cung cấp xác nhận tổng tiền, phụ phí, chính sách hủy và các hạng mục đã bao gồm. Cách này quan trọng hơn việc cố tìm một con số rẻ nhất trên mạng.</p>

<h2>Ở đâu khi đi Cần Thơ 2N1Đ?</h2>
<p>Người lần đầu nên ưu tiên Ninh Kiều vì thuận tiện ăn tối, đi bộ và ra bến tàu. Phòng không cần quá sang nhưng nên có đánh giá tốt về vệ sinh, cách âm và hỗ trợ khách đi chợ nổi sớm. Nếu thích yên tĩnh, bạn có thể ở xa trung tâm hơn, song phải tính thêm chi phí xe và thời gian di chuyển lúc rạng sáng.</p>
<p>Gia đình có trẻ nhỏ nên hỏi về giường phụ, thang máy, bữa sáng và phòng không hút thuốc. Cặp đôi có thể chọn phòng nhìn sông nhưng cần kiểm tra tiếng ồn cuối tuần. Nhóm bạn nên xác nhận số người tối đa và phụ thu. Dù đặt qua nền tảng nào, hãy lưu mã xác nhận và số điện thoại trực tiếp của nơi ở.</p>

<h2>Đi Cần Thơ cuối tuần cần chuẩn bị gì?</h2>
<ul>
<li>Trang phục thoáng, áo khoác mỏng, nón, kem chống nắng và áo mưa gọn.</li>
<li>Giày hoặc sandal bám tốt; tránh giày đế trơn khi lên xuống tàu.</li>
<li>Túi chống nước cho điện thoại, giấy tờ và pin dự phòng.</li>
<li>Thuốc cá nhân, thuốc say tàu và một ít tiền mặt mệnh giá nhỏ.</li>
<li>Bản xác nhận phòng, vé xe và thông tin tàu lưu ngoại tuyến.</li>
</ul>
<p>Thời tiết miền Tây có thể thay đổi nhanh. Hãy theo dõi dự báo sát ngày, nhưng đừng để một cơn mưa ngắn phá hỏng chuyến đi. Phương án dự phòng là bảo tàng, nhà cổ nếu đang mở, quán cà phê hoặc một bữa ăn chậm. Với hoạt động trên sông, an toàn luôn quan trọng hơn lịch trình.</p>

<h2>Mẹo tối ưu lịch trình Cần Thơ</h2>
<p>Đặt phòng và tàu trước khi đi Cần Thơ cuối tuần, nhưng chỉ chuyển khoản cho đơn vị có thông tin rõ ràng. Nhóm từ ba người nên so sánh tàu riêng với tàu ghép; tàu riêng linh hoạt, còn tàu ghép tiết kiệm hơn. Hãy hỏi đúng câu: giờ xuất phát, thời lượng, điểm dừng, áo phao, giá cuối cùng và điều kiện đổi lịch khi mưa.</p>
<p>Giữ nhịp ăn uống vừa phải. Đặc sản Cần Thơ phong phú nhưng ăn quá nhiều trong tối đầu dễ làm buổi sáng kém thoải mái. Đừng biến chuyến đi thành cuộc đua ảnh; hãy dành thời gian quan sát cây bẹo, nghe tiếng máy ghe, trò chuyện lịch sự với người bán và cảm nhận dòng sông lúc trời sáng.</p>
<p>Cuối cùng, chọn điểm theo sở thích. Người yêu kiến trúc có thể ưu tiên Bình Thủy; gia đình thích hoạt động chọn Cồn Sơn; người mê ẩm thực dành thêm thời gian ở chợ và quán địa phương. Một lịch trình tốt không phải lịch có nhiều dấu tích nhất, mà là lịch giúp mọi thành viên thấy thoải mái.</p>

<h2>Câu hỏi thường gặp về Cần Thơ 2N1Đ</h2>
<h3>Hai ngày một đêm có đủ khám phá Cần Thơ không?</h3>
<p>Đủ cho các trải nghiệm tiêu biểu gồm Bến Ninh Kiều, một điểm văn hóa hoặc sinh thái, ẩm thực và chợ nổi Cái Răng. Nếu muốn đi sâu nhiều vườn, Cồn Sơn và khu ngoại thành, bạn nên dành ba ngày hai đêm.</p>
<h3>Nên đi chợ nổi lúc mấy giờ?</h3>
<p>Khung 5–7 giờ sáng thường phù hợp để cảm nhận hoạt động chợ và bình minh. Nếu xuất phát từ Ninh Kiều, nên có mặt tại bến khoảng 4 giờ 45–5 giờ. Giờ cụ thể còn tùy mùa, thời tiết và vị trí bến.</p>
<h3>Cần Thơ 2N1Đ có phù hợp với trẻ nhỏ không?</h3>
<p>Có, nếu giảm số điểm, cho trẻ ngủ sớm và chọn tàu an toàn có áo phao đúng cỡ. Gia đình nên mang đồ ăn nhẹ, quần áo dự phòng và tránh để trẻ di chuyển tự do trên tàu.</p>
<h3>Nên ở gần chợ nổi hay Bến Ninh Kiều?</h3>
<p>Người lần đầu thường thuận tiện hơn khi ở Ninh Kiều vì dễ ăn uống và tham quan buổi tối. Ở gần Cái Răng giúp rút ngắn hành trình sáng sớm, phù hợp với người ưu tiên chợ nổi hơn không khí trung tâm.</p>

<h2>Kết luận</h2>
<p><strong>Cần Thơ 2N1Đ</strong> phù hợp cho một cuối tuần đủ trải nghiệm mà không quá vội: ngày đầu dành cho thành phố, văn hóa và ẩm thực; ngày sau thức sớm để gặp miền sông nước đúng nhịp. Hãy giữ lịch linh hoạt, xác nhận giá trước khi dùng dịch vụ và tôn trọng đời sống địa phương. Bạn có thể xem thêm <a href="/bai-viet/du-lich-can-tho">kinh nghiệm du lịch Cần Thơ tự túc</a> và tham khảo thông tin cập nhật tại <a href="https://canthotourism.vn/vi/chocairang" target="_blank" rel="noopener noreferrer">Cổng thông tin Du lịch Cần Thơ</a> trước ngày khởi hành.</p>
HTML;

        $post = Post::query()->updateOrCreate(
            ['slug' => 'can-tho-2-ngay-1-dem'],
            [
                'title' => 'Cần Thơ 2N1Đ: Lịch trình chi tiết cho người đi lần đầu',
                'summary' => '<p><strong>Cần Thơ 2N1Đ</strong> với lịch trình chợ nổi Cái Răng, Bến Ninh Kiều, miệt vườn, món ngon và chi phí dự kiến dành cho người đi lần đầu.</p>',
                'content' => $content,
                'status' => 'draft',
                'seo_title' => 'Cần Thơ 2N1Đ: Lịch trình chi tiết từ A đến Z',
                'seo_description' => 'Cần Thơ 2N1Đ với lịch trình chợ nổi, Bến Ninh Kiều, miệt vườn, món ngon, nơi ở và chi phí dự kiến chi tiết cho người đi lần đầu tự túc, kèm mẹo hữu ích.',
                'seo_keywords' => 'Cần Thơ 2N1Đ, lịch trình Cần Thơ, đi Cần Thơ, cuối tuần Cần Thơ',
                'published_at' => null,
            ]
        );

        $heroPath = public_path('images/posts/can-tho-2-ngay-1-dem/can-tho-2-ngay-1-dem-hero.avif');
        if ($post->getMedia('Ảnh chính')->isEmpty() && is_file($heroPath)) {
            $post->addMedia($heroPath)
                ->preservingOriginal()
                ->usingName('Cần Thơ 2N1Đ')
                ->usingFileName('can-tho-2-ngay-1-dem.avif')
                ->toMediaCollection('Ảnh chính');
        }
    }
}
