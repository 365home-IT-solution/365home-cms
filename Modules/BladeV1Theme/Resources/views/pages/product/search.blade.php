@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData"/>

@section('content')
    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')
    <div class="md:py-12 py-8 relative">
        <div class="max-w-screen-xl md:px-8 px-4 mx-auto relative">
            @livewire('bladethemev1::product-search', ['config' => []])
        </div>
    </div>
    @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')
@endsection