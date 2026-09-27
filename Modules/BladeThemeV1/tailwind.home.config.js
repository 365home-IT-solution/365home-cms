// Cấu hình Tailwind RIÊNG cho home.css (chỉ trang chủ nạp file này) — dùng qua directive @config ở
// đầu Resources/assets/sass/home.scss. theme/plugins giữ nguyên tailwind.config.js để giao diện
// không đổi; chỉ thu hẹp "content" về đúng những gì trang chủ render (Lighthouse "Reduce unused
// CSS": config chung quét MỌI view của cả site nên home.css mang theo class của mọi trang khác).
// app.css (các trang còn lại) vẫn dùng tailwind.config.js đầy đủ.
//
// Danh sách lấy bằng cách render "/" và ghi lại mọi view Blade được compose (event composing:*),
// cộng thêm những gì chỉ xuất hiện sau tương tác/trạng thái khác (popup, header khi đã đăng nhập,
// HTML dựng bằng JS). THÊM VIEW MỚI VÀO TRANG CHỦ => PHẢI THÊM VÀO ĐÂY, nếu không các class
// Tailwind chỉ dùng trong view đó sẽ không có trong home.css.
const base = require('./tailwind.config.js');

const views = './Resources/views/';
const livewire = [
    'auth-modal', 'book', 'bottom-sidebar', 'branch-suggestion', 'contact-link', 'drawer-menu',
    'flash-sale', 'footer-v2', 'header', 'hero-section', 'home-booking-board', 'location-modal',
    'menu-item', 'minihouse-branch-suggestion', 'notification', 'popup', 'voucher',
];

module.exports = {
    ...base,
    content: [
        views + 'layouts/**/*.blade.php',
        views + 'pages/index.blade.php',
        views + 'components/header/**/*.blade.php',
        views + 'components/seo-content/**/*.blade.php',
        views + 'components/seo.blade.php',
        views + 'components/icon-sprite.blade.php',
        ...livewire.map((name) => views + 'livewire/' + name + '.blade.php'),
        views + 'livewire/book/**/*.blade.php',
        views + 'livewire/hero-section/**/*.blade.php',
        // Class trong chuỗi PHP (Livewire component trả về class động) và HTML dựng bằng JS.
        './Livewire/**/*.php',
        './Resources/assets/js/**/*.js',
        './Resources/assets/sass/**/*.scss',
        '../../public/js/home-sections.js',
        '../../public/js/hero-section.js',
    ],
};
