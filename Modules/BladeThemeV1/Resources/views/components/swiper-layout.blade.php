<section>
    <div
            class="lazy-load-section {{ $config['heading'] || $config['heading_first_part'] || $config['heading_second_part'] || $config['heading_sub'] ? 'py-8' : '' }}"
            style="
            @if(!empty($config['background_image']))
                background-image: url('/storage/{{ $config['background_image'] ?? '' }}');
            @endif

            @if(!empty($config['background_color']))
                background-color: {{ $config['background_color'] ?? 'transparent' }};
            @endif
            background-size: cover;
            background-position: center;
        ">
        <div class="max-w-screen-xl mx-auto md:px-8 px-4">
            @if ($config['heading'] || $config['heading_first_part'] || $config['heading_second_part'] || $config['heading_sub'])
                @if ($config['heading'])
                    <div data-aos="fade-up"
                         data-aos-duration="500"
                         data-aos-delay="100" class="relative flex justify-between items-center">
                        <!-- Title with line after -->
                        <h2 class="inline-block ml-3 uppercase bg-sky-600 text-white text-xs md:text-lg font-bold px-5 py-2 skew-x-12 relative after:content-[''] after:absolute after:left-0 after:-bottom-2 after:w-[calc(100%-24px)] after:max-w-[500px] after:h-[2px] after:bg-sky-600 after:rounded-full">
                            {{$config['heading']}}
                        </h2>

                        <div
                                class="absolute right-[37px] -translate-x-4 top-[30px]">
                            <div class="flex md:flex">
                                <button id="slider-button-left-{{$loop->index}}"
                                        class="swiper-button-prev hidden sm:flex group !p-2 justify-center items-center rounded-lg border-2 border-sky-600  !w-[35px] !h-[35px] transition-all duration-500 bg-white hover:bg-sky-600 !-translate-x-9"
                                        data-carousel-prev>
                                    <svg class="h-5 w-5 text-sky-600  group-hover:text-white"
                                         xmlns="http://www.w3.org/2000/svg"
                                         width="16" height="16" viewBox="0 0 16 16" fill="none">
                                        <path d="M10.0002 11.9999L6 7.99971L10.0025 3.99719" stroke="currentColor"
                                              stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </button>

                                <button id="slider-button-right-{{$loop->index}}"
                                        class="swiper-button-next hidden sm:flex group !p-2 flex justify-center items-center rounded-lg border-2 border-sky-600 !w-[35px] !h-[35px] transition-all duration-500 bg-white hover:bg-sky-600 !translate-x-16"
                                        data-carousel-next>
                                    <svg class="h-5 w-5 text-sky-600 group-hover:text-white"
                                         xmlns="http://www.w3.org/2000/svg"
                                         width="16" height="16" viewBox="0 0 16 16" fill="none">
                                        <path d="M5.99984 4.00012L10 8.00029L5.99748 12.0028" stroke="currentColor"
                                              stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                @endif
            @endif
            <div
                    class="@hasSection('sidebar') flex flex-col md:flex-row gap-4 @endif">
                @hasSection('sidebar')
                    <div class="w-full md:w-1/4">
                        @yield('sidebar')
                    </div>
                @endif
                <div class="@hasSection('sidebar') md:w-3/4 @else w-full @endif">
                    {{ $slot }}
                </div>
            </div>
        </div>
    </div>
</section>
