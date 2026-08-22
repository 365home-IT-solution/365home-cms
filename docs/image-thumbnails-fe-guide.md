# Ảnh thumbnail nhiều cỡ — hướng dẫn cho FE

BE đã triển khai xong phần sinh ảnh thumbnail nhiều cỡ cho ảnh phòng / chi nhánh / banner / tỉnh
thành, theo đúng yêu cầu đo băng thông trước đó (trang chủ tải ~27,9 MB ảnh, mục tiêu xuống dưới
~1,5 MB). Tài liệu này mô tả **response mới**, field `thumbnail_url`/`image_url` cũ **vẫn giữ
nguyên không đổi** — app cũ ngoài store không bị vỡ.

## 1. Field mới: `thumbnail`

Đi kèm mọi field ảnh cũ, response giờ có thêm object `thumbnail`:

```json
{
  "thumbnail_url": "https://365home.vn/storage/610/01KWNCHBE8WE9YV9NKC0CJJ1NN.avif",
  "thumbnail": {
    "thumb": "https://365home.vn/storage/610/conversions/01KWNCHBE8WE9YV9NKC0CJJ1NN-thumb.avif",
    "card": "https://365home.vn/storage/610/conversions/01KWNCHBE8WE9YV9NKC0CJJ1NN-card.avif",
    "wide": "https://365home.vn/storage/610/conversions/01KWNCHBE8WE9YV9NKC0CJJ1NN-wide.avif",
    "full": "https://365home.vn/storage/610/conversions/01KWNCHBE8WE9YV9NKC0CJJ1NN-full.avif",
    "width": 6000,
    "height": 3376
  }
}
```

- `width`/`height` là kích thước ẢNH GỐC — dùng để đặt `aspectRatio` trước khi ảnh tải xong, tránh
  layout nhảy.
- **Preset nào chưa sinh xong (backfill ảnh cũ chưa chạy tới, hoặc job nền chưa xong) thì trả
  `null`** cho đúng preset đó, không bao giờ thiếu key. Luôn kiểm tra null và fallback về
  `thumbnail_url` (hoặc `image_url`) khi preset cần dùng là `null`.

## 2. Bảng preset — dùng đúng chỗ nào

| Preset  | Cạnh dài tối đa | Dùng cho |
|---------|-----------------|----------|
| `thumb` | 240 px | Ảnh nhỏ trong danh sách đơn, dịch vụ kèm theo, ảnh chi nhánh nhỏ trong tỉnh |
| `card`  | 480 px | Thẻ phòng, thẻ chi nhánh, thẻ tỉnh thành (trang chủ, tìm kiếm, yêu thích) |
| `wide`  | 1080 px | Banner trang chủ, hero tỉnh thành, thẻ chi nhánh full-width (tìm kiếm) |
| `full`  | 1440 px | Bộ ảnh chi tiết phòng (khách vuốt xem) |

Tất cả preset đều `fit: inside` (giữ tỉ lệ, không cắt xén) — app tự `contentFit: cover` như cũ.

## 3. Danh sách endpoint đã có `thumbnail`

**Ảnh phòng (room):**
- `thumbnail` trong card phòng — mọi API trả danh sách phòng (trang chủ, tìm kiếm, yêu thích, xem
  tất cả, gợi ý cho bạn...) đều dùng chung 1 hàm build card nên tự động có field này.
- `GET /v1/rooms/{id}` (chi tiết phòng): thêm 2 mảng mới `main_thumbnails` và `gallery_thumbnails`,
  **index-matched** với `main`/`gallery` đã có (cùng độ dài, cùng thứ tự — phần tử thứ `i` trong
  `main_thumbnails` tương ứng phần tử thứ `i` trong `main`).

**Ảnh chi nhánh / tỉnh / banner** — mọi chỗ đang có `image_url` giờ có thêm `thumbnail` cạnh bên,
bao gồm:
- `GET /v1/home` (banner + card chi nhánh trong nhiều section)
- `GET /v1/search` (branches), `GET /v1/branches`, `GET /v1/branches/{id}`
- `GET /v1/room-types/{id}` (2 chỗ: có province / không province)
- `GET /v1/wards/{code}`
- `GET /v1/provinces/nearest`
- App-page dynamic sections (banner, province list)

**Không đổi (cố tình):** endpoint admin/CMS (`/admin/...`) không có field `thumbnail` — chỉ áp
dụng cho API app mobile.

## 4. Ảnh cũ vs ảnh mới

- Ảnh **mới** upload từ sau khi BE deploy: có đủ 4 preset ngay lập tức.
- Ảnh **cũ** (upload trước ngày deploy): cần BE chạy lệnh backfill một lần trên server, có thể có
  độ trễ — trong lúc đó `thumbnail.*` sẽ là `null` cho ảnh cũ chưa được xử lý, **bắt buộc FE phải
  fallback về `thumbnail_url`/`image_url`** cho các trường hợp này, không được coi `null` là lỗi.

## 5. Việc FE cần làm

- Đổi các chỗ đang dùng `thumbnail_url`/`image_url` sang đọc preset tương ứng theo bảng ở mục 2,
  có fallback về field cũ khi preset là `null`.
- Dùng `width`/`height` gốc để đặt aspect ratio trước khi ảnh load xong.
- Không cần đổi cách hiển thị/crop — presets đều giữ tỉ lệ gốc, FE tự `cover`/`contain` như hiện
  tại.
