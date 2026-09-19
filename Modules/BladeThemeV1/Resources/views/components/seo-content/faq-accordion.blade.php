{{-- Accordion FAQ dùng chung cho các khối nội dung SEO tĩnh — items: [['q' => ..., 'a' => ...]].
     $schema=true (mặc định) phát thêm JSON-LD FAQPage cho khối này. --}}
@props(['items' => [], 'title' => 'Câu hỏi thường gặp', 'schema' => true])

<div>
    <h2 class="text-2xl font-bold text-gray-900 mb-5">{{ $title }}</h2>
    <div class="space-y-3 max-w-3xl">
        @foreach ($items as $item)
            <details class="group rounded-2xl border border-gray-100 bg-white open:border-[rgba(var(--color-primary-rgb),0.3)] open:shadow-sm transition-colors">
                <summary class="flex items-center justify-between gap-4 cursor-pointer list-none px-5 py-4 select-none">
                    <span class="text-base font-semibold text-gray-900">{{ $item['q'] }}</span>
                    <span class="shrink-0 w-8 h-8 rounded-full bg-gray-100 group-open:bg-[rgba(var(--color-primary-rgb),0.12)] flex items-center justify-center transition-colors">
                        <svg class="w-4 h-4 text-gray-500 group-open:text-[var(--color-primary)] transition-transform duration-300 group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </span>
                </summary>
                <p class="px-5 pb-4 -mt-1 text-sm text-gray-600 leading-relaxed">{{ $item['a'] }}</p>
            </details>
        @endforeach
    </div>

    @if ($schema)
        @php
            $faqSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => array_map(fn ($item) => [
                    '@type' => 'Question',
                    'name' => $item['q'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $item['a'],
                    ],
                ], $items),
            ];
        @endphp
        <script type="application/ld+json">{!! json_encode($faqSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}</script>
    @endif
</div>
