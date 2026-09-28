<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Portal khách thuê')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    {{-- Alpine.js dùng chung cho toàn Portal (trước đây chat.blade.php tự tải riêng bằng @once —
    giờ tải 1 lần duy nhất ở đây để tránh nạp trùng script khi mở trang chat). --}}
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <style>
        :root {
            --mh-bg: #F5F6FB;
            --mh-surface: #FFFFFF;
            --mh-border: #E8E9F3;
            --mh-text: #14172B;
            --mh-text-muted: #6B7280;
            --mh-text-faint: #9CA3AF;
            --mh-primary: #2B5257;
            --mh-primary-dark: #1F3D41;
            --mh-primary-soft: #E6ECED;
        }

        body {
            background: var(--mh-bg);
            color: var(--mh-text);
            font-family: 'Inter', ui-sans-serif, system-ui, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        h1, h2, h3, .mh-heading {
            font-family: 'Manrope', 'Inter', ui-sans-serif, sans-serif;
            letter-spacing: -0.01em;
        }

        .mh-tabular { font-variant-numeric: tabular-nums; }

        /* ── Component nền tảng dùng chung toàn Portal — giữ mọi trang cùng 1 phong cách ── */
        .mh-card { background: var(--mh-surface); border: 1px solid var(--mh-border); border-radius: 1.25rem; box-shadow: 0 1px 2px rgba(20,23,43,0.04); }
        .mh-card-pad { padding: 1.25rem; }

        .mh-list-row { display: flex; align-items: center; gap: 0.875rem; background: var(--mh-surface); border: 1px solid var(--mh-border); border-radius: 1.125rem; padding: 0.875rem 1.125rem; transition: border-color .15s ease, transform .15s ease; }
        a.mh-list-row:hover { border-color: #C7CBF0; }

        /* Dashboard: ô lối tắt dạng dọc (icon phía trên, nhãn phía dưới) — tách riêng khỏi
        .mh-list-row (dạng ngang, dùng cho danh sách hợp đồng/hoá đơn/thông báo...) để không phải
        đè hướng flex bằng utility Tailwind (dễ lệch do thứ tự nạp CSS giữa Tailwind CDN và block
        này). */
        .mh-tile { position: relative; display: flex; flex-direction: column; align-items: flex-start; gap: 0.625rem; background: var(--mh-surface); border: 1px solid var(--mh-border); border-radius: 1.125rem; padding: 1rem; transition: border-color .15s ease; }
        a.mh-tile:hover { border-color: #C7CBF0; }

        .mh-badge { display: inline-flex; align-items: center; font-size: 0.6875rem; font-weight: 600; padding: 0.15rem 0.55rem; border-radius: 999px; line-height: 1.4; }
        .mh-badge-green { background: #ECFDF5; color: #047857; }
        .mh-badge-yellow { background: #FFFBEB; color: #B45309; }
        .mh-badge-red { background: #FEF2F2; color: #B91C1C; }
        .mh-badge-gray { background: #F3F4F6; color: #4B5563; }

        .mh-btn-primary { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; width: 100%; border-radius: 0.875rem; background: var(--mh-primary); color: #fff; font-size: 0.875rem; font-weight: 600; padding: 0.75rem 1rem; transition: background .15s ease; }
        .mh-btn-primary:hover { background: var(--mh-primary-dark); }
        .mh-btn-secondary { display: inline-flex; align-items: center; justify-content: center; gap: 0.375rem; border-radius: 0.75rem; border: 1px solid var(--mh-border); background: var(--mh-surface); color: var(--mh-text); font-size: 0.8125rem; font-weight: 500; padding: 0.5rem 0.875rem; transition: border-color .15s ease, background .15s ease; }
        .mh-btn-secondary:hover { border-color: #C7CBF0; background: #FAFAFF; }

        .mh-field-label { display: block; font-size: 0.8125rem; font-weight: 500; color: #374151; margin-bottom: 0.375rem; }
        .mh-input { width: 100%; border-radius: 0.75rem; border: 1px solid #D6D8E5; padding: 0.625rem 0.875rem; font-size: 0.875rem; background: #fff; transition: border-color .15s ease, box-shadow .15s ease; }
        .mh-input:focus { outline: none; border-color: var(--mh-primary); box-shadow: 0 0 0 3px var(--mh-primary-soft); }

        .mh-empty { border: 1px dashed var(--mh-border); border-radius: 1.125rem; padding: 2.5rem 1.5rem; text-align: center; color: var(--mh-text-faint); font-size: 0.875rem; }
    </style>
</head>
<body class="min-h-screen">
    @auth('tenant')
        @php
            $mhNav = [
                ['route' => 'minihouse.portal.dashboard', 'label' => 'Tổng quan'],
                ['route' => 'minihouse.portal.chat.show', 'label' => 'Chat'],
            ];
        @endphp
        <header class="sticky top-0 z-30 border-b" style="background: rgba(245,246,251,0.85); backdrop-filter: blur(8px); border-color: var(--mh-border);">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 py-3 flex items-center justify-between">
                <a href="{{ route('minihouse.portal.dashboard') }}" class="flex items-center gap-2.5">
                    <span class="flex h-8 w-8 items-center justify-center rounded-xl text-white font-bold text-sm mh-heading" style="background: var(--mh-primary);">M</span>
                    <span class="font-bold text-[15px] mh-heading text-gray-900">MiniHouse</span>
                </a>

                <nav class="flex items-center gap-1 text-sm">
                    @foreach ($mhNav as $item)
                        <a href="{{ route($item['route']) }}"
                           class="px-3 py-1.5 rounded-lg font-medium transition {{ request()->routeIs($item['route']) ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-900' }}">
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </nav>

                <form method="POST" action="{{ route('minihouse.portal.logout') }}">
                    @csrf
                    <button type="submit" class="text-sm font-medium text-gray-400 hover:text-red-600 transition">Đăng xuất</button>
                </form>
            </div>
        </header>
    @endauth

    <main class="max-w-3xl mx-auto px-4 sm:px-6 py-6">
        @if (session('portal_info'))
            <div class="mb-4 rounded-xl bg-white border border-green-200 text-green-700 text-sm px-4 py-3 shadow-sm">
                {{ session('portal_info') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4 rounded-xl bg-white border border-red-200 text-red-700 text-sm px-4 py-3 shadow-sm space-y-0.5">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
