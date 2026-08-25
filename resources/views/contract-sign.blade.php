<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ký hợp đồng điện tử — {{ $partner->name }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f3f4f6;
            color: #111827;
            padding: 24px 12px;
        }
        .wrap { max-width: 720px; margin: 0 auto; }
        .card {
            background: #fff;
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        h1 { font-size: 1.2rem; margin: 0 0 4px; }
        .sub { color: #6b7280; font-size: 0.85rem; margin-bottom: 16px; }
        .contract-content { font-size: 0.92rem; border-top: 1px solid #e5e7eb; padding-top: 16px; }
        .hash { font-size: 0.72rem; color: #9ca3af; word-break: break-all; margin-top: 12px; }
        .alert { padding: 10px 14px; border-radius: 8px; font-size: 0.85rem; margin-bottom: 16px; }
        .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        label { display: block; font-size: 0.82rem; font-weight: 600; margin-bottom: 4px; color: #374151; }
        input[type=text], input[type=email] {
            width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 8px;
            font-size: 0.9rem; margin-bottom: 14px;
        }
        .row { display: flex; gap: 10px; align-items: flex-end; }
        .row > div { flex: 1; }
        button {
            padding: 9px 16px; border-radius: 8px; border: none; font-size: 0.88rem;
            font-weight: 600; cursor: pointer;
        }
        .btn-primary { background: #111827; color: #fff; }
        .btn-secondary { background: #f3f4f6; color: #111827; border: 1px solid #d1d5db; }
        .checkbox-row { display: flex; align-items: flex-start; gap: 8px; margin: 14px 0; font-size: 0.85rem; }
        .signed-badge {
            display: inline-block; padding: 6px 14px; border-radius: 9999px; background: #ecfdf5;
            color: #065f46; font-weight: 700; font-size: 0.85rem;
        }
        .signed-meta { font-size: 0.8rem; color: #6b7280; margin-top: 8px; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Ký hợp đồng điện tử</h1>
            <div class="sub">Đối tác: {{ $partner->legal_name ?? $partner->name }}</div>

            @if (session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="alert alert-error">{{ session('error') }}</div>
            @endif

            <div class="contract-content">
                {!! $framedContent !!}
            </div>

            <div class="hash">Mã xác thực nội dung (SHA-256): {{ $version->content_hash }}</div>
        </div>

        <div class="card">
            @if ($version->isPartnerConfirmed())
                <span class="signed-badge">✓ ĐÃ XÁC NHẬN</span>
                <div class="signed-meta">
                    Xác nhận bởi: {{ $version->partner_signed_by_name }}<br>
                    Thời gian: {{ $version->partner_confirmed_at->format('H:i d/m/Y') }}<br>
                    IP: {{ $version->partner_signed_ip }}<br>
                    @if ($version->isPlatformSigned())
                        Nền tảng đã ký số và phát hành hợp đồng lúc {{ $version->platform_signed_at->format('H:i d/m/Y') }}.
                    @else
                        Đang chờ nền tảng ký số và phát hành hợp đồng chính thức.
                    @endif
                </div>
            @else
                <form method="POST" action="{{ route('contract.sign.send-otp', $version->signing_token) }}" style="margin-bottom:16px;">
                    @csrf
                    <button type="submit" class="btn-secondary">Gửi mã xác nhận qua email</button>
                </form>

                <form method="POST" action="{{ route('contract.sign.submit', $version->signing_token) }}">
                    @csrf
                    <div class="row">
                        <div>
                            <label>Họ tên người ký</label>
                            <input type="text" name="signer_name" value="{{ old('signer_name') }}" required>
                        </div>
                        <div>
                            <label>Mã xác nhận (OTP)</label>
                            <input type="text" name="otp" maxlength="6" required>
                        </div>
                    </div>

                    <div class="checkbox-row">
                        <input type="checkbox" name="agree" id="agree" value="1" required style="margin-top:3px;">
                        <label for="agree" style="margin:0;font-weight:400;">
                            Tôi đã đọc toàn văn hợp đồng ở trên và đồng ý ký kết bằng chữ ký điện tử này.
                        </label>
                    </div>

                    <button type="submit" class="btn-primary">Xác nhận ký hợp đồng</button>
                </form>
            @endif
        </div>
    </div>
</body>
</html>
