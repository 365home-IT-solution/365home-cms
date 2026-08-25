@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData"/>

@include('bladethemev1::styles.product-detail-styles')

@section('content')
    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')
    @livewire('bladethemev1::breadcrumb', ['slug' => $slug, 'name' => $name, 'parents' => $breadcrumbParents])
    @livewire('bladethemev1::product-detail', ['slug' => $slug])
    @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')
@endsection

@once
    @push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.documentElement.style.setProperty('--swiper-theme-color', @json($primaryColor));
        Fancybox.bind('[data-fancybox="gallerydetail"]', {
            loop: true,
            wheel: false,
            Toolbar: {
                display: {
                    left: [],
                    middle: ["prev", "infobar", "next"],
                    right: ["close"],
                },
            },
            closeButton: false,
        });

        const thumbSwiper = new Swiper(".thumbSwiper", {
            spaceBetween: 10,
            slidesPerView: 5,
            freeMode: true,
            watchSlidesProgress: true,
        });

        const mainSwiper = new Swiper(".mainSwiper", {
            spaceBetween: 10,
            navigation: {
                nextEl: ".swiper-button-next",
                prevEl: ".swiper-button-prev",
            },
            thumbs: {
                swiper: thumbSwiper,
            },
            autoplay: {
                delay: 3000,
                disableOnInteraction: false,
            },
        });

        const swiper = new Swiper(".product-same", {
            loop: true,
            slidesPerView: 1,
            spaceBetween: 20,
            navigation: {
                nextEl: ".swiper-button-next",
                prevEl: ".swiper-button-prev",
            },
            breakpoints: {
                360: {
                    slidesPerView: 1,
                    spaceBetween: 10
                },
                640: {
                    slidesPerView: 2,
                    spaceBetween: 10
                },
                768: {
                    slidesPerView: 2,
                    spaceBetween: 15
                },
                1024: {
                    slidesPerView: 3,
                    spaceBetween: 15
                },
                1280: {
                    slidesPerView: 4,
                    spaceBetween: 15
                }
            }
        });

        const tabs = document.querySelectorAll('[role="tab"]');
        const tabContents = document.querySelectorAll('[role="tabpanel"]');

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                tabs.forEach(t => {
                    t.classList.remove('bg-primary', 'text-white', 'border-primary');
                    t.classList.add('text-gray-800', 'hover:text-primary');
                    t.setAttribute('aria-selected', 'false');
                });

                tab.classList.add('bg-primary', 'text-white', 'border-primary');
                tab.classList.remove('text-gray-800', 'hover:text-primary');
                tab.setAttribute('aria-selected', 'true');

                tabContents.forEach(content => {
                    content.classList.add('hidden');
                });

                const tabContentId = tab.getAttribute('data-tabs-target').substring(1);
                const activeContent = document.getElementById(tabContentId);
                if (!activeContent) return;
                activeContent.classList.remove('hidden');

                const activeP = activeContent.querySelector('p');
                if (activeP) {
                    activeP.classList.remove('slide-down');
                    void activeP.offsetWidth;
                    activeP.classList.add('slide-down');
                }
            });
        });

        if (tabs.length > 0) tabs[0].click();

        const selectableCells = document.querySelectorAll('.selectable');
        selectableCells.forEach(cell => {
            cell.addEventListener('click', () => {
                document.querySelectorAll('.selectable.active').forEach(activeCell => {
                    activeCell.classList.remove('active');
                });
                cell.classList.add('active');
            });
        });

        document.querySelectorAll('.openModal').forEach(img => {
            img.addEventListener('click', (e) => {
                e.stopPropagation();
                const tuimuModal = document.getElementById('tuimuModal');
                if (tuimuModal) tuimuModal.classList.remove('hidden');
            });
        });
        const closeModalBtn = document.getElementById('closeModal');
        if (closeModalBtn) {
            closeModalBtn.addEventListener('click', () => {
                const tuimuModal = document.getElementById('tuimuModal');
                if (tuimuModal) tuimuModal.classList.add('hidden');
            });
        }
        const tuimuModal = document.getElementById('tuimuModal');
        if (tuimuModal) {
            tuimuModal.addEventListener('click', (e) => {
                if (e.target.id === 'tuimuModal') {
                    e.currentTarget.classList.add('hidden');
                }
            });
        }
    });
</script>
    @endpush
@endonce