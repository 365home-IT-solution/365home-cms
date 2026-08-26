{{--
 * Section "Lịch đặt phòng trực tuyến" trên trang chủ — tái dùng nguyên Livewire component
 * Book.php (đã dùng ở trang /branch/{slug} và panel ?view=branches của trang tìm kiếm), chỉ
 * thêm bộ chọn tỉnh/thành + chi nhánh phía trên. Đổi chi nhánh không remount lại Book — bắn sự
 * kiện 'load-branch' (cơ chế có sẵn từ Book::loadBranch()).
--}}
@inject('generalSettings', 'App\Settings\GeneralSettings')
<section class="py-6 bg-white" data-home-realtime-boundary
         x-data="homeBookingBoard(@js(data_get($criticalHome ?? [], 'booking', [])))" x-init="init()">
    <div class="w-full max-w-7xl mx-auto" x-show="provinces.length > 0">

        <div class="px-4 sm:px-6">
            <div class="mb-5">
                <h2 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                    {{-- Ảnh chủ đề lễ hội (ManageGeneral::holiday_theme_active/holiday_logo_image) —
                         CHỈ hiện đúng ở đây, không còn ở logo header hay 2 label khác (Lần đầu khám
                         phá, Các chi nhánh tại...) theo yêu cầu gộp về 1 chỗ duy nhất. --}}
                    @if ($generalSettings->holiday_theme_active && $generalSettings->holiday_logo_image)
                        <img src="{{ asset('/storage/'.$generalSettings->holiday_logo_image) }}"
                             alt="" class="inline-block w-6 h-6 object-contain"/>
                    @endif
                    Lịch đặt phòng trực tuyến
                </h2>
                {{-- Tên khu vực đang chọn lấy từ provinces (đã fetch qua loadProvinces()) khớp
                     activeProvinceId — rơi về câu chung chung nếu chưa xác định được tên. --}}
                <p class="text-sm text-gray-500 mt-1"
                    x-text="'Quản lý và đặt lịch nhanh chóng tại ' + (provinces.find(p => String(p.id) === activeProvinceId)?.name || 'khu vực của bạn')"></p>
            </div>

            {{-- Hàng chi nhánh: carousel nút prev/next (thay cho cuộn ngang), giống hệt các hàng
                 khác trong flash-sale.blade.php — dùng chung carouselNav() đã có. carouselNav() tự
                 dùng ResizeObserver để đo lại canScrollNext/Prev đúng lúc div này hiện ra thật (x-show
                 chuyển từ ẩn sang hiện khi branches có dữ liệu), không cần can thiệp thêm ở đây.
                 Nút "Xem tất cả chi nhánh" và nút prev/next cùng nằm 1 hàng phía trên track —
                 "Xem tất cả" ở đầu (trái), prev/next ở cuối (phải). --}}
            <div x-data="carouselNav()" x-init="init()" x-show="branches.length" class="mb-6">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:10px;">
                    {{-- Xem tất cả chi nhánh của tỉnh đang chọn — trỏ sang panel chi nhánh của trang
                         tìm kiếm, cùng pattern '/s/{slug}' đã dùng ở hero-section/_script.blade.php. --}}
                    <a :href="'/s/' + (provinces.find(p => String(p.id) === activeProvinceId)?.slug || '') + '?view=branches'"
                        class="inline-flex items-center gap-1.5 text-sm font-semibold text-gray-700 hover:text-gray-900 transition-colors underline">
                        Xem tất cả chi nhánh
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:15px;height:15px;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>
                    {{-- prev/next gọi stepBranch() của homeBookingBoard() (scope cha) thay vì
                         carouselNav().prev()/next() thuần cuộn — bấm là tự động active luôn chi
                         nhánh liền trước/sau, không chỉ cuộn cho xem. --}}
                    <div style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
                        <button type="button" class="carousel-nav-btn" aria-label="Trước" x-show="canScrollPrev" @click="stepBranch(-1)">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        </button>
                        <button type="button" class="carousel-nav-btn" aria-label="Tiếp" x-show="canScrollNext" @click="stepBranch(1)">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    </div>
                </div>
                {{-- overflow-x-auto (thay vì overflow-x-hidden trước đây) + hide-scrollbar (class
                     dùng chung, định nghĩa trong flash-sale.blade.php) — cho phép lướt tay ngang
                     qua danh sách chi nhánh, đồng thời vẫn ẩn thanh cuộn/chỉ báo cuộn chạm tay
                     (::-webkit-scrollbar) như ý đồ ban đầu. Nút prev/next vẫn hoạt động bình
                     thường (scrollBy() không phụ thuộc overflow:hidden hay auto). --}}
                <div x-ref="track" class="flex gap-3 overflow-x-auto hide-scrollbar" style="scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch;">
                    <template x-for="b in branches" :key="b.slug">
                        <button type="button" @click="selectBranch(b)" :data-branch-slug="b.slug"
                            :class="b.slug === activeBranchSlug ? 'bg-primary text-white border-primary' : 'bg-white text-gray-900 border-gray-200 hover:bg-gray-50'"
                            class="shrink-0 text-left px-4 py-3 rounded-xl border transition-colors min-w-[180px]" style="scroll-snap-align:start;">
                            <p class="font-semibold text-sm truncate" x-text="b.name"></p>
                            <p class="text-xs mt-0.5" :class="b.slug === activeBranchSlug ? 'text-white/75' : 'text-gray-500'"
                                x-text="b.room_count + ' phòng đang trống'"></p>
                        </button>
                    </template>
                </div>
            </div>

            <p x-show="!loadingBranches && branches.length === 0 && activeProvinceId" x-cloak
                class="text-sm text-gray-500 mb-6">
                Khu vực này chưa có chi nhánh khả dụng.
            </p>
        </div>

        {{-- Giữ padding chuẩn px-4 sm:px-6 như phần trên (header/legend/bảng giá không tràn mép) —
             riêng khung lịch mobile (.book-panel) tự bù margin âm để tràn sát mép, xem CSS
             `.home-book-scope .book-panel` trong book/_styles.blade.php. --}}
        <div class="px-4 sm:px-6 home-book-scope">
            @php
                $initialBookConfig = data_get($criticalHome ?? [], 'booking.default_book_config')
                    ?: \Modules\BladeThemeV1\Support\BranchBookConfig::empty();
            @endphp
            @livewire('bladethemev1::book', ['config' => $initialBookConfig])
        </div>
    </div>
</section>
