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
                                    <i class="fab fa-facebook"></i>
                                    @break
                                @case('twitter')
                                    <i class="fab fa-twitter"></i>
                                    @break
                                @case('instagram')
                                    <i class="fab fa-instagram"></i>
                                    @break
                                @case('linkedin')
                                    <i class="fab fa-linkedin"></i>
                                    @break
                                @default
                                    <i class="fas fa-link"></i>
                            @endswitch
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</header>
