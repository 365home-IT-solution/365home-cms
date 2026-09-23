# API kho dành cho `super_admin`

Tài liệu này mô tả contract tạo phiếu kho sau khi mở quyền thao tác trên app cho tài khoản `super_admin`.

## Thay đổi chính

Trước đây, khi tạo phiếu nhập, xuất hoặc kiểm kê kho, `super_admin` phải gửi cả `partner_id` và `branch_id`. Tuy nhiên, tài khoản `super_admin` không thuộc cố định một đối tác nên app không có `partner_id` mặc định và đã chủ động chặn thao tác.

Hiện tại, app chỉ cần gửi `branch_id`. Backend tự lấy `partner_id` từ chi nhánh gốc được chọn. Nếu request vẫn gửi `partner_id: null`, API vẫn chấp nhận và bỏ qua giá trị này.

Tài khoản thường vẫn hoạt động như cũ: backend lấy `partner_id` từ tài khoản; nếu tài khoản chỉ quản lý một chi nhánh thì backend có thể tự chọn chi nhánh đó.

## Quy tắc chung

- Xác thực bằng `Authorization: Bearer <admin_token>`.
- Dùng middleware `auth:sanctum` và `admin.api`.
- Với `super_admin`, `branch_id` là bắt buộc khi tạo phiếu.
- `branch_id` phải là chi nhánh gốc có `category_type = product`, `parent_id = null` và đã thuộc một đối tác.
- Mọi `warehouse_item_id` trong phiếu phải thuộc đúng cả đối tác và chi nhánh đã chọn.
- Chi nhánh không hợp lệ hoặc chưa thuộc đối tác trả `422` tại trường `branch_id`.
- Vật tư không thuộc chi nhánh đã chọn trả `422` tại trường `items.*.warehouse_item_id`.

## Nhập kho

`POST /api/admin/warehouse/stock-ins`

```json
{
  "branch_id": 1,
  "note": "Nhập bổ sung",
  "items": [{
    "warehouse_item_id": 10,
    "quantity": 20,
    "unit_price": 85000,
    "note": null
  }]
}
```

Khi tạo thành công, tồn kho của từng vật tư được cộng tự động và API trả `201`.

## Xuất kho

`POST /api/admin/warehouse/stock-outs`

```json
{
  "branch_id": 1,
  "product_id": null,
  "employee_id": null,
  "issued_to": "Buồng phòng",
  "note": null,
  "items": [{
    "warehouse_item_id": 10,
    "reason": "housekeeping",
    "quantity": 2,
    "note": null
  }]
}
```

`reason` nhận một trong các giá trị: `housekeeping`, `guest_usage`, `damaged`, `other`. API trả `422` nếu số lượng xuất vượt tồn khả dụng.

## Kiểm kê kho

`POST /api/admin/warehouse/stock-checks`

```json
{
  "branch_id": 1,
  "checked_at": "2026-09-23 08:00:00",
  "note": null,
  "items": [{
    "warehouse_item_id": 10,
    "actual_quantity": 18,
    "note": "Đã đếm thực tế"
  }]
}
```

Không gửi `system_quantity`; backend tự lấy tồn hệ thống tại thời điểm kiểm kê và điều chỉnh tồn về `actual_quantity`.

## Yêu cầu phía app

API đã cho phép `super_admin` tạo phiếu, nhưng app phải:

1. Bỏ điều kiện chặn khi `is_super_admin = true` và không hiển thị hộp thoại “Tài khoản super admin chưa tạo được dữ liệu kho trên app”.
2. Bắt buộc người dùng chọn chi nhánh trước khi gọi API, sau đó gửi `branch_id` trong request.

Các API danh sách, chi tiết, cập nhật và xoá phiếu vẫn giữ nguyên endpoint và hành vi.
