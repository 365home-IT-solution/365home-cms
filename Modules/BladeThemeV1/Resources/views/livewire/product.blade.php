<div x-data="contactProduct()">
    <div class="flex flex-col lg:flex-row gap-4 mt-5">
        <x-bladethemev1::products.category-menu :filteredCategories="$filteredCategories" :primaryColor="$primaryColor" :sortBy="$sortBy" />
        <div class="w-full lg:w-9/12">
            <x-bladethemev1::products.search-and-sort :sortBy="$sortBy" :is_virtual_product="$is_virtual_product" :viewStyle="$viewStyle" />
            @if (!$is_virtual_product)
                <div x-data="{ viewStyle: @entangle('viewStyle') }">
                    <!-- Grid View -->
                    <div x-show="viewStyle === 'grid'"
                        class="grid gap-4 grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-3">
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
                            <x-bladethemev1::products.card :product="$product" :category="$category" :has-discount="$hasDiscount"
                                :discount-price="$discountPrice" :discount-percentage="$discountPercentage" :original-price="$originalPrice" :primaryColor="$primaryColor" />
                        @empty
                            <div class="col-span-full text-center py-10">
                                <p class="text-gray-500">Không có sản phẩm</p>
                            </div>
                        @endforelse
                    </div>

                    <!-- List View -->
                    <div x-show="viewStyle === 'list'" class="space-y-4">
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
                            <x-bladethemev1::products.list-page :product="$product" :category="$category" :has-discount="$hasDiscount"
                                :discount-price="$discountPrice" :discount-percentage="$discountPercentage" :original-price="$originalPrice" :primaryColor="$primaryColor" />
                        @empty
                            <div class="col-span-full text-center py-10">
                                <p class="text-gray-500">Không có sản phẩm</p>
                            </div>
                        @endforelse
                    </div>
                </div>
            @else
                <div class="grid gap-4 grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-3">
                    @foreach ($products as $template)
                        <div
                            class="relative overflow-hidden border md:h-[400px] h-[350px] template_card cursor-pointer shadow-lg">
                            <a href="{{ route('template.detail', ['slug' => $template->slug]) }}">
                                @if ($template->hasMedia('Ảnh bìa'))
                                    <img src="{{ $template->getFirstMedia('Ảnh bìa')->getUrl() }}"
                                        alt="{{ $template->getFirstMedia('Ảnh bìa')->name ?? 'Product Image' }}"
                                        loading="lazy"
                                        style="transition-duration:8000ms" class="w-full h-auto">
                                @else
                                    <div class="w-full h-64 bg-gray-200 flex items-center justify-center">
                                        <span class="text-gray-500">No image available</span>
                                    </div>
                                @endif
                                <div class="absolute bottom-0 left-0 right-0 border-t text-center p-4 bg-white">
                                    <div class="text-md md:text-lg font-semibold">
                                        {{ $template->name ?? 'Unnamed Product' }}</div>
                                </div>
                            </a>
                        </div>
                    @endforeach
                </div>
            @endif
            <x-bladethemev1::products.pagination :products="$products" />
        </div>
    </div>
</div>
