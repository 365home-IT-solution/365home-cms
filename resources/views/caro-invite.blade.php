{{-- Trang link mời đấu Caro (routes/web.php caro.invite): app chưa có deep link nên trang hiện mã mời + hướng dẫn nhập mã,
     nút chính chép mã rồi mở App Store / Google Play theo thiết bị (máy đã cài app thì store hiện nút "Mở"). --}}
@php
    $status = $invite['status'] ?? null;
    $isOpen = $status === 'pending' && ($invite['seconds_left'] ?? 0) > 0;
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>Lời mời đấu Caro — 365Home</title>
    <meta name="description" content="Nhập mã {{ $code }} trong app 365Home để vào trận Caro.">
    <meta property="og:title" content="{{ $invite ? $invite['inviter']['name'] . ' mời bạn đấu Caro' : 'Lời mời đấu Caro' }} — 365Home">
    <meta property="og:description" content="Mở app 365Home → Mini game Caro → nhập mã {{ $code }}. Thắng đủ trận nhận voucher giảm giá!">
    <meta property="og:type" content="website">
    <meta name="theme-color" content="#2B5257">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f4f6f6;
            --card: #ffffff;
            --ink: #14262a;
            --mute: #5b6b6e;
            --line: #e3e8e8;
            --primary: #2B5257;
            --primary-ink: #ffffff;
            --tint: #e8f0f0;
            --warn: #b45309;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0f1a1c;
                --card: #172427;
                --ink: #e8f0f0;
                --mute: #9db0b2;
                --line: #263a3d;
                --primary: #5fa3aa;
                --primary-ink: #0f1a1c;
                --tint: #1d3236;
                --warn: #f5b04c;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            background: var(--bg);
            color: var(--ink);
            font-family: 'Be Vietnam Pro', system-ui, -apple-system, sans-serif;
        }

        .card {
            width: 100%;
            max-width: 400px;
            padding: 28px 22px 22px;
            border-radius: 22px;
            background: var(--card);
            border: 1px solid var(--line);
            box-shadow: 0 10px 30px rgba(20, 38, 42, .08);
            text-align: center;
        }

        .board {
            width: 64px;
            height: 64px;
            margin: 0 auto 14px;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 3px;
            padding: 5px;
            border-radius: 16px;
            background: var(--tint);
            font-weight: 800;
            font-size: 15px;
        }

        .board span {
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            background: var(--card);
            color: var(--primary);
        }

        .board span.o { color: var(--warn); }

        h1 {
            margin: 0 0 6px;
            font-size: 21px;
            font-weight: 800;
            line-height: 1.3;
        }

        .sub {
            margin: 0 0 20px;
            color: var(--mute);
            font-size: 14px;
            line-height: 1.5;
        }

        .code-box {
            padding: 14px 12px 12px;
            border-radius: 16px;
            background: var(--tint);
        }

        .code-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--mute);
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .code {
            margin: 4px 0 2px;
            font-size: 34px;
            font-weight: 800;
            letter-spacing: .18em;
            font-variant-numeric: tabular-nums;
            color: var(--primary);
            user-select: all;
        }

        .status {
            font-size: 13px;
            color: var(--mute);
        }

        .status.is-closed { color: var(--warn); font-weight: 600; }

        .btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            min-height: 50px;
            margin-top: 16px;
            padding: 12px 16px;
            border: 0;
            border-radius: 14px;
            background: var(--primary);
            color: var(--primary-ink);
            font: 700 16px 'Be Vietnam Pro', system-ui, sans-serif;
            text-decoration: none;
            cursor: pointer;
        }

        .btn:active { transform: translateY(1px); }

        .stores {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 10px;
        }

        .store {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 44px;
            padding: 8px;
            border-radius: 12px;
            border: 1px solid var(--line);
            color: var(--ink);
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
        }

        .store svg { width: 18px; height: 18px; flex-shrink: 0; }

        ol {
            margin: 20px 0 0;
            padding: 14px 16px 14px 34px;
            border-radius: 14px;
            border: 1px dashed var(--line);
            text-align: left;
            font-size: 14px;
            line-height: 1.7;
            color: var(--mute);
        }

        ol b { color: var(--ink); }

        .toast {
            position: fixed;
            left: 50%;
            bottom: 24px;
            transform: translateX(-50%);
            padding: 10px 16px;
            border-radius: 999px;
            background: var(--ink);
            color: var(--card);
            font-size: 14px;
            opacity: 0;
            transition: opacity .2s;
            pointer-events: none;
        }

        .toast.show { opacity: 1; }
    </style>
</head>
<body>
    <main class="card">
        <div class="board" aria-hidden="true">
            <span>X</span><span class="o">O</span><span></span>
            <span></span><span>X</span><span class="o">O</span>
            <span class="o">O</span><span></span><span>X</span>
        </div>

        <h1>{{ $invite ? $invite['inviter']['name'] . ' mời bạn đấu Caro!' : 'Lời mời đấu Caro' }}</h1>
        <p class="sub">Thắng đủ số trận để nhận voucher giảm giá đặt phòng trên 365Home.</p>

        <div class="code-box">
            <div class="code-label">Mã mời</div>
            <div class="code" id="code">{{ $code }}</div>
            <div class="status {{ $isOpen ? '' : 'is-closed' }}" id="status"
                 data-seconds="{{ $isOpen ? (int) $invite['seconds_left'] : 0 }}">
                @if ($isOpen)
                    Còn hiệu lực <span id="countdown"></span>
                @elseif (! $invite)
                    Không tìm thấy lời mời — kiểm tra lại mã với bạn của bạn.
                @elseif ($status === 'accepted')
                    Lời mời này đã có người nhận.
                @else
                    Lời mời đã hết hạn — nhờ bạn gửi mã mới nhé.
                @endif
            </div>
        </div>

        <button type="button" class="btn" id="open-app">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
            <span id="open-app-label">Sao chép mã &amp; mở app</span>
        </button>

        <div class="stores">
            <a class="store" href="{{ $ios }}" rel="noopener">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16.37 12.6c-.02-2.2 1.8-3.26 1.88-3.31-1.02-1.5-2.62-1.7-3.19-1.72-1.36-.14-2.65.8-3.34.8-.69 0-1.75-.78-2.88-.76-1.48.02-2.85.86-3.61 2.19-1.54 2.67-.39 6.62 1.11 8.79.73 1.06 1.6 2.25 2.75 2.2 1.1-.04 1.52-.71 2.85-.71s1.71.71 2.88.69c1.19-.02 1.94-1.08 2.66-2.14.84-1.23 1.19-2.42 1.21-2.48-.03-.01-2.32-.89-2.34-3.55ZM14.18 6.13c.61-.74 1.02-1.76.91-2.78-.88.04-1.94.59-2.57 1.32-.56.65-1.06 1.69-.93 2.69.98.08 1.98-.5 2.59-1.23Z"/></svg>
                App Store
            </a>
            <a class="store" href="{{ $android }}" rel="noopener">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3.6 2.3c-.2.2-.3.5-.3.9v17.6c0 .4.1.7.3.9l.1.1 9.9-9.9v-.2L3.7 2.2l-.1.1Zm13.3 13.1-3.3-3.3v-.2l3.3-3.3.1.1 3.9 2.2c1.1.6 1.1 1.7 0 2.3l-3.9 2.2h-.1Zm-.1.1-3.4-3.4L3.6 21.9c.4.4.9.4 1.6.1l11.6-6.5ZM16.8 8.5 5.2 1.9c-.7-.4-1.2-.3-1.6.1l9.8 9.8 3.4-3.3Z"/></svg>
                Google Play
            </a>
        </div>

        <ol>
            <li>Mở app <b>365Home</b> (chưa có thì tải ở nút trên).</li>
            <li>Vào <b>Mini game Caro</b> → <b>Nhập mã mời</b>.</li>
            <li>Dán / nhập mã <b>{{ $code }}</b> để vào trận.</li>
        </ol>
    </main>

    <div class="toast" id="toast" role="status" aria-live="polite"></div>

    <script>
        (function () {
            var code = @json($code);
            var ua = navigator.userAgent || '';
            var isIOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            var isAndroid = /Android/i.test(ua);
            var storeUrl = isIOS ? @json($ios) : (isAndroid ? @json($android) : null);

            var toast = document.getElementById('toast');
            function showToast(text) {
                toast.textContent = text;
                toast.classList.add('show');
                setTimeout(function () { toast.classList.remove('show'); }, 2200);
            }

            function copy(text) {
                if (navigator.clipboard && window.isSecureContext) {
                    return navigator.clipboard.writeText(text);
                }
                return new Promise(function (resolve, reject) {
                    var input = document.createElement('textarea');
                    input.value = text;
                    input.setAttribute('readonly', '');
                    input.style.position = 'fixed';
                    input.style.opacity = '0';
                    document.body.appendChild(input);
                    input.select();
                    try { document.execCommand('copy') ? resolve() : reject(); } catch (e) { reject(e); }
                    document.body.removeChild(input);
                });
            }

            // Máy tính: chỉ chép mã (tải app bằng 2 nút store bên dưới).
            if (!storeUrl) document.getElementById('open-app-label').textContent = 'Sao chép mã mời';

            document.getElementById('open-app').addEventListener('click', function () {
                copy(code).then(function () {
                    showToast('Đã sao chép mã ' + code);
                }, function () {
                    showToast('Mã mời: ' + code);
                }).then(function () {
                    if (storeUrl) setTimeout(function () { window.location.href = storeUrl; }, 700);
                });
            });

            // Đếm ngược thời hạn lời mời.
            var status = document.getElementById('status');
            var left = parseInt(status.getAttribute('data-seconds'), 10) || 0;
            var countdown = document.getElementById('countdown');
            if (countdown && left > 0) {
                var tick = function () {
                    if (left <= 0) {
                        status.classList.add('is-closed');
                        status.textContent = 'Lời mời đã hết hạn — nhờ bạn gửi mã mới nhé.';
                        return;
                    }
                    var m = Math.floor(left / 60), s = left % 60;
                    countdown.textContent = m + ':' + (s < 10 ? '0' : '') + s;
                    left--;
                    setTimeout(tick, 1000);
                };
                tick();
            }
        })();
    </script>
</body>
</html>
