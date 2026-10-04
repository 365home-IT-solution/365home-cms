# CCCD 1 ảnh mã QR — hướng dẫn cho app mobile & FE admin

> **Trạng thái (28/09/2026): CHƯA áp dụng cho API.** API app/admin hiện vẫn dùng
> `cccd_front` + `cccd_back` như cũ; cột `cccd_qr_image` đang được test qua web (trang đặt phòng)
> và Filament. Các API đọc CCCD của admin trả thêm `cccd_qr_image` để xem được ảnh của đơn web.
> Bản API đầy đủ theo tài liệu này nằm ở commit `d5bb15dd` (branch `feature/cccd-qr-image`), sẽ
> áp lại sau khi test xong.

## 0. Endpoint quét độc lập (ĐÃ CÓ, 04/10/2026)

`POST /api/cccd/scan-qr` — quét 1 ảnh mặt có mã QR và trả dữ liệu đọc được. Là endpoint **tuỳ
chọn, thêm mới**: không lưu ảnh, không gắn vào đơn/hồ sơ, không thay đổi API nào khác (các API đặt
phòng/hồ sơ vẫn nhận `cccd_front` + `cccd_back` như cũ). Không cần đăng nhập; có Bearer token thì
giới hạn lượt quét tính theo tài khoản.

Body `multipart/form-data`:

| Trường | Bắt buộc | Ghi chú |
|---|---|---|
| `cccd_qr_image` | có | JPG/PNG/WEBP, tối đa 5MB, cạnh ngắn ≥ 300px và cạnh dài ≥ 500px |
| `checkin_date` | không | `Y-m-d` — mốc tính tuổi, mặc định hôm nay |
| `guest_index` | không | chỉ echo lại để FE map đúng ô đang nhập |

Response 200:

```json
{ "scanned": true,
  "guest_index": 2,
  "data": { "cccd": "087204016918", "old_id": "", "full_name": "NGUYỄN VĂN A", "dob": "12/05/2004",
            "gender": "Nam", "address": "…", "issued_date": "01/01/2022", "source": "qr" },
  "birth_province": "Đồng Tháp",
  "age": 22, "min_age": 16, "under_age": false }
```

- Tuổi **không chặn** ở endpoint này (không biết đơn có qua đêm hay không) — FE tự xử lý theo
  `under_age`.
- Lỗi trả theo định dạng ở mục 2 với `field = "cccd_qr_image"`: `cccd_required`,
  `cccd_image_invalid`, `cccd_qr_unreadable`, `cccd_invalid` (422), `cccd_rate_limited` (429).
- Giới hạn: 15 request/phút/IP, và 30 lượt quét/IP + 12 lượt/tài khoản mỗi 10 phút.

Từ 28/09/2026, BE chuyển toàn bộ luồng CCCD sang **1 ảnh mặt có mã QR** (cột mới
`cccd_qr_image`). Tài liệu này mô tả thay đổi hợp đồng API. App bản cũ đang gửi
`cccd_front`/`cccd_back` **vẫn chạy** trong giai đoạn chuyển tiếp (xem mục 6).

## 1. Quy tắc chung (web, app, admin)

- Chỉ nhận dữ liệu đọc từ **mã QR** trên CCCD gắn chip (app khách không còn fallback OCR).
- **6 số đầu** của số CCCD phải khớp thông tin trên thẻ, ví dụ `087204016918`:
  - `087`: mã tỉnh nơi đăng ký khai sinh (Đồng Tháp); phải là mã tỉnh có thật.
  - `2`: thế kỷ + giới tính (0/1 = 19xx nam/nữ, 2/3 = 20xx nam/nữ).
  - `04`: 2 số cuối năm sinh (2004).
- **Dưới 16 tuổi** (tính tại **ngày nhận phòng**) → lỗi:
  - người đặt phòng: với mọi loại khung (khung giờ, qua đêm, theo ngày);
  - người đi cùng: khi đơn có khung qua đêm / đặt theo ngày.
- **Không trùng người** giữa người đặt và người đi cùng, và giữa các người đi cùng. Hai người bị
  coi là trùng khi trùng số CCCD, **hoặc** trùng họ tên + ngày sinh.
- Người đi cùng chỉ bắt buộc khi đơn có khung qua đêm hoặc đặt theo ngày.

## 2. Lỗi trả về

Mọi lỗi CCCD trả **422** (hoặc **429** khi gửi quá nhiều lần) theo 1 định dạng:

```json
{ "message": "Người đi cùng #2 chưa đủ 16 tuổi — theo quy định không được lưu trú qua đêm.",
  "code": "cccd_under_age",
  "field": "guests.0.qr_image" }
```

| `code` | Ý nghĩa |
|---|---|
| `cccd_required` | Thiếu ảnh CCCD (người đặt hoặc người đi cùng) |
| `cccd_image_invalid` | Không phải ảnh JPG/PNG/WEBP, quá 5MB, hoặc ảnh quá nhỏ |
| `cccd_qr_unreadable` | Không đọc được mã QR |
| `cccd_invalid` | QR đọc được nhưng số CCCD / ngày sinh / ngày cấp sai cấu trúc |
| `cccd_under_age` | Dưới 16 tuổi |
| `cccd_duplicate` | Trùng người trong đơn / trong hồ sơ |
| `cccd_mismatch` | (admin) Các ảnh gửi lên là CCCD của 2 người khác nhau |
| `companion_cccd_invalid` | Người đi cùng chọn từ hồ sơ có dữ liệu không hợp lệ — kèm `companion_id`, yêu cầu khách tải lại ảnh |
| `cccd_rate_limited` | Gửi quá nhiều lần, thử lại sau (HTTP 429) |

`field` là key cần sửa, để app đánh dấu đúng ô. `POST /api/orders` khi hồ sơ chưa có CCCD vẫn trả
thêm `"error": "cccd_required"` như trước cho app cũ.

## 3. API khách (app)

| Endpoint | Trường CCCD |
|---|---|
| `POST /api/guest/orders` | `cccd_qr_image` (bắt buộc), `guests[i][qr_image]` |
| `POST /api/guest/orders/{code}` | `cccd_qr_image` (đổi CCCD người đặt), `guests[i][qr_image]` (khi tăng `guest_count`) |
| `POST /api/guest/orders/{code}/extra` | `guests[{guest_index}][qr_image]` — key theo **guest_index** (2, 3…) như trước |
| `POST /api/orders` (đã đăng nhập) | `cccd_qr_image` (tuỳ chọn — không gửi thì dùng hồ sơ), `guests[i][qr_image]` **hoặc** `guests[i][companion_id]` |
| `POST /api/orders/{code}` (đã đăng nhập) | `guests[{guest_index}][qr_image]` hoặc `guests[{guest_index}][companion_id]` — key theo **guest_index** như trước |
| `POST /api/orders/{code}/extra` | như bản guest |
| `POST /api/auth/me` | `cccd_qr_image`, `companions[i][qr_image]` |

`i` là **vị trí 0-based** trong danh sách người đi cùng (người đi cùng đầu tiên = `guests[0]`),
trừ 3 endpoint ghi rõ "theo guest_index".

### Response

- Đơn / người đi cùng / hồ sơ có thêm `cccd_qr_image` (URL), bên cạnh `cccd_front`/`cccd_back`
  (dữ liệu cũ; đơn mới để `null`).
- `GET|POST /api/auth/me`: thêm `cccd_valid` (hồ sơ dùng được để đặt phòng không) cho chính chủ
  và từng `companions[]`. `cccd_valid = false` → app yêu cầu khách tải lại ảnh QR.
- `POST /api/auth/me` trả thêm `companions_sync: [{index, status}]`.
- `POST /api/orders` trả thêm `cccd_profile_sync: {booker, companions: [{guest_index, status}]}`.
- Giá trị `status`:
  - `created`: thêm người mới vào hồ sơ;
  - `updated`: cùng người đã có trong hồ sơ, cập nhật ảnh và dữ liệu mới;
  - `conflict`: cùng số CCCD nhưng khác họ tên / ngày sinh, **giữ nguyên dữ liệu cũ**, nên báo
    khách kiểm tra lại;
  - `skipped`: chính là chủ tài khoản.

## 4. Người đi cùng lưu trong hồ sơ (`companion_id`)

- App **nên cho khách chọn** người đi cùng trong hồ sơ (`GET /api/auth/me` → `companions[]`) và
  gửi `guests[i][companion_id]`, thay vì để server tự lấy theo thứ tự như trước.
- Đơn lưu **bản chụp** tại thời điểm đặt: dữ liệu và ảnh được copy sang đơn, kèm `companion_id`
  để truy vết. Sau này khách sửa hay xoá người đi cùng trong hồ sơ thì đơn cũ và khai báo lưu trú
  cũ không đổi.
- Ảnh mới gửi kèm đơn được tự lưu vào hồ sơ để lần sau chọn lại, có chống trùng theo số CCCD
  (xem `cccd_profile_sync`).

## 5. API admin (lễ tân)

- Nhận tuỳ ý `cccd_qr_image`, `cccd_front`, `cccd_back`, không trường nào bắt buộc riêng. Gửi ảnh
  nào lưu ảnh đó (bổ sung dần được). Với người đi cùng: `guests[{guest_index}][qr_image|front|back]`;
  với hồ sơ: `companions[i][qr_image|cccd_front|cccd_back]`.
- Server quét QR **từng ảnh riêng** rồi đối chứng:
  - các ảnh là của 2 người khác nhau → 422 `cccd_mismatch`;
  - dưới 16 tuổi / trùng người → 422;
  - **không đọc được QR thì vẫn lưu**, trả cảnh báo để lễ tân nhập tay.
- Response có `cccd_check`: `{checks: {cccd_qr_image|cccd_front|cccd_back: "match"|"unreadable"}, warnings: [...]}`.
- `POST /api/admin/cccd/scan` giờ **chỉ quét để xem trước, không lưu file**. Body: `qr_image?`,
  `front?`, `back?` (ít nhất 1 ảnh), `guest_index?`. Trả về `scanned`, `data`, `checks`,
  `warnings`; không còn trả path/URL ảnh.

## 6. Giai đoạn chuyển tiếp (app bản cũ)

Biến môi trường `CCCD_ACCEPT_LEGACY_FRONT_BACK` (mặc định `true`):

- App cũ gửi `cccd_front`/`cccd_back` (hoặc `guests[i][front|back]`) → server quét cả 2 ảnh, lưu
  ảnh chứa QR vào `cccd_qr_image`.
- App cũ không gửi `guests[...]` khi đặt có đăng nhập → server tự lấy người đi cùng trong hồ sơ
  theo thứ tự như trước (vẫn áp đủ kiểm tra tuổi / trùng người).

Khi phần lớn người dùng đã lên app mới, đặt `CCCD_ACCEPT_LEGACY_FRONT_BACK=false`; không cần sửa
code.
