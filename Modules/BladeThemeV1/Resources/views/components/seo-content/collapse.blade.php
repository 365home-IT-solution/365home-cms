{{-- Khối nội dung SEO dạng đóng/mở, cùng kiểu với từng câu của faq-accordion. Dùng <details> thuần
     (không Alpine): nội dung vẫn nằm sẵn trong HTML gốc cho crawler, chỉ thu gọn về mặt hiển thị.
     Tiêu đề giữ thẻ heading (mặc định h2) bên trong <summary> để không mất cấu trúc heading của trang. --}}
@props(['title', 'open' => false, 'tag' => 'h2'])

<details {{ $attributes->class('group rounded-2xl border border-gray-100 bg-white open:border-[rgba(var(--color-primary-rgb),0.3)] open:shadow-sm transition-colors') }} @if ($open) open @endif>
    <summary class="flex items-center justify-between gap-4 cursor-pointer list-none px-4 sm:px-5 py-4 select-none [&::-webkit-details-marker]:hidden">
        <{{ $tag }} class="text-base sm:text-lg font-bold text-gray-900">{{ $title }}</{{ $tag }}>
        <span class="shrink-0 w-8 h-8 rounded-full bg-gray-100 group-open:bg-[rgba(var(--color-primary-rgb),0.12)] flex items-center justify-center transition-colors">
            <svg class="w-4 h-4 text-gray-500 group-open:text-[var(--color-primary)] transition-transform duration-300 group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
            </svg>
        </span>
    </summary>
    <div class="px-4 sm:px-5 pb-5">
        {{ $slot }}
    </div>
</details>
