<div class="group rounded-lg border border-gray-200 bg-white shadow-sm transition-all duration-300 hover:shadow-md dark:border-gray-700 dark:bg-gray-800">
    @php
        $routeName = $product->type === 'service' ? 'template.detail' : 'product.detail';
    @endphp

    <div class="relative w-full overflow-hidden rounded">
        <a href="{{ route($routeName, ['slug' => $product->slug]) }}" class="block">
            @if ($product->hasMedia('Ảnh bìa'))
                <!-- Tối ưu hiệu ứng scale để tránh rung lắc các phần tử khác -->
                <div class="overflow-hidden">
                    <img class="w-full cursor-pointer object-cover transition-all duration-700 ease-in-out group-hover:scale-105 group-hover:brightness-105 dark:hidden"
                         src="{{ $product->getFirstMedia('Ảnh bìa')->getUrl() }}"
                         alt="{{ $product->getFirstMedia('Ảnh bìa')->name ?? 'Product Image' }}">
                    <img class="hidden w-full cursor-pointer object-cover transition-all duration-700 ease-in-out group-hover:scale-105 group-hover:brightness-105 dark:block"
                         src="{{ $product->getFirstMedia('Ảnh bìa')->getUrl() }}"
                         alt="{{ $product->getFirstMedia('Ảnh bìa')->name ?? 'Product Image' }}">
                </div>
            @else
                <div class="flex h-48 w-full items-center justify-center bg-gray-200 dark:bg-gray-700">
                    <span class="text-gray-500 dark:text-gray-400">Không có hình ảnh</span>
                </div>
            @endif

            <!-- Hiệu ứng overlay tinh tế khi hover -->
            <div class="absolute inset-0 bg-black/10 opacity-0 transition-opacity duration-300 group-hover:opacity-100"></div>
        </a>
    </div>

    @if (!empty($category))
        <div class="px-3 pt-2">
            <span class="inline-block rounded bg-green-600 px-2 py-1 text-xs font-medium text-white transition-all duration-300 ease-out group-hover:bg-green-700">
                {{ Str::limit($category->name, 20) }}
            </span>
        </div>
    @endif

    <div class="p-3">
        <h3 class="mb-1 text-lg font-bold text-gray-900 transition-colors duration-300 ease-out group-hover:text-green-700 dark:text-white dark:group-hover:text-green-400">
            <a href="{{ route($routeName, ['slug' => $product->slug]) }}">
                {{ Str::limit($product->name, 40) }}
            </a>
        </h3>

        <div class="mb-3 flex items-center">
            @if ($originalPrice > 0)
                <span class="text-lg font-bold text-green-700 transition-all duration-300 ease-out dark:text-primary">
                    {{ number_format($discountPrice > 0 ? $discountPrice : $originalPrice, 0, ',', '.') }} đ
                </span>
                @if ($discountPrice > 0)
                    <span class="ml-2 text-sm font-medium text-gray-500 line-through dark:text-gray-400">
                        {{ number_format($originalPrice, 0, ',', '.') }} đ
                    </span>
                @endif
            @else
                <span class="text-lg font-bold text-primary dark:text-primary">Liên hệ</span>
            @endif
        </div>

        <div class="flex items-center gap-2">
            @if ($originalPrice > 0)
                <div class="flex-shrink-0">
                    @livewire('bladethemev1::button-cart-card', [
                    'product' => $product,
                    'class' => 'bg-green-700 text-white px-3 py-2 rounded hover:bg-green-800 transition-all duration-300 ease-out hover:shadow-sm md:text-sm text-xs'
                    ], key('cart1-'.$product->id))
                </div>
            @endif
            <a href="{{ route($routeName, ['slug' => $product->slug]) }}" class="flex-1">
                <x-bladethemev1::buttons.button :style="'1'" :text_size="'md:text-sm text-xs'" class="w-full justify-center transition-all duration-300 ease-out hover:shadow-sm">
                    Xem chi tiết
                </x-bladethemev1::buttons.button>
            </a>
        </div>
    </div>
</div>