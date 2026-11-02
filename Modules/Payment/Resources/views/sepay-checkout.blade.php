<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Thanh toán đơn {{ $payment['order_code'] }}</title>
    <style>
        :root { --ink:#1f2937; --muted:#6b7280; --line:#e5e7eb; --brand:#1f4e79; --ok:#15803d; --bad:#b91c1c; --bg:#f5f7fa; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--bg); color:var(--ink); font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
        .wrap { max-width:460px; margin:0 auto; padding:16px; }
        .card { background:#fff; border:1px solid var(--line); border-radius:14px; padding:20px; }
        h1 { margin:0 0 4px; font-size:1.15rem; }
        .sub { color:var(--muted); font-size:.9rem; margin:0 0 16px; }
        .qr { display:block; margin:0 auto 16px; width:100%; max-width:300px; height:auto; border:1px solid var(--line); border-radius:10px; }
        dl { margin:0; }
        .row { display:flex; justify-content:space-between; gap:12px; padding:10px 0; border-top:1px solid var(--line); }
        .row dt { color:var(--muted); font-size:.9rem; flex:0 0 38%; }
        .row dd { margin:0; font-weight:600; text-align:right; word-break:break-word; }
        .amount { font-size:1.35rem; color:var(--brand); }
        button.copy { margin-left:8px; border:1px solid var(--line); background:#fff; border-radius:8px; padding:2px 10px; font-size:.8rem; cursor:pointer; }
        .note { margin:16px 0 0; padding:10px 12px; background:#fff7ed; border:1px solid #fed7aa; border-radius:10px; font-size:.86rem; color:#9a3412; }
        .state { text-align:center; padding:28px 8px; }
        .state strong { display:block; font-size:1.2rem; margin-bottom:6px; }
        .ok strong { color:var(--ok); } .bad strong { color:var(--bad); }
        a.btn { display:inline-block; margin-top:14px; padding:10px 18px; border-radius:10px; background:var(--brand); color:#fff; text-decoration:none; }
        .timer { text-align:center; color:var(--muted); font-size:.9rem; margin-top:12px; }
        button.claim { display:block; width:100%; margin-top:14px; padding:12px; border:0; border-radius:10px; background:var(--brand); color:#fff; font-size:1rem; font-weight:600; cursor:pointer; }
        button.claim[disabled] { opacity:.6; cursor:default; }
        .claimed { margin-top:14px; padding:10px 12px; background:#ecfdf5; border:1px solid #a7f3d0; border-radius:10px; font-size:.9rem; color:#065f46; text-align:center; }
        [hidden] { display:none !important; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card" id="pay" @if ($payment['status'] !== 'pending') hidden @endif>
        <h1>Thanh toán đơn #{{ $payment['order_code'] }}</h1>
        <p class="sub">@if (($payment['provider'] ?? null) === 'manual')Quét mã QR bằng ứng dụng ngân hàng. Sau khi chuyển khoản, chủ nhà sẽ xác nhận đã nhận tiền và đơn được xác nhận ngay sau đó.@else Quét mã QR bằng ứng dụng ngân hàng. Đơn được xác nhận tự động sau khi chuyển khoản thành công.@endif</p>
        <img class="qr" src="{{ $payment['qr_url'] }}" alt="Mã QR chuyển khoản">
        <dl>
            <div class="row"><dt>Số tiền</dt><dd class="amount">{{ number_format($payment['amount'], 0, ',', '.') }} ₫</dd></div>
            <div class="row"><dt>Ngân hàng</dt><dd>{{ $payment['bank']['code'] }}</dd></div>
            <div class="row"><dt>Số tài khoản</dt><dd>{{ $payment['bank']['account_number'] }}</dd></div>
            <div class="row"><dt>Chủ tài khoản</dt><dd>{{ $payment['bank']['account_holder'] }}</dd></div>
            <div class="row"><dt>Nội dung</dt><dd><span id="code">{{ $payment['transfer_content'] }}</span><button class="copy" type="button" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('code').textContent)">Chép</button></dd></div>
        </dl>
        <p class="note">Giữ nguyên <b>số tiền</b> và <b>nội dung chuyển khoản</b> ở trên. Sai một trong hai thì @if (($payment['provider'] ?? null) === 'manual')chủ nhà khó đối chiếu được khoản chuyển @else hệ thống không tự xác nhận được đơn @endif.</p>
        @if (($payment['provider'] ?? null) === 'manual')
            <button class="claim" type="button" id="claim" @if (! empty($payment['customer_claimed_at'])) hidden @endif>Tôi đã chuyển khoản</button>
            <div class="claimed" id="claimed" @if (empty($payment['customer_claimed_at'])) hidden @endif>Đã báo chủ nhà. Đơn sẽ được xác nhận ngay khi chủ nhà kiểm tra thấy tiền về.</div>
        @endif
        @if ($payment['expires_at'])
            <div class="timer">Còn lại <b id="left">--:--</b> để thanh toán</div>
        @endif
    </div>

    <div class="card state ok" id="paid" @if ($payment['status'] !== 'paid') hidden @endif>
        <strong>Thanh toán thành công</strong>
        Đơn #{{ $payment['order_code'] }} đã được xác nhận.
        <div><a class="btn" href="{{ $detailUrl }}">Xem đơn đặt phòng</a></div>
    </div>

    <div class="card state bad" id="closed" @if ($payment['status'] !== 'cancelled') hidden @endif>
        <strong>Yêu cầu thanh toán đã đóng</strong>
        Đơn đã đổi hoặc tạo lại mã thanh toán. Vui lòng mở lại đơn để lấy mã mới.
        <div><a class="btn" href="{{ $detailUrl }}">Xem đơn đặt phòng</a></div>
    </div>

    <div class="card state bad" id="expired" hidden>
        <strong>Đã hết thời gian thanh toán</strong>
        Vui lòng đặt lại hoặc liên hệ lễ tân nếu bạn đã chuyển khoản.
        <div><a class="btn" href="{{ $detailUrl }}">Xem đơn đặt phòng</a></div>
    </div>
</div>

<script>
    (function () {
        var statusUrl = @json($statusUrl);
        var expiresAt = @json($payment['expires_at']) ? new Date(@json($payment['expires_at'])).getTime() : null;
        var claimUrl = @json($payment['claim_url'] ?? null);
        var done = false;
        function show(id) { ['pay', 'paid', 'closed', 'expired'].forEach(function (k) { document.getElementById(k).hidden = (k !== id); }); }
        function tick() {
            var left = document.getElementById('left');
            if (done || !expiresAt || !left) return;
            var s = Math.max(0, Math.floor((expiresAt - Date.now()) / 1000));
            var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60);
            left.textContent = (h ? h + ':' : '') + String(m).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
            if (s === 0) { show('expired'); }
        }
        function poll() {
            if (done) return;
            fetch(statusUrl, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (j) {
                    if (!j || !j.data) return;
                    if (j.data.status === 'paid') { done = true; show('paid'); }
                    else if (j.data.status === 'cancelled') { done = true; show('closed'); }
                })
                .catch(function () {});
        }
        var claimBtn = document.getElementById('claim');
        if (claimBtn && claimUrl) {
            claimBtn.addEventListener('click', function () {
                claimBtn.disabled = true;
                fetch(claimUrl, { method: 'POST', headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                    .then(function (res) {
                        if (!res.ok) { claimBtn.disabled = false; alert((res.j && res.j.message) || 'Không gửi được, vui lòng thử lại.'); return; }
                        claimBtn.hidden = true;
                        document.getElementById('claimed').hidden = false;
                        if (res.j.data && res.j.data.expires_at) { expiresAt = new Date(res.j.data.expires_at).getTime(); }
                    })
                    .catch(function () { claimBtn.disabled = false; });
            });
        }
        setInterval(tick, 1000); tick();
        setInterval(poll, 4000);
    })();
</script>
</body>
</html>
