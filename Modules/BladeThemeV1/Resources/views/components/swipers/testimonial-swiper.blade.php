<!-- resources/views/components/testimonial-slider.blade.php -->
<div class="testimonial-container relative overflow-hidden" data-aos="fade-up" data-aos-duration="1000">
    <div class="max-w-full mx-auto">
        <div class="testimonial-slider mx-6">
            <!-- Chia dữ liệu thành các nhóm 2 nội dung cho mỗi slide -->
            @php
                // Chia dữ liệu thành các nhóm 2 phần tử
                $testimonialChunks = array_chunk($testimonialData, 2);
            @endphp

            @foreach ($testimonialChunks as $chunk)
                <div class="px-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        @foreach ($chunk as $testimonial)
                            <div class="flex items-start space-x-4">
                                <div class="flex-shrink-0">
                                    @if (!empty($testimonial['image']) && is_array($testimonial['image']))
                                        @foreach ($testimonial['image'] as $image)
                                            <img src="{{ asset('storage/' . $image) }}" alt="{{ $testimonial['user_name'] }}" class="rounded-full w-20 h-20 object-cover" />
                                        @endforeach
                                    @else
                                        <img src="/api/placeholder/100/100" alt="{{ $testimonial['user_name'] }}" class="rounded-full w-20 h-20 object-cover" />
                                    @endif
                                </div>
                                <div class="flex-1">
                                    <h3 class="text-primary font-semibold text-lg">{{ strtoupper($testimonial['user_name']) }}</h3>
                                    <p class="text-primary font-medium text-sm mb-2">{{ $testimonial['role'] }}</p>
                                    <p class="text-gray-700 text-sm leading-snug">
                                        {!! nl2br($testimonial['content']) !!}
                                    </p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <!-- Navigation arrows -->
        <div class="navigation-wrapper relative h-12 mt-4">
            <button class="prev-arrow absolute left-0 bottom-0 text-primary hover:text-info z-10">
                <div class="arrow-container flex items-center justify-center w-10 h-10">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 12H3"></path>
                        <polyline points="9 18 3 12 9 6"></polyline>
                    </svg>
                </div>
            </button>
            <button class="next-arrow absolute right-0 bottom-0 text-primary hover:text-info z-10">
                <div class="arrow-container flex items-center justify-center w-10 h-10">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 12h18"></path>
                        <polyline points="15 6 21 12 15 18"></polyline>
                    </svg>
                </div>
            </button>
        </div>
    </div>

    <style>
        .testimonial-container {
            background-color: #ffffff;
            padding: 2rem 1.5rem;
            max-height: 340px;
            overflow: hidden;
        }

        .testimonial-slider .slick-slide {
            padding: 0.5rem;
            height: auto;
        }

        .testimonial-slider p {
            margin-bottom: 0;
            line-height: 1.4;
        }

        .arrow-container {
            transition: all 0.3s ease;
        }

        .prev-arrow:hover .arrow-container,
        .next-arrow:hover .arrow-container {
            transform: scale(1.1);
        }

        @media (max-width: 768px) {
            .testimonial-container {
                padding: 1.5rem 1rem;
                max-height: none;
            }

            .flex.items-start.space-x-4 {
                margin-bottom: 1.5rem;
            }
        }
    </style>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        $('.testimonial-slider').slick({
            infinite: true,
            slidesToShow: 1,
            slidesToScroll: 1,
            arrows: false,
            dots: false,
            fade: true,
            autoplay: true,
            autoplaySpeed: 5000,
            adaptiveHeight: true
        });

        document.querySelector('.prev-arrow').addEventListener('click', function() {
            $('.testimonial-slider').slick('slickPrev');
        });

        document.querySelector('.next-arrow').addEventListener('click', function() {
            $('.testimonial-slider').slick('slickNext');
        });
    });
</script>