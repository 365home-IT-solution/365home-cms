<div x-data="productContactGrid()">
    @if ($show_category_filter)
        <div class="mb-4 flex justify-center items-center">
            <div id="slide-navtab-{{ $uniqueId }}"
                class="sliderTab w-[400px] overflow-hidden relative p-2 bg-gray-100 shadow-xl rounded-full"
                data-tabs-toggle="#{{ $uniqueId }}" role="tablist"
                data-tabs-active-classes="bg-primary text-white active">
                <div class="swiper-wrapper space-x-3">
                    @foreach ($products as $index => $category)
                        <div class="swiper-slide !w-auto" role="presentation">
                            <div class="block">
                                <a href='javascript:void(0);'
                                    data-tabs-target="#navtab-{{ $uniqueId }}-{{ $index }}" type="button"
                                    role="tab" aria-controls="tab-{{ $index }}" aria-selected="false"
                                    class="leading-tight inline-block whitespace-nowrap hover:shadow-lg md:px-6 px-3 md:py-3 py-2 hover:bg-primary hover:text-white ease-in-out font-semibold transition-all duration-300 rounded-full z-10 relative text-xs lg:text-sm">
                                    {{ $category['category_name'] }}
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <div id="{{ $uniqueId }}">
        @if (!$show_category_filter)
            <div
                class="grid grid-cols-1 xl:grid-cols-{{ $column }}
                                lg:grid-cols-{{ min(4, $column) }}
                                md:grid-cols-{{ min(3, $column) }}
                                sm:grid-cols-{{ min(2, $column) }} gap-4">
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

                    <div
                        class="group relative overflow-hidden shadow-md hover:shadow-lg rounded transition-transform duration-300 ease-in-out hover:-translate-y-1">
                        @switch($config['style'] ?? 'default')
                            @case('overlay')
                                <x-bladethemev1::products.overlay :product="$product" :category="$category"
                                    :has-discount="$hasDiscount" :discount-price="$discountPrice" :discount-percentage="$discountPercentage" :original-price="$originalPrice" :primaryColor="$primaryColor"
                                    :isBottom="true" />
                            @break

                            @case('card')
                                <x-bladethemev1::products.card :product="$product" :category="$category"
                                    :has-discount="$hasDiscount" :discount-price="$discountPrice" :discount-percentage="$discountPercentage" :original-price="$originalPrice"
                                    :primaryColor="$primaryColor" />
                            @break

                            @case('minimal')
                                <x-bladethemev1::products.minimal :product="$product" :category="$category"
                                    :has-discount="$hasDiscount" :discount-price="$discountPrice" :original-price="$originalPrice" :primaryColor="$primaryColor" />
                            @break

                            @default
                                <x-bladethemev1::products.card :product="$product" :category="$category"
                                    :has-discount="$hasDiscount" :discount-price="$discountPrice" :discount-percentage="$discountPercentage" :original-price="$originalPrice"
                                    :primaryColor="$primaryColor" />
                        @endswitch
                    </div>
                    @empty
                        <div class="col-span-full text-center py-10">
                            <p class="text-gray-500">Không có sản phẩm</p>
                        </div>
                    @endforelse
                </div>
            @else
                @foreach ($products as $index => $category)
                    <div id="navtab-{{ $uniqueId }}-{{ $index }}">
                        <div
                            class="grid grid-cols-1 xl:grid-cols-{{ $column }}
                                        lg:grid-cols-{{ min(4, $column) }}
                                        md:grid-cols-{{ min(3, $column) }}
                                        sm:grid-cols-{{ min(2, $column) }} gap-4">
                            @foreach ($category['products'] as $product)
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
                                <div
                                    class="group relative overflow-hidden shadow-md hover:shadow-lg rounded transition-transform duration-300 ease-in-out hover:-translate-y-1">
                                    @switch($config['style'] ?? 'default')
                                        @case('overlay')
                                            <x-bladethemev1::products.overlay :product="$product" :category="$category"
                                                :has-discount="$hasDiscount" :discount-price="$discountPrice" :discount-percentage="$discountPercentage" :original-price="$originalPrice"
                                                :primaryColor="$primaryColor" :isBottom="true" />
                                        @break

                                        @case('card')
                                            <x-bladethemev1::products.card :product="$product" :category="$category"
                                                :has-discount="$hasDiscount" :discount-price="$discountPrice" :discount-percentage="$discountPercentage" :original-price="$originalPrice"
                                                :primaryColor="$primaryColor" />
                                        @break

                                        @case('minimal')
                                            <x-bladethemev1::products.minimal :product="$product" :category="$category"
                                                :has-discount="$hasDiscount" :discount-price="$discountPrice" :original-price="$originalPrice" :primaryColor="$primaryColor" />
                                        @break

                                        @default
                                            <x-bladethemev1::products.card :product="$product" :category="$category"
                                                :has-discount="$hasDiscount" :discount-price="$discountPrice" :discount-percentage="$discountPercentage" :original-price="$originalPrice"
                                                :primaryColor="$primaryColor" />
                                    @endswitch
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            @endif

        </div>

        <div x-show="isModalOpen" x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 transform scale-90" x-transition:enter-end="opacity-100 transform scale-100"
            x-transition:leave="transition ease-in duration-300" x-transition:leave-start="opacity-100 transform scale-100"
            x-transition:leave-end="opacity-0 transform scale-90" x-on:click.away="closeModal()" x-cloak
            class="fixed inset-0 z-50 overflow-hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex items-end justify-center h-full pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div x-show="isModalOpen" x-transition:enter="transition ease-out duration-300"
                    x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                    x-transition:leave="transition ease-in duration-300" x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0" class="fixed inset-0 bg-white bg-opacity-30 transition-opacity"
                    aria-hidden="true"></div>

                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                @livewire('bladethemev1::form', ['form' => $form, 'name' => $selectedPlanName])
            </div>
        </div>
    </div>
    <script>
        function productContactGrid() {
            return {
                isModalOpen: false,
                selectedCategory: 'all',
                selectedPlanName: '',
                openModal(planName) {
                    this.selectedPlanName = planName;
                    this.isModalOpen = true;
                    Livewire.dispatch('setNameForm', {
                        name: planName
                    });
                },
                closeModal() {
                    this.isModalOpen = false;
                    Livewire.dispatch('resetForm');
                },
                selectCategory(categoryId) {
                    this.selectedCategory = categoryId;
                    // Dispatch Livewire event to filter products
                    Livewire.dispatch('filterProducts', {
                        categoryId: categoryId
                    });
                }
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const tabSwiper = new Swiper("#slide-navtab-{{ $uniqueId }}", {
                slidesPerView: 'auto', // Hiển thị số lượng slide tự động
                freeMode: true, // Cho phép kéo tự do
            });
        })
    </script>
