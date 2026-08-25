{{--
 * ĐÁNH GIÁ SAO — thay cho khối "Bình luận" cũ.
 * API: GET/POST/DELETE /api/rooms/{id}/ratings (xem app/Http/Controllers/Api/RatingController.php)
 * Biến nhận vào: $product (Eloquent Product model, bắt buộc)
--}}
<div class="mb-6" id="pd-ratings-section" x-data="pdRatingsWidget('{{ $product->id }}')" x-init="init()">
    <style>
        .pd-reviews-scroll { scrollbar-width: none; }
        .pd-reviews-scroll::-webkit-scrollbar { display: none; }
    </style>

    <h2 class="text-xl font-bold text-gray-900 mb-4">Đánh giá</h2>

    {{-- Tổng quan: điểm trung bình + phân bố số sao --}}
    <div class="flex flex-col sm:flex-row sm:items-center gap-6 mb-6" x-show="!loadingSummary" x-cloak>
        <div class="flex items-center gap-3 shrink-0">
            <span class="text-4xl font-bold text-gray-900"
                x-text="summary.average !== null ? summary.average.toFixed(1) : '—'"></span>
            <div>
                <div class="flex items-center gap-0.5">
                    <template x-for="i in 5" :key="i">
                        <svg class="w-4 h-4" :fill="i <= Math.round(summary.average || 0) ? '#f59e0b' : '#e5e7eb'"
                            viewBox="0 0 24 24" stroke="currentColor" stroke-width="1" style="color:#f59e0b;">
                            <path
                                d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z">
                            </path>
                        </svg>
                    </template>
                </div>
                <p class="text-sm text-gray-500 mt-0.5">
                    <span x-text="summary.total_count"></span> đánh giá
                </p>
            </div>
        </div>

        <div class="flex-1 space-y-1 max-w-sm" x-show="summary.total_count > 0">
            <template x-for="star in [5, 4, 3, 2, 1]" :key="star">
                <div class="flex items-center gap-2 text-xs text-gray-600">
                    <span class="w-2.5 shrink-0" x-text="star"></span>
                    <svg class="w-3 h-3 shrink-0" fill="#f59e0b" viewBox="0 0 24 24">
                        <path
                            d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z">
                        </path>
                    </svg>
                    <div class="flex-1 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                        <div class="h-full bg-amber-400 rounded-full"
                            :style="'width:' + (summary.total_count ? (summary.distribution[star] / summary.total_count * 100) : 0) + '%'">
                        </div>
                    </div>
                    <span class="w-5 shrink-0 text-right" x-text="summary.distribution[star]"></span>
                </div>
            </template>
        </div>

        {{-- Nút đánh giá: mobile (flex-col) canh trái như các nội dung khác trong trang; desktop
             (flex-row) đẩy về cuối hàng bằng ml-auto (sm:justify-end không có tác dụng vì wrapper
             này chỉ rộng bằng nút — justify-content chỉ ảnh hưởng bên trong 1 flex container mà
             nút là con duy nhất, phải dùng margin-left:auto để tự đẩy sang phải trong hàng cha). --}}
        <div class="flex justify-start sm:ml-auto shrink-0">
            <button type="button" @click="openReviewModal()"
                class="inline-flex items-center px-5 py-2.5 rounded-xl border border-gray-900 text-gray-900 font-semibold text-sm hover:bg-gray-900 hover:text-white transition-colors">
                <span x-text="myRatingId ? 'Sửa đánh giá của bạn' : 'Đánh giá phòng này'"></span>
            </button>
        </div>
    </div>

    {{-- Lưới xem trước — tối đa 6 đánh giá. Mobile: 1 hàng cuộn ngang. Desktop: 2 cột flex ĐỘC
         LẬP (mỗi cột tự co giãn theo nội dung riêng của nó, lấp tối đa 3 theo cột dọc) — cố tình
         không dùng CSS Grid (grid-flow-col + grid-rows-3) vì grid buộc mọi ô trên cùng 1 hàng
         phải cao bằng ô cao nhất, khiến đánh giá ngắn bị kéo giãn theo đánh giá dài nhất và để
         lại khoảng trắng lớn/lệch hàng giữa 2 cột. --}}
    <div x-show="loadingPreview"
        class="pd-reviews-scroll flex md:hidden items-start gap-0 overflow-x-auto snap-x snap-mandatory pb-1">
        <template x-for="n in 6" :key="n">
            <div class="flex gap-3 animate-pulse shrink-0 w-[82%] snap-start pr-6 border-r border-gray-100"
                :class="n > 1 ? 'pl-2' : ''">
                <span class="h-10 w-10 shrink-0 rounded-full bg-gray-200"></span>
                <div class="flex-1 min-w-0 space-y-2">
                    <div class="h-3.5 w-24 bg-gray-200 rounded"></div>
                    <div class="h-3 w-32 bg-gray-200 rounded"></div>
                    <div class="h-3 w-full bg-gray-200 rounded mt-2"></div>
                    <div class="h-3 w-5/6 bg-gray-200 rounded"></div>
                    <div class="h-3 w-2/3 bg-gray-200 rounded"></div>
                </div>
            </div>
        </template>
    </div>
    <div x-show="loadingPreview" class="hidden md:grid md:grid-cols-2 md:gap-x-10 md:gap-y-6">
        <template x-for="n in 6" :key="n">
            <div class="flex gap-3 animate-pulse">
                <span class="h-10 w-10 shrink-0 rounded-full bg-gray-200"></span>
                <div class="flex-1 min-w-0 space-y-2">
                    <div class="h-3.5 w-24 bg-gray-200 rounded"></div>
                    <div class="h-3 w-32 bg-gray-200 rounded"></div>
                    <div class="h-3 w-full bg-gray-200 rounded mt-2"></div>
                    <div class="h-3 w-5/6 bg-gray-200 rounded"></div>
                    <div class="h-3 w-2/3 bg-gray-200 rounded"></div>
                </div>
            </div>
        </template>
    </div>

    <div x-show="!loadingPreview" x-cloak>
        <template x-if="previewList.length === 0">
            <p class="text-sm text-gray-500">Chưa có đánh giá nào cho phòng này.</p>
        </template>

        {{-- Mobile: 1 hàng cuộn ngang, kéo được --}}
        <div class="pd-reviews-scroll flex md:hidden items-start gap-0 overflow-x-auto snap-x snap-mandatory pb-1"
            x-show="previewList.length > 0">
            <template x-for="(r, idx) in previewList" :key="r.id">
                @include('bladethemev1::components.product-detail._review-preview-card', ['variant' => 'mobile'])
            </template>
        </div>

        {{-- Desktop: 2 cột flex độc lập (masonry thật) --}}
        <div class="hidden md:grid md:grid-cols-2 md:gap-x-10" x-show="previewList.length > 0">
            <div class="flex flex-col gap-y-6">
                <template x-for="r in previewCol(0)" :key="r.id">
                    @include('bladethemev1::components.product-detail._review-preview-card', ['variant' => 'desktop'])
                </template>
            </div>
            <div class="flex flex-col gap-y-6">
                <template x-for="r in previewCol(1)" :key="r.id">
                    @include('bladethemev1::components.product-detail._review-preview-card', ['variant' => 'desktop'])
                </template>
            </div>
        </div>

        <button type="button" x-show="summary.total_count > 6" x-cloak @click="openModal()"
            class="mt-6 inline-flex items-center gap-2 px-5 py-2.5 rounded-xl border border-gray-900 text-gray-900 font-semibold text-sm hover:bg-gray-900 hover:text-white transition-colors">
            Hiển thị tất cả <span x-text="summary.total_count"></span> đánh giá
        </button>
    </div>

    {{-- Modal "tất cả đánh giá" — cuộn vô hạn, skeleton khi tải trang tiếp theo --}}
    <template x-teleport="body">
        <div x-show="modalOpen" x-cloak
            class="fixed inset-0 z-[10050] md:flex md:items-center md:justify-center md:p-6">
            <div class="absolute inset-0 bg-black/50" @click="closeModal()"></div>
            <div class="relative w-full h-full md:h-[85vh] md:max-w-[50rem] bg-white md:rounded-2xl overflow-hidden flex flex-col shadow-2xl"
                @click.stop>
                <div class="shrink-0 flex items-center justify-center relative px-5 py-4 border-b border-gray-200">
                    <p class="font-semibold text-gray-900">Đánh giá</p>
                    <button type="button" @click="closeModal()" aria-label="Đóng"
                        class="absolute right-4 top-1/2 -translate-y-1/2 flex h-8 w-8 items-center justify-center rounded-full hover:bg-gray-100 text-gray-500">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" d="M18 6L6 18M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto px-5 py-6 sm:px-10" x-ref="modalScroll">
                    <div class="text-center max-w-md mx-auto mb-8">
                        <p class="text-5xl font-bold text-gray-900"
                            x-text="summary.average !== null ? summary.average.toFixed(1) : '—'"></p>
                        <div class="flex items-center justify-center gap-0.5 mt-2">
                            <template x-for="i in 5" :key="i">
                                <svg class="w-4 h-4" :fill="i <= Math.round(summary.average || 0) ? '#222' : '#e5e7eb'"
                                    viewBox="0 0 24 24">
                                    <path
                                        d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z">
                                    </path>
                                </svg>
                            </template>
                        </div>
                        <p class="text-sm text-gray-500 mt-2">
                            Dựa trên <span x-text="summary.total_count"></span> đánh giá
                        </p>

                        <div class="space-y-1.5 mt-6 text-left">
                            <template x-for="star in [5, 4, 3, 2, 1]" :key="star">
                                <div class="flex items-center gap-2 text-xs text-gray-600">
                                    <span class="w-2.5 shrink-0" x-text="star"></span>
                                    <svg class="w-3 h-3 shrink-0" fill="#222" viewBox="0 0 24 24">
                                        <path
                                            d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z">
                                        </path>
                                    </svg>
                                    <div class="flex-1 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                                        <div class="h-full bg-gray-900 rounded-full"
                                            :style="'width:' + (summary.total_count ? (summary.distribution[star] / summary.total_count * 100) : 0) + '%'">
                                        </div>
                                    </div>
                                    <span class="w-5 shrink-0 text-right" x-text="summary.distribution[star]"></span>
                                </div>
                            </template>
                        </div>
                    </div>

                    <hr class="border-gray-200 max-w-2xl mx-auto mb-8">

                    <div class="max-w-2xl mx-auto divide-y divide-gray-100">
                        <template x-for="r in modalList" :key="r.id">
                            <div class="py-5 flex gap-3 rounded-lg" :data-review-id="r.id">
                                <span
                                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#222222] text-white text-base font-semibold"
                                    x-text="(r.user_name || '?').charAt(0).toUpperCase()"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold text-gray-900" x-text="r.user_name"></p>
                                    <div class="flex items-center gap-1.5 mt-0.5 text-xs text-gray-500">
                                        <div class="flex items-center gap-0.5">
                                            <template x-for="i in 5" :key="i">
                                                <svg class="w-2.5 h-2.5" :fill="i <= r.star ? '#222' : '#e5e7eb'"
                                                    viewBox="0 0 24 24">
                                                    <path
                                                        d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z">
                                                    </path>
                                                </svg>
                                            </template>
                                        </div>
                                        <span>&middot;</span>
                                        <span x-text="pdFormatRelative(r.created_at)"></span>
                                    </div>
                                    <p class="text-sm text-gray-700 mt-1.5 break-words" x-show="r.comment"
                                        x-text="r.comment"></p>
                                </div>
                            </div>
                        </template>
                    </div>

                    {{-- Skeleton khi tải trang kế tiếp (cuộn vô hạn) --}}
                    <div class="max-w-2xl mx-auto divide-y divide-gray-100" x-show="modalLoadingMore" x-cloak>
                        <template x-for="n in 3" :key="n">
                            <div class="py-5 flex gap-3 animate-pulse">
                                <span class="h-11 w-11 shrink-0 rounded-full bg-gray-200"></span>
                                <div class="flex-1 min-w-0 space-y-2">
                                    <div class="h-3.5 w-28 bg-gray-200 rounded"></div>
                                    <div class="h-3 w-20 bg-gray-200 rounded"></div>
                                    <div class="h-3 w-full bg-gray-200 rounded mt-2"></div>
                                    <div class="h-3 w-4/5 bg-gray-200 rounded"></div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div x-ref="modalSentinel" class="h-1"></div>
                </div>
            </div>
        </div>
    </template>

    {{-- Modal viết / sửa đánh giá — chỉ mở khi đã đăng nhập (nút kích hoạt bị ẩn nếu chưa đăng
         nhập). Form dùng chung state (form.star/comment, myRatingId) nên khi user đã có đánh giá,
         mở lại modal sẽ tự hiện đúng nội dung cũ để sửa (đã prefill từ my_rating trong response
         API — tra theo customer_id đăng nhập, không đoán theo tên). --}}
    <template x-teleport="body">
        <div x-show="reviewModalOpen" x-cloak class="fixed inset-0 z-[10060] flex items-center justify-center p-4 md:p-6">
            <div class="absolute inset-0 bg-black/50" @click="closeReviewModal()"></div>
            <div class="relative w-full max-w-md bg-white rounded-2xl overflow-hidden shadow-2xl" @click.stop>
                <div class="shrink-0 flex items-center justify-center relative px-5 py-4 border-b border-gray-200">
                    <p class="font-semibold text-gray-900" x-text="myRatingId ? 'Sửa đánh giá của bạn' : 'Đánh giá của bạn'"></p>
                    <button type="button" @click="closeReviewModal()" aria-label="Đóng"
                        class="absolute right-4 top-1/2 -translate-y-1/2 flex h-8 w-8 items-center justify-center rounded-full hover:bg-gray-100 text-gray-500">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" d="M18 6L6 18M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="p-5">
                    <div class="flex items-center gap-1 mb-3">
                        <template x-for="i in 5" :key="i">
                            <button type="button" @click="form.star = i" @mouseenter="hoverStar = i"
                                @mouseleave="hoverStar = 0" class="p-0.5" aria-label="Chấm điểm">
                                <svg class="w-8 h-8 transition-colors"
                                    :fill="i <= (hoverStar || form.star) ? '#f59e0b' : '#e5e7eb'" viewBox="0 0 24 24">
                                    <path
                                        d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z">
                                    </path>
                                </svg>
                            </button>
                        </template>
                    </div>
                    <textarea x-model="form.comment" rows="4" maxlength="1000"
                        placeholder="Chia sẻ trải nghiệm của bạn (không bắt buộc)..."
                        class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2"
                        style="--tw-ring-color: var(--color-primary);"></textarea>
                    <p class="text-xs text-red-500 mt-1" x-show="formError" x-cloak x-text="formError"></p>
                    <div class="flex items-center gap-4 mt-4">
                        <button type="button" @click="submitRating()" :disabled="form.star === 0 || submitting"
                            class="px-5 py-2 rounded-full text-sm font-semibold text-white transition-opacity"
                            :class="(form.star === 0 || submitting) ? 'opacity-50 cursor-not-allowed' : ''"
                            style="background: var(--color-primary);">
                            <span x-text="submitting ? 'Đang gửi...' : (myRatingId ? 'Cập nhật đánh giá' : 'Gửi đánh giá')"></span>
                        </button>
                        <button type="button" x-show="myRatingId" x-cloak @click="deleteRating()"
                            class="text-sm font-semibold text-gray-500 hover:text-red-600">
                            Xoá đánh giá
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>

<script>
    // Định dạng thời gian tương đối kiểu Airbnb: gần đây → "X ngày/tuần trước", xa hơn →
    // "tháng M năm YYYY". Dùng chung cho cả lưới xem trước lẫn modal.
    function pdFormatRelative(iso) {
        if (!iso) return '';
        var date = new Date(iso);
        var diffDays = Math.floor((Date.now() - date.getTime()) / 86400000);
        if (diffDays < 1) return 'Hôm nay';
        if (diffDays < 7) return diffDays + ' ngày trước';
        if (diffDays < 30) return Math.floor(diffDays / 7) + ' tuần trước';
        if (diffDays < 60) return '1 tháng trước';
        return 'tháng ' + (date.getMonth() + 1) + ' năm ' + date.getFullYear();
    }

    // 1 instance riêng cho mỗi card trong lưới xem trước — tự đo xem nội dung có tràn quá 3
    // dòng (line-clamp-3) hay không để chỉ hiện nút "Hiển thị thêm" khi thực sự cần.
    function pdReviewCard() {
        return {
            overflowing: false,
            checkOverflow(root) {
                var self = this;
                this.$nextTick(function () {
                    var el = root.querySelector('.pd-review-text');
                    if (el) self.overflowing = el.scrollHeight > el.clientHeight + 2;
                });
            },
        };
    }

    function pdRatingsWidget(roomId) {
        return {
            roomId: roomId,
            summary: { average: null, total_count: 0, distribution: { 5: 0, 4: 0, 3: 0, 2: 0, 1: 0 } },
            previewList: [],
            loadingSummary: true,
            loadingPreview: true,

            // Desktop: chia previewList thành 2 cột độc lập, lấp tối đa 3 mỗi cột theo cột dọc
            // (0,1,2 → cột 1; 3,4,5 → cột 2) — xem ghi chú "masonry thật" ở trên.
            previewCol(i) {
                var start = i * 3;
                return this.previewList.slice(start, start + 3);
            },

            modalOpen: false,
            modalList: [],
            modalPage: 0,
            modalLastPage: 1,
            modalLoadingMore: false,
            modalObserver: null,

            form: { star: 0, comment: '' },
            hoverStar: 0,
            submitting: false,
            formError: '',
            myRatingId: null,

            isLoggedIn: false,
            reviewModalOpen: false,

            init() {
                var self = this;
                this.isLoggedIn = !!localStorage.getItem('auth_token');
                window.addEventListener('auth-state-changed', function () {
                    self.isLoggedIn = !!localStorage.getItem('auth_token');
                });
                this.fetchPreview();
            },

            openReviewModal() {
                if (!this.isLoggedIn) {
                    window.dispatchEvent(new CustomEvent('open-auth-modal'));
                    return;
                }
                this.formError = '';
                this.reviewModalOpen = true;
            },

            closeReviewModal() {
                this.reviewModalOpen = false;
            },

            authHeaders() {
                var token = localStorage.getItem('auth_token');
                var headers = { Accept: 'application/json' };
                if (token) headers.Authorization = 'Bearer ' + token;
                return headers;
            },

            // Trang đầu (per_page=10 từ API) đủ để vừa lấy summary vừa lấy 6 đánh giá xem trước
            // — không cần gọi API riêng cho việc này.
            fetchPreview() {
                var self = this;
                this.loadingSummary = true;
                this.loadingPreview = true;
                fetch('/api/rooms/' + this.roomId + '/ratings?page=1', { headers: this.authHeaders() })
                    .then(function (res) { return res.json(); })
                    .then(function (json) {
                        self.summary = json.summary;
                        self.previewList = (json.data || []).slice(0, 6);
                        if (json.my_rating) {
                            self.myRatingId = json.my_rating.id;
                            self.form.star = json.my_rating.star;
                            self.form.comment = json.my_rating.comment || '';
                        } else {
                            self.myRatingId = null;
                            self.form = { star: 0, comment: '' };
                        }
                    })
                    .catch(function () { self.previewList = []; })
                    .finally(function () {
                        self.loadingSummary = false;
                        self.loadingPreview = false;
                    });
            },

            openModal() {
                this.modalOpen = true;
                document.body.style.overflow = 'hidden';
                if (this.modalList.length === 0) {
                    this.loadModalPage(1);
                }
                this.$nextTick(() => this.setupInfiniteScroll());
            },

            closeModal() {
                this.modalOpen = false;
                document.body.style.overflow = '';
                if (this.modalObserver) {
                    this.modalObserver.disconnect();
                    this.modalObserver = null;
                }
            },

            // Mở popup "tất cả đánh giá" rồi cuộn tới đúng thẻ đánh giá vừa bị cắt bớt chữ (nút
            // "Hiển thị thêm" trong lưới xem trước) — modalList tải bất đồng bộ nên phải chờ / thử
            // lại vài lần cho tới khi phần tử [data-review-id] xuất hiện trong DOM.
            openReviewInModal(id) {
                this.openModal();
                this.scrollToReviewWhenLoaded(id, 0);
            },

            scrollToReviewWhenLoaded(id, attempts) {
                var self = this;
                this.$nextTick(function () {
                    var el = self.$refs.modalScroll
                        ? self.$refs.modalScroll.querySelector('[data-review-id="' + id + '"]')
                        : null;
                    if (el) {
                        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        el.style.transition = 'background-color 0.6s ease';
                        el.style.backgroundColor = '#fef9c3';
                        setTimeout(function () { el.style.backgroundColor = ''; }, 1600);
                        return;
                    }
                    if (attempts < 25) {
                        setTimeout(function () { self.scrollToReviewWhenLoaded(id, attempts + 1); }, 150);
                    }
                });
            },

            setupInfiniteScroll() {
                var self = this;
                if (this.modalObserver || !this.$refs.modalSentinel || !this.$refs.modalScroll) return;
                this.modalObserver = new IntersectionObserver(function (entries) {
                    if (entries[0].isIntersecting) self.loadNextModalPage();
                }, { root: this.$refs.modalScroll, rootMargin: '200px' });
                this.modalObserver.observe(this.$refs.modalSentinel);
            },

            loadModalPage(page) {
                var self = this;
                this.modalLoadingMore = true;
                fetch('/api/rooms/' + this.roomId + '/ratings?page=' + page, { headers: this.authHeaders() })
                    .then(function (res) { return res.json(); })
                    .then(function (json) {
                        self.modalList = self.modalList.concat(json.data || []);
                        self.modalPage = json.meta.current_page;
                        self.modalLastPage = json.meta.last_page;
                    })
                    .finally(function () { self.modalLoadingMore = false; });
            },

            loadNextModalPage() {
                if (this.modalLoadingMore || this.modalPage >= this.modalLastPage) return;
                this.loadModalPage(this.modalPage + 1);
            },

            submitRating() {
                if (this.form.star === 0 || this.submitting) return;
                if (!localStorage.getItem('auth_token')) {
                    window.dispatchEvent(new CustomEvent('open-auth-modal'));
                    return;
                }
                var self = this;
                this.submitting = true;
                this.formError = '';
                fetch('/api/rooms/' + this.roomId + '/ratings', {
                    method: 'POST',
                    headers: Object.assign({ 'Content-Type': 'application/json' }, this.authHeaders()),
                    body: JSON.stringify({ star: this.form.star, comment: this.form.comment || null }),
                })
                    .then(function (res) {
                        return res.json().then(function (json) {
                            if (!res.ok) throw { status: res.status, body: json };
                            return json;
                        });
                    })
                    .then(function (json) {
                        self.myRatingId = json.rating.id;
                        self.modalList = [];
                        self.modalPage = 0;
                        self.reviewModalOpen = false;
                        self.fetchPreview();
                    })
                    .catch(function (err) {
                        if (self.handleAuthError(err)) return;
                        var body = err && err.body;
                        self.formError = (body && body.errors && body.errors.star && body.errors.star[0])
                            || (body && body.message)
                            || 'Có lỗi xảy ra, vui lòng thử lại.';
                    })
                    .finally(function () { self.submitting = false; });
            },

            // Token còn trong localStorage nhưng đã hết hạn/bị thu hồi phía server → API trả 401.
            // Trước đây lỗi này chỉ set formError (dễ bị bỏ sót nếu modal đã đóng), khiến người
            // dùng không hiểu vì sao thao tác thất bại. Giờ dọn auth cũ và mở lại modal đăng nhập,
            // giống hệt luồng "chưa đăng nhập" ở trên.
            handleAuthError(err) {
                if (!err || err.status !== 401) return false;
                localStorage.removeItem('auth_token');
                localStorage.removeItem('auth_user');
                this.isLoggedIn = false;
                window.dispatchEvent(new CustomEvent('auth-state-changed'));
                this.formError = 'Phiên đăng nhập đã hết hạn, vui lòng đăng nhập lại.';
                window.dispatchEvent(new CustomEvent('open-auth-modal'));
                return true;
            },

            deleteRating() {
                if (!localStorage.getItem('auth_token')) return;
                if (!confirm('Xoá đánh giá của bạn cho phòng này?')) return;
                var self = this;
                fetch('/api/rooms/' + this.roomId + '/ratings', {
                    method: 'DELETE',
                    headers: this.authHeaders(),
                })
                    .then(function (res) {
                        return res.json().then(function (json) {
                            if (!res.ok) throw { status: res.status, body: json };
                            return json;
                        });
                    })
                    .then(function () {
                        self.myRatingId = null;
                        self.form = { star: 0, comment: '' };
                        self.modalList = [];
                        self.modalPage = 0;
                        self.reviewModalOpen = false;
                        self.fetchPreview();
                    })
                    .catch(function (err) {
                        if (self.handleAuthError(err)) return;
                        self.formError = (err && err.body && err.body.message) || 'Có lỗi xảy ra, vui lòng thử lại.';
                    });
            },
        };
    }
</script>
