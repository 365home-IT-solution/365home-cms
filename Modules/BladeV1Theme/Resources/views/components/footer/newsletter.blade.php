@php use Modules\BladeThemeV1\Types\Footer\FooterNewsletterData; @endphp
@props([
    'data' => new FooterNewsletterData('', '', null)
])

@php
    /** @var FooterNewsletterData $data */
    $heading = $data->heading;
    $description = $data->description;
    $form = $data->form;
@endphp

<div class="border-b relative">
    <div class="max-w-screen-xl mx-auto py-12 md:px-8 px-4">
        <div class="md:flex justify-between items-center">
            <div class="mb-6 md:mb-0 md:w-1/2">
                <h3 class="text-2xl font-bold mb-2">{{ $heading }}</h3>
                <p class="text-white">{{ $description }}</p>
            </div>
            <div class="md:w-1/2 text-black">
                @if($data->hasValidForm())
                    @livewire('bladethemev1::form-newsletter', ['form' => $form])
                @endif
            </div>
        </div>
    </div>
</div>
