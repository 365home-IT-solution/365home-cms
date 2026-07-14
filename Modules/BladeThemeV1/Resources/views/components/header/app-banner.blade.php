{{--
    Banner quảng cáo ứng dụng — dính ngay dưới header (position:sticky, top = chiều cao header đo
    động qua ResizeObserver, xem script bên dưới), tương tự "smart app banner" của các site lớn
    (App Store/Google Play). Được include 1 lần duy nhất ở livewire/header.blade.php nên xuất hiện
    trên mọi trang dùng @livewire('bladethemev1::header') — riêng trang branch/{slug}
    (booking-board.blade.php) tự ép #main-header-bar thành position:fixed nên cũng tự ép banner
    này thành fixed theo (xem override trong chính file đó) để không bị lệch layout.

    Ẩn mặc định (display:none) — JS bật lên sau khi xác nhận người dùng CHƯA từng bấm nút đóng
    (localStorage), tránh nháy hiện-rồi-ẩn khi tải trang.
--}}
<div id="app-store-banner" class="w-full bg-white border-b border-gray-100" style="display:none; position:sticky; top:var(--header-h, 0px); z-index:1190;">
    <div class="max-w-11xl mx-auto flex items-center gap-3 px-3 py-2 sm:px-4">
        <button type="button" id="app-banner-close" aria-label="Đóng"
            class="shrink-0 w-7 h-7 flex items-center justify-center rounded-full text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
        </button>

        {{-- href mặc định trỏ Google Play (fallback nếu JS chưa kịp chạy) — JS bên dưới sẽ đổi
             sang App Store nếu phát hiện thiết bị iOS. --}}
        <a id="app-banner-cta" href="https://play.google.com/store/apps/details?id=com.home365.app"
            class="flex-1 min-w-0 flex items-center gap-3" style="text-decoration:none;">
            <img src="{{ asset('images/logoapp.webp') }}" alt="365 Home App" width="40" height="40"
                style="border-radius:10px; object-fit:cover; flex-shrink:0; background:#f3f4f6;"
                onerror="this.style.display='none'">
            <span class="min-w-0">
                <span class="block text-sm font-semibold text-gray-900 truncate">Có trải nghiệm tốt nhất</span>
                <span class="flex items-center gap-0.5 mt-0.5">
                    @for ($i = 0; $i < 5; $i++)
                        <svg style="width:12px;height:12px;color:#f59e0b;flex-shrink:0;" viewBox="0 0 20 20" fill="currentColor"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                    @endfor
                </span>
            </span>
            <span class="ml-auto shrink-0 text-sm font-semibold whitespace-nowrap" style="color:var(--color-primary, #4e6b4c); text-decoration:underline;">Mở ứng dụng</span>
        </a>
    </div>
</div>

<script>
    (function () {
        var STORAGE_KEY = 'app_banner_dismissed';
        var APP_STORE_URL = 'https://apps.apple.com/us/app/365-home/id6781598163';
        var PLAY_STORE_URL = 'https://play.google.com/store/apps/details?id=com.home365.app';

        // --header-h: chiều cao thực tế của #main-header-bar, đo động (không hardcode) vì header
        // đổi cao/thấp theo breakpoint + trạng thái sticky-compact/mở rộng thanh tìm kiếm. CSS var
        // này quyết định "top" của banner (sticky ngay dưới header) và được các trang có header
        // position:fixed riêng (booking-board.blade.php) dùng lại để bù padding-top.
        function syncHeaderHeight() {
            var header = document.getElementById('main-header-bar');
            var h = header ? header.getBoundingClientRect().height : 0;
            document.documentElement.style.setProperty('--header-h', h + 'px');
        }

        function init() {
            var banner = document.getElementById('app-store-banner');
            if (!banner || banner.__appBannerInited) return;
            banner.__appBannerInited = true;

            // window.__appBannerDisabled (đặt trước @@livewire('bladethemev1::header') ở trang
            // không muốn hiện banner này — xem pages/login.blade.php/register.blade.php).
            if (window.__appBannerDisabled) {
                document.documentElement.style.setProperty('--app-banner-h', '0px');
                return;
            }

            if (localStorage.getItem(STORAGE_KEY) === '1') {
                document.documentElement.style.setProperty('--app-banner-h', '0px');
                return;
            }

            var ua = navigator.userAgent || '';
            var isIOS = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
            var cta = document.getElementById('app-banner-cta');
            if (cta) cta.href = isIOS ? APP_STORE_URL : PLAY_STORE_URL;

            banner.style.display = 'block';
            document.documentElement.style.setProperty('--app-banner-h', banner.offsetHeight + 'px');

            var closeBtn = document.getElementById('app-banner-close');
            if (closeBtn) {
                closeBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    banner.style.display = 'none';
                    document.documentElement.style.setProperty('--app-banner-h', '0px');
                    try { localStorage.setItem(STORAGE_KEY, '1'); } catch (err) {}
                });
            }

            syncHeaderHeight();
            var header = document.getElementById('main-header-bar');
            if (header && window.ResizeObserver) {
                new ResizeObserver(syncHeaderHeight).observe(header);
            }
            window.addEventListener('resize', syncHeaderHeight);
        }

        document.addEventListener('DOMContentLoaded', init);
        document.addEventListener('livewire:navigated', init);
    })();
</script>
