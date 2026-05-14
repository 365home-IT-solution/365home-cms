 <div class="swiper mainSwiper">
                                <div class="swiper-wrapper">
                                    @if ($product->hasMedia('Ảnh bìa'))
                                        <div class="swiper-slide">
                                            <a href="{{ $product->getFirstMedia('Ảnh bìa')->getUrl() }}"
                                               data-fancybox="gallerydetail">
                                                <img src="{{ $product->getFirstMedia('Ảnh bìa')->getUrl() }}"
                                                     loading="lazy"
                                                     alt="{{ $product->getFirstMedia('Ảnh bìa')->name ?? 'Ảnh bìa sản phẩm' }}"
                                                     class="h-full w-full object-cover">
                                            </a>
                                        </div>
                                    @endif

                                    @foreach ($mediaSecond as $imageSecond)
                                        <div class="swiper-slide">
                                            <img src="{{ $imageSecond->getUrl() }}" alt="{{ $product->name }}"
                                                 class="h-full w-full object-cover">
                                        </div>
                                    @endforeach
                                </div>
                                <div class="swiper-button-next"></div>
                                <div class="swiper-button-prev"></div>
                            </div>

                            <div class="swiper thumbSwiper mt-5">
                                <div class="swiper-wrapper">
                                    @if ($product->hasMedia('Ảnh bìa'))
                                        <div class="swiper-slide">
                                            <img src="{{ $product->getFirstMedia('Ảnh bìa')->getUrl() }}" loading="lazy"
                                                 alt="{{ $product->getFirstMedia('Ảnh bìa')->name ?? 'Ảnh bìa sản phẩm' }}"
                                                 class="object-cover rounded-lg cursor-pointer">
                                        </div>
                                    @endif

                                    @foreach ($mediaSecond as $imageSecond)
                                        <div class="swiper-slide">
                                            <img src="{{ $imageSecond->getUrl() }}" alt="{{ $product->name }}"
                                                 class="object-cover rounded-lg cursor-pointer">
                                        </div>
                                    @endforeach
                                </div>
                            </div>