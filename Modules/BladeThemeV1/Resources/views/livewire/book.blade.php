@inject('generalSettings', 'App\Settings\GeneralSettings')
{{-- Có ô khung giờ => layout nạp echo/ws-client (realtime). Dạng khối @php/@endphp, KHÔNG dùng
     @php(...) một dòng: Blade tách khối @php...@endphp trước, sẽ gộp nhầm tới @endphp kế tiếp. --}}
@php \Modules\BladeThemeV1\Support\RealtimeAssets::need(); @endphp
<div x-data="xBlock('bookRoot', $el)" x-on:book-category-changed.window="resetSelection()"
    class="{{ $generalSettings->holiday_theme_active ? 'holiday-theme' : '' }}"
    data-room-ids="{{ implode(',', $this->roomIds) }}">
    {{-- Sprite icon khung giờ (mặt trời/mặt trăng) — mỗi bảng có hàng chục tiêu đề khung giờ (mobile + desktop),
         trước đây in nguyên ~800 ký tự path SVG cho từng cái. Đặt NGOÀI wire:loading.remove để luôn có mặt. --}}
    <svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
        <symbol id="bk-moon" viewBox="0 0 24 24"><path fill-rule="evenodd" d="M9.528 1.718a.75.75 0 0 1 .162.819A8.97 8.97 0 0 0 9 6a9 9 0 0 0 9 9 8.97 8.97 0 0 0 3.463-.69.75.75 0 0 1 .981.98 10.503 10.503 0 0 1-9.694 6.46c-5.799 0-10.5-4.7-10.5-10.5 0-4.368 2.667-8.112 6.46-9.694a.75.75 0 0 1 .818.162Z" clip-rule="evenodd"/></symbol>
        <symbol id="bk-sun" viewBox="0 0 16 16"><path d="M8 1a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0v-1.5A.75.75 0 0 1 8 1ZM10.5 8a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0ZM12.95 4.11a.75.75 0 1 0-1.06-1.06l-1.062 1.06a.75.75 0 0 0 1.061 1.062l1.06-1.061ZM15 8a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 15 8ZM11.89 12.95a.75.75 0 0 0 1.06-1.06l-1.06-1.062a.75.75 0 0 0-1.062 1.061l1.061 1.06ZM8 12a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0v-1.5A.75.75 0 0 1 8 12ZM5.172 11.89a.75.75 0 0 0-1.061-1.062L3.05 11.89a.75.75 0 1 0 1.06 1.06l1.06-1.06ZM4 8a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 4 8ZM4.11 5.172A.75.75 0 0 0 5.173 4.11L4.11 3.05a.75.75 0 1 0-1.06 1.06l1.06 1.06Z"/></symbol>
    </svg>
    {{-- Đối chiếu layout server đã in với bề rộng màn hình thật (breakpoint lg = 1024px, cùng mốc
         với lg:hidden / hidden lg:block của 2 layout): lệch thì bỏ lựa chọn đang dở (ô của layout cũ
         sắp bị thay) rồi nhờ server in lại layout đúng. Cũng chạy khi xoay máy/đổi kích thước cửa sổ
         qua mốc đó. Chỉ dùng nháy đơn trong x-init (nằm trong attribute nháy kép). --}}
    <span hidden x-data x-init="(() => { const mq = window.matchMedia('(min-width: 1024px)'); const sync = () => { const want = mq.matches ? 'desktop' : 'mobile'; if ($wire.layout !== want) { resetSelection(); $wire.setLayout(want); } }; sync(); mq.addEventListener('change', sync); })()"></span>
    <div class="w-full mx-auto">
        @if ($this->deferred)
        {{-- Chế độ hoãn tải (Book::$deferred, trang chủ): chỉ in khung chờ; khi khối này cách vùng nhìn
             dưới 1000px (trên desktop là gần như ngay lúc vào trang) thì gọi loadBoard() lấy bảng thật.
             Trình duyệt không có IntersectionObserver thì tải luôn. Chỉ dùng nháy đơn trong x-init. --}}
        <div class="book-deferred" x-data
             x-init="(() => { const go = () => { if ($wire.deferred) $wire.loadBoard(); }; if (!('IntersectionObserver' in window)) { go(); return; } const io = new IntersectionObserver((entries) => { if (entries.some((e) => e.isIntersecting)) { io.disconnect(); go(); } }, { rootMargin: '1000px 0px' }); io.observe($el); })()">
            @include('bladethemev1::livewire.book._skeleton')
        </div>
        @else
        @include('bladethemev1::livewire.book._header')

        <div id="default-styled-tab-content" wire:loading.class="pointer-events-none">
            {{-- Skeleton (book/_skeleton.blade.php) thay chỗ nội dung thật trong lúc Livewire tải
                 dữ liệu (đổi chi nhánh — loadBranch(), hoặc đổi tab danh mục —
                 setActiveCategoryTab()) — mượt hơn hẳn so với chỉ làm mờ opacity nội dung cũ.
                 Không dùng wire:target giới hạn theo tên method — loadBranch() được gọi gián tiếp
                 qua sự kiện 'load-branch' (Livewire.dispatch), không phải wire:click trực tiếp,
                 nên wire:target khớp theo tên method không nhận diện đúng request đang chạy. --}}
            <div wire:loading.block style="display:none;">
                @include('bladethemev1::livewire.book._skeleton')
            </div>
            <div wire:loading.remove>
            @if(!empty($activeCategoryData))
            @php $category = $activeCategoryData; @endphp
            <div class="relative" id="styled-{{ \Str::slug($category['name']) }}" role="tabpanel"
                aria-labelledby="styled-{{ \Str::slug($category['name']) }}-tab" wire:key="book-category-{{ $category['id'] }}">

                @php
                // $dates chỉ chứa $visibleDaysCount ngày (mặc định 15/31) — không build sẵn cả
                // tháng, tránh render (số phòng x số khung giờ x số ngày) ô lịch cùng lúc gây
                // tràn bộ nhớ PHP. Bấm "Xem thêm ngày" gọi loadMoreDates() (round-trip Livewire
                // nhỏ) để tăng dần con số này.
                $dates = $this->getDatesForOneMonth();
                $styleOneRooms = collect($category['products'])->filter(fn($r) => ($r->styles ?? 1) == 1)->values();
                $totalStyleOneRooms = $styleOneRooms->count();
                $today = now()->startOfDay();
                // Chỉ ở trang chủ (compactDays): tên phòng dẫn sang trang chi tiết để xem thêm ngày.
                $roomDetailUrls = $this->compactDays ? $this->roomDetailUrls($styleOneRooms) : [];
                @endphp

                @if($totalStyleOneRooms > 0)
                    @include('bladethemev1::livewire.book._legend')

                    {{-- Bảng đặt phòng, và bên dưới là bảng tính giá (Giá cơ bản, tổng tiền tạm
                         tính) — luôn xếp dọc (cả mobile lẫn desktop). Trên mobile, bảng tính giá này
                         bị ẩn và thay bằng bottom sheet (bên dưới) để không chiếm chỗ khi chưa chọn
                         khung giờ. --}}
                    @php $slotMap = new \ArrayObject(); @endphp
                    <div class="book-panel">
                        {{-- Chỉ in MỘT layout (xem Book::$layout) — không in cả 2 rồi ẩn bớt bằng CSS. --}}
                        @if ($this->layout === 'mobile')
                            @include('bladethemev1::livewire.book._mobile')
                        @else
                            @include('bladethemev1::livewire.book._desktop-grid')
                        @endif

                        {{-- Dữ liệu từng ô giờ do book/_slot-cell.blade.php gom vào $slotMap — in ra 1 LẦN
                             (JSON_HEX_TAG chặn chuỗi </script> trong nhãn khuyến mãi phá thẻ). --}}
                        @php
                            // Mọi ô do cùng 1 đoạn code dựng nên => cùng thứ tự trường; tên trường lấy từ ô đầu
                            // tiên, mỗi ô chỉ giữ mảng giá trị (toggleSlot() ghép lại thành object như cũ).
                            $slotMapRows = $slotMap->getArrayCopy();
                            $slotMapPayload = [
                                'k' => $slotMapRows ? array_keys(reset($slotMapRows)) : [],
                                's' => array_map('array_values', $slotMapRows),
                            ];
                        @endphp
                        <script type="application/json" data-slot-map>{!! json_encode($slotMapPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>

                        <div class="book-pricing-desktop">
                            @include('bladethemev1::livewire.book._pricing')
                        </div>
                    </div>
                @else
                    <div style="padding:2.5rem 1rem; text-align:center;">
                        <svg style="width:40px;height:40px;color:#d1d5db;margin:0 auto 12px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <p style="color:#6b7280;font-size:14px;margin:0;">Không có phòng khả dụng cho danh mục này.</p>
                    </div>
                @endif

            </div>
            @endif
            </div>
        </div>

        {{-- Mobile bottom sheet: hiện bảng tính giá đầy đủ sau khi user chọn khung giờ.
             Không dùng backdrop toàn màn hình nữa — trước đây backdrop phủ inset:0 chặn
             luôn cả click vào bảng khung giờ phía trên sheet, khiến không chọn thêm được
             khung giờ thứ 2 (chỉ bấm được nút "X" hoặc bấm ra ngoài để đóng). Giờ người
             dùng có thể chọn tiếp trong khi sheet vẫn hiện, đóng bằng nút "X". --}}
        <div class="book-bottom-sheet"
             x-show="selectedSlots.length > 0"
             x-transition:enter="sheet-enter"
             x-transition:enter-start="sheet-enter-from"
             x-transition:enter-end="sheet-enter-to"
             x-transition:leave="sheet-leave"
             x-transition:leave-start="sheet-leave-from"
             x-transition:leave-end="sheet-leave-to"
             style="display:none">
            <div class="book-sheet-handle-row">
                <span class="book-sheet-handle"></span>
                <button type="button" class="book-sheet-close" @click="resetSelection()" aria-label="Đóng">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="book-sheet-scroll">
                @include('bladethemev1::livewire.book._pricing')
            </div>
        </div>

        @endif

        @include('bladethemev1::livewire.book._styles')
    </div>
</div>