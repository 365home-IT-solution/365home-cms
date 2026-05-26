@extends('bladethemev1::layouts.master')

@section('title', 'Tài khoản của tôi')

@section('content')
    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')

    <div class="min-h-screen bg-gray-50" style="padding-top: 0;">
        @livewire('bladethemev1::account-page')
    </div>

    @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')
@endsection
