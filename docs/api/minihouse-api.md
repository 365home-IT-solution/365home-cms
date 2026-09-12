# MiniHouse — Tài liệu API

Tài liệu API đầy đủ của module MiniHouse, viết theo đúng thứ tự các API/tính năng đã được xây dựng
(CRUD cơ bản → nghiệp vụ nâng cao → cổng thanh toán → Portal khách thuê web → Portal khách thuê API).
Muốn hiểu tổng quan kiến trúc/luồng nghiệp vụ trước khi đọc từng endpoint, xem file riêng "MiniHouse
— Báo cáo dự án".

## 1. Xác thực (Admin)

Dùng **chung** hệ đăng nhập admin của Home — không có endpoint đăng nhập riêng cho MiniHouse.

### Đăng nhập

```
POST /api/admin/login
Content-Type: application/json
```

**Request**

```json
{
  "email": "minihouse@gmail.com",
  "password": "matkhau123"
}
```

**Response `200`**

```json
{
  "token": "1|AbCdEfGhIjKlMnOpQrStUvWxYz...",
  "user": {
    "id": "a1d056a0-bf35-41b5-b69c-f2ffa3ed5ad0",
    "fullname": "Nguyễn Văn A",
    "email": "minihouse@gmail.com",
    "roles": ["Quản lý MiniHouse"],
    "partner_id": null,
    "partner_name": null,
    "is_super_admin": false,
    "categories": []
  }
}
```

**Response `401`** — sai email/mật khẩu:

```json
{ "message": "Email hoặc mật khẩu không đúng." }
```

**Response `403`** — tài khoản chưa có vai trò nào (không phải nhân viên nội bộ):

```json
{ "message": "Tài khoản không có quyền truy cập khu vực quản trị." }
```

Tài khoản còn phải có quyền `access_minihouse` (trực tiếp hoặc qua vai trò, xem
`MinihousePermissionSeeder`) — không có quyền này thì đăng nhập vẫn thành công (token vẫn cấp) nhưng
mọi endpoint MiniHouse bên dưới đều trả `403` vì thiếu quyền resource tương ứng.

### Gọi API

Mọi request bên dưới đều cần header:

```
Authorization: Bearer <token>
Accept: application/json
```

### Đăng xuất / lấy thông tin tài khoản

```
POST /api/admin/logout   → { "message": "Đã đăng xuất." }
GET  /api/admin/me       → { "user": { ... } }
```

---

## 2. Quy ước chung

### Lọc theo toà nhà (RẤT QUAN TRỌNG)

Mọi API bên dưới tự lọc dữ liệu theo **toà nhà tài khoản được quản lý**
(`User::rootBuildingIds()` — Super Admin thấy tất cả; tài khoản thường chỉ thấy toà đã được gán ở
trang "Tài khoản", chưa gán toà nào thì mặc định thấy TẤT CẢ toà). Thao tác lên 1 bản ghi thuộc toà
nhà ngoài phạm vi được quản lý luôn trả về:

- `404 { "message": "Không tìm thấy ..." }` cho GET/PUT/DELETE 1 bản ghi cụ thể (cố tình trả 404
  thay vì 403 để không lộ việc bản ghi đó tồn tại).
- `403 { "message": "Không có quyền ..." }` khi tạo mới/chuyển bản ghi sang toà nhà ngoài phạm vi.

### Phân quyền

Mỗi resource yêu cầu đúng 1 trong 4 quyền `view_any_x` / `create_x` / `update_x` / `delete_x` (xem
`Modules\Minihouse\App\Support\MinihousePermissions`). Thiếu quyền → `403`:

```json
{ "message": "Không có quyền xem phòng." }
```

### Phân trang

Các endpoint danh sách (`index`) trả nguyên object paginator chuẩn của Laravel (không bọc thêm):

```json
{
  "current_page": 1,
  "data": [ ... ],
  "first_page_url": "...",
  "from": 1,
  "last_page": 3,
  "last_page_url": "...",
  "links": [ ... ],
  "next_page_url": "...",
  "path": "...",
  "per_page": 20,
  "prev_page_url": null,
  "to": 20,
  "total": 45
}
```

Tham số chung: `?per_page=20&search=...` (tuỳ resource còn có thêm filter riêng, ghi rõ ở từng mục).

### Lỗi validate

Chuẩn Laravel `422`, thông báo bằng tiếng Việt (dự án cấu hình `APP_LOCALE=vi`):

```json
{
  "message": "Trường name là bắt buộc.",
  "errors": { "name": ["Trường name là bắt buộc."] }
}
```

### Response 1 bản ghi

`show`/`store`/`update` bọc trong `{ "data": ... }`. `destroy` trả `{ "message": "Đã xoá ..." }`.

---

## 3. Toà nhà — `/api/admin/minihouse/buildings`

Quyền: `view_any_buildings` / `create_buildings` / `update_buildings` / `delete_buildings`.

### `GET /buildings?search=&per_page=`

**Response `200`**

```json
{
  "data": [
    {
      "id": 2,
      "name": "Toà nhà A - Quận 1",
      "address": "12 Nguyễn Huệ, Phường Bến Nghé",
      "rooms_count": 8,
      "electric_unit_price": 3800,
      "water_unit_price": 20000
    }
  ],
  "current_page": 1, "total": 2, "...": "..."
}
```

### `GET /buildings/{id}`

```json
{
  "data": {
    "id": 2,
    "name": "Toà nhà A - Quận 1",
    "address": "12 Nguyễn Huệ, Phường Bến Nghé",
    "province": "TP. Hồ Chí Minh",
    "ward": "Phường Bến Nghé",
    "electric_unit_price": 3800,
    "water_unit_price": 20000,
    "note": null,
    "image": null,
    "created_at": "2026-09-06T15:00:00+07:00",
    "updated_at": "2026-09-06T15:00:00+07:00"
  }
}
```

> Toà nhà còn có nhiều field cấu hình khác (khu vực `zone_id`, thông tin chủ nhà/ngân hàng để hiện
> VietQR, thông tin xác thực PayOS/MoMo/VNPay, `payment_method` đang bật, kiểu chu kỳ tính tiền
> `billing_cycle_type`, các mốc ngày nhắc thu tiền/hết hạn hợp đồng...) — hiện **chỉ cấu hình được
> qua panel Filament**, chưa mở field này trong `store`/`update` của API (xem mục 16).

### `POST /buildings`

**Request**

```json
{
  "name": "Toà nhà D - Tân Bình",
  "address": "99 Cộng Hoà",
  "province": "TP. Hồ Chí Minh",
  "ward": "Phường 4",
  "electric_unit_price": 3800,
  "water_unit_price": 20000,
  "note": null
}
```

**Response `201`** — `{ "data": {...} }`, kèm `notice` nếu tài khoản không phải Super Admin và
chưa được gán quản lý toà vừa tạo:

```json
{
  "data": { "id": 5, "name": "Toà nhà D - Tân Bình", "...": "..." },
  "notice": "Toà nhà đã tạo nhưng tài khoản này chưa được gán quản lý — cần admin gán lại ở trang Tài khoản mới thấy được toà này."
}
```

### `PUT/PATCH /buildings/{id}` — cùng field như tạo, tất cả `sometimes`.

### `DELETE /buildings/{id}` — `{ "message": "Đã xoá toà nhà." }`

---

## 4. Phòng — `/api/admin/minihouse/rooms`

Quyền: `view_any_rooms` / `create_rooms` / `update_rooms` / `delete_rooms`.

### `GET /rooms?building_id=&status=&search=&per_page=`

`status`: `trong` (Trống) | `dat_coc` (Đã đặt cọc) | `dang_thue` (Đang thuê) | `bao_tri` (Đã khoá).

```json
{
  "data": [
    { "id": 19, "code": "A-01", "floor": 1, "building_id": 2, "building_name": "Toà nhà A - Quận 1", "price": 3200000, "status": "bao_tri" }
  ],
  "...": "phân trang"
}
```

### `GET /rooms/{id}`

```json
{
  "data": {
    "id": 19,
    "building_id": 2,
    "building_name": "Toà nhà A - Quận 1",
    "code": "A-01",
    "floor": 1,
    "position_row": 1,
    "position_col": 1,
    "area": 20.5,
    "price": 3200000,
    "status": "bao_tri",
    "note": null,
    "photos": ["minihouse/rooms/abc.jpg"],
    "amenities": [{ "id": 1, "name": "Máy lạnh" }],
    "created_at": "...", "updated_at": "..."
  }
}
```

### `POST /rooms`

**Request**

```json
{
  "building_id": 2,
  "code": "A-09",
  "floor": 3,
  "position_row": 1,
  "position_col": 1,
  "area": 22,
  "price": 3500000,
  "status": "trong",
  "note": null,
  "amenity_ids": [1, 2]
}
```

`status` bỏ trống mặc định `trong`. `building_id` phải thuộc toà nhà được quản lý (403 nếu không).

**Response `201`** — `{ "data": {...} }` (như GET chi tiết).

### `PUT/PATCH /rooms/{id}` — field như trên, tất cả `sometimes` (trừ ràng buộc riêng: đổi
`building_id` sang toà ngoài quyền quản lý → `403`).

### `DELETE /rooms/{id}` — `{ "message": "Đã xoá phòng." }`

---

## 5. Tiện ích — `/api/admin/minihouse/amenities`

Danh mục dùng chung mọi toà nhà (không lọc theo toà) — quyền dùng chung nhóm `rooms`.

```
GET    /amenities            → { "data": [{ "id": 1, "name": "Máy lạnh", "image": null }] }
POST   /amenities            → { "name": "Tủ lạnh", "image": null } → 201 { "data": {...} }
PUT    /amenities/{id}       → { "name": "..." } → { "data": {...} }
DELETE /amenities/{id}       → { "message": "Đã xoá tiện ích." }
```

---

## 6. Khách thuê — `/api/admin/minihouse/tenants`

Quyền: `view_any_tenants` / `create_tenants` / `update_tenants` / `delete_tenants`.

> Khách **chưa có phòng nào** (`room_id = null` — đã trả phòng) sẽ **không hiện** khi lọc theo toà
> nhà ở danh sách (đúng hành vi bên panel Filament) — vẫn xem/sửa được trực tiếp qua `GET/PUT
> /tenants/{id}` vì không thuộc riêng toà nào để mà chặn.

### `GET /tenants?search=&room_id=&per_page=`

`search` khớp `fullname`, `id_card_number`, `phone`.

```json
{ "data": [{ "id": 26, "fullname": "Trần Thị Bình", "phone": "0912345678", "id_card_number": "012345678901", "room_id": 22, "room_code": "A-04" }] }
```

### `GET /tenants/{id}`

```json
{
  "data": {
    "id": 26,
    "fullname": "Trần Thị Bình",
    "phone": "0912345678",
    "id_card_number": "012345678901",
    "id_card_front": null,
    "id_card_back": null,
    "date_of_birth": "1995-03-12",
    "gender": "nu",
    "hometown": "Đồng Nai",
    "permanent_address": null,
    "occupation": "Nhân viên văn phòng",
    "workplace": null,
    "emergency_contact_name": null,
    "emergency_contact_phone": null,
    "residence_declared": false,
    "residence_declared_at": null,
    "room_id": 22,
    "room_code": "A-04",
    "note": null,
    "created_at": "...", "updated_at": "..."
  }
}
```

`gender`: `nam` | `nu` | `khac`. (Khách còn có thể tự đặt `password` để đăng nhập Portal bằng mật
khẩu thay vì OTP mỗi lần — xem mục 15 — field này không hiện trong response API admin vì đã hash.)

### `POST /tenants`

```json
{
  "fullname": "Nguyễn Văn C",
  "phone": "0909999999",
  "id_card_number": "079123456789",
  "date_of_birth": "1998-05-20",
  "gender": "nam",
  "room_id": 20
}
```

Nếu `room_id` có giá trị, phòng đó phải thuộc toà được quản lý (403 nếu không) — để trống thì tạo
bình thường (khách chưa gán phòng).

### `PUT/PATCH /tenants/{id}` / `DELETE /tenants/{id}` — tương tự.

---

## 7. Hợp đồng — `/api/admin/minihouse/contracts`

Quyền: `view_any_contracts` / `create_contracts` / `update_contracts` / `delete_contracts` (4 action
nâng cao bên dưới đều dùng chung quyền `update_contracts`).

### `GET /contracts?status=&room_id=&tenant_id=&per_page=`

`status`: `active` | `expired` | `cancelled`.

```json
{ "data": [{ "id": 26, "room_id": 22, "room_code": "A-04", "tenant_id": 26, "tenant_name": "Trần Thị Bình", "start_date": "2026-06-01", "end_date": "2027-09-06", "monthly_price": 3800000, "status": "active" }] }
```

### `GET /contracts/{id}`

```json
{
  "data": {
    "id": 26,
    "room_id": 22, "room_code": "A-04",
    "building_id": 2, "building_name": "Toà nhà A - Quận 1",
    "tenant_id": 26, "tenant_name": "Trần Thị Bình",
    "start_date": "2026-06-01", "end_date": "2027-09-06",
    "monthly_price": 3800000, "deposit_amount": 3800000,
    "status": "active",
    "electric_unit_price": null, "water_unit_price": null,
    "reason_for_stay": null, "custom_reason": null,
    "checkout_at": null, "deposit_refunded_amount": null, "deposit_deduction_reason": null,
    "transferred_to_contract_id": null, "transferred_from_contract_id": null,
    "created_at": "...", "updated_at": "..."
  }
}
```

### `POST /contracts`

```json
{
  "room_id": 20,
  "tenant_id": 30,
  "start_date": "2026-09-10",
  "end_date": "2027-09-09",
  "monthly_price": 3200000,
  "deposit_amount": 3200000,
  "status": "active"
}
```

`status` bỏ trống mặc định `active`. Chặn tạo hợp đồng `active` thứ 2 cho 1 phòng đang có hợp đồng
`active`:

```json
{ "message": "Phòng này đang có hợp đồng khác còn hiệu lực." }
```
*(HTTP 422)*

Tạo thành công tự đồng bộ `Room.status → dang_thue`, `Tenant.room_id`, mirror vào
`minihouse_contract_tenants` (qua `ContractObserver`) — không cần tự làm ở phía client.

### `PUT/PATCH /contracts/{id}` / `DELETE /contracts/{id}` — tương tự CRUD chuẩn.

### `POST /contracts/{id}/renew` — Gia hạn hợp đồng

Ghi 1 dòng lịch sử vào `minihouse_contract_renewals` TRƯỚC khi cập nhật `end_date`/`monthly_price`.
Chỉ áp dụng cho hợp đồng đang `active`.

**Request**

```json
{
  "new_end_date": "2028-06-01",
  "new_monthly_price": 4000000,
  "note": "Tăng giá theo thị trường"
}
```

**Response `200`** — trả hợp đồng đã cập nhật (như `GET /contracts/{id}`).

**Response `422`** — ngày kết thúc mới không sau ngày hiện tại:

```json
{
  "message": "Ngày kết thúc mới phải sau ngày kết thúc hiện tại (06/01/2027).",
  "errors": { "new_end_date": ["Ngày kết thúc mới phải sau ngày kết thúc hiện tại (06/01/2027)."] }
}
```

**Response `422`** — hợp đồng không ở trạng thái `active`:

```json
{ "message": "Chỉ gia hạn được hợp đồng đang hiệu lực." }
```

### `POST /contracts/{id}/checkout` — Thanh lý / Hoàn cọc

Dùng khi hợp đồng **hết hạn/thuê xong bình thường**. Tự tính gợi ý số tiền hoàn cọc =
`deposit_amount - (tổng còn nợ của hoá đơn unpaid + partial)`. Đổi `status → expired`, phòng tự trả
về "Trống" (qua `ContractObserver`), số tiền hoàn cọc thực nhận tự sinh 1 dòng "Chi" trong sổ Thu Chi
(hạng mục "Hoàn cọc").

**Request**

```json
{
  "checkout_at": "2026-09-30",
  "deposit_refunded_amount": 2000000,
  "deposit_deduction_reason": "Hư hỏng thiết bị"
}
```

`deposit_refunded_amount` có thể bỏ trống — server tự điền bằng số gợi ý.

**Response `200`**

```json
{
  "data": { "id": 26, "status": "expired", "checkout_at": "2026-09-30", "deposit_refunded_amount": 2000000, "...": "..." },
  "suggested_refund": 2350000,
  "unpaid_total": 1450000
}
```

### `POST /contracts/{id}/cancel` — Huỷ hợp đồng sớm

Khác "Thanh lý" ở chỗ dùng cho **chấm dứt hợp đồng SỚM/không tiếp tục thuê** (không phải hết hạn tự
nhiên) — bắt buộc nêu lý do huỷ. Cùng cơ chế tính gợi ý hoàn cọc + tự sinh dòng "Chi" như "Thanh lý".

**Request**

```json
{
  "cancel_reason": "Khách chuyển công tác",
  "checkout_at": "2026-09-15",
  "deposit_refunded_amount": 1500000,
  "deposit_deduction_reason": "Sơn lại tường"
}
```

**Response `200`** — cùng khuôn dạng `checkout`:

```json
{
  "data": { "id": 26, "status": "cancelled", "checkout_at": "2026-09-15", "deposit_refunded_amount": 1500000, "deposit_deduction_reason": "Lý do huỷ: Khách chuyển công tác. Trừ cọc: Sơn lại tường", "...": "..." },
  "suggested_refund": 1700000,
  "unpaid_total": 2100000
}
```

**Response `422`** — hợp đồng không `active`: `{ "message": "Chỉ huỷ được hợp đồng đang hiệu lực." }`

### `POST /contracts/{id}/transfer-room` — Chuyển phòng

Hợp đồng cũ kết thúc (`status → expired`, phòng cũ tự trả về "Trống"), tạo NGAY hợp đồng mới cho
phòng đích — mang theo tiền cọc, người ở cùng, và phụ thu định kỳ (chỉ khi phòng mới **cùng toà
nhà**). KHÔNG hoàn cọc như "Thanh lý". Phòng đích phải đang "Trống" và khác phòng hiện tại; nếu khác
toà nhà, tài khoản vẫn phải được quản lý toà đích mới chuyển được.

**Request**

```json
{
  "new_room_id": 20,
  "transfer_at": "2026-09-10",
  "new_monthly_price": 3500000
}
```

**Response `201`** — trả **hợp đồng MỚI** (không phải hợp đồng cũ vừa gọi action):

```json
{
  "data": {
    "id": 40,
    "room_id": 20, "room_code": "A-02",
    "tenant_id": 26, "tenant_name": "Trần Thị Bình",
    "start_date": "2026-09-10", "end_date": "2027-09-06",
    "monthly_price": 3500000, "deposit_amount": 3800000,
    "status": "active",
    "transferred_from_contract_id": 26, "transferred_to_contract_id": null,
    "...": "..."
  }
}
```

**Response `422`** — phòng đích không hợp lệ:

```json
{ "message": "Phòng mới phải đang \"Trống\" và khác phòng hiện tại." }
```

---

## 8. Hoá đơn — `/api/admin/minihouse/invoices`

Quyền: `view_any_invoices` / `create_invoices` / `update_invoices` / `delete_invoices`.

> `status`, `amount_paid`, `paid_at` là cột **cache tự đồng bộ** từ các lần thanh toán ĐÃ DUYỆT —
> KHÔNG set trực tiếp được qua `store`/`update`, phải ghi nhận + duyệt thanh toán qua mục 8.1.

### `GET /invoices?contract_id=&status=&month=YYYY-MM-DD&per_page=`

`status`: `unpaid` | `partial` | `paid`.

```json
{ "data": [{ "id": 12, "contract_id": 25, "room_code": "A-03", "month": "2026-10", "total_amount": 3600000, "amount_paid": 0, "remaining": 3600000, "status": "unpaid" }] }
```

### `GET /invoices/{id}`

```json
{
  "data": {
    "id": 12, "contract_id": 25, "room_code": "A-03", "building_id": 2,
    "month": "2026-10", "period_start": "2026-10-01", "period_end": "2026-10-31",
    "room_price": 3600000,
    "electric_start": 120, "electric_end": 145, "electric_unit_price": 3800, "electric_amount": 95000,
    "water_start": 10, "water_end": 15, "water_unit_price": 20000, "water_amount": 100000,
    "service_amount": 100000, "total_amount": 3895000,
    "amount_paid": 2000000, "remaining": 1895000, "status": "partial", "paid_at": "2026-10-05T00:00:00+07:00",
    "items": [{ "id": 5, "name": "Gửi xe", "amount": 100000 }],
    "payments": [{ "id": 9, "amount": 2000000, "paid_at": "2026-10-05", "payment_method": "tien_mat", "note": null, "status": "approved", "approved_at": "2026-10-05T09:00:00+07:00", "approved_by": "a1d0..." }],
    "created_at": "...", "updated_at": "..."
  }
}
```

> Chỉ khoản thanh toán **đã duyệt** (`status: "approved"`) mới được cộng vào `amount_paid` — khoản
> `"pending"` (chờ duyệt) vẫn hiện trong mảng `payments` để theo dõi nhưng chưa tính vào tổng đã thu.

### `POST /invoices` — tạo hoá đơn thủ công cho 1 hợp đồng

```json
{
  "contract_id": 25,
  "month": "2026-11-01",
  "period_start": "2026-11-01",
  "period_end": "2026-11-30",
  "room_price": 3600000,
  "electric_start": 145,
  "electric_end": 170,
  "electric_unit_price": 3800,
  "water_start": 15,
  "water_end": 20,
  "water_unit_price": 20000,
  "service_amount": 100000
}
```

Server tự tính `electric_amount`/`water_amount`/`total_amount` (làm tròn đồng), `status` luôn khởi
tạo `unpaid`. Trùng hợp đồng + tháng đã có hoá đơn (kể cả hoá đơn đã xoá mềm rồi tạo lại) →
`422 { "message": "Hợp đồng này đã có hoá đơn tháng đó." }`.

> `electric_start`/`electric_end`/`water_start`/`water_end` để trống thì hoá đơn coi như "thiếu chỉ
> số" — sẽ KHÔNG hiện trên Portal khách thuê và không gửi thông báo cho tới khi được bổ sung đủ qua
> `PUT/PATCH` (xem mục A.3 bước 2–3/A.4 trong file "Báo cáo dự án" và cột "Đã gửi khách" trên bảng
> hoá đơn ở panel Filament).

### `PUT/PATCH /invoices/{id}` — sửa chỉ số/giá, tự tính lại `total_amount` (field như trên, tất cả
`sometimes`, không nhận `contract_id`/`month`).

### `DELETE /invoices/{id}` — xoá mềm, tự xoá kèm mọi `InvoicePayment` (và `Transaction` liên kết)
qua `InvoiceObserver`. `{ "message": "Đã xoá hoá đơn." }`

### `POST /invoices/generate` — Lập hoá đơn hàng loạt theo tháng

```json
{
  "month": "2026-11-01",
  "building_ids": [2, 3]
}
```

`building_ids` bỏ trống = TOÀN BỘ toà nhà tài khoản được quản lý (không phải toàn bộ site). Chọn
toà nhà ngoài quyền quản lý → `403`. Lập cho mọi hợp đồng `active` của tháng đó, bỏ qua hợp đồng đã
có hoá đơn tháng đó — không báo lỗi, liệt kê ở `skipped`. Có khoá `Cache::lock()` theo từng hợp
đồng để không lập trùng nếu bị gọi 2 lần gần như đồng thời.

**Response `200`**

```json
{
  "created_count": 4,
  "skipped_count": 1,
  "created": [
    { "id": 12, "contract_id": 25, "room_code": "A-03", "month": "2026-11", "total_amount": 3600000, "amount_paid": null, "remaining": 3600000, "status": "unpaid" }
  ],
  "skipped": [{ "contract_id": 26, "room_id": 22 }]
}
```

### Tạo mã/link thanh toán trực tuyến cho hoá đơn (dùng khi nhân viên gửi tay cho khách, VD in QR
dán tại phòng — khác với việc chính khách tự bấm "Thanh toán" trên Portal ở mục 14/15, tuy dùng
chung service phía sau)

```
POST /invoices/{id}/qr     → VietQR/PayOS tuỳ toà nhà cấu hình gì (quyền update_invoices)
POST /invoices/{id}/momo   → MoMo (chỉ hoạt động nếu toà nhà tự cấu hình MoMo riêng, không có tài khoản dùng chung)
POST /invoices/{id}/vnpay  → VNPay
```

Cả 3 trả `{ "data": { "qr_image"|"checkout_url"|"pay_url"|"payment_url": "...", "amount": ..., "expired_at": "..." } }`
tuỳ cổng, hoặc `422 { "message": "..." }` nếu toà nhà chưa cấu hình đủ thông tin cổng đó.

### 8.1. Thanh toán hoá đơn — `/api/admin/minihouse/invoices/{invoiceId}/payments`

Mỗi khoản thanh toán ghi nhận qua đây (nhân viên tự khai tiền mặt/chuyển khoản) luôn khởi tạo ở
trạng thái **`pending` (chờ duyệt)** — KHÔNG cộng ngay vào `Invoice.amount_paid`. Phải được duyệt qua
endpoint `approve` bên dưới (quyền `approve_invoice_payments`, tách biệt với quyền ghi nhận
`update_invoices` — nhân viên ghi nhận được nhưng không tự duyệt được chính mình) thì mới đồng bộ lại
`Invoice.amount_paid/paid_at/status` **và** tự tạo 1 dòng "Thu" trong sổ Thu Chi
(`Transaction.invoice_payment_id`). Thanh toán qua webhook cổng điện tử (mục 13) thì tự động ở thẳng
trạng thái `approved` vì đã xác thực chữ ký từ cổng, không cần người duyệt tay.

```
GET /invoices/{invoiceId}/payments
```

```json
{ "data": [
  { "id": 9, "invoice_id": 12, "amount": 2000000, "paid_at": "2026-10-05", "payment_method": "tien_mat", "note": null, "status": "approved", "approved_at": "2026-10-05T09:00:00+07:00", "approved_by": "a1d0...", "created_by": "a1d0..." }
] }
```

```
POST /invoices/{invoiceId}/payments
```

**Request**

```json
{
  "amount": 1895000,
  "paid_at": "2026-10-20",
  "payment_method": "chuyen_khoan",
  "note": "Thanh toán nốt phần còn lại"
}
```

`payment_method`: `tien_mat` | `chuyen_khoan` | `khac`. Chỉ nhận **đúng 1 khoản, đủ 100%** tổng còn
thiếu của hoá đơn (`Invoice::validateSinglePayment()`) — sai số tiền → `422`.

**Response `201`**

```json
{
  "data": { "id": 10, "invoice_id": 12, "amount": 1895000, "paid_at": "2026-10-20", "payment_method": "chuyen_khoan", "note": "...", "status": "pending", "approved_at": null, "approved_by": null, "created_by": "..." },
  "invoice": { "amount_paid": 2000000, "status": "partial", "remaining": 1895000 }
}
```

*(`invoice.amount_paid` CHƯA đổi vì khoản vừa tạo đang `pending`, chờ duyệt.)*

```
POST /invoices/{invoiceId}/payments/{paymentId}/approve
```

Quyền `approve_invoice_payments`. Chặn duyệt nếu hoá đơn đã có 1 khoản KHÁC được duyệt rồi (VD
webhook tự động duyệt trước) → `422 { "message": "Hoá đơn này đã có 1 khoản thanh toán khác được duyệt rồi — vui lòng kiểm tra lại trước khi duyệt khoản này." }`.

**Response `200`**

```json
{
  "data": { "id": 10, "status": "approved", "approved_at": "2026-10-20T10:00:00+07:00", "approved_by": "a1d0...", "...": "..." },
  "invoice": { "amount_paid": 3895000, "status": "paid", "remaining": 0 }
}
```

```
DELETE /invoices/{invoiceId}/payments/{paymentId}  →  { "message": "Đã xoá lần thanh toán." }
```

---

## 9. Thu chi — `/api/admin/minihouse/transactions`

Quyền: `view_any_transactions` / `create_transactions` / `update_transactions` / `delete_transactions`.

> Dòng nào có `invoice_payment_id` khác `null` là **tự sinh** từ 1 lần thanh toán hoá đơn ĐÃ DUYỆT —
> KHÔNG sửa/xoá trực tiếp được ở đây (`422`), phải sửa/xoá đúng lần thanh toán đó (mục 8.1).

### `GET /transactions?building_id=&type=&category=&from=&to=&per_page=`

`type`: `thu` | `chi`. `category`: `sua_chua` (sửa chữa) | `van_hanh` (vận hành) | `hoan_coc` (hoàn
cọc) | `khac`. `from`/`to`: `YYYY-MM-DD`.

```json
{ "data": [{ "id": 3, "building_id": 2, "building_name": "Toà nhà A - Quận 1", "room_code": "A-03", "type": "thu", "category": null, "amount": 3600000, "transaction_date": "2026-10-05", "is_auto": true }] }
```

### `GET /transactions/{id}`

```json
{
  "id": 3, "building_id": 2, "building_name": "Toà nhà A - Quận 1", "room_code": "A-03",
  "type": "thu", "category": null, "amount": 3600000, "transaction_date": "2026-10-05", "is_auto": true,
  "contract_id": 25, "invoice_payment_id": 9, "note": "Thanh toán hoá đơn tháng 10/2026 - phòng A-03",
  "receipt_image": null, "created_at": "...", "updated_at": "..."
}
```
*(bọc trong `{ "data": {...} }`)*

### `POST /transactions` — giao dịch nhập tay (sửa chữa, vận hành...)

```json
{
  "building_id": 2,
  "contract_id": null,
  "type": "chi",
  "category": "sua_chua",
  "amount": 500000,
  "transaction_date": "2026-10-12",
  "note": "Sửa máy bơm nước tầng 1"
}
```

### `PUT/PATCH /transactions/{id}` — field như trên. Dòng tự động (`invoice_payment_id` khác null):

```json
{ "message": "Dòng tự động sinh từ thanh toán hoá đơn — sửa đúng lần thanh toán đó, không sửa trực tiếp ở đây." }
```
*(HTTP 422)*

### `DELETE /transactions/{id}` — cùng chặn như trên với thông báo tương ứng.

---

## 10. Phụ thu — `/api/admin/minihouse/surcharges`

Quyền dùng chung nhóm `buildings`. Danh mục phụ thu định kỳ theo từng toà (VD: gửi xe, internet).

```
GET    /surcharges?building_id=&per_page=
POST   /surcharges     { "building_id": 2, "name": "Gửi xe", "amount": 100000, "is_active": true }
PUT    /surcharges/{id}
DELETE /surcharges/{id}
```

**Item mẫu**

```json
{ "id": 1, "building_id": 2, "building_name": "Toà nhà A - Quận 1", "name": "Gửi xe", "amount": 100000, "note": null, "is_active": true }
```

*(Không có endpoint `show` riêng — dùng `index` với `building_id` để lọc.)*

---

## 11. Nhắc việc — `/api/admin/minihouse/reminders`

Quyền: `view_any_reminders` / `create_reminders` / `update_reminders` / `delete_reminders`.

> Nhắc việc CHUNG (`room_id` và `contract_id` đều `null`, VD "Đóng thuế quý") hiện cho **mọi** tài
> khoản MiniHouse, không giới hạn theo toà. Nhắc việc gắn phòng/hợp đồng cụ thể mới lọc theo toà
> được quản lý. Nhắc việc gắn `invoice_id` (VD "nhắc thu tiền hoá đơn X") tự đánh dấu `is_done: true`
> ngay khi hoá đơn đó chuyển sang `paid` — không cần tự đóng bằng tay.

```
GET /reminders?is_done=&type=&per_page=
```

`type`: `thu_tien` | `het_han_hop_dong` | `bao_tri` | `khac`.

```json
{ "data": [{ "id": 4, "title": "Thu tiền phòng A-03 tháng 11", "content": null, "remind_date": "2026-11-01", "type": "thu_tien", "room_id": 21, "room_code": "A-03", "contract_id": null, "is_done": false }] }
```

```
POST /reminders
```

```json
{
  "title": "Đóng thuế quý IV",
  "content": null,
  "remind_date": "2026-12-20",
  "type": "khac"
}
```

`room_id`/`contract_id` tuỳ chọn — có giá trị thì phải thuộc toà nhà được quản lý (403 nếu không).

```
PUT/PATCH /reminders/{id}   { "is_done": true }
DELETE    /reminders/{id}
```

*(Không có endpoint `show` riêng.)*

---

## 12. Khai báo lưu trú — `/api/admin/minihouse/residence-declarations`

Quyền: `view_any_residence_declarations` / `create_residence_declarations` /
`update_residence_declarations` / `delete_residence_declarations`.

> Bảng này CHỈ lưu tham chiếu nội bộ — **không** tự gửi cho ASM/dịch vụ công, chủ trọ vẫn phải tự
> nộp thủ công theo quy định.

### `GET /residence-declarations?contract_id=&declared=true|false&per_page=`

```json
{ "data": [{ "id": 7, "contract_id": 25, "room_code": "A-03", "tenant_id": 25, "full_name": "Nguyễn Văn An", "checked_in_at": "2026-06-01T00:00:00+07:00", "is_declared": false, "is_overdue": true }] }
```

### `GET /residence-declarations/{id}`

```json
{
  "data": {
    "id": 7, "contract_id": 25, "room_code": "A-03", "tenant_id": 25,
    "full_name": "Nguyễn Văn An", "date_of_birth": "1990-01-01", "gender": "M - Nam",
    "cccd_number": "079123456789", "nationality": "VNM - Viet Nam", "document_type": "1 - Thẻ CCCD",
    "phone_number": "0911111111",
    "checked_in_at": "2026-06-01T00:00:00+07:00", "checked_out_at": null,
    "room_number": "A-03", "stay_address": "12 Nguyễn Huệ, Phường Bến Nghé",
    "reason_for_stay": "1 - Du lịch", "custom_reason": null,
    "current_residence": "45 Lê Lợi, Đồng Nai", "residence_type": "2 - Tạm trú",
    "province": "Đồng Nai", "ward": "Phường Trấn Biên", "address_detail": null, "notes": null,
    "declared_at": null, "declared_by": null,
    "is_declared": false, "is_overdue": true, "is_data_complete": true, "missing_fields": []
  }
}
```

### `POST /residence-declarations`

```json
{
  "contract_id": 25,
  "tenant_id": 25,
  "full_name": "Nguyễn Văn An",
  "date_of_birth": "1990-01-01",
  "gender": "M - Nam",
  "cccd_number": "079123456789",
  "nationality": "VNM - Viet Nam",
  "document_type": "1 - Thẻ CCCD",
  "checked_in_at": "2026-06-01",
  "room_number": "A-03",
  "stay_address": "12 Nguyễn Huệ, Phường Bến Nghé",
  "reason_for_stay": "1 - Du lịch",
  "current_residence": "45 Lê Lợi, Đồng Nai",
  "residence_type": "2 - Tạm trú",
  "province": "Đồng Nai",
  "ward": "Phường Trấn Biên"
}
```

### `PUT/PATCH /residence-declarations/{id}` — field như trên (trừ `contract_id`/`tenant_id`).

### `POST /residence-declarations/{id}/mark-declared` — Đánh dấu "đã nộp thủ công"

Chặn nếu còn thiếu dữ liệu bắt buộc theo Luật Cư trú.

**Response `200`**

```json
{ "data": { "id": 7, "declared_at": "2026-09-07T10:00:00+07:00", "declared_by": "a1d0...", "is_declared": true, "...": "..." } }
```

**Response `422`**

```json
{
  "message": "Còn thiếu dữ liệu bắt buộc, chưa đánh dấu \"đã khai báo\" được.",
  "missing_fields": ["Số phòng", "Địa chỉ lưu trú"]
}
```

### `DELETE /residence-declarations/{id}` — `{ "message": "Đã xoá khai báo lưu trú." }`

---

## 13. Webhook cổng thanh toán — `/api/minihouse/webhook/*`

**Công khai** (không qua `auth:sanctum`/`admin.api`) — do chính cổng thanh toán gọi vào, không phải
client gọi tay. Mỗi cổng tự xác thực bằng chữ ký riêng, KHÔNG dùng Bearer token nội bộ. Cả 3 đều: so
khớp số tiền với số hoá đơn còn thiếu thực tế, khoá `lockForUpdate()` + transaction để chặn ghi trùng
khi cổng gọi lại (retry), và chặn tạo khoản thanh toán thứ 2 nếu hoá đơn đã có 1 khoản được duyệt.

```
POST /api/minihouse/webhook/payos   — PayOS gọi khi có giao dịch, xác thực chữ ký HMAC riêng của PayOS
POST /api/minihouse/webhook/momo    — MoMo IPN, tự xác thực HMAC-SHA256 (không có SDK chính thức)
GET  /api/minihouse/webhook/vnpay   — VNPay IPN (VNPay gọi bằng query string GET, không phải POST body)
```

Không cần tích hợp thủ công — chỉ cần khai báo đúng 3 URL này khi cấu hình tài khoản merchant ở từng
cổng thanh toán (PayOS/MoMo/VNPay Dashboard), hệ thống tự xử lý phần còn lại.

---

## 14. Portal khách thuê — Web (session)

Trang web server-render dành cho khách thuê mở bằng trình duyệt điện thoại (VD từ link SMS/Zalo) —
đăng nhập bằng guard `tenant` riêng (KHÔNG dùng chung guard `web` của nhân viên). Route
`Modules/Minihouse/Routes/web.php`, controller `Modules\Minihouse\Http\Controllers\Portal\*`.

### Đăng nhập

```
GET  /minihouse/portal/login                 — trang nhập số điện thoại (2 tab: Mật khẩu / Mã OTP)
POST /minihouse/portal/login                 — { phone } → gửi OTP (Zalo trước, SMS dự phòng nếu Zalo lỗi)
POST /minihouse/portal/login/password        — { phone, password } → đăng nhập thẳng, giới hạn 5 lần sai/60 giây
GET  /minihouse/portal/login/verify          — trang nhập mã OTP
POST /minihouse/portal/login/verify          — { phone, code } → xác thực; SĐT dùng chung nhiều hồ sơ thì
                                                 chuyển sang bước chọn hồ sơ bên dưới thay vì đăng nhập thẳng
GET  /minihouse/portal/login/select-profile  — trang chọn hồ sơ (khi 1 SĐT gắn nhiều khách, VD người thân)
POST /minihouse/portal/login/select-profile  — { tenant_id } → hoàn tất đăng nhập đúng hồ sơ đã chọn
POST /minihouse/portal/logout
```

Đăng nhập thành công phát hành cookie "nhớ đăng nhập" ~13 tháng (`remember: true`) — khách chỉ cần
xác thực OTP/mật khẩu lại khi đổi thiết bị hoặc xoá cookie, không phải mỗi lần vào Portal.

### Các trang sau khi đăng nhập (`middleware('auth:tenant')`)

```
GET  /minihouse/portal/                       — Tổng quan: hợp đồng đang thuê, tổng nợ, danh sách thẻ chức năng
GET  /minihouse/portal/invoices               — Danh sách hoá đơn (chỉ hoá đơn ĐÃ ĐỦ chỉ số điện/nước)
GET  /minihouse/portal/invoices/{invoice}     — Chi tiết 1 hoá đơn
POST /minihouse/portal/invoices/{invoice}/pay — Tạo mã QR/link thanh toán theo cổng toà nhà đã cấu hình
GET  /minihouse/portal/payments               — Lịch sử thanh toán
GET  /minihouse/portal/contracts              — Danh sách hợp đồng (hiện tại + lịch sử)
GET  /minihouse/portal/contracts/{contract}   — Chi tiết hợp đồng, kèm link tải file hợp đồng/biên bản
GET  /minihouse/portal/notifications          — Thông báo (hoá đơn mới, phản hồi được trả lời, thông báo chung...) — xem tự đánh dấu đã đọc
GET  /minihouse/portal/feedback               — Trang gửi phản hồi/đánh giá
POST /minihouse/portal/feedback               — { rating, content }
GET  /minihouse/portal/password               — Trang đặt/đổi mật khẩu (không bắt nhập mật khẩu cũ — OTP luôn là đường khôi phục dự phòng)
POST /minihouse/portal/password               — { password, password_confirmation }
```

Trang Tổng quan hiện 2 badge số đỏ: số thông báo chưa đọc, và số hoá đơn chưa thanh toán — tự cập
nhật ngay khi có hoá đơn mới đủ điều kiện hiện hoặc khi 1 khoản thanh toán được duyệt.

---

## 15. Portal khách thuê — REST API (Sanctum)

Bản API đầy đủ, tương đương 100% với Portal Web ở mục 14 (dùng chung
`TenantPortalService`/`InteractsWithTenantPortalData` — xem mục A.5 trong file "Báo cáo dự án"), dành
cho app di động/đối tác thứ 3.
Route `routes/api_minihouse.php`, controller `App\Http\Controllers\Api\Minihouse\Portal\*`.

### 15.1. Xác thực

**Lấy mã OTP**

```
POST /api/minihouse/portal/otp/request
```
```json
{ "phone": "0912345678" }
```
`200 { "message": "Đã gửi mã xác thực.", "channel": "zalo" }` (hoặc `"sms"` nếu Zalo lỗi/chưa cấu
hình) — `404` nếu không có hồ sơ khách nào dùng số này, `422` nếu cả Zalo lẫn SMS đều chưa cấu hình.

**Xác thực OTP**

```
POST /api/minihouse/portal/otp/verify
```
```json
{ "phone": "0912345678", "code": "123456" }
```

SĐT chỉ khớp **1** hồ sơ → trả token thẳng:
```json
{ "token": "5|AbC...", "tenant": { "id": 26, "fullname": "Trần Thị Bình", "phone": "0912345678" } }
```

SĐT dùng chung **nhiều** hồ sơ (VD người thân) → chưa cấp token ngay, trả vé chọn hồ sơ:
```json
{
  "requires_selection": true,
  "selection_ticket": "aZ9...40 ký tự",
  "profiles": [
    { "id": 26, "fullname": "Trần Thị Bình", "room": "A-04" },
    { "id": 31, "fullname": "Trần Văn Em", "room": "A-04" }
  ]
}
```
`422` nếu sai mã/mã hết hạn.

**Chọn hồ sơ** (chỉ cần khi bước trên trả `requires_selection: true`)

```
POST /api/minihouse/portal/select-profile
```
```json
{ "selection_ticket": "aZ9...40 ký tự", "tenant_id": 26 }
```
`200` → cùng khuôn dạng token như trên. `403` nếu `tenant_id` không nằm trong danh sách đã xác thực
OTP hợp lệ (chặn giả mạo chọn nhầm hồ sơ người khác). `422` nếu vé hết hạn (5 phút) — phải xin OTP
lại từ đầu.

**Đăng nhập bằng mật khẩu** (khách đã từng đặt mật khẩu ở mục 15.3)

```
POST /api/minihouse/portal/login/password
```
```json
{ "phone": "0912345678", "password": "matkhau123" }
```
`200` → cùng khuôn dạng token. `422 { "message": "Số điện thoại hoặc mật khẩu không đúng." }` (không
tiết lộ sai ở phần nào). `429` sau 5 lần sai trong 60 giây.

**Đăng xuất** (`auth:sanctum` + `tenant.api`)

```
POST /api/minihouse/portal/logout   → { "message": "Đã đăng xuất." }
```
Chỉ thu hồi đúng token của thiết bị đang gọi, không đăng xuất các thiết bị khác.

Mọi endpoint từ đây trở xuống cần header:
```
Authorization: Bearer <token>
Accept: application/json
```

### 15.2. Tổng quan

```
GET /api/minihouse/portal/dashboard
```
```json
{
  "data": {
    "tenant": { "id": 26, "fullname": "Trần Thị Bình", "phone": "0912345678" },
    "active_contract": {
      "id": 25, "room_code": "A-03", "building_name": "Toà nhà A - Quận 1",
      "monthly_price": 3600000, "start_date": "2026-06-01", "end_date": "2027-09-06"
    },
    "unpaid_total": 1895000,
    "unpaid_invoice_count": 1,
    "unread_notification_count": 2,
    "owner": { "name": "Nguyễn Văn Chủ", "phone": "0900000000", "address": "12 Nguyễn Huệ" }
  }
}
```
`active_contract`/`owner` là `null` nếu khách hiện không có hợp đồng đang hiệu lực.

### 15.3. Thông báo

```
GET /api/minihouse/portal/notifications?per_page=20
```
Gọi endpoint này tự động đánh dấu TOÀN BỘ thông báo hiện có là đã đọc (giống hành vi web).
```json
{
  "data": [
    { "id": 5, "type": "invoice_new", "title": "Hoá đơn tháng 11/2026 đã có", "body": "...", "link": "/minihouse/portal/invoices/13", "read_at": null, "created_at": "..." }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 5 }
}
```

### 15.4. Hoá đơn

```
GET /api/minihouse/portal/invoices?per_page=12
```
Chỉ trả hoá đơn ĐÃ đủ chỉ số điện/nước (`isReadyForTenant()`), của mọi hợp đồng (kể cả hợp đồng cũ
đã kết thúc) — không riêng hợp đồng đang hiệu lực.
```json
{
  "data": [{ "id": 13, "month": "11/2026", "room_code": "A-03", "total_amount": 3700000, "amount_paid": 0, "remaining": 3700000, "status": "unpaid" }],
  "meta": { "...": "..." }
}
```

```
GET /api/minihouse/portal/invoices/{id}
```
```json
{
  "data": {
    "id": 13, "month": "11/2026", "room_code": "A-03", "total_amount": 3700000, "amount_paid": 0, "remaining": 3700000, "status": "unpaid",
    "building_name": "Toà nhà A - Quận 1", "room_price": 3600000,
    "electric_amount": 100000, "electric_start": 145, "electric_end": 172,
    "water_amount": 0, "water_start": 15, "water_end": 15,
    "items": [{ "name": "Gửi xe", "amount": 100000 }]
  }
}
```
`403` nếu hoá đơn không thuộc khách đang đăng nhập. `404` nếu hoá đơn chưa đủ chỉ số/không tồn tại
(cố tình trả 404 kể cả khi hoá đơn tồn tại nhưng chưa sẵn sàng, để không lộ đang có hoá đơn dở).

```
POST /api/minihouse/portal/invoices/{id}/pay
```
Tạo mã/link thanh toán theo ĐÚNG cổng toà nhà đã cấu hình — trả về khuôn dạng chuẩn hoá GIỐNG NHAU
dù dùng cổng nào (nhờ `TenantPortalService::normalize*Result()`):
```json
{ "data": { "qr_image": "https://...", "open_url": "https://...", "open_label": "Mở trang thanh toán PayOS", "amount": 3700000, "expired_at": "2026-09-10T23:59:59+07:00" } }
```
VietQR (chuyển khoản tĩnh) trả `"open_url": null, "open_label": ""` (chỉ có ảnh QR, không có trang
mở riêng). `409` nếu hoá đơn đã thanh toán đủ. `422` nếu toà nhà chưa cấu hình cổng thanh toán nào.

### 15.5. Lịch sử thanh toán

```
GET /api/minihouse/portal/payments?per_page=20
```
```json
{
  "data": [{ "id": 9, "amount": 2000000, "paid_at": "2026-10-05", "payment_method": "tien_mat", "status": "approved", "note": null, "invoice_id": 12, "invoice_month": "10/2026" }],
  "meta": { "...": "..." }
}
```

### 15.6. Hợp đồng

```
GET /api/minihouse/portal/contracts
```
```json
{ "data": [{ "id": 25, "status": "active", "room_code": "A-03", "building_name": "Toà nhà A - Quận 1", "monthly_price": 3600000, "start_date": "2026-06-01", "end_date": "2027-09-06" }] }
```

```
GET /api/minihouse/portal/contracts/{id}
```
```json
{
  "data": {
    "id": 25, "status": "active", "room_code": "A-03", "building_name": "Toà nhà A - Quận 1",
    "monthly_price": 3600000, "start_date": "2026-06-01", "end_date": "2027-09-06",
    "deposit_amount": 3600000, "checkout_at": null, "deposit_refunded_amount": null, "contract_content": "...",
    "files": [{ "label": "Hợp đồng (file)", "url": "https://.../storage/minihouse/contracts/abc.pdf" }]
  }
}
```
`403` nếu hợp đồng không thuộc khách đang đăng nhập.

### 15.7. Gửi phản hồi

```
POST /api/minihouse/portal/feedback
```
```json
{ "rating": 5, "content": "Chủ nhà hỗ trợ nhiệt tình" }
```
`rating`: 1–5 (bắt buộc), `content` tuỳ chọn. `201 { "data": { "id": 12 } }`.

### 15.8. Đặt/đổi mật khẩu

```
POST /api/minihouse/portal/password
```
```json
{ "password": "matkhau123", "password_confirmation": "matkhau123" }
```
Không yêu cầu mật khẩu cũ (OTP luôn là đường khôi phục dự phòng nếu quên). `200 { "message": "Đã đặt mật khẩu — lần sau bạn có thể đăng nhập bằng SĐT + mật khẩu, không cần chờ mã OTP nữa." }`.

### 15.9. Bảo mật token

- Token phát hành qua `Laravel\Sanctum\HasApiTokens` trên model `Tenant`, đa hình CHUNG bảng
  `personal_access_tokens` với `App\Models\User` nhưng TÁCH BIỆT hoàn toàn theo `tokenable_type`.
- Middleware `tenant.api` (`App\Http\Middleware\TenantApiAuth`) đảm bảo token đang dùng thuộc ĐÚNG 1
  `Tenant` — dùng nhầm token admin gọi API Portal (hoặc ngược lại) đều bị chặn `403`.
- 1 khách nhiều hồ sơ dùng chung SĐT: OTP xác thực xong chỉ cho chọn ĐÚNG 1 trong các hồ sơ đã xác
  thực hợp lệ (`selection_ticket` dùng 1 lần, hết hạn 5 phút) — không thể sửa `tenant_id` để đăng
  nhập nhầm hồ sơ người khác dù cùng SĐT.

---

## 16. Chưa có trong API (chỉ ở panel Filament)

- Cấu hình toà nhà nâng cao: thông tin chủ nhà/ngân hàng (VietQR), thông tin xác thực
  PayOS/MoMo/VNPay, `payment_method` đang bật, kiểu chu kỳ tính tiền, các mốc ngày nhắc — chỉ sửa
  được qua panel Filament (`BuildingForm`), API admin (`store`/`update` toà nhà) chưa mở các field
  này.
- Quản lý Khu vực (Zone), cấu hình Zalo OTP/SMS OTP, Thông báo (Announcement) gửi hàng loạt tới
  Portal — chỉ thao tác qua giao diện quản trị.
- Gửi thông báo nhắc việc tự động (hiện chỉ chạy theo lịch cron —
  `php artisan minihouse:send-reminder-notifications`).
- Xuất Excel Khai báo lưu trú theo mẫu Bộ Công an (`ResidenceDeclarationExcelExporter`).
- In/Xuất PDF nội dung hợp đồng (`ContractContentRenderer`/`ContractPdfRenderer`).
- Quản lý Vai trò/Phân quyền, Tài khoản, Nhật ký hoạt động — chỉ thao tác được qua giao diện quản
  trị, không có API riêng (đây là dữ liệu cấu hình hệ thống, không phải nghiệp vụ cho thuê).
