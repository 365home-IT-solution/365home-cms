@props([
    'data'               => new \Modules\BladeThemeV1\Types\HeaderData('', '', [], [], [], [], false),
    'menu'               => null,
    'authHeaderEnabled'  => false,
])

@php
    /** @var \Modules\BladeThemeV1\Types\HeaderData $data */
    // Logo configs
    $logoHeight = $data->logoConfig['height'] . 'px';
    $logoHeightMobile = ($data->logoConfig['height'] * 2/3) .'px';
    $logoPosition = $data->logoConfig['logo_position'] . 'left';
    $logoLink = $data->logoConfig['logo_link'];
    $logoStyle = $data->logoConfig['logo_style'];
    $cartConfig = $data->cartConfig['show_cart_icon'];

    // Header main configs
    $headerHeight = $data->headerConfig['height'] . 'px';
    $headerBackgroundColor = $data->headerConfig['background_color'];
    $addShadow = $data->headerConfig['add_shadow'];

    // Navigation bar configs
    $navStyle = $data->navConfig['navigation_style'];
    $navSize = 14;
    $navSpacing = $data->navConfig['nav_spacing'];
    $navUppercase = $data->navConfig['uppercase'];

    // Action configs
    $searchButtonConfig = [
        'visible' => $data->actionConfig['search_button']
    ];

    // Trang đăng nhập: ẩn hẳn mọi khối tìm kiếm (pill gọn, hàng tab, hàng tìm kiếm đầy đủ) NGAY
    // TỪ SERVER — không dựa vào cờ JS window.__headerAlwaysCompact (chỉ ẩn được SAU khi Alpine
    // kịp khởi tạo, có thể bị lỡ nhịp/hiện chớp nhoáng tuỳ thời điểm JS chạy). Đồng thời dùng lại
    // để LUÔN hiện menu item thật (ẩn nút bars) ở trang này thay vì thu gọn theo isSticky như các
    // trang khác — trang gọn nhẹ này không cần hành vi thu gọn khi cuộn.
    // (Trang đăng ký — register.page — đã bị gỡ bỏ.)
    $isAuthPage = request()->routeIs('login.page');

    $ctaButtonConfig = [
        'visible' => $data->actionConfig['cta_button'],
        'label' => $data->actionConfig['cta_button_label'],
        'link' => $data->actionConfig['cta_button_link']
    ];

    // Logo image
    $logo =
        $logoStyle === 'light_version'
            ?
            $data->logoLightVersion
            :
            $data->logo;

    // Nav spacing classes
    $navSpacingClass = match ($navSpacing) {
        'xs' => 'gap-x-4',
        'sm' => 'gap-x-6',
        'md' => 'gap-x-8',
        'lg' => 'gap-x-10',
        'xl' => 'gap-x-12',
        '0' => '',
        default => '',
    };

    // Header luôn nền trắng + logo màu gốc (đồng bộ với hàng 2 tìm kiếm), không còn kiểu trong suốt trên trang chủ.
    $initBgColor = '#ffffff';
    $logoFilterExpr = "'none'";
@endphp

<div id="main-header-bar"
     x-data="{
        {{-- window.__headerAlwaysCompact (set trước @livewire('bladethemev1::header') ở trang cần
             header luôn thu gọn kiểu đã cuộn — bars menu, không hàng search đầy đủ, xem
             pages/login.blade.php/register.blade.php) — ép isSticky=true ngay từ đầu bất kể trang
             chủ hay không, không đụng tới __headerAlwaysExpanded (dùng ngược lại ở chỗ khác). --}}
        isSticky: {{ $data->headerStyle
            ? "(window.__headerAlwaysExpanded ? false : (window.__headerAlwaysCompact ? true : window.matchMedia('(max-width: 767px)').matches))"
            : "(window.__headerAlwaysCompact ? true : false)" }},
        mobileMenuOpen: false,
        searchExpanded: false,
        activeTabMirror: 'hour',
        isHomePage: {{ request()->is('/') ? 'true' : 'false' }},
        // Trang chủ có sẵn khung tìm kiếm nổi đè banner (flash-sale.blade.php) — thanh tìm kiếm
        // RIÊNG của header (hàng 2 + hàng tab tuyệt đối) phải ẩn hẳn cho tới khi cuộn qua khỏi
        // khung đó (isSticky = true), tránh 2 khung tìm kiếm cùng hiện chồng nhau. Trang khác
        // (không có khung nổi) vẫn hiện ngay từ đầu như cũ.
        get formRowExpanded() {
            return this.isHomePage ? (this.isSticky && this.searchExpanded) : (!this.isSticky || this.searchExpanded);
        }
    }"
     x-init="
        let stickyOnThreshold = {{ request()->is('/') ? 400 : 80 }};
        // Ngưỡng bật sticky-pill — mặc định 80px (trang thường)/400px (trang chủ, fallback trước
        // khi đo xong). Trang chủ cần đo ĐỘNG theo vị trí thật của khung tìm kiếm nổi đè banner
        // (#hero-banner-search-overlay, xem flash-sale.blade.php): thanh tìm kiếm RIÊNG của header
        // (hàng 2 + pill gọn) phải ẩn hẳn lúc đầu trang, chỉ hiện sau khi cuộn qua khỏi khung nổi
        // đó — tránh 2 khung tìm kiếm cùng hiện chồng nhau. QUAN TRỌNG: dòng let trên phải là dòng
        // ĐẦU TIÊN (không có comment/khoảng trắng nào đứng trước nó trong x-init) — Alpine chỉ tự
        // bọc IIFE cho x-init nhiều câu lệnh khi phần đầu (sau trim) khớp đúng regex bắt đầu bằng
        // let hoặc const (xem generateFunctionFromString trong livewire.js); nếu có comment đứng
        // trước từ khoá let thì điều kiện này không khớp, Alpine coi cả khối là 1 biểu thức đơn và
        // gán thẳng vào with(scope) — vỡ cú pháp ngay (lỗi Unexpected identifier đã gặp, mất cả
        // buổi mới dò ra được). Đồng thời KHÔNG được để ký tự dấu ngoặc kép ở bất kỳ đâu trong toàn
        // bộ x-init này (kể cả trong comment) — x-init nằm trong 1 thuộc tính HTML bọc bởi dấu
        // ngoặc kép, gặp phải ký tự đó sẽ cắt đứt thuộc tính ngay tại đó (lỗi Unexpected end of
        // input vừa gặp lại, y hệt lỗi đã ghi chú ở toggleSlot() trong book.blade.php trước đây).
        const recomputeStickyThreshold = () => {
            const overlay = document.getElementById('hero-banner-search-overlay');
            if (!overlay || overlay.offsetHeight === 0) return;
            const rect = overlay.getBoundingClientRect();
            stickyOnThreshold = Math.round(rect.bottom + window.scrollY);
        };
        setTimeout(recomputeStickyThreshold, 250);
        let __hstResizeTimer;
        window.addEventListener('resize', () => {
            clearTimeout(__hstResizeTimer);
            __hstResizeTimer = setTimeout(recomputeStickyThreshold, 200);
        }, { passive: true });
        let ticking = false;
        window.addEventListener('scroll', () => {
            // Một số trang (vd tìm kiếm) cần header giữ nguyên kích thước cố định vì có phần tử
            // sticky khác (bản đồ) neo theo chiều cao header — nếu header co giãn khi cuộn, phần
            // tử đó bị giật theo. window.__headerAlwaysExpanded = true tắt hẳn hành vi thu gọn này.
            if (ticking || window.__headerAlwaysExpanded) return;
            ticking = true;
            requestAnimationFrame(() => {
                // Hysteresis: ngưỡng bật khác ngưỡng tắt (cách nhau 40px) để tránh isSticky
                // nhấp nháy liên tục (gây rung/giật) khi scrollY dao động quanh 1 mốc duy nhất.
                if (!isSticky && window.scrollY > stickyOnThreshold) isSticky = true;
                else if (isSticky && window.scrollY < stickyOnThreshold - 40) isSticky = false;
                ticking = false;
            });
        }, { passive: true });
        // Khi cuộn lại lên đầu (isSticky tắt) thì thanh tìm kiếm đầy đủ đã hiện sẵn rồi,
        // reset searchExpanded để lần cuộn xuống kế tiếp lại bắt đầu từ trạng thái thu gọn.
        // emitFormPinState() bắn CustomEvent sang scope Alpine khác của chính form tìm kiếm (nằm
        // trong hàng 2 header — _banner-form.blade.php, khác cây Alpine, không đọc thẳng
        // isSticky/searchExpanded được) để form biết lúc nào cần ẩn bớt hàng tab RIÊNG của nó (đã
        // có hàng tab hiện ngay trong hàng 1 header rồi — xem khối tab tuyệt đối phía dưới): true
        // bất cứ khi nào KHÔNG phải trạng thái pill gọn (isSticky && !searchExpanded) — tức hễ hàng
        // tab ngoài (hàng 1) đang hiện thì hàng tab riêng trong form phải ẩn đi, ở mọi lúc kể cả
        // chưa cuộn.
        const emitFormPinState = () => {
            const pinned = !isSticky || searchExpanded;
            window.dispatchEvent(new CustomEvent('header-form-pin', { detail: { pinned } }));
        };
        // Gọi ngay từ đầu (không chỉ đợi $watch, vốn chỉ bắn khi có THAY ĐỔI): hàng tab ngoài phải
        // hiện + hàng tab riêng trong form phải ẩn NGAY LÚC TẢI TRANG, không cần đợi người dùng
        // cuộn/mở rộng tìm kiếm trước. Trì hoãn qua setTimeout(...,0) — không gọi thẳng ngay tại
        // đây — vì #main-header-bar (phần tử NGOÀI) chạy x-init này TRƯỚC KHI Alpine kịp khởi tạo
        // xong các component con nằm sâu bên trong hàng 2 (data-bar của _banner-form.blade.php, nơi
        // lắng nghe event này); bắn ngay lúc đó thì listener chưa kịp gắn, mất event. setTimeout 0
        // đẩy lệnh gọi này ra sau toàn bộ lượt duyệt cây Alpine đồng bộ ban đầu của cả trang, đảm
        // bảo mọi component con đã sẵn sàng lắng nghe.
        setTimeout(emitFormPinState, 0);
        $watch('isSticky', val => { if (!val) searchExpanded = false; emitFormPinState(); });
        $watch('searchExpanded', () => emitFormPinState());
        // activeTabMirror chỉ để tô sáng đúng tab (Theo giờ/Qua đêm/Theo ngày) ở hàng tab hiện
        // ngay trong header khi đã cuộn xuống — dữ liệu thật (activeTab) nằm ở scope Alpine khác
        // (_banner-form.blade.php, khác cây), nghe qua CustomEvent 'hero-active-tab-changed' do
        // banner-form tự bắn mỗi khi activeTab đổi.
        window.addEventListener('hero-active-tab-changed', (e) => { activeTabMirror = e.detail.tab; });
    "
     @click.outside="searchExpanded = false"
     :class="{
        'shadow-md': {{ $addShadow ? 'true' : 'false' }} && isSticky,
     }"
     style="position: sticky; top: 0; overflow-anchor: none;"
     class="w-full z-[1200] transition-shadow duration-300 ease-in-out header-hero-sticky border-b border-gray-100">

    <div style="background-color: {{ $initBgColor }};">

        {{-- Hàng 1: logo / menu / actions (chỉ desktop) — cao tối thiểu theo cấu hình, tự giãn thêm
             nếu logo được set cao hơn mức đó (tránh bị cắt/đè lên hàng dưới). --}}
        <div class="hidden lg:block w-full max-w-11xl md:px-8 px-4 mx-auto py-[8px]" style="min-height: {{ $headerHeight }};">
                <div class='flex flex-wrap items-center relative'>
                    <!-- Desktop Logo -->
                    <a href="{{ $logoLink }}"
                       class="hidden lg:flex items-center flex-shrink-0 order-1">
                        {{-- Style render thẳng từ server (không dùng Alpine :style) để chiều cao logo
                             có ngay từ lần vẽ đầu tiên — nếu chỉ dùng :style, trước khi Alpine kịp
                             chạy thì ảnh hiển thị ở kích thước gốc (rất to) rồi mới co lại, gây
                             giật/lỗi giao diện mỗi lần tải trang. --}}
                        <img style="height: {{ $logoHeight }}; filter: {{ trim($logoFilterExpr, "'") }};"
                             src="{{ asset('/storage/'.$logo) }}" alt="Logo"
                             class="transition-all duration-300"/>
                    </a>

                    {{-- Menu item đầu tiên (thường là "Trang chủ") — đứng ngay cạnh logo, nhưng vẫn
                         ẩn cùng lúc với phần menu còn lại khi đã cuộn & thu gọn (isSticky &&
                         !searchExpanded), để nhường chỗ hẳn cho thanh tìm kiếm gọn lúc đó. Trang
                         đăng nhập ($isAuthPage): luôn hiện, cùng logic với khối menu còn lại phía
                         dưới (xem giải thích ở đó) — bỏ sót chỗ này ở lần sửa trước khiến menu
                         cạnh logo biến mất trên trang đăng nhập. --}}
                    @if (!empty($menu?->menuItems) && $menu->menuItems->isNotEmpty())
                        <ul class="hidden lg:flex items-center order-2" style="margin-left:18px;"
                            x-show="!isSticky || searchExpanded || {{ $isAuthPage ? 'true' : 'false' }}" x-cloak>
                            @livewire('bladethemev1::menu-item', [
                                'menuItem' => $menu->menuItems->first(),
                                'depth' => 1,
                                'loop' => null,
                                'navStyle' => $navStyle,
                                'navSize' => $navSize,
                            ], key('near-logo-'.$menu->menuItems->first()->id))
                        </ul>
                    @endif

                    {{-- Khối tìm kiếm gọn/tabs — canh giữa TUYỆT ĐỐI theo toàn bộ hàng header (logo
                         bên trái + menu/actions bên phải không cân bằng bề rộng, nếu để tham gia
                         flex bình thường sẽ bị lệch tâm khỏi trang, không đúng cảm giác "ở giữa"
                         như mẫu tham khảo Go2Joy) — vì vậy dùng position:absolute + translate(-50%)
                         ngay trên chính giữa hàng (cha đã có position:relative), không phụ thuộc
                         bề rộng 2 bên nữa. --}}
                    <div class="hidden lg:block" style="position:absolute; left:50%; top:50%; transform:translate(-50%,-50%); z-index:5;">
                    @unless ($isAuthPage)
                        {{-- Chỗ trống để thanh tìm kiếm gọn (compact pill) "teleport" vào khi cuộn —
                             thêm !window.__headerAlwaysCompact để ẩn hẳn ở trang đăng nhập/đăng ký
                             (__headerAlwaysCompact ép isSticky=true nên nếu không loại trừ, pill gọn
                             này vẫn hiện trên desktop dù trang đó chủ định không có hàng tìm kiếm). --}}
                        <div id="header-search-slot"
                             class="flex w-max min-w-0 items-center justify-center"
                             x-show="isSticky && !searchExpanded && !window.__headerAlwaysCompact" x-cloak></div>

                        {{-- Hàng tab Theo giờ/Qua đêm/Theo ngày — dùng chung điều kiện
                             formRowExpanded với hàng 2 bên dưới (trang chủ: đợi cuộn qua khung nổi
                             đè banner; trang khác: hiện ngay). Chỉ đổi activeTab/dayMode thật (nằm
                             ở scope Alpine khác của _banner-form.blade.php) qua CustomEvent
                             'hero-set-tab' — không đọc/ghi thẳng biến của cây đó được (khác scope). --}}
                        <div class="flex items-center justify-center gap-1"
                             x-show="formRowExpanded" x-cloak>
                            {{-- Icon GIF giống hệt bộ tab trong _banner-form.blade.php (đồng bộ) để
                                 2 nơi nhìn nhất quán. --}}
                            <button type="button"
                                @click="window.dispatchEvent(new CustomEvent('hero-set-tab', { detail: { tab: 'hour' } }))"
                                class="flex flex-col items-center gap-0.5 px-4 py-1.5 rounded-full text-sm font-semibold transition-colors"
                                :class="activeTabMirror === 'hour' ? 'bg-white shadow-md text-[var(--color-primary)]' : 'text-gray-500 hover:text-gray-700'">
                                <img src="{{ asset('images/hourglass.gif') }}" alt="" class="w-4 h-4 object-contain">
                                Theo giờ
                            </button>
                            <button type="button"
                                @click="window.dispatchEvent(new CustomEvent('hero-set-tab', { detail: { tab: 'overnight' } }))"
                                class="flex flex-col items-center gap-0.5 px-4 py-1.5 rounded-full text-sm font-semibold transition-colors"
                                :class="activeTabMirror === 'overnight' ? 'bg-white shadow-md text-[var(--color-primary)]' : 'text-gray-500 hover:text-gray-700'">
                                <img src="{{ asset('images/night-time.gif') }}" alt="" class="w-4 h-4 object-contain">
                                Qua đêm
                            </button>
                            <button type="button"
                                @click="window.dispatchEvent(new CustomEvent('hero-set-tab', { detail: { tab: 'day' } }))"
                                class="flex flex-col items-center gap-0.5 px-4 py-1.5 rounded-full text-sm font-semibold transition-colors"
                                :class="activeTabMirror === 'day' ? 'bg-white shadow-md text-[var(--color-primary)]' : 'text-gray-500 hover:text-gray-700'">
                                <img src="{{ asset('images/building.gif') }}" alt="" class="w-4 h-4 object-contain">
                                Theo ngày
                            </button>
                        </div>
                    @endunless
                    </div>

                    {{-- Khối bên phải (menu còn lại + actions) — LUÔN gộp chung 1 nhóm flex duy nhất
                         với margin-left:auto đặt trên chính nhóm này (không phải trên menu còn lại
                         như trước), để nhóm luôn bị đẩy sát mép phải bất kể menu còn lại đang ẩn hay
                         hiện bên trong nó (trước đây margin-left:auto nằm trên khối menu — khi khối
                         đó ẩn hẳn (isSticky && !searchExpanded) thì margin cũng biến mất theo, khiến
                         actions (bars/đăng nhập) bị tụt lên đứng ngay cạnh "Trang chủ" đầu hàng thay
                         vì cuối hàng — đúng lỗi đã gặp). --}}
                    <div class="hidden lg:flex items-center order-3" style="margin-left:auto;">
                        {{-- Menu còn lại (trừ item đầu, đã đứng cạnh logo) — hiện ở đầu trang (chưa
                             cuộn) VÀ khi đã cuộn nhưng đang mở rộng tìm kiếm; chỉ ẩn đúng lúc đã cuộn
                             & thu gọn (isSticky && !searchExpanded) để nhường chỗ nút bars. Trang
                             đăng nhập/đăng ký ($isAuthPage): luôn hiện menu thật, không thu gọn
                             thành bars — 2 trang này không có hàng cuộn/search nên isSticky bị ép
                             true ngay từ đầu (window.__headerAlwaysCompact) không mang ý nghĩa
                             "đã cuộn" như các trang khác. --}}
                        <div class="flex items-center shrink-0" style="margin-right:24px;" x-show="!isSticky || searchExpanded || {{ $isAuthPage ? 'true' : 'false' }}" x-cloak>
                            <ul class="flex items-center whitespace-nowrap gap-3">
                                @if (!empty($menu?->menuItems))
                                    @foreach ($menu->menuItems->skip(1) as $menuItem)
                                        @livewire('bladethemev1::menu-item', [
                                        'menuItem' => $menuItem,
                                        'depth' => 1,
                                        'loop' => $loop,
                                        'navStyle' => $navStyle,
                                        'navSize' => $navSize,
                                        ], key('compact-'.$menuItem->id))
                                    @endforeach
                                @endif
                            </ul>
                        </div>

                        <!-- Desktop Action buttons -->
                        <div class="flex items-center gap-4">
                            <!-- Search -->
                            @if($searchButtonConfig['visible'])
                                <x-bladethemev1::header.actions.search :searchButtonConfig="$searchButtonConfig"/>
                            @endif

                            <!-- CTA Button -->
                            @if($ctaButtonConfig['visible'])
                                <x-bladethemev1::header.actions.cta-button :ctaButtonConfig="$ctaButtonConfig"/>
                            @endif

                            <!-- Auth Button -->
                            @if($authHeaderEnabled)
                                <x-bladethemev1::header.actions.auth-button />
                            @endif

                            {{-- Nút menu (bars-3) — đứng SAU nút đăng nhập (yêu cầu: "nút đăng nhập
                                 nằm trước nút bars"). CHỈ hiện khi header đã thu gọn & chưa mở rộng
                                 tìm kiếm (isSticky && !searchExpanded): lúc đó menu cạnh nút đăng
                                 nhập cũng đang ẩn để nhường chỗ cho thanh tìm kiếm gọn, nên cần 1 lối
                                 khác để xem lại menu — bấm ra popup, cùng kiểu giao diện với dropdown
                                 tài khoản (auth-button.blade.php: bg-white rounded-2xl shadow-lg
                                 border, mỗi mục 1 hàng). Chỉ liệt kê phẳng menu cấp 1 (không kèm
                                 menu con lồng nhau) cho gọn trong popup nhỏ này.
                                 Trang đăng nhập/đăng ký ($isAuthPage): không cần nút này vì menu
                                 thật đã hiện thường trực ở trên (xem giải thích ở khối menu). --}}
                            <div class="relative" x-show="isSticky && !searchExpanded && {{ $isAuthPage ? 'false' : 'true' }}" x-cloak x-data="{ menuDropOpen: false }">
                                <button type="button" @click="menuDropOpen = !menuDropOpen" @click.outside="menuDropOpen = false"
                                    aria-label="Menu"
                                    class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-white text-gray-700 shadow-md hover:bg-gray-100 transition-colors duration-150">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                                    </svg>
                                </button>
                                <div x-show="menuDropOpen" x-cloak
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
                                    x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                                    x-transition:leave="transition ease-in duration-100"
                                    x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                                    x-transition:leave-end="opacity-0 scale-95 -translate-y-1"
                                    class="absolute right-0 mt-2 w-56 rounded-2xl border border-gray-100 bg-white shadow-lg shadow-black/10 py-2 z-50">
                                    @if (!empty($menu?->menuItems))
                                        @foreach ($menu->menuItems as $menuItem)
                                            <a href="{{ $menuItem->branch_booking_url ?: $menuItem->getFullUrl() }}"
                                                @click="menuDropOpen = false"
                                                class="w-full text-left px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 hover:text-gray-900 transition-colors duration-150 flex items-center gap-2.5">
                                                {{ $menuItem->title }}
                                            </a>
                                        @endforeach
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        {{-- Mobile: thanh tìm kiếm gọn (luôn hiện) + hàng menu, đặt ngay trong header --}}
        <div class="lg:hidden">
            <div class="w-full px-4 pb-1 pt-3">
                @livewire('bladethemev1::hero-section', ['mobileHeaderModal' => true], key('hero-section-mobile'))
            </div>
        </div>

        {{-- Hàng 2: form tìm kiếm đầy đủ — co/giãn mượt bằng grid-template-rows (không dùng height:auto).
             overflow chỉ ẩn (hidden) lúc đang co lại (!formRowExpanded) để cắt phần tràn cho
             animation mượt; lúc mở rộng bình thường phải overflow-visible, nếu không các dropdown
             (lịch, danh sách địa điểm, số người...) tràn ra ngoài khung sẽ bị cắt mất/che khuất.
             formRowExpanded (định nghĩa ở x-data phía trên): trang chủ đợi cuộn qua khung tìm kiếm
             nổi đè banner mới giãn ra; trang khác giãn ngay từ đầu như cũ.
             Trang đăng nhập/đăng ký: ẩn hẳn khối này server-side qua $isAuthPage (không dựa
             cờ JS __headerAlwaysCompact — xem giải thích ở khối #header-search-slot phía trên). --}}
        @unless ($isAuthPage)
        <div class="hidden lg:grid transition-[grid-template-rows] duration-150 ease-in-out"
             :class="formRowExpanded ? 'overflow-visible' : 'overflow-hidden'"
             :style="{ gridTemplateRows: formRowExpanded ? '1fr' : '0fr' }">
            <div :class="formRowExpanded ? 'overflow-visible' : 'overflow-hidden'" class="min-h-0">
                <div class="w-full max-w-7xl md:px-8 px-4 mx-auto">
                    {{-- Thanh tìm kiếm giờ nằm thẳng trong hàng 2 header này ở MỌI trang, kể cả
                         trang chủ (không còn teleport đè lên banner nữa) — gộp thành 1 khối liền
                         mạch với header thay vì 1 thẻ nổi tách biệt trên banner. --}}
                    @livewire('bladethemev1::hero-section', [
                        'headerRow' => true,
                    ], key('hero-section-header-row'))
                </div>
            </div>
        </div>
        @endunless

    </div>
</div>

<style>
    /* Sau khi scroll xuống (nền trắng): chữ màu primary */
    .header-hero-sticky .main-menu-item {
        color: var(--color-primary) !important;
    }
    .header-hero-sticky .main-menu-item:hover {
        color: var(--color-primary) !important;
        opacity: 0.8;
    }

    /* Custom styles for mobile optimization */
    .fixed-header {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 50;
    }

    /* Ensure mobile menu doesn't interfere with content */
    @media (max-width: 768px) {
        .fixed-header + * {
            margin-top: {{ $headerHeight }};
        }

        /* Mobile responsive adjustments */
        .container {
            padding-left: 1rem;
            padding-right: 1rem;
        }

        .mx-\[110px\] {
            margin-left: 0;
            margin-right: 0;
        }

        .px-\[12px\] {
            padding-left: 0.75rem;
            padding-right: 0.75rem;
        }
    }

    /* Smooth transitions for mobile menu */
    [x-cloak] {
        display: none !important;
    }

    /* Touch-friendly button sizes */
    @media (max-width: 768px) {
        button {
            min-height: 44px;
            min-width: 44px;
        }
    }

    /* Mobile menu styling */
    @media (max-width: 768px) {
        .mobile-menu-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            z-index: 50;
        }
    }
</style>