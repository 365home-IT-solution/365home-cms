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
                     class="w-6 h-6 {{ $i <= round($average) ? 'text-yellow-400' : 'text-gray-300' }}"
                     fill="currentColor">
                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.958a1 1 0 00.95.69h4.162c.969 0 1.371 1.24.588 1.81l-3.368 2.448a1 1 0 00-.363 1.118l1.287 3.957c.3.922-.755 1.688-1.538 1.118l-3.367-2.447a1 1 0 00-1.176 0l-3.367 2.447c-.783.57-1.838-.196-1.538-1.118l1.286-3.957a1 1 0 00-.363-1.118L2.063 9.385c-.783-.57-.38-1.81.588-1.81h4.162a1 1 0 00.95-.69l1.286-3.958z" />
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
