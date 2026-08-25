<div
    class="relative bg-white dark:bg-gray-800 h-96 group rounded overflow-hidden shadow-lg hover:shadow-xl transition-shadow duration-300 ease-in-out">
    @php
        $productUrl = \Modules\BladeThemeV1\Support\BranchBookConfig::resolveProductUrl($product);
    @endphp
    <a href="{{ $productUrl }}" class="block h-full">
        @if ($product->hasMedia('Ảnh bìa'))
            @php $media = $product->media->first(); @endphp
            <img class="object-cover w-full h-full transition-transform duration-300 group-hover:scale-105"
                src="{{ $product->getFirstMedia('Ảnh bìa')->getUrl() }}"
                alt="{{ $product->getFirstMedia('Ảnh bìa')->name ?? 'Product Image' }}">
        @else
            <div class="flex h-full w-full items-center justify-center bg-gray-200 dark:bg-gray-700">
                <span class="text-gray-500 dark:text-gray-400">Không có hình ảnh</span>
            </div>
        @endif

        <div
            class="absolute inset-0 bg-gradient-to-t from-black/70 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300">
        </div>

        <div
            class="absolute bottom-0 left-0 right-0 p-4 transform translate-y-full group-hover:translate-y-0 transition-transform duration-300">

            <span class="rounded-md bg-primary bg-opacity-10 px-2 py-1 text-xs font-medium text-white">
                @if (!empty($category))
                    {{ Str::limit($category->name, 20) }}
                @else
                    Không có danh mục
                @endif
            </span>

            <h3 class="text-xl font-bold text-white mb-2 line-clamp-2 mt-4">
                {{ $product->name }}
            </h3>
            <p class="text-md text-gray-200 mb-3 line-clamp-3">
                {{ Str::limit(html_entity_decode(strip_tags($product->short_description)), 100, '...') }}
            </p>
            @if ($originalPrice > 0)
                <div class="flex items-center justify-between">
                    <span class="text-2xl font-bold text-primary">
                        {{ number_format($discountPrice > 0 ? $discountPrice : $originalPrice, 0, ',', '.') }} đ
                    </span>
                    @if ($discountPrice > 0)
                        <span class="text-xl font-medium text-gray-300 line-through">
                            {{ number_format($originalPrice, 0, ',', '.') }} đ
                        </span>
                    @endif
                </div>
            @else
                <span class="text-2xl font-bold text-primary">Liên hệ</span>
            @endif
        </div>
    </a>

    <div
        class="absolute top-4 right-4 flex space-x-2 opacity-0 group-hover:opacity-100 transition-opacity duration-300">

        @if ($originalPrice > 0)
            <div class="inline-block">
                @livewire('bladethemev1::button-cart-card', [
                'product' => $product,
                'class' => 'px-4 py-2 md:text-md text-xs'
                ], key('cart1-'.$product->id))
            </div>
        @endif

        @include('bladethemev1::components.buttons.tooltip-button', [
            'route' => $productUrl,
            'icon' => 'fa-eye',
            'tooltip' => 'Xem chi tiết',
            'classes' => 'bg-white text-gray-800 hover:bg-gray-100',
        ])
        @include('bladethemev1::components.buttons.tooltip-button', [
            'onclick' => "openModal('{$product->name}')",
            'icon' => 'fa-phone',
            'tooltip' => 'Liên hệ ngay',
            'classes' => 'bg-white text-gray-800 hover:bg-gray-100',
        ])
    </div>

    @if ($discountPrice > 0)
        <div class="absolute top-4 left-4 bg-primary text-white px-2 py-1 rounded-md text-xs font-bold">
            {{ round((($originalPrice - $discountPrice) / $originalPrice) * 100) }}% Giảm
        </div>
    @endif
</div>
