# API quét CCCD bằng 1 ảnh mã QR — hướng dẫn cho FE

Cập nhật: 04/10/2026

## 1. Tóm tắt

BE có 5 API mới để **quét 1 ảnh CCCD mặt có mã QR và trả về thông tin đọc được**.

- Các API này **chỉ đọc**: không lưu ảnh, không lưu thông tin vào đơn, hồ sơ hay khách thuê.
- FE dùng API quét như một bước **kiểm tra và hiển thị trước**: biết ảnh có đọc được không, lấy họ
  tên, số CCCD, ngày sinh để điền form hoặc cảnh báo khách.
- Để **lưu**, các API đặt phòng, sửa đơn, hồ sơ, người đi cùng giờ nhận thêm ảnh QR như một lựa
  chọn song song với `cccd_front` + `cccd_back` (xem mục 9). Không gửi ảnh QR thì các API đó chạy
  y như trước, FE không cần sửa gì ở luồng đang chạy.

## 2. Chọn API theo màn hình

| Màn hình | API | Token |
|---|---|---|
| App khách, chưa đăng nhập (vãng lai) | `POST {BASE_URL}/api/guest/cccd/scan-qr` | không cần |
| App khách, đã đăng nhập | `POST {BASE_URL}/api/cccd/scan-qr` | token khách |
| App quản trị homestay | `POST {BASE_URL}/api/admin/cccd/scan-qr` | token quản trị |
| App quản trị minihouse | `POST {BASE_URL}/api/admin/minihouse/cccd/scan-qr` | token quản trị, tài khoản có quyền tạo hoặc sửa khách thuê |
| App khách thuê minihouse (portal) | `POST {BASE_URL}/api/minihouse/portal/cccd/scan-qr` | token khách thuê |

Mỗi API có giới hạn lượt gọi riêng, không dùng lẫn giữa các phần.

## 3. Request

- Method: `POST`
- Body: `multipart/form-data` (bắt buộc, vì có gửi file; không dùng JSON)
- Header: `Accept: application/json`, và `Authorization: Bearer <token>` với API cần token

| Trường | Kiểu | Bắt buộc | Ghi chú |
|---|---|---|---|
| `cccd_qr_image` | file | có | Ảnh mặt CCCD **có mã QR** |
| `checkin_date` | text `YYYY-MM-DD` | không | Ngày nhận phòng, dùng để tính tuổi. Không gửi thì tính theo hôm nay. Minihouse không dùng |
| `guest_index` | số nguyên ≥ 1 | không | BE trả lại nguyên vẹn để FE biết kết quả thuộc ô khách nào. Minihouse không dùng |

Yêu cầu với ảnh:

- Định dạng **JPG, PNG hoặc WEBP**. Ảnh HEIC (iPhone) và AVIF bị từ chối, FE cần chuyển sang JPG
  trước khi gửi.
- Dung lượng tối đa **5MB** với app khách, **10MB** với quản trị.
- Cạnh ngắn ≥ 300px và cạnh dài ≥ 500px.
- Không nén mạnh, không cắt nhỏ ảnh trước khi gửi: mã QR vỡ nét sẽ không đọc được.

Mỗi lượt quét mất khoảng 4–8 giây, trường hợp xấu có thể tới hơn 20 giây. FE nên hiện trạng thái
đang xử lý và đặt timeout request từ 30 giây trở lên.

## 4. Response thành công

### 4.1. App khách (vãng lai và đã đăng nhập) — HTTP 200

```json
{
  "scanned": true,
  "guest_index": 2,
  "data": {
    "cccd": "087204016918",
    "old_id": "",
    "full_name": "NGUYỄN VĂN A",
    "dob": "12/05/2004",
    "gender": "Nam",
    "address": "Ấp 1, Xã X, Huyện Y, Đồng Tháp",
    "issued_date": "01/01/2022",
    "source": "qr"
  },
  "birth_province": "Đồng Tháp",
  "age": 22,
  "min_age": 16,
  "under_age": false
}
```

API **đã đăng nhập** trả thêm 2 trường:

```json
{
  "same_as_profile": true,
  "companion_id": null
}
```

### 4.2. Quản trị homestay — HTTP 200

Giống mục 4.1 (không có `same_as_profile`, `companion_id`), thêm `warnings`:

```json
{
  "scanned": true,
  "guest_index": 2,
  "data": { "cccd": "087204016918", "full_name": "NGUYỄN VĂN A", "dob": "12/05/2004", "...": "..." },
  "warnings": [],
  "birth_province": "Đồng Tháp",
  "age": 22,
  "min_age": 16,
  "under_age": false
}
```

### 4.3. Quản trị minihouse — HTTP 200

```json
{
  "scanned": true,
  "data": { "cccd": "087204016918", "full_name": "NGUYỄN VĂN A", "dob": "12/05/2004", "...": "..." },
  "warnings": [],
  "tenant_fields": {
    "fullname": "NGUYỄN VĂN A",
    "id_card_number": "087204016918",
    "gender": "nam",
    "permanent_address": "Ấp 1, Xã X, Huyện Y, Đồng Tháp",
    "date_of_birth": "2004-05-12",
    "id_card_issued_date": "2022-01-01"
  },
  "age": 22,
  "min_age": 14,
  "under_age": false
}
```

### 4.4. Khách thuê minihouse (portal) — HTTP 200

Giống mục 4.3 nhưng không có `warnings`, thêm `matches_profile`:

```json
{
  "scanned": true,
  "data": { "cccd": "087204016918", "full_name": "NGUYỄN VĂN A", "dob": "12/05/2004", "...": "..." },
  "tenant_fields": { "fullname": "NGUYỄN VĂN A", "id_card_number": "087204016918", "...": "..." },
  "matches_profile": true,
  "age": 22,
  "min_age": 14,
  "under_age": false
}
```

Không đọc được QR hoặc số CCCD sai cấu trúc thì trả 422 như app khách homestay (mục 6), không
trả 200 kèm `warnings` như bản quản trị.

## 5. Ý nghĩa các trường

| Trường | Ý nghĩa |
|---|---|
| `scanned` | `true` khi đọc được mã QR |
| `guest_index` | Số FE gửi lên, trả lại nguyên vẹn; `null` nếu không gửi |
| `data.cccd` | Số CCCD 12 chữ số |
| `data.old_id` | Số CMND cũ, có thể rỗng |
| `data.full_name` | Họ tên trên thẻ |
| `data.dob` | Ngày sinh, dạng `dd/mm/yyyy` |
| `data.gender` | `Nam` hoặc `Nữ` |
| `data.address` | Nơi thường trú |
| `data.issued_date` | Ngày cấp, dạng `dd/mm/yyyy` |
| `data.source` | Luôn là `qr` |
| `birth_province` | Tỉnh đăng ký khai sinh, suy từ 3 số đầu của số CCCD |
| `age` | Tuổi tròn tại `checkin_date` (hoặc hôm nay) |
| `min_age` | Mốc tuổi của hệ thống: 16 ở homestay, 14 ở minihouse |
| `under_age` | `true` khi `age < min_age` |
| `same_as_profile` | (đã đăng nhập) CCCD có phải của chính chủ tài khoản không; `null` nếu hồ sơ chưa có CCCD |
| `companion_id` | (đã đăng nhập) id người đi cùng đã lưu trong hồ sơ trùng với CCCD này; `null` nếu chưa có |
| `warnings` | (quản trị) danh sách cảnh báo dạng chuỗi để hiện cho nhân viên; rỗng khi mọi thứ hợp lệ |
| `tenant_fields` | (minihouse) dữ liệu đã đổi sang đúng tên và định dạng trường của API khách thuê; chỉ gồm trường đọc được |
| `matches_profile` | (portal minihouse) số CCCD vừa quét có khớp số CCCD trong hồ sơ khách thuê không; `null` nếu hồ sơ chưa có số CCCD |

**Tuổi ở minihouse chỉ để tham khảo.** Khách thuê có thể là trẻ ở cùng cha mẹ nên BE không chặn
theo tuổi ở bất kỳ API minihouse nào. `min_age` là 14 vì từ đủ 14 tuổi công dân bắt buộc có thẻ căn
cước; `under_age: true` nghĩa là người này dưới 14 tuổi, FE có thể hiện ghi chú, không cần chặn.

## 6. Lỗi

Lỗi CCCD có cùng một định dạng:

```json
{
  "message": "Không đọc được mã QR trên CCCD. Vui lòng chụp rõ nét mặt có mã QR, không chụp lại màn hình.",
  "code": "cccd_qr_unreadable",
  "field": "cccd_qr_image"
}
```

`message` là câu tiếng Việt hiển thị thẳng cho người dùng được. FE xử lý theo `code`:

| HTTP | `code` | Khi nào | FE nên làm |
|---|---|---|---|
| 422 | `cccd_required` | Không gửi file, hoặc gửi `cccd_qr_image` dạng text | Yêu cầu chọn ảnh |
| 422 | `cccd_image_invalid` | Sai định dạng, quá dung lượng, ảnh quá nhỏ | Yêu cầu chọn ảnh khác |
| 422 | `cccd_qr_unreadable` | Không đọc được mã QR | Yêu cầu chụp lại: đủ sáng, thẳng, lấy trọn thẻ, đúng mặt có QR |
| 422 | `cccd_invalid` | Đọc được QR nhưng số CCCD không khớp ngày sinh, giới tính, mã tỉnh, hoặc ngày cấp sai | Báo CCCD không hợp lệ |
| 429 | `cccd_rate_limited` | Quét quá nhiều lần | Hiện `message` (có số phút cần chờ) |
| 429 | không có `code` | Gọi quá nhiều request trong 1 phút | Chờ rồi thử lại |
| 401 | không có `code` | Thiếu token, token hết hạn, hoặc dùng sai loại token | Đăng nhập lại |
| 403 | không có `code` | Tài khoản khách bị khoá, hoặc tài khoản minihouse thiếu quyền | Hiện `message` |
| 422 | không có `code`, có `errors` | `checkin_date` hoặc `guest_index` sai định dạng | Sửa dữ liệu gửi lên |

**Khác biệt quan trọng giữa app khách và quản trị:**

| Tình huống | App khách | Quản trị (homestay, minihouse) |
|---|---|---|
| Không đọc được QR | 422 `cccd_qr_unreadable` | **200**, `scanned: false`, `data: null`, `warnings` có 1 dòng |
| Số CCCD sai cấu trúc | 422 `cccd_invalid` | **200**, `scanned: true`, có `data`, `warnings` có 1 dòng |

Ở quản trị, nhân viên được phép nhập tay khi máy không đọc được, nên BE không trả lỗi. FE quản
trị phải kiểm tra `scanned` và `warnings`, không chỉ dựa vào HTTP 200. Khi `scanned: false` thì
`age` là `null`, `under_age` là `false` và `tenant_fields` là `null`.

## 7. Giới hạn lượt gọi

| API | Mỗi phút | Mỗi 10 phút |
|---|---|---|
| Vãng lai | 15 request / IP | 30 lượt quét / IP |
| Đã đăng nhập | 15 request / tài khoản | 12 lượt quét / tài khoản và 30 / IP |
| Quản trị homestay | 30 request / tài khoản | không giới hạn |
| Quản trị minihouse | 30 request / tài khoản | không giới hạn |
| Khách thuê minihouse (portal) | 15 request / khách thuê | 12 lượt quét / khách thuê và 30 / IP |

FE không nên tự động gọi lại khi quét thất bại; để người dùng chủ động chọn ảnh khác.

## 8. Cách dùng theo từng luồng

### 8.1. Đặt phòng (app khách, vãng lai hoặc đã đăng nhập)

1. Khách chọn ảnh mặt có QR cho từng người. FE gọi API quét, gửi kèm `guest_index`
   (`1` = người đặt, `2` trở đi = người đi cùng) và `checkin_date` là ngày nhận phòng.
2. Quét lỗi thì hiện `message` tại đúng ô ảnh đó.
3. Quét được thì FE tự áp các quy tắc sau (API quét không chặn):
   - **Tuổi:** người đặt có `under_age: true` → không cho đặt, ở mọi loại khung giờ. Người đi cùng
     có `under_age: true` → chỉ chặn khi đơn qua đêm.
   - **Trùng người:** so `data.cccd` giữa các khách trong đơn; không cho 2 khách dùng chung 1 CCCD.
4. Khi tạo đơn, FE gửi lại chính ảnh QR đó trong API đặt phòng (`cccd_qr_image`,
   `guests[i][qr_image]`, xem mục 9), hoặc gửi `cccd_front` + `cccd_back` như cũ. API đặt phòng
   không nhận kết quả của API quét; server tự quét lại ảnh lúc lưu.

Đơn được coi là **qua đêm** khi:

- đặt theo ngày (`type = daily`): luôn là qua đêm;
- đặt theo khung giờ (`type = slot`): có ít nhất 1 khung giờ được chọn mang `over_night: true`.
  Trường `over_night` có sẵn trong từng khung giờ của `GET /api/slots?room_id=...&date=YYYY-MM-DD`.

Đơn qua đêm có từ 2 khách trở lên thì mỗi người đi cùng phải có CCCD.

### 8.2. Khách cập nhật CCCD của mình và người đi cùng trong hồ sơ

1. Gọi `POST /api/cccd/scan-qr` để kiểm tra ảnh và hiện thông tin cho khách xác nhận.
2. Dùng `same_as_profile` và `companion_id`:
   - màn hình "CCCD của tôi": `same_as_profile: false` → cảnh báo CCCD này khác CCCD đang lưu;
   - màn hình "Thêm người đi cùng": `same_as_profile: true` → đây là CCCD của chính chủ, không
     thêm làm người đi cùng; `companion_id` khác `null` → người này đã có trong danh sách.
3. Để **lưu**, gọi `POST /api/auth/me` với `cccd_qr_image` (chính chủ) hoặc
   `companions[i][qr_image]` (người đi cùng), xem mục 9. Luồng cũ với `cccd_front` + `cccd_back`
   vẫn dùng được.

### 8.3. Quản trị homestay

1. Lễ tân chọn ảnh QR của từng khách, FE gọi `POST /api/admin/cccd/scan-qr` kèm `guest_index`.
2. `scanned: true` và `warnings` rỗng → điền thông tin từ `data`.
3. `scanned: false` hoặc có `warnings` → hiện cảnh báo, cho lễ tân nhập tay hoặc chụp lại.
4. Tạo và sửa đơn: gửi ảnh QR trong các API quản trị (mục 9), hoặc `cccd_front` + `cccd_back` như
   cũ.

API cũ `POST /api/admin/cccd/scan` (2 ảnh `front` + `back`) vẫn hoạt động như trước.

### 8.4. Quản trị minihouse

1. Nhân viên chọn ảnh QR, FE gọi `POST /api/admin/minihouse/cccd/scan-qr`.
2. Lấy `tenant_fields` điền vào form khách thuê. Các trường đã đúng tên và định dạng của
   `POST /api/admin/minihouse/tenants` (ngày dạng `YYYY-MM-DD`, giới tính `nam` / `nu`).
3. Nhân viên kiểm tra, sửa nếu cần rồi lưu bằng API khách thuê như bình thường.

API quét không lưu ảnh. Muốn lưu ảnh QR vào hồ sơ khách thuê thì gửi `id_card_qr_image` trong API
khách thuê (mục 9.3).

### 8.5. Khách thuê minihouse (portal)

1. Khách thuê chọn ảnh QR, FE gọi `POST /api/minihouse/portal/cccd/scan-qr`.
2. Hiện thông tin từ `data`; dùng `matches_profile` để báo CCCD có khớp hồ sơ hay không.

API này chỉ đọc. Khách thuê không tự sửa được thông tin CCCD trong hồ sơ; việc đó do nhân viên làm
ở app quản trị.

## 9. Lưu bằng 1 ảnh QR (tuỳ chọn, song song với 2 mặt)

Các API lưu nhận thêm trường ảnh QR. Với mỗi người, FE chọn **một trong hai**: gửi ảnh QR, hoặc
gửi đủ mặt trước + mặt sau như cũ. Gửi cả hai thì ảnh QR được dùng, 2 mặt bị bỏ qua.

### 9.1. App khách

| API | Người đặt / chính chủ | Người đi cùng |
|---|---|---|
| `POST /api/guest/orders` | `cccd_qr_image` | `guests[i][qr_image]`, `i` từ 0 |
| `POST /api/guest/orders/{code}` | `cccd_qr_image` | `guests[i][qr_image]`, `i` từ 0 |
| `POST /api/guest/orders/{code}/extra` | | `guests[{guest_index}][qr_image]` |
| `POST /api/orders` | lấy từ hồ sơ | `guests[i][qr_image]`, `i` từ 0 |
| `POST /api/orders/{code}` | | `guests[{guest_index}][qr_image]` |
| `POST /api/orders/{code}/extra` | | `guests[{guest_index}][qr_image]` |
| `POST /api/auth/me` | `cccd_qr_image` | `companions[i][qr_image]` |

Cách đánh số `i` / `guest_index` giữ nguyên như key `front` / `back` đang dùng ở từng API.

Khác với luồng 2 mặt:

- Chỉ nhận dữ liệu từ mã QR (không OCR) và kiểm tra cấu trúc số CCCD. Ảnh không đạt thì **không
  lưu gì** và trả lỗi theo định dạng mục 6 (`cccd_image_invalid`, `cccd_qr_unreadable`,
  `cccd_invalid`), `field` là đúng key ảnh bị lỗi, ví dụ `guests.0.qr_image`.
  Riêng 2 API `/extra` trả `{ "message": "Khách thứ N: ..." }` không có `code`.
- Quy định tuổi của từng API giữ nguyên như luồng 2 mặt.
- `POST /api/orders`: hồ sơ chỉ có ảnh QR (không có 2 mặt) vẫn đặt phòng được.
- `POST /api/auth/me` với `companions[i][qr_image]`: server chống trùng theo số CCCD và trả thêm
  `companions_sync: [{ "index": 0, "status": "created" }]`. `status` là `created` (thêm mới),
  `updated` (người đã có, cập nhật ảnh), `conflict` (trùng số CCCD nhưng khác họ tên hoặc ngày
  sinh, **không lưu**), `skipped` (là CCCD của chính chủ tài khoản, **không lưu**).
- `POST /api/auth/me` với `cccd_qr_image` trùng một người đi cùng đã lưu → 422 `cccd_duplicate`.

Response của đơn, hồ sơ và người đi cùng có thêm `cccd_qr_image` (URL ảnh). Bản ghi lưu bằng ảnh
QR có `cccd_front` và `cccd_back` là `null`; FE cần hiển thị theo `cccd_qr_image` trong trường hợp
đó.

### 9.2. Quản trị homestay

| API | Khách chính | Khách đi cùng |
|---|---|---|
| `POST /api/admin/orders` | `cccd_qr_image` | `guests[{guest_index}][qr_image]` |
| `POST /api/admin/orders/{code}` | `cccd_qr_image` | `guests[{guest_index}][qr_image]` |
| `POST /api/admin/customers`, `POST /api/admin/customers/{id}` | `cccd_qr_image` | |
| `POST /api/admin/customers/{id}/companions` | | `companions[i][qr_image]` |
| `POST /api/admin/customers/{id}/companions/{companion_id}` | | `cccd_qr_image` |

Giống luồng 2 mặt của quản trị: không đọc được QR **vẫn lưu ảnh**, thông tin CCCD để trống để lễ
tân bổ sung sau. Response có thêm `cccd_qr_image` (đơn) hoặc `cccd_qr_image_url` (khách hàng,
người đi cùng).

### 9.3. Quản trị minihouse

`POST /api/admin/minihouse/tenants` và `POST /api/admin/minihouse/tenants/{id}` nhận thêm
`id_card_qr_image` (file ảnh, tối đa 5MB), song song với `id_card_front` / `id_card_back`.

- Ảnh được lưu vào hồ sơ; response có thêm `id_card_qr_image` (đường dẫn ảnh, cùng kiểu với
  `id_card_front`).
- Sau khi lưu, server tự quét mã QR trên ảnh và **chỉ điền các trường đang trống** (họ tên, số
  CCCD, ngày sinh, giới tính, nơi thường trú); trường nhân viên đã nhập thì giữ nguyên. Nếu khách
  thuê đã có ảnh CCCD từ trước và lần này thay ảnh khác thì các trường đó được ghi đè theo ảnh mới.
- Không đọc được QR vẫn lưu khách thuê và ảnh bình thường.
- Không có ràng buộc tuổi.

## 10. Ví dụ gọi API

```bash
# Vãng lai
curl -X POST {BASE_URL}/api/guest/cccd/scan-qr \
  -H "Accept: application/json" \
  -F "cccd_qr_image=@cccd.jpg" -F "checkin_date=2026-12-01" -F "guest_index=1"

# Đã đăng nhập
curl -X POST {BASE_URL}/api/cccd/scan-qr \
  -H "Accept: application/json" -H "Authorization: Bearer <token_khach>" \
  -F "cccd_qr_image=@cccd.jpg" -F "checkin_date=2026-12-01" -F "guest_index=1"

# Quản trị homestay
curl -X POST {BASE_URL}/api/admin/cccd/scan-qr \
  -H "Accept: application/json" -H "Authorization: Bearer <token_quan_tri>" \
  -F "cccd_qr_image=@cccd.jpg" -F "guest_index=2"

# Quản trị minihouse
curl -X POST {BASE_URL}/api/admin/minihouse/cccd/scan-qr \
  -H "Accept: application/json" -H "Authorization: Bearer <token_quan_tri>" \
  -F "cccd_qr_image=@cccd.jpg"
```

JavaScript (React Native / web):

```js
const form = new FormData();
form.append('cccd_qr_image', { uri: image.uri, name: 'cccd.jpg', type: 'image/jpeg' }); // web: form.append('cccd_qr_image', file)
form.append('checkin_date', '2026-12-01');
form.append('guest_index', '1');

const res = await fetch(`${BASE_URL}/api/guest/cccd/scan-qr`, {
  method: 'POST',
  headers: { Accept: 'application/json' }, // KHÔNG tự đặt Content-Type
  body: form,
});
const json = await res.json();

if (res.ok) {
  // json.data, json.under_age ...
} else {
  // json.code, json.message
}
```

## 11. Chưa hỗ trợ

- Khách thuê minihouse tự lưu CCCD từ portal (chỉ quét để xem, nhân viên mới sửa được hồ sơ).
- Ảnh HEIC, AVIF.
