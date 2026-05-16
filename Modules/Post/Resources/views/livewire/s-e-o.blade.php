<div>
    @php
        $manifest = json_decode(file_get_contents(public_path('build-post/manifest.json')), true);
        $cssFile = $manifest['poss.css']['file'] ?? 'assets/poss.6cc9d075.css';
    @endphp
    <link rel="stylesheet" href="{{ asset('build-post/' . $cssFile) }}">
    <div class="v-w-full">

        <div class="v-mb-6">
            <h3 class="v-text-md v-font-semibold v-mb-2">Điểm SEO: {{ number_format($score) }}/100</h3>
            <div class="v-w-full v-bg-gray-200 v-rounded-full v-h-2">
                <div class="v-h-2 v-rounded-full v-transition-all v-duration-500 v-ease-in-out
                {{ $score >= 80 ? 'v-bg-green-600' : ($score >= 60 ? 'v-bg-yellow-400' : 'v-bg-red-600') }}"
                    style="width: {{ $score }}%">
                </div>
            </div>
        </div>

        <div class="v-p-4 v-bg-gray-50 v-rounded-lg dark:v-bg-gray-900 v-mb-4 dark:v-bg-white/10 dark:v-ring-white/20">
            <h4 class="v-font-medium v-text-sm v-mb-2">Từ khóa</h4>
            <div class="flex v-flex v-flex-wrap v-gap-2">
                @if (!empty($focusKeyword))
                    @foreach ($focusKeyword as $index => $keyword)
                        @php
                            $isPrimary = $index === 0;
                            $keyword = trim($keyword);
                        @endphp
                        <div
                            class="v-inline-flex v-items-center v-gap-1.5 v-py-1.5 v-px-3 v-rounded-full v-text-xs v-font-medium
                        {{ $score >= 80
                            ? 'v-bg-green-100 v-text-green-700'
                            : ($score > 60
                                ? 'v-bg-yellow-100 v-text-yellow-700'
                                : 'v-bg-red-100 v-text-red-700') }}
                                {{ $isPrimary ? 'v-border-2 v-border-current' : '' }}">

                            @if ($isPrimary)
                                <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
                                    <path
                                        d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                </svg>
                            @endif

                            <span>{{ $keyword }}</span>

                            {{-- <div class="inline-flex items-center ml-2">
                            <!-- Hiển thị vị trí xuất hiện của từ khóa -->
                            <div class="flex gap-1">
                                @if (str_contains(strtolower($title), strtolower($keyword)))
                                    <span class="w-2 h-2 rounded-full {{ $isPrimary ? 'bg-current' : 'bg-current/60' }}"
                                          title="Xuất hiện trong tiêu đề"></span>
                                @endif
                                @if (str_contains(strtolower($description), strtolower($keyword)))
                                    <span class="w-2 h-2 rounded-full {{ $isPrimary ? 'bg-current' : 'bg-current/60' }}"
                                          title="Xuất hiện trong mô tả"></span>
                                @endif
                                @if (str_contains(strtolower($slug), strtolower($keyword)))
                                    <span class="w-2 h-2 rounded-full {{ $isPrimary ? 'bg-current' : 'bg-current/60' }}"
                                          title="Xuất hiện trong URL"></span>
                                @endif
                                @if (str_contains(strtolower(strip_tags($content)), strtolower($keyword)))
                                    <span class="w-2 h-2 rounded-full {{ $isPrimary ? 'bg-current' : 'bg-current/60' }}"
                                          title="Xuất hiện trong nội dung"></span>
                                @endif
                            </div>
    
                            <span class="ml-2 inline-flex items-center justify-center rounded-full bg-white/60 w-5 h-5 text-xs">
                                {{ round($keywordScore) }}
                            </span>
                        </div> --}}
                        </div>
                    @endforeach
                @else
                    <span class="v-text-sm v-text-gray-500">Chưa thiết lập từ khóa</span>
                @endif
            </div>

        </div>

        <!-- SEO Analysis Accordion -->
        <div x-data="{ openAnalysis: false }" class="v-border v-rounded-lg">
            <button type="button" @click="openAnalysis = !openAnalysis"
                class="v-w-full v-px-4 v-py-2 v-flex v-items-center v-justify-between hover:v-bg-gray-100 hover:v-rounded-lg dark:hover:v-bg-white/10 dark:v-ring-white/20">
                <div class="v-flex v-items-center v-gap-3">
                    <h4 class="v-text-sm v-font-medium">SEO cơ bản</h4>
                    @php
                        $errorCountErr = count(
                            array_filter($analyses, fn($analysis) => $analysis['status'] === 'error'),
                        );
                        $errorCountWar = count(
                            array_filter($analyses, fn($analysis) => $analysis['status'] === 'warning'),
                        );
                        $errorCountSuc = count(
                            array_filter($analyses, fn($analysis) => $analysis['status'] === 'success'),
                        );
                    @endphp
                    @if ($errorCountErr > 0)
                        <span class="v-px-2 v-py-1 v-text-xs v-font-medium v-rounded-full v-bg-red-100 v-text-red-700">
                            {{ $errorCountErr }} lỗi
                        </span>
                    @endif
                    @if ($errorCountWar > 0)
                        <span class="v-px-2 v-py-1 v-text-xs v-font-medium v-rounded-full v-bg-yellow-100 v-text-yellow-700">
                            {{ $errorCountWar }} cảnh báo
                        </span>
                    @endif
                    @if ($errorCountSuc === count($analyses))
                        <span class="v-px-2 v-py-1 v-text-xs v-font-medium v-rounded-full v-bg-green-100 v-text-green-700">
                            Tốt
                        </span>
                    @endif
                </div>
                <svg class="v-w-5 v-h-5 v-transform v-transition-transform" :class="{ 'v-rotate-180': openAnalysis }"
                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </button>

            <div x-show="openAnalysis" x-collapse class="v-border-t">
                <div class="v-p-4 v-space-y-4">
                    @foreach ($analyses as $key => $analysis)
                        <div
                            class="v-border v-rounded-lg v-p-2
                    {{ $analysis['status'] === 'success'
                        ? 'v-border-green-200 v-bg-green-50'
                        : ($analysis['status'] === 'warning'
                            ? 'v-border-yellow-200 v-bg-yellow-50'
                            : 'v-border-red-200 v-bg-red-50') }}">
                            <div class="v-flex v-items-center v-gap-3">
                                <div class="v-flex-shrink-0">
                                    @if ($analysis['status'] === 'success')
                                        <svg class="v-w-5 v-h-5 v-text-green-600" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M5 13l4 4L19 7"></path>
                                        </svg>
                                    @elseif($analysis['status'] === 'warning')
                                        <svg class="v-w-5 v-h-5 v-text-yellow-600" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z">
                                            </path>
                                        </svg>
                                    @else
                                        <svg class="v-w-5 v-h-5 v-text-red-600" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                    @endif
                                </div>
                                <div>
                                    <h4 class="v-text-sm v-font-medium">
                                        @switch($key)
                                            @case('title')
                                            @break

                                            @case('description')
                                            @break

                                            @case('url')
                                            @break

                                            @case('keywordPlacement')
                                            @break

                                            @case('contentLength')
                                            @break

                                            @default
                                                {{ ucfirst($key) }}
                                        @endswitch
                                    </h4>
                                    <p class="v-text-sm v-text-gray-600">{{ $analysis['message'] }}</p>
                                </div>
                            </div>
                            {{-- @if (isset($analysis['score']))
                            <div class="mt-3 flex items-center gap-2">
                                <div class="flex-1 h-1.5 bg-gray-200 rounded-full">
                                    <div class="h-1.5 rounded-full transition-all duration-300
                                        {{ $analysis['score'] >= 90 ? 'bg-green-600' : 
                                           ($analysis['score'] >= 70 ? 'bg-yellow-400' : 'bg-red-600') }}"
                                        style="width: {{ $analysis['score'] }}%">
                                    </div>
                                </div>
                                <span class="text-xs text-gray-500">{{ $analysis['score'] }}/100</span>
                            </div>
                        @endif --}}
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div x-data="{ openAdvanced: false }" class="v-border v-rounded-lg v-mt-2">
            <button type="button" @click="openAdvanced = !openAdvanced"
                class="v-w-full v-px-4 v-py-2 v-flex v-items-center v-justify-between hover:v-bg-gray-100 hover:v-rounded-lg dark:hover:v-bg-white/10 dark:v-ring-white/20">
                <div class="v-flex v-items-center v-gap-3">
                    <h4 class="v-text-sm v-font-medium">Bổ sung</h4>
                    @php
                        $advErrorCount = count(
                            array_filter($advancedAnalyses, fn($analysis) => $analysis['status'] === 'error'),
                        );
                        $advWarningCount = count(
                            array_filter($advancedAnalyses, fn($analysis) => $analysis['status'] === 'warning'),
                        );
                        $advSuccessCount = count(
                            array_filter($advancedAnalyses, fn($analysis) => $analysis['status'] === 'success'),
                        );
                    @endphp
                    @if ($advErrorCount > 0)
                        <span class="v-px-2 v-py-1 v-text-xs v-font-medium v-rounded-full v-bg-red-100 v-text-red-700">
                            {{ $advErrorCount }} lỗi
                        </span>
                    @endif
                    @if ($advWarningCount > 0)
                        <span class="v-px-2 v-py-1 v-text-xs v-font-medium v-rounded-full v-bg-yellow-100 v-text-yellow-700">
                            {{ $advWarningCount }} cảnh báo
                        </span>
                    @endif
                    @if ($advSuccessCount === count($advancedAnalyses))
                        <span class="v-px-2 v-py-1 v-text-xs v-font-medium v-rounded-full v-bg-green-100 v-text-green-700">
                            Tốt
                        </span>
                    @endif
                </div>
                <svg class="v-w-5 v-h-5 v-transform v-transition-transform" :class="{ 'v-rotate-180': openAdvanced }"
                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </button>

            <div x-show="openAdvanced" x-collapse class="v-border-t">
                <div class="v-p-4 v-space-y-4">
                    @foreach ($advancedAnalyses as $key => $analysis)
                        <div
                            class="v-border v-rounded-lg v-p-2
                        {{ $analysis['status'] === 'success'
                            ? 'v-border-green-200 v-bg-green-50'
                            : ($analysis['status'] === 'warning'
                                ? 'v-border-yellow-200 v-bg-yellow-50'
                                : 'v-border-red-200 v-bg-red-50') }}">
                            <div class="v-flex v-items-center v-gap-3">
                                <div class="v-flex-shrink-0">
                                    @if ($analysis['status'] === 'success')
                                        <svg class="v-w-5 v-h-5 v-text-green-600" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M5 13l4 4L19 7"></path>
                                        </svg>
                                    @elseif($analysis['status'] === 'warning')
                                        <svg class="v-w-5 v-h-5 v-text-yellow-600" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z">
                                            </path>
                                        </svg>
                                    @else
                                        <svg class="v-w-5 v-h-5 v-text-red-600" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                    @endif
                                </div>
                                <div>
                                    <p class="v-text-sm v-text-gray-600">{{ $analysis['message'] }}</p>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div x-data="{ openReadability: false }" class="v-border v-rounded-lg v-mt-2">
            <button type="button" @click="openReadability = !openReadability"
                class="v-w-full v-px-4 v-py-2 v-flex v-items-center v-justify-between hover:v-bg-gray-100 hover:v-rounded-lg dark:hover:v-bg-white/10 dark:v-ring-white/20">
                <div class="v-flex v-items-center v-gap-3 ">
                    <h4 class="v-text-sm v-font-medium">Nội dung</h4>
                    @php
                        $readErrorCount = count(
                            array_filter($readabilityAnalyses, fn($analysis) => $analysis['status'] === 'error'),
                        );
                        $readWarningCount = count(
                            array_filter($readabilityAnalyses, fn($analysis) => $analysis['status'] === 'warning'),
                        );
                        $readSuccessCount = count(
                            array_filter($readabilityAnalyses, fn($analysis) => $analysis['status'] === 'success'),
                        );
                    @endphp
                    @if ($readErrorCount > 0)
                        <span class="v-px-2 v-py-1 v-text-xs v-font-medium v-rounded-full v-bg-red-100 v-text-red-700">
                            {{ $readErrorCount }} lỗi
                        </span>
                    @endif
                    @if ($readWarningCount > 0)
                        <span class="v-px-2 v-py-1 v-text-xs v-font-medium v-rounded-full v-bg-yellow-100 v-text-yellow-700">
                            {{ $readWarningCount }} cảnh báo
                        </span>
                    @endif
                    @if ($readSuccessCount === count($readabilityAnalyses))
                        <span class="v-px-2 v-py-1 v-text-xs v-font-medium v-rounded-full v-bg-green-100 v-text-green-700">
                            Tốt
                        </span>
                    @endif
                </div>
                <svg class="v-w-5 v-h-5 v-transform v-transition-transform" :class="{ 'v-rotate-180': openReadability }"
                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </button>

            <div x-show="openReadability" x-collapse class="v-border-t">
                <div class="v-p-4 v-space-y-4">
                    @foreach ($readabilityAnalyses as $key => $analysis)
                        <div
                            class="v-border v-rounded-lg v-p-2
                        {{ $analysis['status'] === 'success'
                            ? 'v-border-green-200 v-bg-green-50'
                            : ($analysis['status'] === 'warning'
                                ? 'v-border-yellow-200 v-bg-yellow-50'
                                : 'v-border-red-200 v-bg-red-50') }}">
                            <div class="v-flex v-items-center v-gap-3">
                                <div class="flex-shrink-0">
                                    @if ($analysis['status'] === 'success')
                                        <svg class="v-w-5 v-h-5 v-text-green-600" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M5 13l4 4L19 7"></path>
                                        </svg>
                                    @elseif($analysis['status'] === 'warning')
                                        <svg class="v-w-5 v-h-5 v-text-yellow-600" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z">
                                            </path>
                                        </svg>
                                    @else
                                        <svg class="v-w-5 v-h-5 v-text-red-600" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                    @endif
                                </div>
                                <div>
                                    <p class="v-text-sm v-text-gray-600">{{ $analysis['message'] }}</p>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        @if (!empty($style))
            {!! $style !!}
        @endif

    </div>
    <style>
.v-text-gray-600 {
  color: #111827 !important;  /* black */
}

.v-text-gray-500 {
  color: #374151 !important;
}    
</style>
</div>

{{-- <script src="https://cdn.tailwindcss.com"></script> --}}
{{-- <script>

document.documentElement.classList.remove('dark');
document.documentElement.classList.add('light');
</script> --}}
{{-- [#111827] => gray-900 --}}
