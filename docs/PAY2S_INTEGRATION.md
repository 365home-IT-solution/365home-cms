# Tích hợp Pay2S (cổng thứ hai, cùng luồng với SePay)

Đối tác chọn MỘT kênh thu tiền: **PayOS** (mặc định) · **SePay** · **Pay2S**. Pay2S và SePay dùng chung bảng tài khoản / giao dịch / yêu cầu thanh toán và chung `SePayService::handleIncoming()`
(kiểm tra tài khoản, mã `DH########`, số tiền, trạng thái đơn → xác nhận đơn), chỉ khác cách nhận tiền vào.

| | SePay | Pay2S |
|---|---|---|
| Webhook URL | `https://<domain>/api/webhook/sepay` | `https://<domain>/api/webhook/pay2s` |
| Xác thực | HMAC-SHA256 (`X-SePay-Signature`) | `Authorization: Bearer <token>` — token RIÊNG của từng webhook (từng tài khoản ngân hàng) |
| Payload | một giao dịch | `{"transactions":[{id, gateway, transactionDate, transactionNumber, accountNumber, content, transferType IN/OUT, transferAmount, checksum}]}` |
| Trả lời | HTTP 200 + `{"success":true}` | HTTP 200 + `{"success":true}` |
| Gửi lại | 7 lần / 5 giờ | 5 lần cách nhau 60 giây (rồi dừng) — nên cần API kéo dự phòng |
| API kéo giao dịch | `GET my.sepay.vn/userapi/transactions/list` (Bearer) | `POST api.pay2s.vn/userapi/transactions` (header `pay2s-token`, `begin`/`end` dd/mm/yyyy, tối đa 60 lần/phút) |
| Nội dung VietinBank | thêm `SEVQR` | giữ nguyên |

## Việc làm một lần (Super Admin)

1. Chạy `php artisan migrate --force` (thêm cột `provider`, `webhook_token`; tạo cài đặt `pay2s`), rồi `php artisan optimize:clear`.
2. CMS → Cấu hình web → **Pay2S — cấu hình chung**: bật Pay2S, nhập **API Token** (my.pay2s.vn → Tích hợp Web/App), bấm **Kiểm tra kết nối API Pay2S**.
3. Tiền tố mã thanh toán dùng chung với SePay (mặc định `DH`).

## Với MỖI đối tác dùng Pay2S

1. Đối tác (chủ tài khoản) tự thêm tài khoản ngân hàng vào Pay2S: my.pay2s.vn → Ngân hàng → Thêm tài khoản → chọn ngân hàng, loại API, nhập thông tin, đồng ý chính sách, xác nhận OTP nếu được hỏi.
2. CMS → **SePay / Pay2S theo đối tác** → Tạo mới: chọn đối tác, **Cổng = Pay2S**, ngân hàng, số tài khoản, chủ tài khoản, bật "Dùng chuyển khoản tự động". Lưu.
3. Pay2S → **Webhooks → Thêm webhook**:
   - Tài khoản ngân hàng: chọn đúng tài khoản của đối tác;
   - Loại: **Nhận tiền** (IN);
   - URL: `https://<domain>/api/webhook/pay2s`;
   - Lưu → Pay2S hiện **token một lần duy nhất** → chép ngay.
4. CMS → sửa dòng tài khoản đó → dán **Token webhook Pay2S** → Lưu.
5. Thử: tạo đơn thật, chuyển đúng số tiền với nội dung `DH########` → đơn tự chuyển "đã thanh toán". Kiểm tra CMS → **Giao dịch SePay / Pay2S** (cột Cổng = Pay2S, trạng thái "Đã xác nhận đơn").

## Khi không tự xác nhận

- Không có dòng nào ở "Giao dịch SePay / Pay2S": Pay2S chưa gửi (tài khoản chưa liên kết xong, webhook gắn sai tài khoản/loại, hoặc token sai → webhook trả 401). Xem lịch sử webhook trên Pay2S (có nút gửi lại) hoặc đợi lệnh `sepay:sync` kéo bù (mỗi phút, cần API Token).
- "Bị từ chối": đọc cột Lý do (sai số tiền, số tài khoản chưa khai báo, cổng khai báo khác, đơn không còn chờ…).
- Tiền vào ngoài hệ thống (không có mã `DH########`) không được ghi.

## Lưu ý

- Mỗi đối tác chỉ có một tài khoản/cổng tại một thời điểm; đổi cổng = sửa dòng đó (đổi Cổng, nhập token nếu Pay2S). Yêu cầu thanh toán đã tạo trước đó vẫn được xử lý nếu tiền về cổng cũ? Không: giao dịch từ cổng khác với cổng đang khai báo bị từ chối kèm lý do để đối soát.
- Pay2S chỉ lọc API theo ngày; hệ thống bỏ qua giao dịch cũ hơn số giờ yêu cầu.
- Phần `vaNumber` (tài khoản ảo) nếu Pay2S gửi sẽ được khớp như `subAccount` của SePay.
