<x-filament-widgets::widget>
    {{-- <style> phải nằm TRONG root element này, không được đặt trước <x-filament-widgets::widget>
    — Livewire bắt buộc template của 1 component chỉ có ĐÚNG 1 phần tử gốc; đặt <style> làm anh em
    (sibling) phía trước sẽ khiến Livewire gắn nhầm wire:id lên chính thẻ <style> đó thay vì đúng
    widget, làm mọi lời gọi $wire bên trong (toggleRoomRepair/bulkMarkRepair) bị chỉ sai sang tận
    component CHA (trang Dashboard) — gây lỗi 500 "method not found on component". --}}
    <style>
        /* Desktop (>=768px): 1 trang = 3 toà xếp cạnh nhau, y hệt trước đây. Mobile: 1 trang = ĐÚNG 1
        toà, chiếm trọn màn hình (không xuống hàng) — số toà/trang (pageSize, tính trong x-data bên
        dưới) đổi số cột grid tương ứng qua :style, CSS này chỉ lo khoảng cách. */
        .mh-room-map-buildings {
            display: grid;
            gap: 1rem;
        }
    </style>

    <x-filament::section>
        <x-slot name="heading">Sơ đồ phòng</x-slot>
        <x-slot name="description">Đúng theo mặt bằng đã khai báo (hàng/cột ở trang Chỉnh sửa phòng) — bấm vào 1 phòng: đang có khách thì mở Hợp đồng, còn trống/đã khoá thì mở menu chọn hành động.</x-slot>

        <div class="mb-5 flex flex-wrap items-center gap-4 text-xs text-gray-500 dark:text-gray-400">
            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-success-500"></span>Trống</span>
            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-primary-500"></span>Đang thuê, đủ tiền</span>
            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-danger-600"></span>Đang thuê, còn nợ (dải đỏ = số tiền nợ)</span>
            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-warning-500"></span>Đã khoá</span>
            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded border border-dashed border-gray-300 dark:border-white/20"></span>Ô trống (không có phòng)</span>
        </div>

        @php
            // Danh sách PHẲNG (không gộp trước theo Blade nữa) — số toà/trang giờ tính bên JS theo
            // kích thước màn hình thực tế (3 ở desktop ≥768px, 1 ở mobile), Blade không biết trước
            // được viewport nên không thể chunk() cố định 1 số như trước.
            $buildingPages = $buildings->values();
        @endphp

        @if ($buildingPages->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Chưa có phòng nào.</p>
        @else
            <div
                x-data="{
                    total: {{ $buildingPages->count() }},
                    pageSize: window.matchMedia('(min-width: 768px)').matches ? 3 : 1,
                    page: 0,
                    touchStartX: null,
                    get pages() { return Math.max(1, Math.ceil(this.total / this.pageSize)); },
                    isOnPage(i) { return Math.floor(i / this.pageSize) === this.page; },
                    goToPage(p) { this.page = ((p % this.pages) + this.pages) % this.pages; },
                    init() {
                        // Đổi cỡ màn hình (xoay ngang/dọc, thu nhỏ trình duyệt) — tính lại pageSize và
                        // reset về trang đầu để tránh 'page' trỏ ra ngoài phạm vi mới.
                        window.matchMedia('(min-width: 768px)').addEventListener('change', (e) => {
                            this.pageSize = e.matches ? 3 : 1;
                            this.page = 0;
                        });
                    },
                    onTouchStart(e) { this.touchStartX = e.changedTouches[0].screenX; },
                    onTouchEnd(e) {
                        if (this.touchStartX === null) return;
                        const delta = e.changedTouches[0].screenX - this.touchStartX;
                        this.touchStartX = null;
                        if (Math.abs(delta) < 40) return; // vuốt quá ngắn — bỏ qua, tránh nhầm với chạm thường
                        this.goToPage(this.page + (delta < 0 ? 1 : -1));
                    },
                }"
            >
                <template x-if="pages > 1">
                    <div class="mb-4 flex items-center justify-center gap-3">
                        <button
                            type="button"
                            @click="goToPage(page - 1)"
                            class="flex h-7 w-7 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-white"
                        >
                            <x-heroicon-o-chevron-left class="h-4 w-4" />
                        </button>

                        <div class="flex items-center gap-1.5">
                            <template x-for="p in pages" :key="p">
                                <button
                                    type="button"
                                    @click="goToPage(p - 1)"
                                    :class="page === (p - 1) ? 'w-5 bg-primary-500' : 'w-1.5 bg-gray-300 dark:bg-white/20'"
                                    class="h-1.5 rounded-full transition-all"
                                    :aria-label="'Trang ' + p"
                                ></button>
                            </template>
                        </div>

                        <button
                            type="button"
                            @click="goToPage(page + 1)"
                            class="flex h-7 w-7 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-white"
                        >
                            <x-heroicon-o-chevron-right class="h-4 w-4" />
                        </button>
                    </div>
                </template>

                <div
                    @touchstart="onTouchStart($event)" @touchend="onTouchEnd($event)"
                    class="mh-room-map-buildings"
                    x-bind:style="'grid-template-columns: repeat(' + pageSize + ', 1fr);'"
                >
                @foreach ($buildingPages as $i => $group)
                    <div x-show="isOnPage({{ $i }})" x-cloak>
                        {{-- x-data ở CẤP TOÀ NHÀ — "Chọn nhiều" chỉ chọn được phòng TRONG CÙNG 1 toà, không
                            lẫn sang toà khác. `rooms` là map id -> {code,status,createHref,roomEditHref} của
                            các phòng CHƯA có khách (đang thuê không cho chọn) để phần header tra cứu link/tên
                            khi hiện nút hành động cho đúng phòng đang chọn.

                            wire:key BẮT BUỘC phải đổi mỗi khi trạng thái phòng trong toà này thay đổi (hash
                            theo đúng dữ liệu `selectableRooms`) — không có key này, sau khi bấm Khoá/Mở khoá
                            (wire:click gọi lên server rồi Livewire vá lại HTML), Alpine KHÔNG khởi tạo lại
                            x-data mà giữ nguyên `rooms`/`selected` cũ trong bộ nhớ trình duyệt (Livewire chỉ vá
                            DOM tại đúng vị trí, không huỷ-tạo-lại phần tử) — khiến nút "Khoá phòng"/"Mở khoá"
                            hiện sai nhãn (dựa theo trạng thái CŨ) và việc chọn nhiều bị lẫn lộn giữa các lần
                            thao tác. Đổi key ép Livewire coi đây là phần tử MỚI, Alpine khởi tạo lại sạch. --}}
                            <div
                                wire:key="mh-building-{{ $group['building']?->id ?? 'none' }}-{{ md5(json_encode($group['selectableRooms'])) }}"
                                x-data="{
                                    multiMode: false,
                                    selected: [],
                                    rooms: {{ Illuminate\Support\Js::from($group['selectableRooms']) }},
                                    toggleRoom(id) {
                                        if (this.multiMode) {
                                            const i = this.selected.indexOf(id);
                                            if (i === -1) { this.selected.push(id); } else { this.selected.splice(i, 1); }
                                        } else {
                                            this.selected = (this.selected[0] === id) ? [] : [id];
                                        }
                                    },
                                    isSelected(id) { return this.selected.includes(id); },
                                    toggleMultiMode() { this.multiMode = ! this.multiMode; this.selected = []; },
                                    clearSelection() { this.selected = []; },
                                }"
                                class="overflow-hidden rounded-xl border border-gray-200 dark:border-white/10"
                            >
                                {{-- flex-col ở mobile (tên toà + hành động xếp thành 2 hàng riêng, mỗi hàng đủ
                                rộng để không bị bóp/cắt chữ nút) — sm:flex-row trở lại đúng 1 hàng như cũ ở
                                màn hình ≥640px, nơi đủ chỗ cho cả 2 bên. --}}
                                <div class="flex flex-col gap-2 border-b border-gray-200 bg-gray-50 px-3 py-2.5 dark:border-white/10 dark:bg-white/5 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $group['building']?->name ?? 'Chưa gán toà nhà' }}</div>
                                        <div class="mt-1.5 flex flex-wrap items-center gap-1 text-[11px]">
                                            <span class="rounded-full bg-success-50 px-1.5 py-0.5 font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400">{{ $group['stats']['empty'] }} trống</span>
                                            <span class="rounded-full bg-primary-50 px-1.5 py-0.5 font-medium text-primary-700 dark:bg-primary-500/10 dark:text-primary-400">{{ $group['stats']['rented'] }} đang thuê</span>
                                            @if ($group['stats']['reserved'] > 0)
                                                <span class="rounded-full bg-info-50 px-1.5 py-0.5 font-medium text-info-700 dark:bg-info-500/10 dark:text-info-400">{{ $group['stats']['reserved'] }} đã đặt cọc</span>
                                            @endif
                                            @if ($group['stats']['repair'] > 0)
                                                <span class="rounded-full bg-warning-50 px-1.5 py-0.5 font-medium text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">{{ $group['stats']['repair'] }} khoá</span>
                                            @endif
                                            @if ($group['stats']['inDebt'] > 0)
                                                <span class="rounded-full bg-danger-600 px-1.5 py-0.5 font-semibold text-white">{{ $group['stats']['inDebt'] }} nợ · {{ number_format($group['stats']['debtSum'], 0, ',', '.') }}đ</span>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Khu hành động ở HEADER — thay cho menu nổi trên từng ô trước đây: chưa chọn
                                    gì thì chỉ có nút "Chọn nhiều"; chọn đúng 1 phòng (chế độ thường) thì hiện 3
                                    hành động cho phòng đó; đang "Chọn nhiều" thì CHỈ 1 nút áp dụng hàng loạt. --}}
                                    {{-- Dùng style nội tuyến cho TOÀN BỘ nút ở đây (không phải class Tailwind) —
                                    đây là vùng UI mới, tránh lệ thuộc phải build lại CSS mỗi lần thêm class mới
                                    (đã từng bị nút hiện "mờ" do thiếu 1 class chưa kịp build). --}}
                                    <div style="display:flex; flex-wrap:wrap; align-items:center; justify-content:flex-start; gap:4px;">
                                        <template x-if="selected.length === 0">
                                            <button
                                                type="button"
                                                @click="toggleMultiMode()"
                                                style="border-radius:6px; padding:4px 8px; font-size:11px; font-weight:500; transition:.15s; border:none; cursor:pointer;"
                                                :style="'background:' + (multiMode ? '#374151' : '#e5e7eb') + ';color:' + (multiMode ? '#fff' : '#4b5563') + ';'"
                                            >
                                                <span x-text="multiMode ? 'Đang chọn nhiều — bấm phòng để chọn' : 'Chọn nhiều'"></span>
                                            </button>
                                        </template>

                                        <template x-if="selected.length > 0 && ! multiMode">
                                            <div style="display:flex; flex-wrap:wrap; align-items:center; gap:4px;">
                                                <span style="font-size:11px; color:#6b7280;" x-text="'Phòng ' + (rooms[selected[0]]?.code ?? '')"></span>
                                                <a :href="rooms[selected[0]]?.createHref" style="border-radius:6px; padding:4px 8px; font-size:11px; font-weight:500; background:rgba(var(--primary-600),1); color:#fff; text-decoration:none;">Đặt phòng</a>
                                                <button
                                                    type="button"
                                                    @click="$wire.toggleRoomRepair(selected[0]); clearSelection();"
                                                    style="border-radius:6px; padding:4px 8px; font-size:11px; font-weight:500; background:rgba(var(--warning-600),1); color:#fff; border:none; cursor:pointer;"
                                                    x-text="rooms[selected[0]]?.status === 'bao_tri' ? 'Mở khoá' : 'Khoá phòng'"
                                                ></button>
                                                <a :href="rooms[selected[0]]?.roomEditHref" style="border-radius:6px; padding:4px 8px; font-size:11px; font-weight:500; background:#e5e7eb; color:#374151; text-decoration:none;">Chỉnh sửa</a>
                                                <button type="button" @click="clearSelection()" style="border-radius:6px; padding:4px 8px; font-size:11px; color:#9ca3af; background:none; border:none; cursor:pointer;">Huỷ</button>
                                            </div>
                                        </template>

                                        <template x-if="selected.length > 0 && multiMode">
                                            <div style="display:flex; flex-wrap:wrap; align-items:center; gap:4px;">
                                                <span style="font-size:11px; color:#6b7280;" x-text="'Đã chọn ' + selected.length + ' phòng'"></span>
                                                <button
                                                    type="button"
                                                    @click="$wire.bulkToggleRepair(selected); clearSelection(); multiMode = false;"
                                                    style="border-radius:6px; padding:4px 8px; font-size:11px; font-weight:500; background:rgba(var(--warning-600),1); color:#fff; border:none; cursor:pointer;"
                                                    title="Phòng đang khoá sẽ mở ra, phòng đang trống sẽ bị khoá lại — mỗi phòng tự đảo đúng trạng thái của nó."
                                                >
                                                    Khoá / Mở khoá
                                                </button>
                                                <button type="button" @click="toggleMultiMode()" style="border-radius:6px; padding:4px 8px; font-size:11px; color:#9ca3af; background:none; border:none; cursor:pointer;">Huỷ</button>
                                            </div>
                                        </template>
                                    </div>
                                </div>

                                <div class="space-y-4 overflow-y-auto p-3" style="max-height: 28rem;">
                                    @foreach ($group['floors'] as $floor => $floorData)
                                        <div>
                                            <div class="mb-1.5 inline-block rounded-md bg-gray-100 px-1.5 py-0.5 text-[11px] font-medium text-gray-600 dark:bg-white/5 dark:text-gray-400">
                                                {{ $floor > 0 ? 'Tầng ' . $floor : 'Chưa rõ tầng' }}
                                            </div>

                                            @if ($floorData['maxCol'] > 0)
                                                {{-- Sơ đồ THẬT theo đúng hàng/cột đã khai báo — ô không có phòng để trống, không phải bị thiếu dữ liệu.
                                                CỐ Ý dùng "inline-grid" (co vừa đúng nội dung, ô luôn cố định 4.5rem) chứ KHÔNG giãn hết chiều rộng
                                                thẻ — đã cân nhắc phương án giãn ô để lấp khoảng trống ở toà/tầng ít cột, nhưng chọn giữ kích thước ô
                                                ĐỒNG NHẤT giữa mọi toà/tầng (chấp nhận có khoảng trống bên phải ở toà/tầng ít phòng) thay vì để cùng 1
                                                mã phòng to nhỏ khác nhau tuỳ toà/tầng. --}}
                                                <div class="overflow-x-auto pb-1">
                                                    <div class="inline-grid gap-1.5" style="grid-template-columns: repeat({{ $floorData['maxCol'] }}, minmax(4.5rem, 1fr));">
                                                        @foreach ($floorData['grid'] as $rowCells)
                                                            @foreach ($rowCells as $room)
                                                                @if ($room)
                                                                    @include('minihouse::filament.widgets.partials.room-card', ['room' => $room])
                                                                @else
                                                                    <div class="aspect-square rounded-lg border border-dashed border-gray-200 dark:border-white/10"></div>
                                                                @endif
                                                            @endforeach
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif

                                            @if ($floorData['unpositioned']->isNotEmpty())
                                                <div class="{{ $floorData['maxCol'] > 0 ? 'mt-2 border-t border-dashed border-gray-200 pt-2 dark:border-white/10' : '' }}">
                                                    @if ($floorData['maxCol'] > 0)
                                                        <div class="mb-1.5 text-[10px] text-gray-400 dark:text-gray-500">Chưa khai báo vị trí:</div>
                                                    @endif
                                                    <div class="flex flex-wrap gap-1.5">
                                                        @foreach ($floorData['unpositioned'] as $room)
                                                            @include('minihouse::filament.widgets.partials.room-card', ['room' => $room, 'extraClass' => 'w-24'])
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                    </div>
                @endforeach
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
