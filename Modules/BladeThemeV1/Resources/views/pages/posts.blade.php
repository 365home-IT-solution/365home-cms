@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData" />

@section('content')
    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')

    <div class="min-h-screen bg-gray-50 px-4 py-6 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-900">Tin tức</h1>
                <p class="text-sm text-gray-500">Cập nhật những thông tin mới nhất từ 365 Home.</p>
            </div>

            @livewire('bladethemev1::post-page', [
                'config' => [
                    'component' => [
                        'show_sidebar' => false,
                        'columns' => 3,
                        'number-post' => 9,
                        'show_pagination' => true,
                        'location-sidebar' => '',
                        'new_post' => '0',
                        'post_category' => '0',
                        'link_social' => '',
                    ],
                ],
            ], key('posts-page'))
        </div>
    </div>
@endsection
