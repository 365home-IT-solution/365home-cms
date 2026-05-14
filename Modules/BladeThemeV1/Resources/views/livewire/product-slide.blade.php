<div x-data="productContact()">
    <div class="swiper {{ $uniqueId }}" id="{{ $uniqueId }}">
        <div class="swiper-wrapper flex">
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
                <div class="swiper-slide group relative overflow-hidden rounded group shadow-md hover:shadow-lg">
                    @switch($breakpointConfig['style'])
                        @case('overlay')
                            <x-bladethemev1::products.overlay :product="$product" :category="$category" :has-discount="$hasDiscount"
                                :discount-price="$discountPrice" :discount-percentage="$discountPercentage" :original-price="$originalPrice" :primaryColor="$primaryColor" :isBottom="true" />
                        @break

                        @case('card')
                            <x-bladethemev1::products.card :product="$product" :category="$category" :has-discount="$hasDiscount" :discount-price="$discountPrice"
                                :discount-percentage="$discountPercentage" :original-price="$originalPrice" :primaryColor="$primaryColor" />
                        @break

                        @case('minimal')
                            <x-bladethemev1::products.minimal :product="$product" :category="$category" :has-discount="$hasDiscount"
                                :discount-price="$discountPrice" :original-price="$originalPrice" :primaryColor="$primaryColor" />
                        @break

                        @default
                            <x-bladethemev1::products.card :product="$product" :category="$category" :has-discount="$hasDiscount"
                                :discount-price="$discountPrice" :discount-percentage="$discountPercentage" :original-price="$originalPrice" :primaryColor="$primaryColor" />
                    @endswitch
                </div>
                @empty
                    <div class="col-span-full text-center py-10">
                        <p class="text-gray-500">Không có sản phẩm</p>
                    </div>
                @endforelse

            </div>

            @if ($breakpointConfig['show_pagination'])
                <div class="swiper-pagination"></div>
            @endif

            @if ($breakpointConfig['show_navigation'])
                <div class="swiper-button-next"></div>
                <div class="swiper-button-prev"></div>
            @endif

            <script>
                document.addEventListener('DOMContentLoaded', () => {
                    if (document.getElementById('{{ $uniqueId }}')) {
                        let swiperConfig = {
                            loop: true,
                            slidesPerView: 1,
                            spaceBetween: {{ $breakpointConfig['space_between'] }},
                            pagination: {
                                el: '.{{ $uniqueId }} .swiper-pagination',
                                clickable: true,
                            },
                            navigation: {
                                nextEl: '.{{ $uniqueId }} .swiper-button-next',
                                prevEl: '.{{ $uniqueId }} .swiper-button-prev',
                            },
                            breakpoints: {
                                640: {
                                    slidesPerView: {{ $this->calculateColumns()['sm'] }},
                                },
                                768: {
                                    slidesPerView: {{ $this->calculateColumns()['md'] }},
                                },
                                1024: {
                                    slidesPerView: {{ $this->calculateColumns()['lg'] }},
                                },
                            },
                        }

                        if ({{ $breakpointConfig['autoplay_speed'] }} >= 1000) {
                            swiperConfig.autoplay = {
                                delay: {{ $breakpointConfig['autoplay_speed'] }},
                                disableOnInteraction: false,
                            };
                            swiperConfig.loop = true;
                        }

                        const swiper = new Swiper("#{{ $uniqueId }}", swiperConfig);
                    }
                });

                function productContact() {
                    return {
                        isModalOpen: false,
                        planName: '',
                        openModal(planName) {
                            this.planName = planName;
                            this.isModalOpen = true;
                            Livewire.dispatch('setNameForm', {
                                name: planName
                            });
                        },
                        closeModal() {
                            this.isModalOpen = false;
                            Livewire.dispatch('resetForm');
                        },
                    }
                }
            </script>
        </div>

        {{-- Modal Form --}}
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
