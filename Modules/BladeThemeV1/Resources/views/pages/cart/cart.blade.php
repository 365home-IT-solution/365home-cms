@extends('bladethemev1::layouts.master')

@section('title', 'Giỏ hàng')

@section('content')
    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')
    @livewire('bladethemev1::cart')
    @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')
@endsection
