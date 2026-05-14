@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData"/>

@section('content')

    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')
    @foreach ($configuration as $config)
        <x-bladethemev1::layout :page="$page" :componentName="$config['component']['name']" :loop="$loop" :config="$config['layout']" :primaryColor="$primaryColor">
            @isset($config['component'])
                @livewire('bladethemev1::' . $config['component']['name'], ['config' => $config, 'loop' => $loop])
            @endisset
        </x-bladethemev1::layout>
    @endforeach
   
    @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')
@endsection

<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.documentElement.style.setProperty('--swiper-theme-color', @json($primaryColor));
    });
</script>
