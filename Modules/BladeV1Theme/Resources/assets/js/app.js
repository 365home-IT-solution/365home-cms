import 'owl.carousel';
import 'owl.carousel/dist/assets/owl.carousel.min.css';
import 'owl.carousel/dist/assets/owl.theme.default.min.css';
import Swiper from 'swiper/bundle';
import { Fancybox } from "@fancyapps/ui";
import 'swiper/css/bundle';
import "@fancyapps/ui/dist/fancybox/fancybox.css";
import "perfect-scrollbar/css/perfect-scrollbar.css";
import "flowbite";
import AOS from 'aos';
import 'aos/dist/aos.css';
import Scrollbar from 'smooth-scrollbar';
import { CountUp } from "countup.js";
import { initTooltips } from 'flowbite';

// Gán các thư viện vào window để sử dụng toàn cục
window.CountUp = CountUp;
window.Scrollbar = Scrollbar;
window.Swiper = Swiper;
window.Fancybox = Fancybox;
window.initTooltips = initTooltips;

// Khởi tạo AOS khi DOM loaded
document.addEventListener('DOMContentLoaded', () => {
    AOS.init();
});

