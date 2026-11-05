@php
    /** @var \App\Models\ContractShareLink|null $link */
    $partner = $link?->partner;
    $version = $link?->version;
    $title = $partner ? 'Hợp đồng ' . ($partner->common_name ?: $partner->legal_name ?: $partner->name) : 'Hợp đồng';
    $stateLabels = \App\Services\ContractShareLinkService::SIGNATURE_STATES;
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} — {{ config('app.name') }}</title>
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
        .topbar { background: #fff; border-bottom: 1px solid var(--line); }
        .topbar .inner { max-width: 1344px; margin: 0 auto; padding: 0 16px; display: flex; align-items: center; justify-content: space-between; height: 64px; gap: 12px; }
        .brand { font-weight: 800; letter-spacing: .02em; color: var(--brand); font-size: 1.15rem; text-decoration: none; white-space: nowrap; }
        .secure { font-size: .8rem; color: var(--muted); }

        .page { padding: 32px 16px 64px; }
        .card { max-width: 520px; margin: 0 auto; background: var(--card); border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04); padding: 28px 24px; }
        @media (min-width: 640px) { .card { padding: 36px 36px; } .page { padding-top: 56px; } }

        .file { display: flex; gap: 16px; align-items: flex-start; }
        .file-icon { flex: none; width: 52px; height: 64px; border-radius: 8px; background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; font-weight: 800; font-size: .75rem; display: flex; align-items: flex-end; justify-content: center; padding-bottom: 10px; }
        .file h1 { font-size: 1.15rem; line-height: 1.35; margin: 0; overflow-wrap: anywhere; }
        .meta { color: var(--muted); font-size: .85rem; margin-top: 4px; }

        .pill { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 9999px; font-weight: 700; font-size: .78rem; margin-top: 10px; }
        .pill-ok { background: var(--ok-bg); color: var(--ok); border: 1px solid #a7f3d0; }
        .pill-wait { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }

        .rows { margin: 22px 0 0; padding: 16px 0 0; border-top: 1px solid var(--line); font-size: .88rem; }
        .rows div { display: flex; justify-content: space-between; gap: 12px; padding: 4px 0; }
        .rows dt { color: var(--muted); }
        .rows dd { margin: 0; font-weight: 600; text-align: right; }

        .actions { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 24px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; padding: 13px 16px; border-radius: 10px; font-size: .95rem; font-weight: 700; text-decoration: none; cursor: pointer; border: none; font-family: inherit; width: 100%; }
        .btn-primary { background: var(--brand); color: #fff; }
        .btn-primary:hover { background: var(--brand-dark); }
        .btn-secondary { background: #fff; color: var(--ink); border: 1px solid #d1d5db; }

        .alert { padding: 12px 14px; border-radius: 10px; font-size: .9rem; margin-top: 18px; }
        .alert-error { background: var(--err-bg); color: var(--err); border: 1px solid #fecaca; }
        .note { font-size: .8rem; color: var(--muted); margin-top: 16px; }

        label { display: block; font-size: .85rem; font-weight: 600; margin: 22px 0 6px; color: #374151; }
        input[type=password] { width: 100%; padding: 12px 14px; border: 1px solid #d1d5db; border-radius: 10px; font-size: 16px; background: #fff; font-family: inherit; }
        input[type=password]:focus { outline: 2px solid var(--brand); outline-offset: 1px; border-color: transparent; }
        .empty { text-align: center; }
        .empty h1 { font-size: 1.2rem; margin: 0 0 6px; }
    </style>
</head>
<body>
<header class="topbar">
    <div class="inner">
        <a class="brand" href="{{ url('/') }}">{{ mb_strtoupper(config('contract.platform.short_name', '365 HOME')) }}</a>
        <span class="secure">Tài liệu được chia sẻ</span>
    </div>
</header>

<main class="page">
    <div class="card">
        @if ($notFound)
            <div class="empty">
                <h1>Không tìm thấy tài liệu</h1>
                <p class="meta">Link không đúng hoặc đã bị xoá. Hãy liên hệ người đã gửi link cho bạn.</p>
            </div>
        @elseif ($reason)
            <div class="empty">
                <h1>{{ $reason }}</h1>
                <p class="meta">Hãy liên hệ người đã gửi link để được cấp link mới.</p>
            </div>
        @else
            <div class="file">
                <div class="file-icon">PDF</div>
                <div>
                    <h1>{{ $title }}</h1>
                    <div class="meta">
                        {{ $partner->legal_name }}
                        @if ($partner->contract_code) · Số {{ $partner->contract_code }} @endif
                    </div>
                    @if (! $locked)
                        <span class="pill {{ $signatureState === 'fully_signed' ? 'pill-ok' : 'pill-wait' }}">{{ $stateLabels[$signatureState] ?? '' }}</span>
                    @endif
                </div>
            </div>

            @if ($locked)
                <form method="POST" action="{{ route('contract-share.unlock', ['token' => $token]) }}">
                    @csrf
                    <label for="password">Tài liệu được bảo vệ bằng mật khẩu</label>
                    <input id="password" name="password" type="password" autocomplete="off" required autofocus>
                    @error('password')
                        <div class="alert alert-error">{{ $message }}</div>
                    @enderror
                    <div class="actions" style="grid-template-columns: 1fr;">
                        <button class="btn btn-primary" type="submit">Mở tài liệu</button>
                    </div>
                </form>
            @else
                <dl class="rows">
                    @if ($version->version_label)
                        <div><dt>Phiên bản</dt><dd>{{ $version->version_label }}</dd></div>
                    @endif
                    @if ($version->partner_signed_at || $version->partner_confirmed_at)
                        <div><dt>Đối tác ký</dt><dd>{{ ($version->partner_signed_at ?? $version->partner_confirmed_at)->format('d/m/Y H:i') }}</dd></div>
                    @endif
                    @if ($version->platform_signed_at)
                        <div><dt>{{ config('contract.platform.short_name', '365 HOME') }} ký</dt><dd>{{ $version->platform_signed_at->format('d/m/Y H:i') }}</dd></div>
                    @endif
                    @if ($link->expires_at)
                        <div><dt>Link hết hạn</dt><dd>{{ $link->expires_at->format('d/m/Y H:i') }}</dd></div>
                    @endif
                </dl>

                <div class="actions">
                    <a class="btn btn-secondary" href="{{ route('contract-share.file', ['token' => $token, 'mode' => 'xem']) }}" target="_blank" rel="noopener">Xem</a>
                    <a class="btn btn-primary" href="{{ route('contract-share.file', ['token' => $token, 'mode' => 'tai-xuong']) }}">Tải xuống</a>
                </div>

                @if ($signatureState !== 'fully_signed')
                    <p class="note">Hợp đồng chưa được hai bên ký đủ — file tải về chỉ để tham khảo.</p>
                @endif
            @endif
        @endif
    </div>
</main>
</body>
</html>
