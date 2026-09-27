import Swiper from 'swiper';
import { Autoplay, Navigation, Pagination } from 'swiper/modules';
import { withLoopGuard } from './swiper-loop-guard';

Swiper.use([Autoplay, Navigation, Pagination]);
window.Swiper = withLoopGuard(Swiper);

// Keep the source shared with search/booking pages, but let Vite minify and bundle it on home.
import '../../../../../public/js/home-sections.js';
