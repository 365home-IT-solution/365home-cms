import 'owl.carousel';
import Swiper from 'swiper/bundle';
import { withLoopGuard } from './swiper-loop-guard';
import { Fancybox } from "@fancyapps/ui";
// CSS do JS import ở đây thành 1 file riêng (manifest key Resources/assets/js/app.css), nạp KHÔNG
// chặn hiển thị (xem AppServiceProvider::useStyleTagAttributes) — nên chỉ để lại CSS của thứ hiện
// sau tương tác (fancybox, perfect-scrollbar). CSS ảnh hưởng bố cục lúc vẽ đầu (swiper, owl, aos)
// chuyển sang cuối app.scss (cùng thứ tự cascade như trước: sau CSS của app.scss).
import "@fancyapps/ui/dist/fancybox/fancybox.css";
import "perfect-scrollbar/css/perfect-scrollbar.css";
import "flowbite";
import AOS from 'aos';
import { initTooltips } from 'flowbite';

// Gán các thư viện vào window để sử dụng toàn cục
window.Swiper = withLoopGuard(Swiper);
window.Fancybox = Fancybox;
window.initTooltips = initTooltips;

// Khởi tạo AOS khi DOM loaded
document.addEventListener('DOMContentLoaded', () => {
    AOS.init();
});

