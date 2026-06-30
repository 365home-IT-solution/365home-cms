@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData"/>

@section('content')

    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')

    @if (request()->path() === '/')
        {{-- Trang chủ: chỉ render hero-section + các section tùy chỉnh --}}
        @livewire('bladethemev1::hero-section')
        @livewire('bladethemev1::flash-sale')
        @livewire('bladethemev1::voucher')
        @livewire('bladethemev1::province-list')
        @livewire('bladethemev1::products')

    @else
        {{-- Các trang khác: render CMS components bình thường --}}
        @foreach ($configuration as $config)
            <x-bladethemev1::layout :page="$page" :componentName="$config['component']['name']" :loop="$loop" :config="$config['layout']" :primaryColor="$primaryColor">
                @isset($config['component'])
                    @livewire('bladethemev1::' . $config['component']['name'], ['config' => $config, 'loop' => $loop])
                @endisset
            </x-bladethemev1::layout>
        @endforeach
    @endif

    @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')
@endsection

<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.documentElement.style.setProperty('--swiper-theme-color', @json($primaryColor));
    });
</script>
