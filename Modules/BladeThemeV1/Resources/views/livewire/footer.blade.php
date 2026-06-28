<footer class="relative" style="background-color: {{ $footerConfig['styling']['backgroundColor'] ?? '#fff' }}; color: #fff;">
    {{-- Newsletter --}}
    @if($footerConfig['newsletter']['is_visible'])
        @php $newsletterConfig = $footerConfig['newsletter'] @endphp
        <x-bladethemev1::footer.newsletter :data="(new \Modules\BladeThemeV1\Types\Footer\FooterNewsletterData(
            heading: $newsletterConfig['heading'],
            description: $newsletterConfig['description'],
            form: \Modules\Form\Entities\Form::with(['formFields', 'emailSetting'])->find($newsletterConfig['form'])
        ))"/>
    @endif

    {{--  Footer main  --}}
    <div class="mx-auto md:px-8 px-4 py-6 lg:py-12">
        @if (!empty($footerConfig) && isset($footerConfig['content']) && !empty($footerConfig['content']))
            <!-- Custom Title Style -->
            <style>
                .title-with-underline {
                    position: relative;
                    display: inline-block;
                }

                .title-with-underline::after {
                    content: '';
                    position: absolute;
                    left: 0;
                    bottom: -8px;
                    width: 40px;
                    height: 3px;
                    background-color: {{ $footerConfig['styling']['titleColor'] ?? '#000000' }};
                    border-radius: 2px;
                    transition: width 0.4s ease;
                }

                .footer-column:hover .title-with-underline::after {
                    width: 100%;
                }
            </style>

            <div class="">
                <div style="color: {{ $footerConfig['styling']['textColor'] ?? '#fff' }}"
                     class="grid grid-cols-1 gap-8 text-sm">
                    <div class="grid grid-cols-1 lg:grid-cols-10 gap-8">

                        <div class="lg:col-span-3">
                            @foreach ($footerConfig['content'] as $column)
                                @if($column['content'][0]['type'] === 'business')
                                    <div class="footer-column">
                                        @if(!empty($column['title']))
                                            <h2 class="text-lg font-bold uppercase mb-8 title-with-underline"
                                                style="color: {{ $footerConfig['styling']['titleColor'] ?? '#ffff' }};">
                                                {{ $column['title'] }}
                                            </h2>
                                        @endif
                                        
                                        <div class="space-y-4">
                                            @foreach($column['content'] as $row)
                                                @if ($row['type'] === 'business' && !is_null($row['data']))
                                                    <!-- Business Content -->
                                                    <div class="">
                                                        <a href="/">
                                                            <img style="height: 70px; width: auto; filter: brightness(0) invert(1);" class="mb-6" src="{{ asset('/storage/'.$logo) }}" alt="Logo">
                                                        </a>

                                                        <div class="flex flex-col">
                                                            <!-- Company Name -->
                                                            <h3 class="text-xl font-bold mb-4">{{ $row['data']['name'] }}</h3>

                                                            <!-- Business Info -->
                                                            <div class="space-y-3 ">
                                                                @if($row['data']['description'])
                                                                    <div class="flex items-start space-x-2">
                                                                        <span class="text-justify text-lg leading-relaxed tracking-tight">
                                                                            {{ $row['data']['description'] }}
                                                                        </span>
                                                                    </div>
                                                                @endif

                                                                @if($row['data']['tax_code'])
                                                                    <div class="flex items-start space-x-2">
                                                                        <span class="font-medium min-w-[100px]">Mã số thuế:</span>
                                                                        <span>{{ $row['data']['tax_code'] }}</span>
                                                                    </div>
                                                                @endif

                                                                @if($row['data']['address'])
                                                                    <div class="flex items-start space-x-2">
                                                                        <span class="font-medium min-w-[100px]">Địa chỉ:</span>
                                                                        <span>{{ $row['data']['address'] }}</span>
                                                                    </div>
                                                                @endif

                                                                @if($row['data']['phone'])
                                                                    <div class="flex items-start space-x-2">
                                                                        <span class="font-medium min-w-[100px]">Điện thoại:</span>
                                                                        <a href="tel:{{ $row['data']['phone'] }}" class="transition-colors">
                                                                            {{ $row['data']['phone'] }}
                                                                        </a>
                                                                    </div>
                                                                @endif

                                                                @if($row['data']['email'])
                                                                    <div class="flex items-start space-x-2">
                                                                        <span class="font-medium min-w-[100px]">Email:</span>
                                                                        <a href="mailto:{{ $row['data']['email'] }}" class="transition-colors">
                                                                            {{ $row['data']['email'] }}
                                                                        </a>
                                                                    </div>
                                                                @endif

                                                                @if($row['data']['website'])
                                                                    <div class="flex items-start space-x-2">
                                                                        <span class="font-medium min-w-[100px]">Website:</span>
                                                                        <a href="{{ $row['data']['website'] }}" class="transition-colors" target="_blank">
                                                                            {{ $row['data']['website'] }}
                                                                        </a>
                                                                    </div>
                                                                @endif

                                                                @if($row['data']['image'])
                                                                    <div class="flex items-start space-x-2">
                                                                        <a href="http://online.gov.vn/Home/WebDetails/140984">
                                                                            <img style="height: 45px" src="{{ asset('/storage/'.$row['data']['image']) }}" alt="Logo">
                                                                        </a>
                                                                    </div>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endif

                                                {{-- Social Media Links - Đã di chuyển vào trong vòng lặp content --}}
                                                @if ($row['type'] === 'social_media' && !empty($row['data']))
                                                    <div class="flex flex-wrap gap-3">
                                                        @foreach ($row['data'] as $social)
                                                            @php
                                                                $iconImage = match($social['platform']) {
                                                                    'facebook' => 'https://cdn-icons-png.flaticon.com/128/5968/5968764.png',
                                                                    'tiktok' => 'https://cdn-icons-png.flaticon.com/128/3116/3116491.png',
                                                                    'twitter' => null,
                                                                    'youtube' => null,
                                                                    'instagram' => null,
                                                                    'linkedin' => null,
                                                                    default => null
                                                                };

                                                                $icon = match($social['platform']) {
                                                                    'facebook' => 'fab fa-facebook-f',
                                                                    'twitter' => 'fab fa-twitter',
                                                                    'youtube' => 'fab fa-youtube',
                                                                    'instagram' => 'fab fa-instagram',
                                                                    'linkedin' => 'fab fa-linkedin-in',
                                                                    'tiktok' => 'fab fa-tiktok',
                                                                    default => 'fas fa-link'
                                                                };

                                                                $bgColor = match($social['platform']) {
                                                                    'facebook' => 'hover:bg-[#1877F2]',
                                                                    'twitter' => 'hover:bg-[#1DA1F2]',
                                                                    'youtube' => 'hover:bg-[#FF0000]',
                                                                    'instagram' => 'hover:bg-[#E4405F]',
                                                                    'linkedin' => 'hover:bg-[#0A66C2]',
                                                                    'tiktok' => 'hover:bg-[#000000]',
                                                                    default => 'hover:bg-gray-800'
                                                                };
                                                            @endphp

                                                            <a href="{{ $social['url'] }}" target="_blank"
                                                               class="flex items-center justify-center w-10 h-10 rounded-full overflow-hidden {{ $iconImage ? '' : 'border border-gray-200 ' . $bgColor . ' hover:border-transparent hover:text-white' }} transition-all duration-300">
                                                                @if($iconImage)
                                                                    <img src="{{ $iconImage }}" alt="{{ $social['platform'] }}" class="w-full h-full object-cover">
                                                                @else
                                                                    <i class="{{ $icon }}"></i>
                                                                @endif
                                                            </a>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>

                        <div class="lg:col-span-7 grid sm:grid-cols-3 gap-4">
                            @foreach ($footerConfig['content'] as $column)
                                @if($column['content'][0]['type'] !== 'business')
                                    <div class="footer-column">
                                        @if(!empty($column['title']))
                                            <h2 class="text-lg font-bold uppercase mb-8 title-with-underline"
                                                style="color: {{ $footerConfig['styling']['titleColor'] ?? '#fff' }};">
                                                {{ $column['title'] }}
                                            </h2>
                                        @endif
                                        <div class="space-y-4">
                                            @foreach($column['content'] as $row)
                                                <!-- Menu Links -->
                                                @if ($row['type'] === 'menu' && !is_null($row['data']))
                                                    <ul class="space-y-3">
                                                        @foreach ($row['data'] as $menuItem)
                                                            <li>
                                                                <a href="{{ $menuItem['url'] }}"
                                                                   target="{{ $menuItem['target'] }}"
                                                                   class="group flex items-center hover:text-primary-300 transition-colors text-white">
                                                                    <span class="w-2 h-2 bg-primary rounded-full opacity-0 -ml-4 mr-2 group-hover:opacity-100 group-hover:ml-0 transition-all duration-300"></span>
                                                                    {{ $menuItem['title'] }}
                                                                </a>
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                @endif

                                                <!-- Text Content -->
                                                @if ($row['type'] === 'text' && !empty($row['data']))
                                                    <div class="leading-relaxed">
                                                        {!! $row['data'] !!}
                                                    </div>
                                                @endif

                                                <!-- Image -->
                                                @if ($row['type'] === 'image' && !is_null($row['data']))
                                                    <img src="{{ asset('/storage/' . $row['data']) }}"
                                                         alt="{{ $column['title'] ?? 'No Title' }}"
                                                         class="rounded-lg shadow-sm hover:shadow-md transition-shadow">
                                                @endif

                                                <!-- iFrame/Google Map -->
                                                @if (in_array($row['type'], ['iframe', 'google_map']) && !is_null($row['data']))
                                                    <div class="overflow-hidden shadow-sm hover:shadow-md transition-shadow">
                                                        <div class="iframe-footer">
                                                            {!! $row['data'] !!}
                                                        </div>
                                                    </div>
                                                @endif
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <!-- Bottom Bar -->
    <x-bladethemev1::footer.footer-bottom :data="new \Modules\BladeThemeV1\Types\Footer\FooterBottomData(
     baseColor: $footerBottomConfig['base_color'],
     backgroundColor: $footerBottomConfig['background_color'],
     copyright: $footerBottomConfig['copyright'],
     bottomMenu: $footerBottomConfig['bottom_menu']
)"/>

    <style>
        .iframe-footer {
            position: relative;
            width: 100%;
            height: 230px;
            overflow: hidden;
        }
        .iframe-footer iframe {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 100%;
            height: 100%;
        }
    </style>
</footer>