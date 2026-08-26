import Swiper from 'swiper';
import { Autoplay, Navigation, Pagination } from 'swiper/modules';
import 'swiper/css';
import 'swiper/css/navigation';
import 'swiper/css/pagination';

Swiper.use([Autoplay, Navigation, Pagination]);
window.Swiper = Swiper;

// Keep the source shared with search/booking pages, but let Vite minify and bundle it on home.
import '../../../../../public/js/home-sections.js';
