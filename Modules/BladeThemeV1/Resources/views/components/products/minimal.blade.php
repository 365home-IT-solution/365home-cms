<div class="bg-white rounded overflow-hidden">
    @php
        $productUrl = \Modules\BladeThemeV1\Support\BranchBookConfig::resolveProductUrl($product);
    @endphp
    <a href="{{ $productUrl }}" class="block group">
        <div class="p-4">
            <div class="flex items-center justify-between mb-3">
                <div class="md:text-xl text-lg font-bold text-gray-800 group-hover:text-primary transition-colors duration-300">
                    {{ $product->name }}
                </div>
            </div>

            @if (!empty($category))
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <span class="text-sm text-black font-medium">Danh mục:</span>

                    @if (!empty(trim($category->name)))
                        <span class="bg-primary text-white text-xs font-medium px-2.5 py-0.5 rounded">
                            {{ $category->name }}
                        </span>
                    @endif
                </div>
            @endif

            <p class="text-gray-600 mb-4">
                {{ Str::limit(html_entity_decode(strip_tags($product->short_description)), 120, '...') }}
            </p>

            @if ($product->tags->isNotEmpty())
                <div class="mb-4">
                    <div class="inline-flex items-center">
                        <span class="text-sm text-black font-medium mr-2">Tags:</span>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($product->tags as $tag)
                                @if (!empty(trim($tag->name)))
                                    <span
                                        class="bg-{{ $primaryColor }}-500 text-white text-xs font-medium px-2.5 py-0.5 rounded-full">
                                        {{ $tag->name }}
                                    </span>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            <hr class="my-4 border-gray-200">

            <div class="flex flex-wrap justify-between items-center text-sm font-semibold text-gray-500">
                <span class="flex items-center">
                    <span class="me-4">Giá : </span>
                    @if ($originalPrice > 0)
                        <span
                            class="text-primary"> {{ number_format($discountPrice > 0 ? $discountPrice : $originalPrice, 0, ',', '.') }}
                            đ </span>
                        @if ($discountPrice > 0)
                            <span class="text-sm font-medium text-gray-500 line-through dark:text-gray-400 ml-4">
                                {{ number_format($originalPrice, 0, ',', '.') }} đ
                            </span>
                        @endif
                    @else
                        <span> Liên hệ</span>
                    @endif
                </span>
                <button
                    class="inline-flex items-center text-gray-800 font-medium group-hover:text-primary transition-colors duration-300">
                    <span>Xem chi tiết</span>
                    <svg class="w-4 h-4 ml-1" fill="currentColor" viewBox="0 0 20 20"
                        xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd"
                            d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                            clip-rule="evenodd" />
                    </svg>
                </button>
            </div>
        </div>
    </a>
</div>

<style>
    .hover-lift {
        transition: box-shadow 0.3s ease-in-out;
    }

</style>
