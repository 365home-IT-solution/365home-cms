@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData"/>

@section('content')

    <h1 class="sr-only">Tra cứu đơn đặt phòng tại {{ config('app.name', '365 HOME') }}</h1>

    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')

    @livewire('bladethemev1::search-booking')

    @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')
@endsection
