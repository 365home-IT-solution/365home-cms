# Hợp đồng điện tử MiniHouse — ký khách thuê → chủ trọ (Mức A)

Tài liệu này được nhiều file code trong `Modules/Minihouse/App/Services/ContractDocumentService.php`,
`ContractDocumentRenderer.php`, các controller/migration liên quan tham chiếu tới bằng comment — đây
là bản ghi lại thiết kế thật sự đã triển khai, không phải bản spec gốc của app team (bản đó là 1 file
HTML đơn lẻ không lưu trong repo).

> ⚠️ **Không phải tư vấn pháp lý.** Tài liệu này thiết kế theo tinh thần **Luật Giao dịch điện tử
> 2023** (20/2023/QH15, hiệu lực 01/07/2024) nhưng do người làm kỹ thuật viết. Trước khi dùng hợp
> đồng điện tử làm căn cứ duy nhất khi có tranh chấp, nên đưa **luật sư xem qua ít nhất 1 lượt** —
> nhất là phần chọn mức chữ ký ở mục "Ba mức chữ ký" bên dưới. Mức A (đã triển khai) dựa trên OTP +
> hash + nhật ký, chưa dùng chữ ký số PKI — đủ điều kiện "chữ ký điện tử" theo luật, nhưng mức độ
> chống chối bỏ khi ra toà thấp hơn Mức C (chữ ký số CA).

## 1. Trạng thái đã triển khai

`draft → awaiting_tenant → awaiting_owner → signed`, chuỗi hash móc xích (khách ký lên
`sealed_hash`, chủ ký lên hash bản đã có chữ ký khách), OTP ký tách namespace khỏi OTP đăng nhập,
nhật ký chỉ-thêm cho từng sự kiện, tra cứu công khai qua `verify_code`. Chi tiết endpoint/field xem
trực tiếp trong code — `ContractDocumentService.php` là nơi DUY NHẤT chứa business logic.

## 2. Ba mức chữ ký — đã chọn Mức A

| Mức | Nội dung | Trạng thái |
|---|---|---|
| **A** | Vẽ tay + OTP + hash + nhật ký | **Đã làm** |
| B | Thêm dấu thời gian TSA (RFC 3161) cho mỗi hash | Chưa làm — làm sau khi có tranh chấp thật, không phải sửa lại A |
| C | PDF cuối ký PAdES bằng chứng thư số CA (VNPT-CA/Viettel-CA/FPT-CA...) | Chưa làm — chỉ áp cho BÊN CHO THUÊ, khách thuê vẫn ký Mức A |

## 3. Điểm khác so với bản spec gốc của app team (và lý do)

- **Mẫu HTML hợp đồng**: dựng lại từ đầu ở `ContractDocumentRenderer.php` (mở rộng từ
  `ContractContentRenderer.php` đã có) — không có quyền truy cập file `contractDoc.ts` của app.
- **`signer_id`**: cột `string` (không FK) vì trỏ 2 bảng khác kiểu khoá (`minihouse_tenants.id` int,
  `users.id` uuid).
- **`final_pdf_path`/`final_hash`**: được ghi NGAY khi khách ký xong (1 chữ ký), rồi ghi đè khi chủ
  ký xong (2 chữ ký, từ đó bất biến) — không phải chỉ ghi 1 lần lúc đủ 2 chữ ký như tên gợi ý.
- **OTP ký hợp đồng**: tái dùng `TenantOtpService` (đã có sẵn cho đăng nhập Portal), thêm tham số
  `purpose` để tách cache namespace — `purpose='login'` PHẢI giữ nguyên văn khoá cache cũ (không
  breaking OTP đăng nhập đang chạy), chỉ `purpose` khác mới có tiền tố riêng.
- **`html_url`**: route công khai có chữ ký hết hạn (Laravel `signed` middleware,
  `URL::temporarySignedRoute`, 30 phút) — không bắt Bearer token vì app mở trong WebView, không tự
  gắn header Authorization được.
- **Email PDF cuối**: chỉ gửi cho **chủ trọ** (`Building.owner_email`) — `minihouse_tenants` chưa có
  cột email nên chưa gửi được cho khách thuê ở giai đoạn này.

## 4. Bug đã phát hiện & sửa trong lúc triển khai (không liên quan trực tiếp tính năng ký)

Ghi lại ở đây vì đều lộ ra TỪ việc test luồng ký, dù nguyên nhân nằm ở code khác:

- **`Building::syncProvinceLink()`** ném 500 khi save() Building vì lý do bất kỳ (không chỉ đổi
  tỉnh/thành) nếu bảng `provinces` có sẵn 1 dòng cùng slug nhưng khác tên — đã sửa tự tra thêm theo
  slug. Xem `Modules/Minihouse/App/Models/Building.php`.
- **`MinihouseZaloTokenService`** — 3 bug liên tiếp: (1) cache token cũ không bị xoá khi admin dán
  refresh_token mới qua panel; (2) race condition đa container do dùng `Cache::lock()` thay vì khoá
  DB thật; (3) **gốc rễ thật sự**: production dùng CHUNG đúng 1 Zalo OA cho cả Home lẫn MiniHouse,
  nhưng code giả định 2 OA riêng — 2 nơi quản lý refresh_token ĐỘC LẬP cùng cầm 1 token gốc (refresh_
  token của Zalo chỉ dùng được 1 lần) liên tục giẫm chân nhau, làm CẢ Home lẫn MiniHouse lỗi "Invalid
  refresh token." lặp lại vô tận. Sửa (1)(2) trước không đủ — chỉ hết hẳn sau khi **hợp nhất về đúng
  1 nơi quản lý token**: `MinihouseZaloTokenService` giờ chỉ uỷ quyền cho `App\Services\ZaloTokenService`
  (của Home), không tự refresh/lưu token riêng nữa. `ZaloSettingsPage` (MiniHouse) bỏ hẳn phần App
  ID/App Secret/Refresh Token, chỉ còn 4 mẫu ZNS Template ID riêng. Nếu sau này MiniHouse có Zalo OA
  THẬT SỰ riêng, tách lại bằng cách khôi phục implementation cũ từ lịch sử git.
- **`ZaloSetting::current()`** không đảm bảo đúng 1 dòng `id=1` do `id` không nằm trong `$fillable`
  — đã sửa dùng `forceCreate()`.

## 5. Việc chưa làm / để sau

- Mức B, C (xem mục 2).
- Gửi email PDF cuối cho khách thuê (chờ thêm cột email vào `minihouse_tenants`).
- UI Filament cho luồng ký (hiện chỉ có API — admin panel chưa có trang riêng để bấm gửi/ký/thu hồi).
