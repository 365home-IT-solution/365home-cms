@php
    $background_linear = $config['background_linear'];
    $gradient_start = $config['gradient_start'];
    $gradient_end = $config['gradient_end'];
    $gradient_direction = $config['gradient_direction'] ? $config['gradient_direction'] : 'to bottom';
    $overlay_color = $config['overlay_color'];
    $overlay_opacity = $config['overlay_opacity'];
    $heading_style = [
        'left' => 'me-auto text-start',
        'center' => 'mx-auto text-center',
        'right' => 'ms-auto text-end',
    ][$config['heading_alignment'] ?? 'center'];
@endphp
<section>
    <div class="{{ $componentName != 'banner' && !$config['heading_background_image'] ? 'md:py-8 py-6' : '' }} {{ $config['heading_background_image'] ? 'md:pb-12 pb-8' : '' }} relative"
        style="@if ($background_linear && $gradient_start && $gradient_end) background-image: linear-gradient({{ $gradient_direction }}, {{ $gradient_start }}, {{ $gradient_end }}); @endif @if (!empty($config['background_image'])) background-image: url('/storage/{{ $config['background_image'] ?? '' }}'); @endif @if (!empty($config['background_color'])) background-color: {{ $config['background_color'] ?? 'transparent' }}; @endif background-size: cover;background-position: center;">
        @if (!empty($config['background_image']))
            <div style="background: {{ $overlay_color }}; opacity: {{ $overlay_opacity }}" class="absolute inset-0">
            </div>
        @endif
        {{-- Heading start --}}
        @if (!$config['heading_background_image'])
            <x-bladethemev1::heading.heading :heading="$config['heading']" :heading_color="$config['heading_color']" :heading_sub="$config['heading_sub']" :heading_sub_color="$config['heading_sub_color']"
                :heading_style="$heading_style" />
        @else
            <x-bladethemev1::heading.heading-background :heading="$config['heading']" :heading_color="$config['heading_color']" :heading_sub="$config['heading_sub']"
                :heading_sub_color="$config['heading_sub_color']" :heading_style="$heading_style" :heading_background_image="$config['heading_background_image']" />
        @endif
        {{-- Heading end --}}
        <div class="{{ !($componentName === 'banner') ? 'md:px-8 px-4' : '' }} mx-auto relative">
            <div class="w-full">
                {{ $slot }}
            </div>
        </div>
    </div>

</section>
