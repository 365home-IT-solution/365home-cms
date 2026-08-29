@props([
    'height' => '40',
    'background_color' => 'var(--color-primary)',
    'header_contacts' => [],
    'text_color' => 'light',
    'social_icon' => false,
    'social_links' => []
])

@php
    $contactIcons = [
        'phone' => 'heroicon-o-phone',
        'email' => 'heroicon-o-envelope',
        'address' => 'heroicon-o-map-pin',
        'hotline' => 'heroicon-o-phone-arrow-up-right',
        'working_hours' => 'heroicon-o-clock',
        'website' => 'heroicon-o-globe-alt',
    ];

    $contactLabels = [
        'phone' => 'Số điện thoại',
        'email' => 'Email',
        'address' => 'Địa chỉ',
        'hotline' => 'Hotline',
        'working_hours' => 'Giờ làm việc',
        'website' => 'Website',
    ];

    $processedContacts = collect($header_contacts)->map(function($contact) use ($contactLabels) {
        return array_merge($contact, [
            'contact_key' => $contact['contact_key'] ?? $contactLabels[$contact['contact_type']] ?? 'Liên hệ'
        ]);
    })->toArray();
@endphp

<header class="relative z-30 max-md:hidden">
    <div style="background: {{ $background_color }};">
        <div style="height: {{ $height . 'px'}}" class="flex items-center justify-between max-w-screen-xl mx-auto md:px-8 px-4">
            {{-- Left Side - Contacts --}}
            <div class="flex items-center max-md:flex-col max-md:gap-y-2 max-md:items-start">
                @foreach ($processedContacts as $contact)
                    <div class="flex items-center gap-2 {{ $text_color === 'light' ? 'text-gray-800' : 'text-white' }}">
                        @if(isset($contactIcons[$contact['contact_type']]))
                            <x-dynamic-component
                                :component="$contactIcons[$contact['contact_type']]"
                                class="w-4 h-4"
                            />
                        @endif
                        <div class="flex items-center gap-1 text-sm">
                            <span class="font-medium">{{ $contact['contact_key'] }}:</span>
                            @switch($contact['contact_type'])
                                @case('email')
                                    <a href="mailto:{{ $contact['contact_value'] }}"
                                       class="hover:underline font-normal">
                                        {{ $contact['contact_value'] }}
                                    </a>
                                    @break
                                @case('phone')
                                @case('hotline')
                                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $contact['contact_value']) }}"
                                       class="hover:underline font-normal">
                                        {{ $contact['contact_value'] }}
                                    </a>
                                    @break
                                @case('website')
                                    <a href="{{ $contact['contact_value'] }}"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       class="hover:underline font-normal">
                                        {{ $contact['contact_value'] }}
                                    </a>
                                    @break
                                @default
                                    <span class="font-normal">{{ $contact['contact_value'] }}</span>
                            @endswitch
                        </div>
                    </div>
                    @if (!$loop->last)
                        <span class="mx-3 h-4 border-l {{ $text_color === 'light' ? 'border-gray-800' : 'border-white' }} max-md:hidden"></span>
                    @endif
                @endforeach
            </div>

            {{-- Right Side - Social Icons --}}
            @if ($social_icon && !empty($social_links))
                <div class="flex gap-4 items-center">
                    @foreach ($social_links as $social)
                        <a href="https://{{ $social['url'] }}"
                           class="text-md {{ $text_color === 'light' ? 'text-gray-800 hover:text-gray-600' : 'text-white hover:text-gray-300' }}"
                           target="_blank"
                           rel="noopener noreferrer">
                            @switch($social['platform'])
                                @case('facebook')
                                    <svg aria-hidden="true" viewBox="0 0 512 512" class="w-4 h-4 fill-current"><path d="M512 256C512 114.6 397.4 0 256 0S0 114.6 0 256C0 376 82.7 476.8 194.2 504.5V334.2H141.4V256h52.8V222.3c0-87.1 39.4-127.5 125-127.5c16.2 0 44.2 3.2 55.7 6.4V172c-6-.6-16.5-1-29.6-1c-42 0-58.2 15.9-58.2 57.2V256h83.6l-14.4 78.2H287V510.1C413.8 494.8 512 386.9 512 256z"/></svg>
                                    @break
                                @case('twitter')
                                    <svg aria-hidden="true" viewBox="0 0 512 512" class="w-4 h-4 fill-current"><path d="M389.2 48h70.6L305.6 224.2 487 464H345L233.7 318.6 106.5 464H35.8L200.7 275.5 26.8 48H172.4L272.9 180.9 389.2 48zM364.4 421.8h39.1L151.1 88h-42L364.4 421.8z"/></svg>
                                    @break
                                @case('instagram')
                                    <svg aria-hidden="true" viewBox="0 0 448 512" class="w-4 h-4 fill-current"><path d="M224.1 141c-63.6 0-114.9 51.3-114.9 114.9S160.5 370.8 224.1 370.8 339 319.5 339 255.9 287.7 141 224.1 141zm0 189.6c-41.1 0-74.7-33.5-74.7-74.7s33.5-74.7 74.7-74.7 74.7 33.5 74.7 74.7-33.6 74.7-74.7 74.7zm146.4-194.3c0 14.9-12 26.8-26.8 26.8s-26.8-12-26.8-26.8 12-26.8 26.8-26.8 26.8 12 26.8 26.8zm76.1 27.2c-1.7-35.9-9.9-67.7-36.2-93.9-26.2-26.2-58-34.4-93.9-36.2-37-2.1-147.9-2.1-184.9 0-35.8 1.7-67.6 9.9-93.9 36.1S3.3 127.5 1.5 163.4c-2.1 37-2.1 147.9 0 184.9 1.7 35.9 9.9 67.7 36.2 93.9s58 34.4 93.9 36.2c37 2.1 147.9 2.1 184.9 0 35.9-1.7 67.7-9.9 93.9-36.2 26.2-26.2 34.4-58 36.2-93.9 2.1-37 2.1-147.8 0-184.8zM398.8 388c-7.8 19.6-22.9 34.7-42.6 42.6-29.5 11.7-99.5 9-132.1 9s-102.7 2.6-132.1-9c-19.6-7.8-34.7-22.9-42.6-42.6-11.7-29.5-9-99.5-9-132.1s-2.6-102.7 9-132.1c7.8-19.6 22.9-34.7 42.6-42.6 29.5-11.7 99.5-9 132.1-9s102.7-2.6 132.1 9c19.6 7.8 34.7 22.9 42.6 42.6 11.7 29.5 9 99.5 9 132.1s2.7 102.7-9 132.1z"/></svg>
                                    @break
                                @case('linkedin')
                                    <svg aria-hidden="true" viewBox="0 0 448 512" class="w-4 h-4 fill-current"><path d="M416 32H31.9C14.3 32 0 46.5 0 64.3v383.4C0 465.5 14.3 480 31.9 480H416c17.6 0 32-14.5 32-32.3V64.3C448 46.5 433.6 32 416 32zM135.4 416H69V202.2h66.5V416zm-33.2-243c-21.3 0-38.5-17.3-38.5-38.5S80.9 96 102.2 96s38.5 17.3 38.5 38.5c0 21.3-17.2 38.5-38.5 38.5zm282.1 243h-66.4V312c0-24.8-.5-56.7-34.5-56.7-34.6 0-39.9 27-39.9 54.9V416h-66.4V202.2h63.7v29.2h.9c8.9-16.8 30.6-34.5 62.9-34.5 67.2 0 79.7 44.3 79.7 101.9V416z"/></svg>
                                    @break
                                @default
                                    <svg aria-hidden="true" viewBox="0 0 640 512" class="w-4 h-4 fill-current"><path d="M579.8 267.7c56.5-56.5 56.5-148 0-204.5-50-50-128.8-56.5-186.3-15.4l-1.6 1.1c-14.4 10.3-17.7 30.3-7.4 44.6s30.3 17.7 44.6 7.4l1.6-1.1c32.1-22.9 76-19.3 103.8 8.6 31.5 31.5 31.5 82.5 0 114L422.3 334.8c-31.5 31.5-82.5 31.5-114 0-27.9-27.9-31.5-71.8-8.6-103.8l1.1-1.6c10.3-14.4 6.9-34.4-7.4-44.6s-34.4-6.9-44.6 7.4l-1.1 1.6C206.5 251.2 213 330 263 380c56.5 56.5 148 56.5 204.5 0l112.3-112.3zM60.2 244.3c-56.5 56.5-56.5 148 0 204.5 50 50 128.8 56.5 186.3 15.4l1.6-1.1c14.4-10.3 17.7-30.3 7.4-44.6s-30.3-17.7-44.6-7.4l-1.6 1.1c-32.1 22.9-76 19.3-103.8-8.6-31.5-31.5-31.5-82.5 0-114l112.2-112.3c31.5-31.5 82.5-31.5 114 0 27.9 27.9 31.5 71.8 8.6 103.9l-1.1 1.6c-10.3 14.4-6.9 34.4 7.4 44.6s34.4 6.9 44.6-7.4l1.1-1.6C433.5 260.8 427 182 377 132c-56.5-56.5-148-56.5-204.5 0L60.2 244.3z"/></svg>
                            @endswitch
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</header>
