// Tự tắt `loop` khi số slide không đủ cho loop mode của Swiper 11 — thay vì để Swiper in
// "Swiper Loop Warning: The number of slides is not enough for loop mode..." ra console, lặp lại
// ở MỖI lần chuyển slide (banner trang chủ autoplay 4s/lần => hàng chục cảnh báo). Các config rải
// rác khắp nơi (home-sections.js, product-detail-init.js, các view Blade inline) đều bật loop theo
// điều kiện tự đoán (vd "> 1 banner") không khớp công thức thật của Swiper, nên chặn 1 chỗ ở đây.
//
// Công thức lấy từ loopCreate/loopFix của swiper-core (11.x): cần
//   slides >= ceil(slidesPerView) (+1 nếu centeredSlides và số đó chẵn) + slidesPerGroup + loopAdditionalSlides
// Tính theo slidesPerView LỚN NHẤT trong mọi breakpoint để không cảnh báo lại khi đổi kích thước
// màn hình. slidesPerView 'auto' không tính trước được => giữ nguyên, để Swiper tự xử lý.
function requiredSlides(params) {
    const configs = [params, ...Object.values(params.breakpoints || {})];
    let needed = 0;
    for (const cfg of configs) {
        const merged = { ...params, ...cfg };
        if (merged.slidesPerView === 'auto') return null;
        let perView = Math.ceil(parseFloat(merged.slidesPerView ?? 1));
        if (merged.centeredSlides && perView % 2 === 0) perView += 1;
        const perGroup = merged.slidesPerGroupAuto ? perView : (merged.slidesPerGroup ?? 1);
        needed = Math.max(needed, perView + perGroup + (merged.loopAdditionalSlides ?? 0));
    }
    return needed;
}

// Container đang bị ẩn (display:none — vd lịch đặt phòng bản desktop "hidden lg:block" trên
// mobile) thì Swiper đo được size 0, updateSlides() thoát sớm, swiper.slides rỗng => loopFix cảnh
// báo dù DOM có đủ slide (và cảnh báo lại ở mỗi lần Livewire morph mount lại). Coi như 0 slide.
function countSlides(el, params) {
    const container = typeof el === 'string' ? document.querySelector(el) : el;
    if (!container || !container.querySelectorAll) return null;
    if (container.getClientRects().length === 0) return 0;
    const wrapperClass = params.wrapperClass || 'swiper-wrapper';
    const slideClass = params.slideClass || 'swiper-slide';
    const wrapper = container.querySelector(':scope > .' + wrapperClass) || container;
    return wrapper.querySelectorAll(':scope > .' + slideClass).length;
}

export function withLoopGuard(Swiper) {
    return class LoopGuardedSwiper extends Swiper {
        constructor(el, params, ...rest) {
            // Swiper cho phép gọi new Swiper(params) không có el (el nằm trong params.el).
            const opts = el && !(typeof el === 'string' || el.nodeType) ? el : params;
            const target = opts === el ? opts.el : el;
            if (opts && opts.loop && !opts.virtual) {
                const needed = requiredSlides(opts);
                const count = countSlides(target, opts);
                if (needed !== null && count !== null && count < needed) {
                    opts.loop = false;
                }
            }
            super(el, params, ...rest);
        }
    };
}
