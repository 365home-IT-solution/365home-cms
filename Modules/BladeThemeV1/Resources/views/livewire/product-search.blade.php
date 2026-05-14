<div x-data="contactProduct()">
    <div class="">
        <!-- Search Result Header -->
        <div class="mb-8 bg-gradient-to-r from-gray-50 to-white p-8 rounded-xl">
            <div class="flex items-center space-x-2 mb-4">
                <svg class="w-6 h-6 text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <h2 class="text-3xl font-bold">Kết quả tìm kiếm</h2>
            </div>
            @if($search)
                <div class="text-gray-700 flex items-center">
                    <span class="text-lg">Tìm thấy <span class="font-semibold text-{{ $primaryColor }}">{{ $products->total() }}</span> kết quả cho từ khóa "<span class="font-medium">{{ $search }}</span>"</span>
                </div>
            @endif
        </div>

        <div class="flex flex-col lg:flex-row gap-4">
            <!-- Main Content -->
            <div class="w-full">
                @if (!$is_virtual_product)
                    <div class="grid gap-6 grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 mt-4">
                        @forelse ($products ?? [] as $product)
                            @php
                                $sku = $product->sku;
                                $originalPrice = $product->price;
                                $discountPrice = $product->discount;
                                $hasDiscount = $discountPrice > 0 && $discountPrice < $originalPrice;
                                $discountPercentage = $hasDiscount
                                    ? round((($originalPrice - $discountPrice) / $originalPrice) * 100)
                                    : 0;
                                $category = $product->categories ? $product->categories->first() : '';
                            @endphp
                            <div class="transform hover:-translate-y-1 transition duration-300">
                                <x-bladethemev1::products.card
                                    :product="$product"
                                    :category="$category"
                                    :has-discount="$hasDiscount"
                                    :discount-price="$discountPrice"
                                    :discount-percentage="$discountPercentage"
                                    :original-price="$originalPrice"
                                    :primaryColor="$primaryColor"
                                />
                            </div>
                        @empty
                            <div class="col-span-full flex flex-col items-center justify-center py-16 bg-gray-50 rounded-xl">
                                <svg class="w-16 h-16 text-gray-400 mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <p class="text-xl font-semibold text-gray-500">Không tìm thấy sản phẩm phù hợp</p>
                                <p class="text-gray-400 mt-2">Vui lòng thử lại với từ khóa khác</p>
                                <a href="/" class="mt-6 px-6 py-2 bg-primary text-white rounded-full hover:bg-opacity-90 transition">
                                    Quay lại trang chủ
                                </a>
                            </div>
                        @endforelse
                    </div>
                @else
                    <div class="grid gap-6 grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 mt-4">
                        @forelse ($products as $template)
                            <div class="transform hover:-translate-y-1 transition duration-300">
                                <div class="relative overflow-hidden border md:h-[400px] h-[350px] template_card shadow-lg rounded-xl">
                                    <a href="{{ route('template.detail', ['slug' => $template->slug]) }}">
                                        @if ($template->hasMedia('Ảnh bìa'))
                                            <img src="{{ $template->getFirstMedia('Ảnh bìa')->getUrl() }}"
                                                 alt="{{ $template->getFirstMedia('Ảnh bìa')->name ?? 'Product Image' }}"
                                                 loading="lazy"
                                                 class="w-full h-full object-cover transition duration-300">
                                        @else
                                            <div class="w-full h-full bg-gray-200 flex items-center justify-center">
                                                <span class="text-gray-500">No image available</span>
                                            </div>
                                        @endif
                                        <div class="absolute bottom-0 left-0 right-0 border-t text-center p-4 bg-white backdrop-blur-sm bg-opacity-90">
                                            <div class="text-lg font-semibold">
                                                {{ $template->name ?? 'Unnamed Product' }}
                                            </div>
                                        </div>
                                    </a>
                                </div>
                            </div>
                        @empty
                            <div class="col-span-full flex flex-col items-center justify-center py-16 bg-gray-50 rounded-xl">
                                <svg class="w-16 h-16 text-gray-400 mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <p class="text-xl font-semibold text-gray-500">Không tìm thấy sản phẩm phù hợp</p>
                                <p class="text-gray-400 mt-2">Vui lòng thử lại với từ khóa khác</p>
                                <a href="/" class="mt-6 px-6 py-2 bg-primary text-white rounded-full hover:bg-opacity-90 transition">
                                    Quay lại trang chủ
                                </a>
                            </div>
                        @endforelse
                    </div>
                @endif
            </div>
        </div>
        <x-bladethemev1::products.pagination :products="$products" />
    </div>
</div>
