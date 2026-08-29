// Defer Swiper initialization to avoid blocking page render
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSwiper);
} else {
    requestIdleCallback(initSwiper, { timeout: 2000 });
}

function initSwiper() {
    import('swiper').then(({ default: Swiper }) => {
        import('swiper/modules').then(({ Autoplay, Navigation, Pagination }) => {
            Swiper.use([Autoplay, Navigation, Pagination]);
            window.Swiper = Swiper;
            // Trigger any pending Swiper initializations
            if (window.__initSwipers) window.__initSwipers();
        });
    });
}

// Keep the source shared with search/booking pages, but let Vite minify and bundle it on home.
import '../../../../../public/js/home-sections.js';
