<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ký hợp đồng điện tử — {{ $partner->name }}</title>
    <style>
        :root {
            --ink: #111827; --muted: #6b7280; --line: #e5e7eb; --bg: #f3f4f6; --card: #ffffff;
            --brand: #1f4b4d; --brand-dark: #173a3c; --ok: #065f46; --ok-bg: #ecfdf5; --err: #991b1b; --err-bg: #fef2f2;
        }
        * { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            margin: 0; background: var(--bg); color: var(--ink);
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 16px; line-height: 1.5;
        }
        /* Cùng bề ngang với các trang trên website (container ~1344px, lề co giãn theo màn hình). */
        .container { width: 100%; max-width: 1344px; margin: 0 auto; padding: 0 16px; }
        @media (min-width: 768px) { .container { padding: 0 32px; } }
        @media (min-width: 1440px) { .container { padding: 0; } }

        .topbar { background: #fff; border-bottom: 1px solid var(--line); }
        .topbar .container { display: flex; align-items: center; justify-content: space-between; height: 64px; gap: 12px; }
        .brand { font-weight: 800; letter-spacing: .02em; color: var(--brand); font-size: 1.15rem; text-decoration: none; white-space: nowrap; }
        .secure { font-size: .8rem; color: var(--muted); display: flex; align-items: center; gap: 6px; text-align: right; }

        .page { padding: 20px 0 48px; }
        @media (min-width: 768px) { .page { padding: 32px 0 64px; } }
        .head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 16px; }
        .head h1 { font-size: 1.35rem; line-height: 1.25; margin: 0; }
        @media (min-width: 768px) { .head h1 { font-size: 1.7rem; } }
        .sub { color: var(--muted); font-size: .9rem; margin-top: 2px; }
        .pill { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 9999px; font-weight: 700; font-size: .8rem; white-space: nowrap; }
        .pill-wait { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
        .pill-ok { background: var(--ok-bg); color: var(--ok); border: 1px solid #a7f3d0; }

        /* Máy tính: hợp đồng rộng bên trái + khung ký dính bên phải. Điện thoại/tablet: xếp dọc. */
        .layout { display: grid; grid-template-columns: minmax(0, 1fr); gap: 20px; align-items: start; }
        @media (min-width: 1024px) { .layout { grid-template-columns: minmax(0, 1fr) 380px; gap: 28px; } .side { position: sticky; top: 20px; } }

        .card { background: var(--card); border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04); }
        .paper { padding: 20px 16px; }
        @media (min-width: 640px) { .paper { padding: 32px 36px; } }
        @media (min-width: 1024px) { .paper { padding: 44px 56px; } }
        .contract-content { font-size: .95rem; line-height: 1.75; overflow-wrap: anywhere; overflow-x: auto; }
        @media (min-width: 1024px) { .contract-content { font-size: 1rem; } }
        .contract-content table { max-width: 100%; }
        .hash { font-size: .72rem; color: #9ca3af; word-break: break-all; margin-top: 20px; padding-top: 14px; border-top: 1px dashed var(--line); }

        .side-card { padding: 20px; }
        @media (min-width: 640px) { .side-card { padding: 24px; } }
        .side-card h2 { font-size: 1.05rem; margin: 0 0 4px; }
        .steps { margin: 12px 0 18px; padding: 0; list-style: none; font-size: .85rem; color: var(--muted); }
        .steps li { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 8px; }
        .steps b { flex: none; width: 22px; height: 22px; border-radius: 50%; background: var(--brand); color: #fff; font-size: .75rem; display: inline-flex; align-items: center; justify-content: center; }

        .alert { padding: 12px 14px; border-radius: 10px; font-size: .9rem; margin-bottom: 16px; }
        .alert-success { background: var(--ok-bg); color: var(--ok); border: 1px solid #a7f3d0; }
        .alert-error { background: var(--err-bg); color: var(--err); border: 1px solid #fecaca; }

        label { display: block; font-size: .85rem; font-weight: 600; margin-bottom: 6px; color: #374151; }
        input[type=text], input[type=email] {
            width: 100%; padding: 12px 14px; border: 1px solid #d1d5db; border-radius: 10px;
            font-size: 16px; margin-bottom: 14px; background: #fff; font-family: inherit;
        }
        input[type=text]:focus { outline: 2px solid var(--brand); outline-offset: 1px; border-color: transparent; }
        button {
            width: 100%; padding: 13px 16px; border-radius: 10px; border: none; font-size: .95rem;
            font-weight: 700; cursor: pointer; font-family: inherit;
        }
        .btn-primary { background: var(--brand); color: #fff; }
        .btn-primary:hover { background: var(--brand-dark); }
        .btn-secondary { background: #fff; color: var(--ink); border: 1px solid #d1d5db; }
        .checkbox-row { display: flex; align-items: flex-start; gap: 10px; margin: 6px 0 16px; font-size: .88rem; }
        .checkbox-row input { margin-top: 3px; width: 18px; height: 18px; flex: none; }
        .signed-meta { font-size: .88rem; color: var(--muted); margin-top: 12px; line-height: 1.7; }
        .jump { display: none; }
        @media (max-width: 1023px) { .jump { display: block; margin-bottom: 14px; } }
        .jump a { display: block; text-align: center; padding: 11px; border-radius: 10px; background: #fff; border: 1px solid var(--line); color: var(--brand); font-weight: 700; text-decoration: none; font-size: .9rem; }

        .btn-print { width: auto; padding: 8px 16px; background: #fff; color: var(--brand); border: 1px solid var(--line); }
        @media (max-width: 640px) { .hd { font-size: 11.5pt !important; } .hd p[style*="font-size:18pt"] { font-size: 15pt !important; } }

        /* In: đúng khổ mẫu — A4, lề trái 3cm / phải 1,5cm / trên-dưới 2cm; chỉ in toàn văn hợp đồng. */
        @page { size: A4; margin: 2cm 1.5cm 2cm 3cm; @bottom-right { content: counter(page); font-family: Calibri, Carlito, Arial, sans-serif; font-size: 11pt; } } /* số trang góc phải dưới như mẫu Word */
        @media print {
            body { background: #fff; padding: 0; }
            .topbar, .head, .jump, .side, .alert, .hash { display: none !important; }
            .container { max-width: none; padding: 0; }
            .page { padding: 0; }
            .layout { display: block; }
            .card { box-shadow: none; border-radius: 0; }
            .paper { padding: 0; }
            .contract-content { overflow: visible; font-size: 13pt; line-height: 22.4pt; }
            .hd, .hd-wrap { max-width: none !important; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="container">
            <a class="brand" href="{{ url('/') }}">365HOME.VN</a>
            <div class="secure">🔒 Ký hợp đồng điện tử an toàn</div>
        </div>
    </header>

    <main class="page">
        <div class="container">
            <div class="head">
                <div>
                    <h1>Ký hợp đồng điện tử</h1>
                    <div class="sub">Đối tác: {{ $partner->legal_name ?? $partner->name }}</div>
                </div>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <button type="button" class="btn-print" onclick="window.print()">🖨 In hợp đồng</button>
                    @if ($version->isPartnerConfirmed())
                        <span class="pill pill-ok">✓ Đã xác nhận</span>
                    @else
                        <span class="pill pill-wait">Chờ bạn xác nhận</span>
                    @endif
                </div>
            </div>

            @if (session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="alert alert-error">{{ session('error') }}</div>
            @endif

            @if (! $version->isPartnerConfirmed() && $canSign)
                <div class="jump"><a href="#sign-box">Xem hợp đồng, rồi kéo xuống để ký ↓</a></div>
            @endif

            <div class="layout">
                <article class="card paper">
                    <div class="contract-content">
                        {!! $framedContent !!}
                    </div>
                    <div class="hash">Mã xác thực nội dung (SHA-256): {{ $version->content_hash }}</div>
                </article>

                <aside class="side" id="sign-box">
                    <div class="card side-card">
                        @if ($version->isPartnerConfirmed())
                            <h2>Bạn đã ký hợp đồng</h2>
                            <div class="signed-meta">
                                Xác nhận bởi: <strong>{{ $version->partner_signed_by_name }}</strong><br>
                                Thời gian: {{ $version->partner_confirmed_at->format('H:i d/m/Y') }}<br>
                                IP: {{ $version->partner_signed_ip }}<br>
                                @if ($version->isPlatformSigned())
                                    Nền tảng đã ký số và phát hành hợp đồng lúc {{ $version->platform_signed_at->format('H:i d/m/Y') }}.
                                @else
                                    Đang chờ nền tảng ký số và phát hành hợp đồng chính thức.
                                @endif
                            </div>
                        @elseif ($canSign)
                            <h2>Xác nhận ký hợp đồng</h2>
                            <ol class="steps">
                                <li><b>1</b><span>Đọc toàn văn hợp đồng.</span></li>
                                <li><b>2</b><span>Nhận mã xác nhận (OTP) qua email.</span></li>
                                <li><b>3</b><span>Nhập họ tên, mã OTP và bấm ký.</span></li>
                            </ol>

                            <form method="POST" action="{{ route('contract.sign.send-otp', $version->signing_token) }}" style="margin-bottom:18px;">
                                @csrf
                                <button type="submit" class="btn-secondary">Gửi mã xác nhận qua email</button>
                            </form>

                            <form method="POST" action="{{ route('contract.sign.submit', $version->signing_token) }}">
                                @csrf
                                <label for="signer_name">Họ tên người ký</label>
                                <input type="text" id="signer_name" name="signer_name" value="{{ old('signer_name') }}" autocomplete="name" required>

                                <label for="otp">Mã xác nhận (OTP)</label>
                                <input type="text" id="otp" name="otp" maxlength="6" inputmode="numeric" autocomplete="one-time-code" required>

                                <div class="checkbox-row">
                                    <input type="checkbox" name="agree" id="agree" value="1" required>
                                    <label for="agree" style="margin:0;font-weight:400;">
                                        Tôi đã đọc toàn văn hợp đồng và đồng ý ký kết bằng chữ ký điện tử này.
                                    </label>
                                </div>

                                <button type="submit" class="btn-primary">Xác nhận ký hợp đồng</button>
                            </form>
                        @else
                            <div class="alert alert-error" style="margin:0;">
                                Hồ sơ pháp lý của đối tác chưa được Super Admin phê duyệt hoặc đang cần xác minh lại. Hợp đồng tạm thời chưa thể ký.
                            </div>
                        @endif
                    </div>
                </aside>
            </div>
        </div>
    </main>
</body>
</html>
