<div
        x-data="popupManager(@entangle('isVisible'))"
        x-init="init()"
        @keydown.escape.window="handleEscape()"
        class="popup-wrapper"
        x-cloak
>
    {{-- Overlay --}}
    @if($config['overlay'] ?? true)
        <div
                x-show="show"
                x-transition:enter="transition-opacity duration-300"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition-opacity duration-300"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                @if($config['close_on_overlay_click'] ?? true)
                    @click="closePopup()"
                @endif
                class="fixed inset-0 flex items-center justify-center"
                style="z-index: 100;background-color: {{ $config['overlay_color'] ?? '#000000' }}; opacity: {{ ($config['overlay_opacity'] ?? 50) / 100 }};"
        ></div>
    @endif

    {{-- Popup Container --}}
    <div
            x-show="show"
            x-transition:enter="transition-all duration-500 ease-out"
            x-transition:enter-start="opacity-0 {{ $animationClass }}-start"
            x-transition:enter-end="opacity-100 {{ $animationClass }}-end"
            x-transition:leave="transition-all duration-300 ease-in"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
            style="pointer-events: none;"
    >
        <div
                class="popup-content relative {{ $sizeClass }} w-full rounded-lg shadow-2xl overflow-hidden"
                style="{{ $backgroundStyle }} {{ $customDimensions }} border-radius: {{ $config['border_radius'] ?? 16 }}px; pointer-events: auto;"
        >
            {{-- Close Button --}}
            @if($config['show_close_button'] ?? true)
                <button
                        @click.stop="closePopup()"
                        class="absolute top-4 right-4 z-10 w-10 h-10 flex items-center justify-center rounded-full bg-black/20 hover:bg-black/40 backdrop-blur-sm transition-all duration-200 group"
                        aria-label="Close popup"
                >
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            @endif

            {{-- Popup Content --}}
            <div class="popup-inner p-8 md:p-12 relative">
                {{-- Content Image với Skeleton Loading --}}
                @if(!empty($config['content_image']))
                    <link rel="preload" as="image" href="{{ asset('storage/' . $config['content_image']) }}">
                    <div class="mb-6 flex justify-center" x-data="{ imageLoaded: false }">
                        {{-- Skeleton Loading --}}
                        <div
                                x-show="!imageLoaded"
                                x-transition:enter="transition-opacity duration-200"
                                x-transition:enter-start="opacity-100"
                                x-transition:enter-end="opacity-100"
                                x-transition:leave="transition-opacity duration-300"
                                x-transition:leave-start="opacity-100"
                                x-transition:leave-end="opacity-0"
                                class="skeleton-loader rounded-lg"
                                style="width: 100%; max-width: 600px; height: 300px;"
                        >
                            <div class="skeleton-shimmer"></div>
                        </div>

                       
                    </div>
                @endif

                {{-- Title --}}
                @if(!empty($config['title']))
                    <h2
                            class="{{ $config['title_size'] ?? 'text-2xl' }} {{ $config['title_weight'] ?? 'font-bold' }} text-center mb-4"
                            style="color: {{ $config['title_color'] ?? '#1f2937' }};"
                    >
                        {{ $config['title'] }}
                    </h2>
                @endif

                {{-- Content --}}
                @if(!empty($config['content']))
                    <div
                            class="prose prose-lg max-w-none text-center mb-6"
                            style="color: {{ $config['content_color'] ?? '#6b7280' }};"
                    >
                        {!! $config['content'] !!}
                    </div>
                @endif

                {{-- Buttons --}}
                @if(!empty($config['buttons']) && is_array($config['buttons']))
                    <div class="flex flex-wrap gap-4 justify-center mt-8">
                        @foreach($config['buttons'] as $index => $button)
                            <button
                                    @if(!empty($button['url']))
                                        wire:click="handleButtonClick({{ $index }})"
                                    @else
                                        @click.stop="closePopup()"
                                    @endif
                                    class="popup-button {{ $this->getButtonSizeClass($button) }} rounded-lg font-semibold transition-all duration-300 hover:shadow-lg hover:scale-105 active:scale-95 flex items-center gap-2"
                                    style="{{ $this->getButtonStyle($button) }}"
                            >
                                {{-- Icon bên trái --}}
                                @if(!empty($button['icon_svg']) && ($button['icon_position'] ?? 'left') === 'left')
                                    <span class="inline-flex items-center flex-shrink-0">
                                        {!! $button['icon_svg'] !!}
                                    </span>
                                @endif

                                <span>{{ $button['text'] ?? 'Button' }}</span>

                                {{-- Icon bên phải --}}
                                @if(!empty($button['icon_svg']) && ($button['icon_position'] ?? 'left') === 'right')
                                    <span class="inline-flex items-center flex-shrink-0">
                                        {!! $button['icon_svg'] !!}
                                    </span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

@push('scripts')
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('popupManager', (isVisible) => ({
                show: isVisible,
                config: @json($config),
                hasShown: false,
                imagePreloaded: false,

                init() {

                    if (this.isDismissed()) {
                        this.show = false;
                        return;
                    }

                    this.preloadImage();
                    this.$watch('isVisible', value => {
                        this.show = value;
                    });

                    this.handleDisplayMode();


                    // Listen cho navigation events từ Livewire
                    Livewire.on('navigate-to', (data) => {
                        console.log('Navigate event received:', data);

                        const url = data.url || data[0]?.url;
                        const target = data.target || data[0]?.target || '_self';

                        if (!url || url === 'undefined') {
                            console.error('Invalid URL:', url);
                            return;
                        }

                        if (target === '_blank') {
                            window.open(url, '_blank');
                        } else {
                            window.location.href = url;
                        }
                    });
                },

                isDismissed() {
                    const frequency = this.config.show_frequency ?? 1;
                    if (frequency == 0) return false;

                    const popupId = '{{ md5(json_encode($config["title"] ?? "popup")) }}';
                    const cookieName = 'popup_dismissed_' + popupId;
                    
                    const match = document.cookie.match(new RegExp('(^| )' + cookieName + '=([^;]+)'));
                    if (!match) return false;

                    const lastShown = new Date(match[2]);
                    const minutesPassed = (Date.now() - lastShown.getTime()) / (1000 * 60);
                    return minutesPassed < frequency;
                },

                preloadImage() {
                    const imageUrl = this.config.content_image;
                    if (imageUrl) {
                        const img = new Image();
                        img.onload = () => {
                            this.imagePreloaded = true;
                        };
                        img.src = '{{ asset("storage") }}/' + imageUrl;
                    } else {
                        this.imagePreloaded = true;
                    }
                },

                handleDisplayMode() {
                    const mode = this.config.display_mode || 'auto';

                    switch(mode) {
                        case 'delay':
                            setTimeout(() => {
                                if (!this.hasShown) {
                                    this.openPopup();
                                }
                            }, (this.config.delay_time || 3) * 1000);
                            break;

                        case 'scroll':
                            window.addEventListener('scroll', () => {
                                if (this.hasShown) return;

                                const scrollPercent = (window.scrollY / (document.documentElement.scrollHeight - window.innerHeight)) * 100;

                                if (scrollPercent >= (this.config.scroll_percentage || 50)) {
                                    this.openPopup();
                                }
                            });
                            break;

                        case 'exit':
                            document.addEventListener('mouseleave', (e) => {
                                if (this.hasShown) return;
                                if (e.clientY <= 0) {
                                    this.openPopup();
                                }
                            });
                            break;

                        case 'manual':
                            break;

                        case 'auto':
                        default:
                            break;
                    }
                },

                openPopup() {
                    if (this.imagePreloaded) {
                        this.hasShown = true;
                        this.show = true;
                        @this.call('show');
                    } else {
                        // Đợi image load xong
                        setTimeout(() => this.openPopup(), 100);
                    }
                },

                closePopup() {
                    // ✅ Đóng UI ngay lập tức, không chờ server
                    this.show = false;

                    // Sync state với Livewire ở background
                    this.$nextTick(() => {
                        @this.call('hide');
                    });
                },

                handleEscape() {
                    if (this.config.close_on_esc ?? true) {
                        this.closePopup();
                    }
                }
            }));
        });
    </script>
@endpush

@push('styles')
@endpush