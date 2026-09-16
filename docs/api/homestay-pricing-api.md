# Home (Homestay) — API Bảng giá & Sửa giá hàng loạt

Tài liệu API cho 2 tính năng đổi giá của panel Home (`/homestay/admin`):

- **Bảng giá** (`price_boards`/`price_board_items`) — bản API của
  `Modules\Book\App\Filament\Resources\PriceBoardResource`. Xem `App\Services\PriceBoardSyncService`
  để hiểu cơ chế áp/khôi phục giá theo ngày hiệu lực.
- **Sửa giá hàng loạt** — bản API của nút "Sửa giá hàng loạt" trong trang "Hệ thống giá"
  (`Modules\Book\App\Filament\Traits\HasBookingHeaderActions`). Ghi thẳng giá xuống hệ thống ngay khi
  gọi, **không qua bảng giá nào**.

Khác với API MiniHouse (bọc response trong `{ "data": ... }`), API Home dùng đúng chuẩn đã có sẵn ở
các endpoint Home khác (`RoomPricingController`, `ProductController`...): trả **thẳng object/mảng**,
không có envelope `data`.

---

## 1. Xác thực

Dùng chung hệ đăng nhập admin đã có, không có endpoint riêng cho 2 API này.

```
POST /api/admin/login
Content-Type: application/json
```

**Request**

```json
{ "email": "admin@365home.vn", "password": "matkhau123" }
```

**Response `200`**

```json
{
  "token": "1|AbCdEfGhIjKlMnOpQrStUvWxYz...",
  "user": { "id": "...", "fullname": "...", "is_super_admin": true, "...": "..." }
}
```

Mọi request bên dưới cần header:

```
Authorization: Bearer <token>
Accept: application/json
```

### Quyền truy cập

Cả 2 nhóm API đều là công cụ ghi giá thẳng xuống hệ thống cho nhiều phòng cùng lúc, **rủi ro cao hơn**
sửa giá từng phòng (`RoomPricingController`) nên bị khoá chặt hơn:

| Nhóm API | Ai gọi được |
|---|---|
| `/api/admin/price-boards/**` | `super_admin`, HOẶC user có quyền Shield riêng (`view_any_price::board`, `create_price::board`, `update_price::board`, `delete_price::board` — cấp qua trang "Vai trò") |
| `/api/admin/rooms/bulk-price` | **CHỈ `super_admin`** — không có quyền nào để mở cho tài khoản khác (giống UI, nút này bị ẩn hẳn với non-super-admin) |

Không đủ quyền → `403 { "message": "Không có quyền thực hiện thao tác này." }`.

Bảng giá không có cột `partner_id` (bảng toàn cục), nhưng **từng phòng nhắc tới** trong
`product_ids`/`items[].product_id`/`room_ids` vẫn bị kiểm tra đúng `partner_id` + phạm vi chi nhánh
(`allowedCategoryIds()`) của tài khoản gọi API — không phải `super_admin` mà đụng tới phòng ngoài
phạm vi sẽ nhận `422 { "message": "Một hoặc nhiều phòng không thuộc phạm vi quản lý của tài khoản." }`.

---

## 2. Quy ước chung

- `id` của **phòng** (`products.id`) là chuỗi UUID (vd `"01kn0ty0getffwhtpv0bjta15m"`), không phải số.
- `id` của **bảng giá** (`price_boards.id`) là số nguyên tự tăng.
- Danh sách (`index`) trả nguyên object paginator chuẩn Laravel — xem mục 3.1.
- Lỗi validate: chuẩn Laravel `422` — `{ "message": "...", "errors": { "field": ["..."] } }`.
- Endpoint tìm không thấy bản ghi: `404 { "message": "Không tìm thấy bảng giá." }`.

---

## 3. Bảng giá — `/api/admin/price-boards`

Đây là các bảng giá **đặt tên**, có thời hạn (Tết, khuyến mãi, đối tác...) — khác với "Bảng giá mặc
định" (`is_default=true`, dữ liệu nội bộ do trang "Hệ thống giá" quản lý, **không xuất hiện** trong
danh sách/API này).

Có 2 chế độ (`pricing_mode`), chọn 1 khi tạo:

- **`override`** (mặc định) — tự nhập giá/điều kiện cho TỪNG phòng trong `items[]`.
- **`adjustment`** — chỉ cộng/trừ **%** hoặc **số tiền cố định** trên giá gốc (bảng giá mặc định) của
  các phòng trong `product_ids[]`, không cần nhập lại giá từng phòng — tự tính lại mỗi lần áp dựa
  trên giá gốc **hiện tại** (không đóng băng).

### 3.1 `GET /price-boards?search=&is_active=&pricing_mode=&per_page=`

- `search` — lọc theo tên bảng giá (chứa chuỗi, không phân biệt hoa/thường).
- `is_active` — `true`/`false`.
- `pricing_mode` — `override`/`adjustment`.
- `per_page` — mặc định 20, tối đa 100.

**Response `200`**

```json
{
  "current_page": 1,
  "data": [
    {
      "id": 12,
      "name": "Khuyến mãi Tết 2027",
      "note": null,
      "start_date": "2027-01-20",
      "end_date": "2027-02-05",
      "is_active": true,
      "status": "Chờ áp dụng",
      "pricing_mode": "override",
      "adjustment_type": null,
      "adjustment_value": null,
      "rooms_count": 8,
      "created_at": "2026-09-16T14:00:00+07:00",
      "updated_at": "2026-09-16T14:00:00+07:00"
    }
  ],
  "first_page_url": "...", "from": 1, "last_page": 1, "last_page_url": "...",
  "links": [ "..." ], "next_page_url": null, "path": "...",
  "per_page": 20, "prev_page_url": null, "to": 1, "total": 1
}
```

`status` là nhãn tính sẵn (không lưu cột riêng): `"Đã tắt"` (is_active=false) | `"Đang áp dụng"`
(đang trong khoảng ngày hiệu lực) | `"Chờ áp dụng"` (chưa tới start_date) | `"Hết hạn"` (qua end_date).

### 3.2 `GET /price-boards/{id}`

Trả đầy đủ như mục 3.1, kèm `items[]` — từng phòng đã gắn và giá/điều kiện đã lưu cho phòng đó.

**Response `200`**

```json
{
  "id": 12,
  "name": "Khuyến mãi Tết 2027",
  "...": "... (như 3.1)",
  "items": [
    {
      "id": 45,
      "room": { "id": "01kn0ty0getffwhtpv0bjta15m", "name": "Ngũ Hành Sơn" },
      "price": 850000,
      "full_booking_discount": null,
      "bulk_discount_rules": null,
      "room_config": { "max_free_guests": 2, "extra_guest_fee": 50000 },
      "default_checkin": "14:00:00",
      "default_checkout": "12:00:00",
      "deposit_1_night": 100,
      "deposit_multi_night": 50,
      "deposit_min_nights": 2,
      "room_time_slots": []
    }
  ]
}
```

> Phòng **theo ngày** (styles=2) dùng `price`/`default_checkin`/`default_checkout`/`deposit_*`;
> phòng **theo khung giờ** (styles=1) dùng `room_time_slots[]` — field không áp dụng cho style của
> phòng đó vẫn được trả về nhưng luôn `null`/rỗng, không có ý nghĩa.
> Chế độ `adjustment`: `items[].price`/`room_time_slots` luôn `null`/rỗng — bảng chỉ lưu
> `product_ids` (không lưu giá riêng từng phòng), giá thật tính lúc áp = giá gốc ± `adjustment_value`.

### 3.3 `POST /price-boards` — Tạo bảng giá

**Field chung**

| Field | Bắt buộc | Ghi chú |
|---|---|---|
| `name` | ✅ | Tên bảng giá |
| `note` | – | Ghi chú |
| `start_date`, `end_date` | – | `Y-m-d`, để trống = không giới hạn |
| `is_active` | – | Mặc định `true` |
| `pricing_mode` | – | `override` (mặc định) \| `adjustment` |
| `adjustment_type` | Khi `pricing_mode=adjustment` | `percent` \| `fixed` |
| `adjustment_value` | Khi `pricing_mode=adjustment` | Số — % hoặc số tiền cộng/trừ |

**Chế độ `adjustment`** — chỉ cần chọn phòng:

```json
{
  "name": "Đối tác ABC +10%",
  "pricing_mode": "adjustment",
  "adjustment_type": "percent",
  "adjustment_value": 10,
  "product_ids": ["01kn0ty0getffwhtpv0bjta15m", "01k9xe994aamcwz1rwn470yve3"]
}
```

**Chế độ `override`** — field `items[]` (styles=2, theo ngày):

```json
{
  "name": "Khuyến mãi Tết 2027",
  "start_date": "2027-01-20",
  "end_date": "2027-02-05",
  "items": [
    {
      "product_id": "01kn0ty0getffwhtpv0bjta15m",
      "price": 850000,
      "default_checkin": "14:00",
      "default_checkout": "12:00",
      "deposit_min_nights": 2,
      "deposit_multi_night": 50,
      "full_booking_discount": "Giảm 10% khi đặt trọn gói",
      "room_config": { "max_free_guests": 2, "extra_guest_fee": 50000 }
    }
  ]
}
```

**Chế độ `override`** — field `items[]` (styles=1, theo khung giờ, dùng `room_time_slots[]` thay cho
`price`/`default_checkin`/`deposit_*`):

```json
{
  "name": "Cuối tuần giá sốc",
  "items": [
    {
      "product_id": "01k9xe994aamcwz1rwn470yve3",
      "bulk_discount_rules": [ { "slots": 2, "discount": 10 }, { "slots": 3, "discount": 15 } ],
      "room_config": { "max_free_guests": 2, "extra_guest_fee": 0 },
      "room_time_slots": [
        { "timeslot_id": 3, "price": 120000, "over_night": false },
        { "timeslot_id": 4, "price": 150000, "over_night": true }
      ]
    }
  ]
}
```

**Response `201`** — object bảng giá đầy đủ như mục 3.2.

**Response `422`** — trùng lịch với 1 bảng giá khác đang active cho cùng ít nhất 1 phòng:

```json
{ "message": "Khoảng ngày hiệu lực trùng với bảng giá \"Khuyến mãi Tết 2027\" cho ít nhất 1 phòng đã chọn." }
```

Tạo xong, nếu bảng đang `is_active=true` và đang trong khoảng ngày hiệu lực, giá được **áp ngay** —
không cần gọi thêm `POST .../apply`.

### 3.4 `PUT/PATCH /price-boards/{id}` — Sửa bảng giá

Field như mục 3.3, tất cả **tuỳ chọn** (`sometimes`) — field không gửi giữ nguyên giá trị cũ. Gửi lại
`items`/`product_ids` sẽ **thay thế toàn bộ** danh sách phòng đang gắn (phòng nào không còn trong
mảng mới sẽ bị gỡ khỏi bảng giá này — giá của phòng đó tự tính lại theo bảng khác đang active hoặc
khôi phục về giá gốc, xem mục 3.5). Cùng lỗi `422` trùng lịch như tạo mới.

### 3.5 `DELETE /price-boards/{id}` — Xoá bảng giá

```json
{ "message": "Đã xoá bảng giá." }
```

Sau khi xoá, giá của từng phòng từng gắn trong bảng này được **tính lại đúng**: nếu phòng còn 1 bảng
KHÁC đang active và trùng ngày → bảng đó thắng như cũ; nếu không còn bảng nào → khôi phục về giá gốc
đã đóng băng lúc tạo bảng đặt tên gần nhất (hoặc "Bảng giá mặc định" nếu chưa từng có bảng đặt tên
nào). Không tự ý ghi đè bằng giá của bảng vừa xoá.

### 3.6 `POST /price-boards/{id}/apply` — Áp dụng ngay

Áp NGAY giá của bảng xuống các phòng đã gắn, **bất kể** ngày hiệu lực/`is_active` — dùng khi cần đổi
giá tức thời dù chưa tới ngày (vd đối tác yêu cầu áp giá sớm).

```json
{ "message": "Đã áp dụng bảng giá \"Khuyến mãi Tết 2027\"." }
```

### 3.7 `PATCH /price-boards/{id}/active` — Bật/tắt nhanh

**Request**

```json
{ "is_active": false }
```

Có hiệu lực ngay (không chờ job `price-boards:sync-due` chạy lúc nửa đêm). Bật lại (`true`) phải qua
lại đúng kiểm tra trùng lịch — thất bại thì trả `422` như mục 3.3 và **giữ nguyên** trạng thái cũ
(không bật).

**Response `200`** — object bảng giá đầy đủ như mục 3.2 (đã cập nhật `is_active`/`status`).

### 3.8 `GET /price-boards/{id}/history?per_page=`

Lịch sử thay đổi giá — chỉ có dòng khi giá **thật sự đổi** (không log nếu áp lại giá giống hệt giá
đang có).

**Response `200`**

```json
{
  "current_page": 1,
  "data": [
    {
      "id": 57,
      "room": { "id": "01kn0ty0getffwhtpv0bjta15m", "name": "Ngũ Hành Sơn" },
      "old_price": "1000000.00",
      "new_price": "850000.00",
      "old_slots": null,
      "new_slots": null,
      "changed_by": null,
      "created_at": "2026-09-16T14:00:00+07:00"
    }
  ],
  "...": "... (paginator chuẩn, xem mục 2)"
}
```

`changed_by` là `null` khi giá do hệ thống tự áp theo lịch (job `price-boards:sync-due`), ngược lại là
tên người gọi API/thao tác trên Filament. `old_slots`/`new_slots` chỉ có giá trị với phòng theo khung
giờ (styles=1) — object `{ "<tên khung giờ>": <giá> }`.

---

## 4. Sửa giá hàng loạt — `POST /api/admin/rooms/bulk-price`

Ghi thẳng giá mới xuống `products.price` (phòng theo ngày) hoặc `room_time_slots.price` (phòng theo
khung giờ) cho **nhiều phòng cùng lúc**, có hiệu lực **ngay lập tức**, không tạo/qua bảng giá nào.
Vẫn tự ghi vào "Bảng giá mặc định" + lịch sử thay đổi giá (mục 3.8, `price_board_id` gắn vào bảng mặc
định) để lịch sử không bị thiếu.

### Body — phòng theo ngày (`room_style=2`)

```json
{
  "room_style": 2,
  "room_ids": ["01kn0ty0getffwhtpv0bjta15m", "01k9xe994aamcwz1rwn470yve3"],
  "apply_mode": "percent",
  "value": 15
}
```

- `apply_mode`: `"price"` (giá cụ thể, VNĐ, field `value`) | `"percent"` (điều chỉnh % trên giá **đang
  có của từng phòng**, +/- được, vd `-10` = giảm 10%).
- `value`: bắt buộc khi `room_style=2` — áp cho **tất cả** phòng trong `room_ids`.

### Body — phòng theo khung giờ (`room_style=1`)

```json
{
  "room_style": 1,
  "room_ids": ["01k9xe994aamcwz1rwn470yve3"],
  "apply_mode": "price",
  "slot_rules": [
    { "pick_mode": "position", "position": "first", "value": 120000 },
    { "pick_mode": "specific", "timeslot_id": 7, "value": 180000 }
  ]
}
```

- `slot_rules[]`: mỗi dòng đổi giá **1 khung giờ**, áp cho **tất cả** phòng trong `room_ids` theo
  đúng vị trí/khung giờ đó (không phải mỗi phòng 1 giá riêng):
  - `pick_mode: "position"` — chọn theo vị trí trong danh sách khung giờ của phòng (sắp theo giờ bắt
    đầu tăng dần): `position: "first"` (sớm nhất) | `"last"` (muộn nhất) | `"nth"` (cần thêm
    `position_n`, 1-based).
  - `pick_mode: "specific"` — chọn đúng `timeslot_id` (khung giờ dùng chung, không phải "ngày đặc
    biệt").
- Nhiều dòng trong `slot_rules[]` = đổi giá nhiều khung giờ trong 1 lần gọi.

**Response `200`**

```json
{
  "updated_count": 2,
  "rooms": [
    { "room_id": "01kn0ty0getffwhtpv0bjta15m", "room_name": "Ngũ Hành Sơn", "touched": true },
    { "room_id": "01k9xe994aamcwz1rwn470yve3", "room_name": "LUMEN", "touched": true }
  ]
}
```

`touched: false` — phòng không có khung giờ nào khớp `slot_rules` (styles=1) hoặc không thuộc đúng
`room_style`/không `is_activated` — bị bỏ qua **âm thầm** (không tính vào `updated_count`, không báo
lỗi cho riêng phòng đó), đúng hành vi của nút "Sửa giá hàng loạt" trên Filament.

**Response `403`** — không phải `super_admin`:

```json
{ "message": "Không có quyền thực hiện thao tác này." }
```
