# API `GET /v1/search/rooms` – Tìm kiếm trả danh sách PHÒNG

> Dành cho FE/app (màn `app/(client)/search.tsx`). Đây là endpoint **mới**, chạy song song với `GET /v1/search` (giữ nguyên cho app cũ). App mới chỉ cần đổi URL sang `/v1/search/rooms`.

```
GET https://365home.vn/api/v1/search/rooms
```

Public, không cần đăng nhập (có token thì `wishlist_status` được điền). Hạn mức chung 60 request/phút/IP.

## 1. Khác gì so với `/v1/search`

| | `/v1/search` (cũ) | `/v1/search/rooms` (mới) |
|---|---|---|
| Có `q` | Trả **chi nhánh** (kèm `room_count`) | Trả **phòng** – không cần tự bung chi nhánh → phòng |
| `date=YYYY-MM-DD` đứng một mình | Không lọc | Lọc phòng **còn trống** trong ngày đó |
| `guests=N` | Không lọc | Lọc phòng đủ chỗ cho N khách |
| Thứ tự khi không có `lat/lng` | Mới nhất lên trước (dồn một loại) | Phòng theo giờ và theo ngày **xen kẽ** |
| `time_type`, `time_from/to`, `from/to`, `month/year` | Có tác dụng | **Bỏ qua** (không cần gửi) |

## 2. Tham số

| Tham số | Kiểu | Mô tả |
|---|---|---|
| `q` | string | Từ khoá. Khớp tên chi nhánh, tên phòng, địa chỉ, tên tỉnh; không phân biệt hoa/thường, dấu, `d`/`đ` |
| `province_id` | int | Tỉnh/thành |
| `province` | string | Slug chi nhánh (hoặc tỉnh); nhiều chi nhánh cách nhau dấu phẩy |
| `ward_code` | int | Phường/xã |
| `lat`, `lng`, `radius` | number | "Gần tôi". `radius` km, mặc định 3. Có toạ độ thì trả **toàn bộ** phòng trong bán kính ở trang 1, xếp theo `distance` tăng dần |
| `date` | `YYYY-MM-DD` | Một ngày (giờ VN). Không gửi = không lọc thời gian. Ngày quá khứ → **422** |
| `guests` | int | Số khách. `0` hoặc không gửi = không lọc |
| `category` | string | Slug loại phòng (`homestay`, `motel`…). MiniHouse: `category=mini_house&tab=3` |
| `page`, `per_page` | int | Phân trang. `per_page` tối đa 100, mặc định 20 |

Các tham số có thể kết hợp tự do (ví dụ `q` + `province_id` + `date` + `guests` + `category`).

### Cách `date` lọc

| Loại phòng (`room_style`) | Hiện khi |
|---|---|
| `theo_gio` | Ngày `date` còn **ít nhất một khung giờ trống** (chưa đặt, không bị admin giữ chỗ, không bị khoá) |
| `theo_ngay` | **Đêm `date` còn trống**: nhận ngày `date`, trả ngày `date + 1` (không bị đặt, không bị khoá) |
| MiniHouse (`tab=3`) | Bỏ qua `date` (thuê tháng) |

### Cách `guests` lọc

Chỉ trả phòng có số khách tối đa không phụ thu ≥ `guests` (cấu hình `max_free_guests` của phòng, mặc định 2 nếu phòng chưa cấu hình). Ví dụ `guests=3` loại các phòng cấu hình tối đa 2 khách.

### Thứ tự kết quả

- Có `lat/lng`: `distance` tăng dần (gần → xa).
- Không có toạ độ: phòng theo giờ và theo ngày xen kẽ (giờ, ngày, giờ, ngày…), trong mỗi loại phòng cập nhật gần nhất đứng trước. Khi một loại hết thì phần còn lại là loại kia.

## 3. Ví dụ request

```
GET /v1/search/rooms?q=can%20tho&page=1
GET /v1/search/rooms?province_id=48&date=2026-11-17
GET /v1/search/rooms?province_id=48&date=2026-11-17&guests=3&category=homestay
GET /v1/search/rooms?lat=10.03&lng=105.78&date=2026-11-17
GET /v1/search/rooms?category=mini_house&tab=3
```

## 4. Response

Shape mỗi phòng và `meta` **giống hệt** `/v1/search` khi không có `q`.

```json
{
  "data": [
    {
      "id": "01kssrxmctsxtw3ss91db78a5h",
      "slug": "whisky-bar",
      "name": "WHISKY BAR",
      "type_slug": "homestay",
      "room_style": "theo_gio",
      "thumbnail_url": "https://365home.vn/storage/586/....jpg",
      "thumbnail": { "thumb": "...", "card": "...", "wide": "...", "full": "...", "width": 1440, "height": 979 },
      "badge": null,
      "price": { "amount": 180000, "unit_label": "/ 2h50" },
      "rating": null,
      "wishlist_status": null,
      "is_available": true,
      "is_variant": false,
      "variant_quantity": 1,
      "latitude": "10.0221279",
      "longitude": "105.7427085",
      "address": "254 Xuân Thủy, An Bình, Cần Thơ",
      "branch": { "id": 167, "name": "254 Xuân Thủy, An Bình, Cần Thơ", "slug": "254-xuan-thuy-an-binh-can-tho", "province_slug": "can-tho" },
      "distance": 1.25
    }
  ],
  "meta": { "current_page": 1, "last_page": 3, "per_page": 20, "total": 51, "province_name": null, "type_name": null }
}
```

- `room_style`: `theo_gio` | `theo_ngay` | `qua_dem` | `theo_thang` (MiniHouse).
- `distance` (km) chỉ có khi gửi `lat/lng`.
- Bấm phòng thì mở `/room/{id}`.

## 5. Lỗi

`date` sai định dạng hoặc ở quá khứ trả **422**:

```json
{ "message": "...", "errors": { "date": ["..."] } }
```

App nên chặn chọn ngày trước hôm nay ngay trên lịch.

## 6. App cần đổi

1. Gọi `/v1/search/rooms` thay cho `/v1/search`.
2. Bỏ phần tự bung chi nhánh → phòng (`branchRoomsParams`, `MAX_BRANCH_EXPAND` ở `constants/client/searchRooms.ts`); `q` phân trang bình thường theo `meta`.
3. `dateSearchParams` chỉ gửi `date`, không gửi `time_type`.
4. `guests` gửi trực tiếp, không cần lọc phía app.

## 7. Lưu ý

- Phòng theo giờ chưa loại các khung giờ **đã qua** trong ngày hôm nay: chọn hôm nay vẫn thấy phòng còn khung giờ trống dù một số khung đã qua.
- Phòng mới đặt/khoá hiển thị đúng ngay (không cache).
