<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Chuyển khoản thanh toán</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px 16px;
            background: #f3f4f6;
            color: #1f2937;
        }

        .card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            max-width: 460px;
            width: 100%;
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: white;
            padding: 24px 20px;
            text-align: center;
        }

        .card-header h1 { font-size: 20px; margin-bottom: 4px; }
        .card-header p { font-size: 14px; opacity: .9; }

        .card-body { padding: 20px; }

        .qr {
            display: block;
            width: 100%;
            max-width: 300px;
            margin: 0 auto 16px;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
        }

        .row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px dashed #e5e7eb;
            font-size: 14px;
        }

        .row span:first-child { color: #6b7280; }
        .row strong { text-align: right; word-break: break-all; }
        .row .amount { color: #dc2626; font-size: 18px; }

        .copy {
            border: none;
            background: #eff6ff;
            color: #2563eb;
            border-radius: 6px;
            padding: 4px 8px;
            margin-left: 6px;
            cursor: pointer;
            font-size: 12px;
        }

        .notice {
            margin-top: 16px;
            padding: 12px;
            border-radius: 10px;
            background: #fffbeb;
            color: #92400e;
            font-size: 13px;
            line-height: 1.5;
        }

        .status {
            margin-top: 16px;
            text-align: center;
            font-size: 14px;
            color: #2563eb;
        }

        .status.done { color: #16a34a; font-weight: 600; }
        .status.expired { color: #dc2626; }
    </style>
</head>

<body>
    <div class="card">
        <div class="card-header">
            <h1><i class="fa-solid fa-building-columns"></i> Chuyển khoản thanh toán</h1>
            <p>Đơn #{{ $order->order_code }}</p>
        </div>

        <div class="card-body">
            <img class="qr" src="{{ $qrImage }}" alt="Mã VietQR thanh toán đơn {{ $order->order_code }}">

            <div class="row"><span>Ngân hàng</span><strong>{{ $info['bank_name'] }}</strong></div>
            <div class="row">
                <span>Số tài khoản</span>
                <strong>{{ $info['account_number'] }}<button class="copy" data-copy="{{ $info['account_number'] }}">Sao chép</button></strong>
            </div>
            @if ($info['account_holder'])
                <div class="row"><span>Chủ tài khoản</span><strong>{{ $info['account_holder'] }}</strong></div>
            @endif
            <div class="row">
                <span>Số tiền</span>
                <strong class="amount">{{ number_format($info['amount'], 0, ',', '.') }}đ<button class="copy" data-copy="{{ $info['amount'] }}">Sao chép</button></strong>
            </div>
            <div class="row">
                <span>Nội dung</span>
                <strong>{{ $info['content'] }}<button class="copy" data-copy="{{ $info['content'] }}">Sao chép</button></strong>
            </div>

            <div class="notice">
                <i class="fa-solid fa-circle-info"></i>
                Quét mã bằng ứng dụng ngân hàng, hoặc chuyển khoản <b>đúng số tiền</b> và <b>đúng nội dung</b> <b>{{ $info['content'] }}</b>
                để đơn được xác nhận tự động.
                @if ($order->expired_at)
                    Giữ phòng đến <b>{{ \Illuminate\Support\Carbon::parse($order->expired_at)->format('H:i d/m/Y') }}</b>.
                @endif
            </div>

            <div class="status" id="status"><i class="fa-solid fa-spinner fa-spin"></i> Đang chờ nhận tiền...</div>
        </div>
    </div>

    <script>
        document.querySelectorAll('.copy').forEach(function (btn) {
            btn.addEventListener('click', function () {
                navigator.clipboard && navigator.clipboard.writeText(btn.dataset.copy);
                btn.textContent = 'Đã chép';
                setTimeout(function () { btn.textContent = 'Sao chép'; }, 1500);
            });
        });

        // Webhook SePay xác nhận đơn ở server — trang chỉ hỏi lại trạng thái và chuyển sang trang thành công khi tiền đã về.
        (function poll() {
            var statusEl = document.getElementById('status');
            var initialStatus = @json($order->status);

            function check() {
                fetch(@json($statusUrl), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res.status !== initialStatus && ['paid', 'deposit'].indexOf(res.status) !== -1) {
                            statusEl.className = 'status done';
                            statusEl.innerHTML = '<i class="fa-solid fa-circle-check"></i> Đã nhận tiền — đang chuyển trang...';
                            window.location.href = @json(route('payment.success', ['orderCode' => $order->order_code]));
                            return;
                        }
                        if (['cancelled_payment', 'failed'].indexOf(res.status) !== -1) {
                            statusEl.className = 'status expired';
                            statusEl.textContent = 'Đơn đã hết hạn giữ phòng. Nếu bạn đã chuyển khoản, vui lòng liên hệ chủ nhà.';
                            return;
                        }
                        setTimeout(check, 4000);
                    })
                    .catch(function () { setTimeout(check, 8000); });
            }

            setTimeout(check, 4000);
        })();
    </script>
</body>

</html>
