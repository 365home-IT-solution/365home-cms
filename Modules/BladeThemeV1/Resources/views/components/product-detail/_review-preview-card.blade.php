{{--
 * 1 thẻ đánh giá trong lưới xem trước (Đánh giá). Include từ ratings.blade.php — bên trong 1
 * x-for="(r, idx) in ..." nên biến `r`/`idx` đã có sẵn trong scope Alpine khi include.
 * Biến nhận vào: $variant = 'mobile' (thẻ trong hàng cuộn ngang) | 'desktop' (thẻ trong cột
 * flex độc lập — không set width/snap/border vì mỗi cột tự co giãn theo nội dung riêng, không
 * bị kéo giãn theo hàng như khi dùng CSS Grid grid-flow-col).
 * Mobile: từ thẻ thứ 2 trở đi thêm pl-2 để nội dung không dính sát vào border-r của thẻ trước.
--}}
<div x-data="pdReviewCard()" x-init="checkOverflow($el)"
    @if ($variant === 'mobile')
        class="flex gap-3 min-w-0 shrink-0 w-[82%] snap-start pr-6 border-r border-gray-100"
        :class="idx > 0 ? 'pl-2' : ''"
    @else
        class="flex gap-3 min-w-0"
    @endif>
    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#222222] text-white text-sm font-semibold"
        x-text="(r.user_name || '?').charAt(0).toUpperCase()"></span>
    <div class="min-w-0 flex-1">
        <p class="text-sm font-semibold text-gray-900 truncate" x-text="r.user_name"></p>
        <div class="flex items-center gap-1.5 mt-0.5 text-xs text-gray-500">
            <div class="flex items-center gap-0.5">
                <template x-for="i in 5" :key="i">
                    <svg class="w-2.5 h-2.5" :fill="i <= r.star ? '#222' : '#e5e7eb'" viewBox="0 0 24 24">
                        <path
                            d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z">
                        </path>
                    </svg>
                </template>
            </div>
            <span>&middot;</span>
            <span x-text="pdFormatRelative(r.created_at)"></span>
        </div>
        <template x-if="r.comment">
            <div>
                <p class="pd-review-text text-sm text-gray-700 mt-1.5 break-words overflow-hidden line-clamp-4"
                    x-text="r.comment"></p>
                <button type="button" x-show="overflowing" x-cloak @click="openReviewInModal(r.id)"
                    class="mt-1 text-xs font-semibold text-gray-900 underline underline-offset-2">
                    Hiển thị thêm
                </button>
            </div>
        </template>
    </div>
</div>
