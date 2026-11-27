<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $collect['title'] }} {{ $collect['code'] }}</title>
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
        [hidden] { display:none !important; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card" id="pay" @if ($collect['status'] !== 'pending') hidden @endif>
        <h1>{{ $collect['title'] }} <span style="color:var(--muted);font-weight:400">{{ $collect['code'] }}</span></h1>
        <p class="sub">Quét mã QR bằng ứng dụng ngân hàng. Khoản thanh toán được ghi nhận tự động sau khi chuyển khoản thành công.</p>
        @if ($qrImage)
            <img class="qr" src="{{ $qrImage }}" alt="Mã QR chuyển khoản">
        @endif
        <dl>
            <div class="row"><dt>Số tiền</dt><dd class="amount">{{ number_format($collect['amount'], 0, ',', '.') }} ₫</dd></div>
            <div class="row"><dt>Ngân hàng</dt><dd>{{ $collect['bank']['code'] }}</dd></div>
            <div class="row"><dt>Số tài khoản</dt><dd>{{ $collect['bank']['account_number'] }}</dd></div>
            <div class="row"><dt>Chủ tài khoản</dt><dd>{{ $collect['bank']['account_holder'] }}</dd></div>
            <div class="row"><dt>Nội dung</dt><dd><span id="code">{{ $collect['code'] }}</span><button class="copy" type="button" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('code').textContent)">Chép</button></dd></div>
        </dl>
        <p class="note">Giữ nguyên <b>số tiền</b> và <b>nội dung chuyển khoản</b> ở trên. Sai một trong hai thì hệ thống không tự ghi nhận được khoản này.</p>
    </div>

    <div class="card state ok" id="paid" @if ($collect['status'] !== 'paid') hidden @endif>
        <strong>Thanh toán thành công</strong>
        Khoản {{ $collect['code'] }} đã được ghi nhận.
    </div>

    <div class="card state bad" id="closed" @if ($collect['status'] !== 'closed') hidden @endif>
        <strong>Yêu cầu thanh toán đã đóng</strong>
        Vui lòng tạo lại yêu cầu thanh toán để lấy mã mới.
    </div>
</div>

<script>
    (function () {
        var statusUrl = @json($statusUrl);
        var done = @json($collect['status'] !== 'pending');
        function show(id) { ['pay', 'paid', 'closed'].forEach(function (k) { document.getElementById(k).hidden = (k !== id); }); }
        function poll() {
            if (done) return;
            fetch(statusUrl, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (j) {
                    if (!j || !j.data) return;
                    if (j.data.status === 'paid') { done = true; show('paid'); }
                    else if (j.data.status === 'closed') { done = true; show('closed'); }
                })
                .catch(function () {});
        }
        setInterval(poll, 5000);
    })();
</script>
</body>
</html>
