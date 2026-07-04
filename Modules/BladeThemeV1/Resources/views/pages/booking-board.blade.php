@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData" />

@section('content')
    {{-- Header: ẩn mặc định, chỉ hiện khi mở expanded hero form --}}
    <div x-data="{ shown: false }"
         @hero-form-open.window="shown = true"
         @hero-form-close.window="shown = false"
         x-show="shown"
         style="display:none;">
        @livewire('bladethemev1::header')
    </div>

    <script>
        window.__heroAlwaysCompact = true;
    </script>
    @livewire('bladethemev1::hero-section', ['noBanner' => true])

    <style>
        #main-header-bar {
            z-index: 1150 !important;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
        }
    </style>

    <main style="background:#fff; min-height:100vh; padding-top:80px;">
        {{-- Chỉ desktop (lg+) mới bỏ giới hạn max-width để bảng đặt phòng + bảng tính giá dùng
             toàn bộ chiều ngang màn hình; mobile/tablet giữ nguyên như cũ. --}}
        <div class="max-w-[1280px] mx-auto px-4 pb-6 lg:max-w-none lg:px-10">
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