<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index, follow">
    <title>Yêu cầu xoá tài khoản — 365Home</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f3f4f6;
            color: #111827;
            padding: 24px 12px 48px;
        }
        .wrap { max-width: 640px; margin: 0 auto; }
        .card {
            background: #fff;
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        h1 { font-size: 1.3rem; margin: 0 0 4px; }
        h2 { font-size: 1.05rem; margin: 0 0 12px; }
        .sub { color: #6b7280; font-size: 0.85rem; margin-bottom: 16px; }
        p { line-height: 1.55; font-size: 0.92rem; }
        ul { line-height: 1.6; font-size: 0.92rem; padding-left: 20px; }
        .alert { padding: 10px 14px; border-radius: 8px; font-size: 0.85rem; margin-bottom: 16px; display: none; }
        .alert.show { display: block; }
        .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-info { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
        label { display: block; font-size: 0.82rem; font-weight: 600; margin-bottom: 4px; color: #374151; }
        input[type=tel], input[type=text] {
            width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 8px;
            font-size: 0.9rem; margin-bottom: 14px;
        }
        .row { display: flex; gap: 10px; align-items: flex-start; }
        .row > div { flex: 1; }
        button {
            padding: 9px 16px; border-radius: 8px; border: none; font-size: 0.88rem;
            font-weight: 600; cursor: pointer;
        }
        button:disabled { opacity: 0.6; cursor: not-allowed; }
        .btn-primary { background: #dc2626; color: #fff; }
        .btn-secondary { background: #f3f4f6; color: #111827; border: 1px solid #d1d5db; }
        .checkbox-row { display: flex; align-items: flex-start; gap: 8px; margin: 4px 0 16px; font-size: 0.85rem; }
        .checkbox-row input { margin-top: 3px; }
        .step { display: none; }
        .step.active { display: block; }
        .hint { font-size: 0.78rem; color: #9ca3af; margin-top: -8px; margin-bottom: 14px; }
        .contact { font-size: 0.88rem; color: #374151; }
        .contact a { color: #111827; font-weight: 600; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Yêu cầu xoá tài khoản</h1>
            <div class="sub">365Home — Ứng dụng đặt phòng theo giờ / qua đêm</div>

            <p>Khi tài khoản 365Home của bạn bị xoá:</p>
            <ul>
                <li>Hồ sơ cá nhân (họ tên, ngày sinh, ảnh CCCD đã lưu) sẽ bị xoá khỏi hệ thống.</li>
                <li>Thông tin người đi cùng đã lưu (CCCD) gắn với tài khoản sẽ bị xoá.</li>
                <li>Bạn sẽ không thể đăng nhập lại bằng số điện thoại này và mọi ưu đãi/điểm thành viên còn lại sẽ mất hiệu lực.</li>
                <li>Lịch sử đơn đặt phòng có thể được lưu lại ở dạng không định danh trong một thời gian theo quy định kế toán/pháp luật hiện hành, nhưng không còn gắn với hồ sơ cá nhân của bạn.</li>
            </ul>
            <p>Thao tác này <strong>không thể hoàn tác</strong>.</p>
        </div>

        <div class="card">
            <h2>Cách 1 — Xoá ngay trên trang này</h2>
            <div id="alertBox" class="alert"></div>

            <div id="step1" class="step active">
                <label for="phone">Số điện thoại đã đăng ký tài khoản</label>
                <input type="tel" id="phone" placeholder="0912345678" autocomplete="tel">
                <div class="hint">Mã xác nhận sẽ được gửi qua Zalo của số điện thoại này.</div>
                <button type="button" class="btn-primary" id="btnSendOtp">Gửi mã xác nhận</button>
            </div>

            <div id="step2" class="step">
                <label for="otp">Mã xác nhận (OTP) từ Zalo</label>
                <input type="text" id="otp" placeholder="6 chữ số" inputmode="numeric" maxlength="6">
                <div class="checkbox-row">
                    <input type="checkbox" id="confirmCheck">
                    <label for="confirmCheck" style="margin:0;font-weight:400;">Tôi hiểu thao tác xoá tài khoản không thể hoàn tác.</label>
                </div>
                <div class="row">
                    <button type="button" class="btn-secondary" id="btnBack">Quay lại</button>
                    <button type="button" class="btn-primary" id="btnDelete">Xác nhận xoá tài khoản</button>
                </div>
            </div>

            <div id="step3" class="step">
                <p>Yêu cầu xoá tài khoản của bạn đã được thực hiện thành công.</p>
            </div>
        </div>

        <div class="card">
            <h2>Cách 2 — Xoá trong ứng dụng</h2>
            <p>Mở ứng dụng 365Home → Tài khoản → Cài đặt → Xoá tài khoản.</p>
        </div>

        <div class="card contact">
            <h2>Cần hỗ trợ?</h2>
            <p>
                Liên hệ chúng tôi nếu bạn gặp khó khăn khi yêu cầu xoá tài khoản:<br>
                @if ($business?->phone)
                    Hotline: <a href="tel:{{ $business->phone }}">{{ $business->phone }}</a><br>
                @endif
                @if ($business?->email)
                    Email: <a href="mailto:{{ $business->email }}">{{ $business->email }}</a>
                @endif
            </p>
        </div>
    </div>

    <script>
        const alertBox = document.getElementById('alertBox');
        const step1 = document.getElementById('step1');
        const step2 = document.getElementById('step2');
        const step3 = document.getElementById('step3');
        const phoneInput = document.getElementById('phone');
        const otpInput = document.getElementById('otp');
        const btnSendOtp = document.getElementById('btnSendOtp');
        const btnBack = document.getElementById('btnBack');
        const btnDelete = document.getElementById('btnDelete');
        const confirmCheck = document.getElementById('confirmCheck');

        function showAlert(type, message) {
            alertBox.className = 'alert show alert-' + type;
            alertBox.textContent = message;
        }

        function hideAlert() {
            alertBox.className = 'alert';
        }

        function showStep(step) {
            [step1, step2, step3].forEach(s => s.classList.remove('active'));
            step.classList.add('active');
        }

        async function postJson(url, body) {
            const res = await fetch(url, {
                method: 'POST',
                headers: Object.assign({ 'Content-Type': 'application/json', 'Accept': 'application/json' }, body._headers || {}),
                body: JSON.stringify(body),
            });
            const data = await res.json().catch(() => ({}));
            return { ok: res.ok, status: res.status, data };
        }

        btnSendOtp.addEventListener('click', async () => {
            hideAlert();
            const phone = phoneInput.value.trim();
            if (!/^(0|\+84)[0-9]{9}$/.test(phone)) {
                showAlert('error', 'Số điện thoại không hợp lệ.');
                return;
            }
            btnSendOtp.disabled = true;
            btnSendOtp.textContent = 'Đang gửi...';
            const { ok, data } = await postJson('/api/auth/send-otp', { phone });
            btnSendOtp.disabled = false;
            btnSendOtp.textContent = 'Gửi mã xác nhận';
            if (!ok) {
                showAlert('error', data.message || 'Không gửi được mã xác nhận. Vui lòng thử lại.');
                return;
            }
            showAlert('info', data.message || 'Đã gửi mã xác nhận qua Zalo.');
            showStep(step2);
        });

        btnBack.addEventListener('click', () => {
            hideAlert();
            showStep(step1);
        });

        btnDelete.addEventListener('click', async () => {
            hideAlert();
            const phone = phoneInput.value.trim();
            const otp = otpInput.value.trim();

            if (!confirmCheck.checked) {
                showAlert('error', 'Vui lòng xác nhận bạn hiểu thao tác này không thể hoàn tác.');
                return;
            }
            if (otp.length !== 6) {
                showAlert('error', 'Mã xác nhận phải gồm 6 chữ số.');
                return;
            }

            btnDelete.disabled = true;
            btnDelete.textContent = 'Đang xử lý...';

            const verify = await postJson('/api/auth/verify-otp', { phone, otp });

            if (!verify.ok) {
                btnDelete.disabled = false;
                btnDelete.textContent = 'Xác nhận xoá tài khoản';
                showAlert('error', verify.data.message || 'Mã xác nhận không đúng hoặc đã hết hạn.');
                return;
            }

            if (verify.data.is_new_user) {
                btnDelete.disabled = false;
                btnDelete.textContent = 'Xác nhận xoá tài khoản';
                showAlert('error', 'Không tìm thấy tài khoản nào gắn với số điện thoại này.');
                return;
            }

            const token = verify.data.token;
            const delRes = await fetch('/api/auth/account', {
                method: 'DELETE',
                headers: {
                    'Accept': 'application/json',
                    'Authorization': 'Bearer ' + token,
                },
            });

            btnDelete.disabled = false;
            btnDelete.textContent = 'Xác nhận xoá tài khoản';

            if (!delRes.ok) {
                showAlert('error', 'Không thể xoá tài khoản lúc này. Vui lòng liên hệ hỗ trợ.');
                return;
            }

            showStep(step3);
        });
    </script>
</body>
</html>
