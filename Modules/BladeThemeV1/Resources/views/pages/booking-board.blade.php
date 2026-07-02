@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData" />

@section('content')
    <style>
        #main-header-bar { position: fixed !important; top: 0; left: 0; right: 0; z-index: 1000; }
    </style>

    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')

    <main style="background:#fff; min-height:100vh; padding-top:78px;">
        <div style="max-width:1280px; margin:0 auto; padding:0 16px 24px;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin:10px 0 14px;">
                <div>
                    {{-- <p style="margin:0 0 4px; font-size:12px; font-weight:700; color:#0f766e; text-transform:uppercase; letter-spacing:.08em;">Bảng booking chi nhánh</p> --}}
                    {{-- <h1 style="margin:0; font-size:24px; line-height:1.25; font-weight:800; color:#111827;">{{ $branch->name }}</h1> --}}
                </div>
                {{-- <a href="{{ url()->previous() }}" style="display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:10px 14px; border-radius:999px; border:1px solid #e5e7eb; color:#374151; text-decoration:none; font-size:13px; font-weight:700; background:#fff;">
                    <span>&larr;</span>
                    <span>Quay lại</span>
                </a> --}}
            </div>

            @if(empty($bookConfig['bookable_room_count']))
                <div style="padding:40px 16px; text-align:center; border:1px solid #f3f4f6; border-radius:14px; background:#f9fafb;">
                    <p style="margin:0; color:#6b7280; font-size:14px;">Chi nhánh này chưa có phòng theo khung giờ để hiển thị.</p>
                </div>
            @else
                @livewire('bladethemev1::book', ['config' => $bookConfig])
            @endif
        </div>
    </main>

    @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')
@endsection