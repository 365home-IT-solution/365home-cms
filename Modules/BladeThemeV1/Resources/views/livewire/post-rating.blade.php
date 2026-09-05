<div class="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
    <span class="text-xs sm:text-sm font-semibold text-gray-700 whitespace-nowrap">Đánh giá:</span>

    <div class="flex items-center -space-x-1" role="group" aria-label="Chấm điểm bài viết">
        @for ($i = 1; $i <= 5; $i++)
            <button
                type="button"
                wire:click="vote({{ $i }})"
                wire:key="post-rating-star-{{ $i }}"
                title="Đánh giá {{ $i }} sao"
                class="p-0.5 rounded-full focus:outline-none"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                     class="w-6 h-6 {{ $i <= $userRating ? 'text-yellow-400' : 'text-gray-300' }}"
                     fill="currentColor">
                    <use href="#i-star" />
                </svg>
            </button>
        @endfor
    </div>

    <span class="text-xs sm:text-sm text-gray-600 whitespace-nowrap">
        {{ number_format($average, 1) }}/5
        @if($count > 0)
            - ({{ $count }} bình chọn)
        @else
            (Chưa có đánh giá)
        @endif
    </span>
</div>
