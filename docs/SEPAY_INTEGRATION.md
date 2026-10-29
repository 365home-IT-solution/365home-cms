# Tích hợp SePay theo đối tác

Đối tác chọn thu tiền đặt phòng qua **SePay** (khách chuyển khoản vào tài khoản ngân hàng của đối tác, hệ thống tự xác nhận đơn) hoặc **PayOS** (như cũ). Mỗi đối tác chỉ dùng một kênh.

## 1. Cách hoạt động

1. Super Admin bật tài khoản SePay của đối tác ở CMS → Cấu hình web → **SePay theo đối tác**.
2. Từ lúc đó, mọi đơn của đối tác (trừ chi nhánh có "PayOS theo chi nhánh") tạo **yêu cầu thanh toán SePay** thay cho link PayOS: cọc, thanh toán đủ, phần còn lại, khoản phát sinh, cọc lại. `PayOsAccountResolver::forOrder()` trả `SePayGateway` (cùng giao diện với cổng PayOS) nên các luồng cũ không phải sửa.
3. Mỗi yêu cầu có **mã `DH` + 8 chữ số** (vd `DH48271936`) và đúng số tiền cần thu. Khách quét QR (ảnh QR kèm sẵn số tiền, nội dung) ở trang `/thanh-toan-sepay/{mã}`, hoặc app tự dựng từ dữ liệu `order.sepay`.
4. Khách chuyển khoản. Hệ thống xác nhận đơn bằng **hai đường song song**, cùng một bộ kiểm tra, mỗi giao dịch chỉ xử lý một lần (`sepay_id` unique):
   - **Webhook SePay** `POST /api/webhook/sepay`: tức thì.
   - **Kéo giao dịch từ API SePay** (cần `SEPAY_API_TOKEN`): trang QR và app hỏi trạng thái thì hệ thống tự hỏi SePay (mỗi yêu cầu tối đa 1 lần / 3 giây); lệnh `sepay:sync` chạy mỗi phút làm dự phòng khi webhook chậm hoặc lỡ.
5. Giao dịch không khớp **không đổi đơn**, được lưu "Bị từ chối" kèm lý do ở CMS → **Giao dịch SePay** để đối soát hoặc hoàn tiền.

Điều kiện dùng SePay: đối tác Homestay có hợp đồng mẫu mới đã hiệu lực (`payment_flow_effective_at`); chưa đủ điều kiện thì 365home vẫn thu hộ qua PayOS chung.

Mã chuyển khoản dài 8 chữ số để vừa "Cấu trúc mã thanh toán" mặc định của SePay (hậu tố số, 6–8 ký tự). Mã đơn dài **không** được dùng làm mã chuyển khoản.

## 2. Cấu hình chung ở CMS (một lần, chỉ Super Admin)

Nhập ngay trên CMS, không cần sửa `.env`: **Cấu hình web → SePay — cấu hình chung**.

| Ô | Ý nghĩa |
|---|---|
| Cho phép dùng SePay | Công tắc tổng. Tắt: mọi đối tác quay về PayOS ngay (không xoá dữ liệu). |
| Khoá bí mật webhook | Phải GIỐNG TUYỆT ĐỐI khoá nhập ở webhook trên SePay (Bảo mật → HMAC-SHA256). Có nút tạo khoá ngẫu nhiên. |
| Tiền tố mã thanh toán | Mặc định `DH`; trùng "Cấu trúc mã thanh toán" trên SePay (2–5 ký tự). |
| API Token | Tạo ở SePay → Cấu hình Công ty → API Access. Có token thì xác nhận đơn ngay cả khi webhook chậm. |
| Số tiền tối thiểu | Mặc định 1.000đ, chỉ áp dụng cho SePay (PayOS luôn 2.000đ). |

- Khoá bí mật được mã hoá khi lưu và không bao giờ hiện lại; để trống ô khoá khi lưu là giữ nguyên khoá cũ.
- Giá trị nhập ở CMS được ưu tiên hơn `.env`; ô nào để trống thì theo biến môi trường tương ứng (`SEPAY_ENABLED`, `SEPAY_WEBHOOK_SECRET`, `SEPAY_CODE_PREFIX`, `SEPAY_API_TOKEN`, `SEPAY_MIN_AMOUNT`, `SEPAY_WEBHOOK_URL`). Mỗi ô ghi rõ đang dùng giá trị CMS, `.env` hay chưa cấu hình.
- Nút **Kiểm tra kết nối API SePay** ở đầu trang gọi thử SePay bằng token đã lưu.

Sau khi cập nhật code trên máy chủ chỉ cần:

```bash
php artisan migrate --force
php artisan optimize:clear
```

- Migration SePay: `2026_10_29_000001` (tài khoản SePay đối tác), `…000002` (giao dịch SePay), `…000003` (yêu cầu thanh toán), `…000004` (cột `ref_code`, đổi sang mã ngắn), và cài đặt `database/settings/2026_10_29_000002_create_sepay_settings` (chạy cùng `migrate`).
- Scheduler của Laravel phải đang chạy (`php artisan schedule:run` mỗi phút, đã dùng cho `orders:expire-pending`). `sepay:sync` được đăng ký chạy mỗi phút khi đã có API Token, trước lệnh huỷ đơn hết hạn.

## 3. Cấu hình trên SePay (một lần, dùng chung mọi đối tác)

1. Liên kết tài khoản ngân hàng của đối tác vào công ty SePay.
2. **Cấu hình Công ty → Cấu hình chung → Cấu trúc mã thanh toán**: bật nhận diện mã thanh toán, tiền tố `DH`, kiểu **Số nguyên**, hậu tố tối thiểu ≤ 8 và tối đa ≥ 8 (mặc định 6–8 là đúng).
3. **Tích hợp → Webhooks → Thêm webhook**: sự kiện **Tiền vào**, chọn các tài khoản của đối tác, URL `https://365home.vn/api/webhook/sepay`, `application/json`, bảo mật **HMAC-SHA256** với đúng khoá bí mật nhập ở CMS (SePay — cấu hình chung), bật **Chỉ gửi khi có mã thanh toán** và chọn tiền tố `DH`, bật tự thử lại.
4. Nút "Gửi thử" của SePay dùng `id = 0`: hệ thống trả 200 nhưng không lưu gì (đúng thiết kế).

Lưu ý: VietinBank tài khoản cá nhân/hộ kinh doanh có thể yêu cầu nội dung chuyển khoản có tiền tố `SEVQR` — kiểm tra với ngân hàng của đối tác trước khi dùng.

## 4. Khai báo tài khoản cho từng đối tác (CMS, chỉ Super Admin)

CMS → Cấu hình web → **SePay theo đối tác** → Tạo mới: chọn đối tác, ngân hàng, số tài khoản (đúng tuyệt đối như đã liên kết ở SePay), chủ tài khoản, bật "Dùng SePay làm kênh thu tiền". Có thể tắt/bật lại bất kỳ lúc nào.

Tương đương API: `POST /api/admin/partners/{partner_uuid}/sepay-account` với `bank_code`, `account_number`, `account_holder`, `is_active`.

Chặn: không bật SePay khi đối tác đang bật kênh PayOS riêng (và ngược lại) — tắt kênh kia trước.

### Tài khoản doanh nghiệp / tài khoản ảo (VA)

Một số ngân hàng (BIDV, OCB, MSB, KienLongBank, VPBank hộ kinh doanh...) chỉ nhận tiền qua **tài khoản ảo (VA)**. Khi đó SePay gửi webhook với `accountNumber` = số tài khoản chính và `subAccount` = số VA. Ở CMS → **SePay theo đối tác** nhập **số mà khách chuyển tới** (số VA nếu có). Hệ thống khớp tài khoản theo `subAccount` trước, rồi `accountNumber`, nên nhập số chính hoặc số VA đều được. Đường kéo API không lọc theo số tài khoản nên cũng thấy giao dịch qua VA.

Đơn không tự xác nhận dù tiền đã vào: xem CMS → Giao dịch SePay. Dòng "Bị từ chối — Số tài khoản nhận tiền chưa được khai báo" nghĩa là số trong CMS không trùng `accountNumber`/`subAccount` SePay gửi (xem chi tiết dữ liệu gốc ở nút "Chi tiết"). Không có dòng nào nghĩa là SePay chưa nhận hoặc chưa gửi giao dịch (tài khoản chưa kết nối xong, hoặc webhook không bao gồm tài khoản đó).

## 5. Dữ liệu thanh toán cho app / web

- Phản hồi đơn (`POST/GET /api/guest/orders`, `POST /api/orders`) có thêm `order.payment_provider` (`sepay` | `payos`) và `order.sepay` (số tiền, nội dung, ngân hàng, `qr_url`, `expires_at`, `checkout_url`; null với PayOS). `order.qr_code` (chuỗi QR của PayOS) là `null` khi đơn dùng SePay — app dùng `order.sepay.qr_url`.
- `GET /api/public/sepay/orders/{order_code}/payment`: yêu cầu thanh toán đang chờ mới nhất của đơn (cọc, phần còn lại hoặc phát sinh).
- `GET /api/public/sepay/payments/{code}`: một yêu cầu theo mã 8 số; app hỏi lại định kỳ (3–5 giây) cho tới khi `status = paid`, hoặc dùng realtime/FCM có sẵn của đơn.

Trường trả về: `amount`, `payment_code` (nội dung chuyển khoản), `qr_url` (ảnh QR), `bank{code,bin,account_number,account_holder}`, `expires_at`, `status` (`pending` / `paid` / `cancelled`), `order_status`.

App không được tự sửa số tiền hoặc nội dung chuyển khoản.

## 6. Quy tắc xử lý giao dịch

- Webhook: xác thực HMAC trên raw body, `X-SePay-Signature` = `sha256=` + HMAC-SHA256(`timestamp.body`), timestamp lệch tối đa 5 phút. Chỉ nhận `transferType = in`.
- Tra yêu cầu theo mã `DH{8 số}` (ưu tiên trường `code` SePay tách sẵn, sau đó nội dung chuyển khoản).
- Phải khớp: số tài khoản thuộc **đúng đối tác của đơn** và đang bật; **đúng tuyệt đối số tiền** của yêu cầu; yêu cầu còn `pending`.
- Trạng thái đơn phải phù hợp khoản thu: đầu = `pending`; còn lại = `deposit`; phát sinh = còn khoản chờ. Đơn hết hạn / huỷ / hoàn tiền **không** được xác nhận (đơn hết hạn 15 phút sau khi tạo nếu chưa thanh toán).
- Lỗi xử lý nội bộ trả HTTP 500 để SePay tự thử lại (tối đa 7 lần trong 5 giờ); đường kéo API cũng tự chạy lại giao dịch lỗi.
- Đơn đổi giá làm huỷ yêu cầu cũ: tiền chuyển vào mã cũ bị từ chối và ghi lại để hoàn.

## 7. Kiểm tra sau khi triển khai

1. CMS → SePay theo đối tác: đối tác có dấu tick "Đang dùng" và "Đủ điều kiện".
2. Tạo đơn thử, mở `checkout_url` (trang QR phải hiện đúng số tiền, nội dung `DH` + 8 số, đúng tài khoản).
3. Chuyển khoản đúng số tiền và nội dung: đơn chuyển `paid` trong vài giây, CMS → Giao dịch SePay có dòng "Đã xác nhận đơn".
4. Nếu đơn không tự xác nhận: xem CMS → Giao dịch SePay (có dòng "Bị từ chối" kèm lý do không?); không có dòng nào thì SePay chưa gửi — kiểm tra webhook trên SePay (URL, secret, tiền tố, "Chỉ gửi khi có mã thanh toán"), và `storage/logs/sepay-sync.log` nếu đã có API Token. Chạy tay `php artisan sepay:sync` để kéo ngay.

Hỗ trợ thử ở máy dev: `php artisan sepay:simulate {mã đơn hoặc mã DH}` giả lập webhook (chỉ chạy khi APP_URL là máy local; máy chủ thật từ chối).
