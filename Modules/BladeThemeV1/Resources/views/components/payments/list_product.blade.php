<div>
    <div class="space-y-2">
        @if ($cart && count($cart) > 0)
            @foreach ($cart as $itemId => $item)
                @php
                    $sku = $item['sku'] ?? '';
                    $originalPrice = $item['price'];
                    $discountPrice = $item['discount'];
                    $hasDiscount = $discountPrice > 0 && $discountPrice < $originalPrice;
                    $discountPercentage = $hasDiscount ? round((($originalPrice - $discountPrice) / $originalPrice) * 100) : 0;
                    $quantity = $item['quantity'];
                    $effectivePrice = $hasDiscount ? $discountPrice : $originalPrice;
                    $totalPrice = $effectivePrice * $quantity;
                @endphp
                        <!-- Sản phẩm 1 -->
                <div wire:loading.remove wire.target="updateQuantity"
                     class="flex gap-2 p-4 hover:bg-gray-50 rounded-xl border flex-col sm:flex-row">
                    @if ($item['image'])
                        <img src="{{ $item['image'] }}"
                             alt="{{ $item['image']['name'] ?? 'Ảnh bìa sản phẩm' }}"
                             class="w-20 h-20 object-cover rounded-lg mb-4 sm:mb-0"/>
                    @endif

                    <div class="flex-1">
                        <h3 class="font-medium text-blue-900">{{ $item['name'] }}</h3>
                        <div class="flex justify-between items-start mt-4 flex-col sm:flex-row w-full gap-3 sm:gap-0">
                            @if ($originalPrice > 0)
                                <div class="flex flex-col sm:flex-row items-start sm:items-center space-y-2 sm:space-y-0 sm:space-x-2 w-full sm:w-auto">
                <span class="text-gray-900 text-md">
                    {{ $hasDiscount ? 'Giá khuyến mãi:' : 'Giá niêm yết:' }}
                </span>
                                    <div class="flex flex-wrap items-center gap-2">
                    <span class="text-red-500 text-md font-bold">
                        {{ number_format($effectivePrice, 0, ',', '.') }} đ
                    </span>

                                        @if ($hasDiscount)
                                            <span class="text-gray-500 line-through text-sm">
                            {{ number_format($originalPrice, 0, ',', '.') }} đ
                        </span>
                                        @endif

                                        @if ($quantity > 1)
                                            <div class="flex items-center gap-1">
                                                <span class="text-gray-800 text-sm">x {{ $quantity }} =</span>
                                                <span class="font-semibold text-md text-red-500">
                                {{ number_format($totalPrice, 0, ',', '.') }} đ
                            </span>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            <div class="flex items-center gap-2 text-emerald-600 bg-emerald-50 px-3 py-1.5 rounded-lg w-full sm:w-fit">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                </svg>
                                <span class="font-medium">Số lượng:</span>
                                <span class="font-bold">{{ $item['quantity'] }}</span>
                            </div>
                        </div>
                    </div>

                </div>
            @endforeach
            <div wire:loading class="space-y-2 w-full">
                <!-- Product Card Skeleton -->
                <div class="flex gap-4 p-4 bg-white rounded-xl border flex-col sm:flex-row">
                    <!-- Image skeleton -->
                    <div class="skeleton w-20 h-20 rounded-lg"></div>

                    <div class="flex-1 space-y-4">
                        <!-- Title skeleton -->
                        <div class="skeleton h-6 w-3/4 rounded"></div>

                        <!-- Price section skeleton -->
                        <div class="flex flex-col sm:flex-row justify-between items-start mt-4 w-full gap-3">
                            <div class="space-y-2 w-full sm:w-2/3">
                                <!-- Price label skeleton -->
                                <div class="skeleton h-5 w-32 rounded"></div>
                                <!-- Price amount skeleton -->
                                <div class="flex items-center gap-2">
                                    <div class="skeleton h-6 w-24 rounded"></div>
                                    <div class="skeleton h-5 w-20 rounded"></div>
                                </div>
                            </div>

                            <!-- Quantity section skeleton -->
                            <div class="skeleton h-10 w-full sm:w-36 rounded-lg"></div>
                        </div>
                    </div>
                </div>

                <!-- Repeat skeleton cards -->
                <div class="flex gap-4 p-4 bg-white rounded-xl border flex-col sm:flex-row">
                    <div class="skeleton w-20 h-20 rounded-lg"></div>
                    <div class="flex-1 space-y-4">
                        <div class="skeleton h-6 w-3/4 rounded"></div>
                        <div class="flex flex-col sm:flex-row justify-between items-start mt-4 w-full gap-3">
                            <div class="space-y-2 w-full sm:w-2/3">
                                <div class="skeleton h-5 w-32 rounded"></div>
                                <div class="flex items-center gap-2">
                                    <div class="skeleton h-6 w-24 rounded"></div>
                                    <div class="skeleton h-5 w-20 rounded"></div>
                                </div>
                            </div>
                            <div class="skeleton h-10 w-full sm:w-36 rounded-lg"></div>
                        </div>
                    </div>
                </div>
            </div>
        @else
            <div class="flex flex-col items-center justify-center p-8 mx-auto max-w-md border-2 border-dashed border-black rounded-lg bg-white">
                <!-- Icon container with background circle -->
                <div class="relative mb-6">
                    <div class="absolute inset-0 bg-red-100 rounded-full transform -translate-x-1 translate-y-1"></div>
                    <div class="relative bg-white p-4 rounded-full shadow-md">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                    </div>
                </div>

                <!-- Message -->
                <div class="text-center space-y-3">
                    <h3 class="text-lg font-semibold text-red-800">
                        Vui lòng mua hàng để thanh toán!
                    </h3>
                    <p class="text-red-500 text-sm">
                        Hãy thêm sản phẩm vào giỏ hàng để tiến hành thanh toán
                    </p>
                </div>

                <!-- Button with enhanced styling -->
                <a href="{{ config('app.url') }}/san-pham"
                   class="mt-6 inline-flex items-center px-6 py-3 rounded-full bg-black text-white font-medium hover:bg-gray-800 transform hover:-translate-y-0.5 transition-all duration-200 shadow-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z" clip-rule="evenodd"/>
                    </svg>
                    Mua sắm ngay
                </a>
            </div>
        @endif
    </div>
</div>