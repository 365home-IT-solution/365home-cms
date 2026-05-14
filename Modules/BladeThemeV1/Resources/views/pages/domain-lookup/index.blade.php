@extends('bladethemev1::layouts.master')
@section('content')
    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')
    @livewire('bladethemev1::breadcrumb', ['slug' => 'kiem-tra-ten-mien', 'name' => 'Kiểm tra tên miền'])
    @livewire('bladethemev1::domain-lookup-detail')
    @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')
@endsection

<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.documentElement.style.setProperty('--swiper-theme-color', @json($primaryColor));
        document.title = 'Kiểm tra tên miền';
    });
</script>
