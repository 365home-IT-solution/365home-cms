@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData"/>

{{-- @include thường (không bọc @push) echo ngay ra output tại đây — TRƯỚC khi layout cha
     (@extends ở trên) kịp render <!DOCTYPE html>/<head>, khiến DOCTYPE không còn là byte đầu
     tiên của response (trang bị audit tool báo "không khai báo DOCTYPE"). Bọc vào @push('head')
     để nội dung <style> được đẩy đúng vào @stack('head') trong layouts/master.blade.php. --}}
@push('head')
    @include('bladethemev1::styles.product-detail-styles')
@endpush

@section('content')
    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')
    @livewire('bladethemev1::breadcrumb', ['slug' => $slug, 'name' => $name, 'parents' => $breadcrumbParents])
    @livewire('bladethemev1::product-detail', ['slug' => $slug])
    @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')
@endsection

@once
    @push('scripts')
<script>
    window.pdPrimaryColor = @json($primaryColor);
</script>
<script src="{{ asset('js/product-detail-init.js') }}?v={{ filemtime(public_path('js/product-detail-init.js')) }}"></script>
    @endpush
@endonce