@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData" />

@section('content')
    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')

    <div class="min-h-screen bg-gray-50 px-4 py-6 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            <div class="mb-6 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Danh sách yêu thích</h1>
                    <p class="text-sm text-gray-500">Lưu các phòng bạn thích để xem lại sau.</p>
                </div>
            </div>

            <div id="favorites-root" class="min-h-[240px]"></div>
        </div>
    </div>

    <script>
        (function () {
            const root = document.getElementById('favorites-root');
            if (!root) return;

            const token = localStorage.getItem('auth_token');
            const renderLoginPrompt = () => {
                root.innerHTML = `
                    <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-8 text-center shadow-sm">
                        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-rose-50 text-rose-500">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="currentColor" viewBox="0 0 24 24">
                                <path d="m11.645 20.91-.007-.003-.022-.012a15.247 15.247 0 0 1-.383-.218 25.18 25.18 0 0 1-4.244-3.17C4.688 15.36 2.25 12.174 2.25 8.25 2.25 5.322 4.714 3 7.688 3A5.5 5.5 0 0 1 12 5.052 5.5 5.5 0 0 1 16.313 3c2.973 0 5.437 2.322 5.437 5.25 0 3.925-2.438 7.111-4.739 9.256a25.175 25.175 0 0 1-4.244 3.17 15.247 15.247 0 0 1-.383.219l-.022.012-.007.004-.003.001a.752.752 0 0 1-.704 0l-.003-.001Z"/>
                            </svg>
                        </div>
                        <h2 class="mb-2 text-lg font-semibold text-gray-900">Bạn cần đăng nhập để xem danh sách yêu thích</h2>
                        <p class="mb-5 text-sm text-gray-500">Đăng nhập để lưu và quản lý các phòng bạn đã thích.</p>
                        <button type="button" id="favorite-login-btn" class="rounded-full bg-[var(--color-primary)] px-5 py-2.5 text-sm font-semibold text-white shadow-sm">Đăng nhập ngay</button>
                    </div>
                `;
                document.getElementById('favorite-login-btn')?.addEventListener('click', () => {
                    window.dispatchEvent(new CustomEvent('open-auth-modal'));
                });
            };

            const renderRooms = (rooms) => {
                if (!rooms.length) {
                    root.innerHTML = `
                        <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-8 text-center shadow-sm">
                            <h2 class="mb-2 text-lg font-semibold text-gray-900">Chưa có phòng yêu thích nào</h2>
                            <p class="text-sm text-gray-500">Hãy lưu lại những phòng bạn thích để xem lại sau.</p>
                        </div>
                    `;
                    return;
                }

                const cards = rooms.map((room) => window.roomCardHtml ? window.roomCardHtml(room) : '').join('');
                root.innerHTML = '<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">' + cards + '</div>';
            };

            const loadFavorites = async () => {
                if (!token) {
                    renderLoginPrompt();
                    return;
                }

                root.innerHTML = '<div class="py-10 text-center text-sm text-gray-500">Đang tải...</div>';
                try {
                    const res = await fetch('/api/wishlist', {
                        headers: {
                            'Accept': 'application/json',
                            'Authorization': 'Bearer ' + token,
                        }
                    });
                    const json = await res.json();
                    renderRooms(json.data || []);
                } catch (e) {
                    root.innerHTML = '<div class="rounded-2xl border border-red-200 bg-red-50 p-6 text-sm text-red-600">Không thể tải danh sách yêu thích lúc này.</div>';
                }
            };

            document.addEventListener('DOMContentLoaded', loadFavorites);
            window.addEventListener('auth-state-changed', loadFavorites);
            loadFavorites();
        })();
    </script>
@endsection
