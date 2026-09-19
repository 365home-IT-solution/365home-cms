{{-- Lưới thẻ tính năng/mô tả dùng chung cho các khối nội dung SEO tĩnh (trang chủ, trang loại
     hình, trang chi tiết phòng) — mỗi item: ['title', 'text', 'icon' => key trong $pdIconPaths
     bên dưới, hoặc 'image' => đường dẫn asset() để dùng ảnh thật thay icon vẽ tay]. --}}
@props(['items' => [], 'title' => null, 'columns' => 4, 'showIcon' => true])

@php
    $pdIconPaths = [
        'home'     => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
        'clock'    => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
        'shield'   => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
        'device'   => 'M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z',
        'tag'      => 'M7 7h.01M7 3h5.586a1 1 0 01.707.293l6.414 6.414a1 1 0 010 1.414l-8.586 8.586a1 1 0 01-1.414 0l-6.414-6.414A1 1 0 013 12.586V7a4 4 0 014-4z',
        'card'     => 'M3 10h18M7 15h1m4 0h1m-7 4h12a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
        'calendar' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
    ];
    $pdColsClass = match ((int) $columns) {
        3 => 'sm:grid-cols-2 lg:grid-cols-3',
        2 => 'sm:grid-cols-2',
        default => 'sm:grid-cols-2 lg:grid-cols-4',
    };
@endphp

<div>
    @if ($title)
        <h2 class="text-2xl font-bold text-gray-900 mb-5">{{ $title }}</h2>
    @endif
    <div class="grid grid-cols-1 {{ $pdColsClass }} gap-4">
        @foreach ($items as $item)
            <div class="rounded-2xl border border-gray-100 bg-white p-5 hover:border-[rgba(var(--color-primary-rgb),0.25)] transition-colors duration-200">
                @if ($showIcon)
                    @if (!empty($item['image']))
                        <img src="{{ asset($item['image']) }}" alt="" width="44" height="44" loading="lazy"
                             class="w-11 h-11 object-contain mb-3">
                    @else
                        <div class="w-11 h-11 rounded-xl bg-[rgba(var(--color-primary-rgb),0.1)] flex items-center justify-center mb-3">
                            <svg class="w-5 h-5 text-[var(--color-primary)]" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.75">
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $pdIconPaths[$item['icon'] ?? 'home'] ?? $pdIconPaths['home'] }}"/>
                            </svg>
                        </div>
                    @endif
                @endif
                <h3 class="text-base font-semibold text-gray-900 mb-1">{{ $item['title'] }}</h3>
                <p class="text-sm text-gray-600 leading-relaxed">{{ $item['text'] }}</p>
            </div>
        @endforeach
    </div>
</div>
