<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Portal khách thuê')</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    @auth('tenant')
        <header class="bg-white border-b border-gray-100 sticky top-0 z-10">
            <div class="max-w-2xl mx-auto px-4 py-3 flex items-center justify-between">
                <a href="{{ route('minihouse.portal.dashboard') }}" class="font-semibold text-gray-900">MiniHouse</a>
                <div class="flex items-center gap-4 text-sm">
                    <a href="{{ route('minihouse.portal.dashboard') }}" class="text-gray-600 hover:text-gray-900">Tổng quan</a>
                    <form method="POST" action="{{ route('minihouse.portal.logout') }}">
                        @csrf
                        <button type="submit" class="text-red-600 hover:underline">Đăng xuất</button>
                    </form>
                </div>
            </div>
        </header>
    @endauth

    <main class="max-w-2xl mx-auto px-4 py-6">
        @if (session('portal_info'))
            <div class="mb-4 rounded-lg bg-green-50 border border-green-200 text-green-700 text-sm px-3 py-2">
                {{ session('portal_info') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
