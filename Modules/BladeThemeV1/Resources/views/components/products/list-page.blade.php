    <div class="flex max-sm:flex-col items-center sm:space-x-4 bg-white sm:p-4 p-2 rounded-lg shadow">
        @php
            $routeName = $product->type === 'service' ? 'template.detail' : 'product.detail';
        @endphp
        <div class="flex-grow flex max-sm:flex-col w-full sm:gap-x-4">
            @if ($product->hasMedia('Ảnh bìa'))
                <img class="w-full h-56 sm:w-32 sm:h-32 object-cover rounded"
                    src="{{ $product->getFirstMedia('Ảnh bìa')->getUrl() }}"
                    alt="{{ $product->getFirstMedia('Ảnh bìa')->name ?? 'Product Image' }}">
            @else
                <div class="w-full h-56 sm:w-32 sm:h-32 bg-gray-200 flex items-center justify-center rounded">
                    <span class="text-gray-500 text-center">Không có hình ảnh</span>
                </div>
            @endif
            <div>
                <h3 class="md:text-lg text-md font-semibold">
                    <a href="{{ route($routeName, ['slug' => $product->slug]) }}" class="hover:underline group-hover:text-primary">
                        {{ $product->name }}
                    </a>
                </h3>
                <p class="text-sm text-gray-600">{{ Str::limit($product->summary, 100) }}</p>
                <div class="mt-2 flex items-center gap-4">
                    <span class="text-sm text-gray-500">
                        <i class="fa-solid fa-layer-group mr-1"></i>
                        {{ Str::limit($product->categories->first()->name ?? 'Không có danh mục', 20) }}
                    </span>
                    @if ($product->discount_percentage > 0)
                        <span class="rounded bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800">
                            Giảm {{ $product->discount_percentage }}%
                        </span>
                    @endif
                </div>
                <div class="mt-2">
                    @if ($originalPrice > 0)
                        <span class="text-lg font-bold text-gray-900">
                            {{ number_format($discountPrice > 0 ? $discountPrice : $originalPrice, 0, ',', '.') }} đ
                        </span>
                        @if ($discountPrice > 0)
                            <span class="text-sm text-gray-500 line-through ml-2">
                                {{ number_format($originalPrice, 0, ',', '.') }} đ
                            </span>
                        @endif
                    @else
                        <span class="max-sm:hidden md:text-md text-sm font-bold">Liên hệ</span>
                    @endif
                </div>
            </div>
        </div>
        <div
            class="max-sm:w-full max-sm:flex justify-between items-center flex-shrink-0 sm:space-y-2 space-y-0 sm:space-x-0 space-x-2 max-sm:mt-2">
                <div class="max-sm:flex flex-shrink-0 sm:space-y-2 space-y-0 sm:space-x-0 space-x-2">
                    <a href="{{ route($routeName, ['slug' => $product->slug]) }}">
                        <x-bladethemev1::buttons.button :padding="'px-4 py-2'" :text_size="'md:text-md text-xs'" :style="'1'">
                            Xem chi tiết
                        </x-bladethemev1::buttons.button>
                    </a>
                    @if ($originalPrice > 0)
                        <div class="w-full">
                            @livewire('bladethemev1::button-cart-card', [
                            'product' => $product,
                            ], key('cart-'.$product->id))
                        </div>
                    @endif
                    <button @click="openModal('{{ $product->name }}')"
                            class="md:text-md text-xs block px-4 py-2 border primaryBorder colorPrimary rounded hover:bg-indigo-50 transition-colors duration-200">
                        Liên hệ ngay
                    </button>
                </div>
        </div>
    </div>
