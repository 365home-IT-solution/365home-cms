# MiniHouse — Cơ sở dữ liệu module

Mô tả toàn bộ bảng CSDL của module **MiniHouse** (quản lý cho thuê phòng/căn hộ theo tháng, panel
`/minihouse/admin`) — 33 bảng, tất cả đặt tiền tố `minihouse_`, sống độc lập hoàn toàn với dữ liệu
đặt phòng ngắn hạn của Home (không bảng nào của MiniHouse tham chiếu tới `products`/`orders`...).
Model tương ứng nằm ở `Modules\Minihouse\App\Models\*`; migration gốc ở
`Modules/Minihouse/Database/Migrations/`.

Muốn hiểu luồng nghiệp vụ tổng quan trước khi đọc bảng, xem
[MiniHouse — Báo cáo dự án](bao-cao-du-an/minihouse-bao-cao-du-an.md). Muốn xem API tương ứng, xem
[docs/api/minihouse-api.md](api/minihouse-api.md).

## Quy ước chung

- Khoá ngoại tới `users` (tài khoản quản trị Home) dùng **UUID**, không phải số nguyên tự tăng —
  MiniHouse dùng chung bảng `users` với Home, không tách tài khoản riêng.
- Hầu hết bảng có `SoftDeletes` (xoá mềm, cột `deleted_at`) — bảng log/lịch sử thuần (không ai sửa
  lại) thì không cần xoá mềm, ghi rõ ở từng mục bên dưới.
- MiniHouse **không có khái niệm "đối tác" (partner)** như Home — ranh giới phân quyền là **toà
  nhà** (`Building`)/**khu vực** (`Zone`), qua 2 bảng trung gian `minihouse_user_buildings` và
  `minihouse_user_zones` (mục 14).
- Bảng nào có nhiều cột thông tin cổng thanh toán (PayOS/MoMo/VNPay) được gom riêng thành mục con
  "Thông tin cổng thanh toán" cho gọn thay vì liệt kê phẳng.

---

## 1. Toà nhà & Khu vực

### `minihouse_zones`
Model: `Zone` — xoá mềm.

Lớp gộp TUỲ CHỌN đứng trên Toà nhà, dùng khi 1 tài khoản quản lý nhiều toà nhà theo khu vực (VD "Khu
Quận 3") — cấp quyền theo khu vực thì tự động bao gồm toà nhà thêm mới sau này trong khu đó, không
cần gán lại từng toà.

- `id`, `name`, `note`, `created_at`/`updated_at`, `deleted_at`

Quan hệ: `hasMany` Building. Không xoá được nếu còn toà nhà thuộc khu vực này.

### `minihouse_buildings`
Model: `Building` — xoá mềm. Bảng gốc của toàn bộ cây dữ liệu (phòng, phụ thu, panorama đều treo vào
đây), lưu hồ sơ chủ nhà, thông tin ngân hàng/QR, thông tin đăng nhập cổng thanh toán riêng của từng
toà (để tiền thuê chuyển thẳng vào tài khoản đúng chủ nhà đó), và cấu hình chu kỳ tính tiền/nhắc nhở.

- `id`, `zone_id` (FK → zones, huỷ liên kết khi xoá khu vực)
- `name`, `address`, `province`, `ward`, `image`, `note`
- `electric_unit_price`, `water_unit_price` — đơn giá điện/nước MẶC ĐỊNH của toà (hợp đồng có thể ghi
  đè riêng, xem `minihouse_contracts`)
- Hồ sơ chủ nhà: `owner_name`, `owner_phone`, `owner_id_card_number`, `owner_email`, `owner_address`
- VietQR tĩnh: `owner_bank_bin`, `owner_bank_name`, `owner_bank_account_number`,
  `owner_bank_account_holder`
- `payment_method` — `vietqr`|`payos`|`momo`|`vnpay`, chọn tường minh cổng nào đang dùng
- Chu kỳ tính tiền: `billing_cycle_type` (`calendar_month`|`anniversary_date`),
  `payment_reminder_days_before`, `payment_reminder_repeat_days`, `fixed_due_day`,
  `contract_expiry_reminder_days_before`

**Thông tin cổng thanh toán riêng của toà** (mỗi toà tự cấu hình tài khoản cổng của mình):
- PayOS: `payos_client_id`, `payos_api_key`, `payos_checksum_key`
- MoMo: `momo_partner_code`, `momo_access_key`, `momo_secret_key`
- VNPay: `vnpay_tmn_code`, `vnpay_hash_secret`
- `payment_sandbox` — bật/tắt môi trường thử nghiệm cho cả 3 cổng trên

Quan hệ: `belongsTo` Zone; `hasMany` Room, PanoramaScene, Surcharge. Không xoá được nếu còn phòng.

### `minihouse_user_zones` (bảng trung gian)
User ↔ Zone, không có cột phụ (khoá chính ghép `user_id`+`zone_id`). Cấp quyền quản lý **cả 1 khu
vực** (mọi toà nhà trong đó, kể cả toà thêm sau này) cho 1 tài khoản.

---

## 2. Phòng

### `minihouse_rooms`
Model: `Room` — xoá mềm. Đơn vị cho thuê thực tế — trung tâm mà khách thuê/hợp đồng/hoá đơn/tài
sản/cảnh 360° đều gắn vào.

- `id`, `building_id` (FK → buildings, xoá theo toà)
- `code`, `floor`, `position_row`/`position_col` — dùng vẽ sơ đồ mặt bằng
- `area`, `price` (giá thuê mặc định), `status` (`trong`|`dat_coc`|`dang_thue`|`bao_tri`)
- `photos` (json), `note`

Quan hệ: `belongsTo` Building; `hasMany` Tenant, Contract, PanoramaScene, RoomAsset; `belongsToMany`
Amenity (qua `minihouse_room_amenity`). Không xoá được nếu từng có hợp đồng (kể cả đã kết thúc).

### `minihouse_amenities`
Model: `Amenity`. Danh mục tiện ích dùng chung (máy lạnh, tủ lạnh...) để chọn cho phòng thay vì gõ
tay JSON tự do như trước.

- `id`, `name` (duy nhất), `image`

Quan hệ: `belongsToMany` Room (qua `minihouse_room_amenity`).

### `minihouse_room_amenity` (bảng trung gian)
Room ↔ Amenity, không có cột phụ. Thay thế cột JSON `amenities` cứng nhắc trước đây bằng quan hệ
nhiều-nhiều CRUD được ngay trên panel.

### `minihouse_asset_types`
Model: `AssetType`. Danh mục tên loại tài sản/nội thất (tủ lạnh, điều hoà, giường, tủ quần áo...) để
chọn từ dropdown, tránh nhân viên gõ tay tên không thống nhất giữa các phòng.

- `id`, `name` (duy nhất)

Không có khoá ngoại tới `RoomAsset` — chỉ là nguồn đặt tên, `RoomAsset.name` vẫn là chuỗi tự do.

### `minihouse_room_assets`
Model: `RoomAsset` — KHÔNG xoá mềm (dữ liệu vận hành, xoá thật). Theo dõi tài sản/nội thất vật lý đã
giao cho từng phòng và tình trạng hiện tại — dùng làm căn cứ khi bàn giao/thanh lý hợp đồng để biết
cần thay gì/đã hư gì (không tự động sinh phụ thu).

- `id`, `room_id` (FK → rooms, xoá theo phòng)
- `name`, `condition` (`tot`|`hu_hong`|`dang_sua`), `note`

Quan hệ: `belongsTo` Room.

---

## 3. Khách thuê

### `minihouse_tenants`
Model: `Tenant` — xoá mềm. Hồ sơ đầy đủ của khách thuê, bao gồm CẢ người đứng tên hợp đồng LẪN mọi
người ở cùng (từ đợt gộp `minihouse_contract_occupants` ngày 2026-09-05 — xem ghi chú cuối mục này).
Đồng thời là tài khoản đăng nhập Cổng khách thuê (OTP hoặc mật khẩu, guard riêng `tenant`).

- `id`, `fullname`, `phone`, `password` (đăng nhập cổng, hashed)
- CCCD: `id_card_number`, `id_card_front`, `id_card_back`
- `date_of_birth`, `gender` (`nam`|`nu`|`khac`), `nationality`, `document_type`
- `hometown`, `permanent_address`, `occupation`, `workplace`
- `emergency_contact_name`, `emergency_contact_phone`
- `residence_declared`, `residence_declared_at` — đã khai báo lưu trú chưa
- `room_id` (FK → rooms, nullable) — phòng đang ở HIỆN TẠI, `ContractObserver` tự đồng bộ
- `remember_token` (cổng "ghi nhớ đăng nhập")

Quan hệ: `belongsTo` Room; `hasMany` Contract (làm người đứng tên chính, qua `tenant_id`),
TenantPushToken; `belongsToMany` Contract qua `minihouse_contract_tenants` (mọi hợp đồng từng ở, dù
đứng tên chính hay chỉ là người ở cùng).

**Ghi chú gộp bảng:** `minihouse_contract_occupants` (tạo 2026-09-05, lưu người ở cùng với tên/CCCD/
quan hệ) đã được **gộp vào `minihouse_tenants`** cùng ngày — lý do: người ở cùng cần đầy đủ hồ sơ như
khách thuê thật (ảnh CCCD, ngày sinh, giới tính, thường trú) để tính đúng số đầu người khi chia tiền
điện/nước và khai báo lưu trú. Bảng cũ đã bị xoá hoàn toàn khỏi schema hiện tại (migration `down()`
chỉ tạo lại bảng rỗng để an toàn khi rollback, dữ liệu không khôi phục lại được).

### `minihouse_contract_tenants` (bảng trung gian, có Model riêng)
Model: `ContractTenant`. Contract ↔ Tenant — được "thăng cấp" thành Model thật (không phải pivot
thuần) vì mang dữ liệu ý nghĩa:

- `id`, `contract_id`, `tenant_id` (cả 2 FK, xoá theo hợp đồng/khách thuê), duy nhất theo cặp
- `role` (`primary`|`occupant`) — `primary` khớp với `Contract.tenant_id` (vẫn giữ để tương thích
  ngược, là nguồn xác thực "ai đứng tên" chính), `occupant` là người ở cùng
- `relationship_to_primary` — quan hệ với người đứng tên chính (vợ/chồng, con, bạn...)

### `minihouse_residence_declarations`
Model: `ResidenceDeclaration`. "Khai báo lưu trú" — theo ĐÚNG mẫu của Bộ Công an (dùng chung mẫu
Excel với `CccdDeclaration` bên Home). Chỉ lưu dữ liệu tham chiếu nội bộ + đánh dấu đã nộp bằng tay,
**KHÔNG** tự động nộp qua hệ thống ASM của công an. 1 dòng = 1 người/1 hợp đồng.

- `id`, `contract_id` (FK → contracts, xoá theo hợp đồng), `tenant_id` (FK → tenants, xoá theo khách)
- `full_name`, `date_of_birth`, `gender`, `cccd_number`, `nationality`, `document_type`, `phone_number`
- `checked_in_at`, `checked_out_at`, `room_number`, `stay_address`
- `reason_for_stay`, `custom_reason`
- `current_residence`, `residence_type`, `province`, `ward`, `address_detail`, `notes`
- `declared_at`, `declared_by` (FK → users) — ai bấm "đã khai báo", lúc nào
- `last_reminded_at` — lần nhắc gần nhất (tránh nhắc dồn dập)

Quan hệ: `belongsTo` Contract, Tenant, User (`declaredBy`).

---

## 4. Hợp đồng

### `minihouse_contracts`
Model: `Contract` — xoá mềm. Hợp đồng thuê — tài liệu trung tâm dẫn tới việc sinh hoá đơn, ghi thu
chi, khai báo lưu trú.

- `id`, `room_id` (FK → rooms), `tenant_id` (FK → tenants — người đứng tên CHÍNH)
- `start_date`, `end_date`, `monthly_price`, `deposit_amount`
- `deposit_refunded_amount`, `deposit_deduction_reason` — hoàn/trừ cọc lúc thanh lý
- `checkout_at`, `checkout_handover_file` — ngày trả phòng + biên bản bàn giao
- `status` (`active`|`expired`|`cancelled`)
- `transferred_to_contract_id`/`transferred_from_contract_id` — tự tham chiếu 1-1, liên kết khi
  KHÁCH CHUYỂN PHÒNG (hợp đồng cũ đóng, tạo hợp đồng mới, 2 bên trỏ lẫn nhau)
- `reason_for_stay`, `custom_reason` — mặc định điền sẵn cho khai báo lưu trú
- `electric_unit_price`, `water_unit_price` — GHI ĐÈ đơn giá của toà nhà cho riêng hợp đồng này
  (để trống thì lấy theo `Building`)
- `contract_content`, `contract_file`, `handover_file`, `deposit_receipt_file`

Quan hệ: `belongsTo` Room, Tenant (chính); `hasMany` Invoice, Transaction, ContractTenant, renewals
(ContractRenewal); `belongsToMany` Tenant (mọi người từng ở, qua contract_tenants), Surcharge (phụ
thu ĐỊNH KỲ đang áp dụng, qua contract_surcharges); tự tham chiếu (chuyển phòng).

### `minihouse_contract_renewals`
Model: `ContractRenewal`. Nhật ký gia hạn hợp đồng — CHỈ để xem lại lịch sử, mọi nơi khác trong hệ
thống vẫn đọc thẳng `Contract.end_date`/`monthly_price` (nguồn sống duy nhất), không đọc lại bảng này.

- `id`, `contract_id` (FK, xoá theo hợp đồng)
- `old_end_date`, `new_end_date`, `old_monthly_price`, `new_monthly_price`, `note`
- `created_by` (FK → users)

Quan hệ: `belongsTo` Contract, User.

### `minihouse_contract_surcharges` (bảng trung gian)
Contract ↔ Surcharge, không có cột phụ. Danh sách phụ thu ĐỊNH KỲ hợp đồng đang chịu (giữ chỗ, sân
phơi...) — KHÁC `invoice_items`: bảng này KHÔNG chụp giá, luôn đọc giá MỚI NHẤT từ danh mục
`minihouse_surcharges`, nên đổi giá phụ thu trong danh mục sẽ tự áp dụng cho hoá đơn tháng sau.

---

## 5. Hoá đơn

### `minihouse_invoices`
Model: `Invoice` — xoá mềm. Hoá đơn hàng tháng của 1 hợp đồng: tiền phòng (tính theo số ngày ở thực
tế), điện/nước (theo số công tơ), phụ thu, kèm tích hợp cổng thanh toán online.

- `id`, `contract_id` (FK → contracts)
- `month`, `period_start`, `period_end` — kỳ tính tiền thực tế (có thể lẻ ngày khi vào/ra giữa tháng)
- `room_price`
- `electric_start`, `electric_end`, `electric_unit_price`, `electric_amount`
- `water_start`, `water_end`, `water_unit_price`, `water_amount`
- `service_amount` — tổng các dòng `invoice_items`
- `total_amount`
- `amount_paid`, `paid_at` — **cột cache**, `InvoicePaymentObserver` tự đồng bộ lại từ
  `minihouse_invoice_payments` mỗi khi có thanh toán mới/được duyệt
- `status` (`unpaid`|`partial`|`paid`)

**Thông tin cổng thanh toán riêng của hoá đơn này** (mỗi hoá đơn 1 phiên giao dịch riêng):
- PayOS: `payos_order_code` (duy nhất), `payos_checkout_url`, `payos_qr_code`, `payos_expired_at`
- MoMo: `momo_order_id`, `momo_qr_code`, `momo_pay_url`, `momo_expired_at`
- VNPay: `vnpay_txn_ref`, `vnpay_payment_url`, `vnpay_expired_at`

Quan hệ: `belongsTo` Contract; `hasMany` InvoiceItem, InvoicePayment.

> **Ghi chú:** ràng buộc `unique(contract_id, month)` từng được thêm (2026-09-06) rồi BỎ lại
> (2026-09-10) vì unique index của MySQL không loại trừ dòng đã xoá mềm — chặn trùng hoá đơn giờ nằm
> hoàn toàn ở tầng ứng dụng (`InvoiceGenerationService`), không còn ràng buộc DB.

### `minihouse_invoice_items`
Model: `InvoiceItem` — KHÔNG xoá mềm. 1 dòng phụ thu ĐÃ áp vào 1 hoá đơn cụ thể — **CHỤP LẠI** tên/số
tiền tại thời điểm tạo hoá đơn (không đọc lại giá mới nhất của `Surcharge`), nên đổi giá phụ thu
trong danh mục về sau KHÔNG làm thay đổi hoá đơn cũ đã tạo.

- `id`, `invoice_id` (FK, xoá theo hoá đơn), `surcharge_id` (FK, nullable — chỉ để biết nguồn gốc)
- `name`, `amount`

Quan hệ: `belongsTo` Invoice, Surcharge.

### `minihouse_invoice_payments`
Model: `InvoicePayment` — KHÔNG xoá mềm. Từng lần thanh toán cho 1 hoá đơn. Thanh toán ghi tay
(tiền mặt/chuyển khoản) cần CHỦ TOÀ NHÀ duyệt mới tính là thật; thanh toán qua PayOS tự duyệt luôn
qua webhook.

- `id`, `invoice_id` (FK, xoá theo hoá đơn)
- `amount`, `paid_at`, `payment_method`, `note`
- `status` (`pending`|`approved`), `approved_at`, `approved_by` (FK → users)
- `created_by` (FK → users) — ai ghi nhận thanh toán này

Quan hệ: `belongsTo` Invoice, User (`createdBy`, `approvedBy`).

---

## 6. Thu chi

### `minihouse_transactions`
Model: `Transaction` — xoá mềm. Sổ thu/chi chung — tiền thuê thu được (thường tự sinh từ thanh toán
hoá đơn), chi phí vận hành, sửa chữa, hoàn cọc; có thể gắn vào 1 hợp đồng hoặc đứng độc lập ở cấp toà
nhà (VD sửa chữa chung của toà).

- `id`, `contract_id` (FK, nullable), `building_id` (FK, nullable — hồi cứu từ hợp đồng→phòng→toà
  cho dữ liệu cũ)
- `invoice_payment_id` (FK, duy nhất, nullable) — nối dòng "thu" tự sinh về đúng lần thanh toán gốc
- `type` (`thu`|`chi`), `category` (`sua_chua`|`van_hanh`|`hoan_coc`|`khac`, chỉ có ý nghĩa với `chi`)
- `amount`, `transaction_date`, `receipt_image`, `note`

Quan hệ: `belongsTo` Contract, Building, InvoicePayment.

---

## 7. Phụ thu

### `minihouse_surcharges`
Model: `Surcharge` — xoá mềm. Danh mục phụ thu định kỳ TÁI SỬ DỤNG được của 1 toà nhà (rác, giữ xe,
internet, phí quản lý...) — chọn khi tạo hoá đơn thay vì gõ tay số tiền mỗi tháng.

- `id`, `building_id` (FK, xoá theo toà)
- `name`, `amount`, `note`, `is_active`

Quan hệ: `belongsTo` Building. Dùng bởi `minihouse_contract_surcharges` (đang áp dụng định kỳ) và
`minihouse_invoice_items` (đã chụp vào hoá đơn cụ thể).

---

## 8. Nhắc việc

### `minihouse_reminders`
Model: `Reminder` — xoá mềm, dùng global scope RIÊNG (không dùng chung trait lọc-theo-toà-nhà chuẩn)
vì nhắc việc có thể KHÔNG gắn phòng/hợp đồng nào cả (ghi chú tự do) nên không được lọc nhầm mất.

- `id`, `title`, `content`, `remind_date`
- `type` (`thu_tien`|`het_han_hop_dong`|`bao_tri`|`khac`), `repeat_interval_days` — lặp lại định kỳ
- `room_id`, `contract_id`, `invoice_id` (đều FK nullable — `invoice_id` chỉ dùng cho nhắc thu tiền)
- `assigned_to` (FK → users) — giao việc cho nhân viên nào
- `is_done`, `notified_at`

Quan hệ: `belongsTo` Room, Contract, Invoice, User (`assignee`); `hasMany` ZaloNotification,
SmsNotification (lịch sử đã gửi nhắc qua Zalo/SMS cho việc này).

---

## 9. Phản hồi

### `minihouse_tenant_feedbacks`
Model: `TenantFeedback` — KHÔNG xoá mềm. Kênh đánh giá/phản hồi của khách thuê — gửi ẨN DANH qua mã
QR gắn với 1 phòng (không cần đăng nhập), HOẶC gửi từ phiên đăng nhập thật ở Cổng khách thuê (có
`tenant_id` để trả lời được thẳng vào thông báo trong cổng của người đó).

- `id`, `room_id` (FK, nullable), `tenant_id` (FK, nullable)
- `tenant_name`, `tenant_phone` (dùng khi gửi ẩn danh, không có `tenant_id`)
- `rating`, `content`
- `is_reviewed`, `staff_note`

Quan hệ: `belongsTo` Room, Tenant.

---

## 10. Thông báo

### `minihouse_announcements`
Model: `Announcement` — KHÔNG xoá mềm. Thông báo chủ toà nhà đăng cho khách thuê xem trong Cổng khách
thuê (VD cúp nước, bảo trì thang máy). `building_id = null` nghĩa là gửi cho TẤT CẢ khách thuê ở mọi
toà — nên cố tình KHÔNG bị lọc theo bộ lọc "toà nhà đang chọn" như các bảng khác.

- `id`, `building_id` (FK, nullable = gửi toàn hệ thống)
- `title`, `body`, `created_by` (FK → users)

Quan hệ: `belongsTo` Building, User. Tạo xong tự nhân bản ra `minihouse_portal_notifications` cho
từng khách thuê liên quan (qua observer).

### `minihouse_portal_notifications`
Model: `PortalNotification` — KHÔNG xoá mềm. Feed thông báo TRONG cổng khách thuê, 1 dòng/1 khách/1
thông báo. Nạp từ 4 nguồn: hoá đơn mới, nhắc việc tới hạn, thông báo chung, phản hồi được trả lời.

- `id`, `tenant_id` (FK, xoá theo khách thuê)
- `type` (`invoice_new`|`reminder`|`announcement`|`feedback_reply`)
- `title`, `body`, `link` (đường dẫn tương đối trong cổng), `read_at`

Quan hệ: `belongsTo` Tenant.

### `minihouse_tenant_push_tokens`
Model: `TenantPushToken` — KHÔNG xoá mềm. Token thiết bị (Web Push/FCM/Expo) để gửi push cho khách
thuê — TÁCH RIÊNG HOÀN TOÀN với bảng `fcm_tokens` của Home (ứng dụng khác, chủ sở hữu khác). 1 khách
thuê có thể đăng ký nhiều thiết bị.

- `id`, `tenant_id` (FK, xoá theo khách thuê)
- `token` (duy nhất — duy nhất theo THIẾT BỊ, không phải theo khách thuê+token)
- `platform` (`web`|`android`|`ios`)

Quan hệ: `belongsTo` Tenant.

---

## 11. Panorama 360°

### `minihouse_panorama_scenes`
Model: `PanoramaScene` — KHÔNG xoá mềm. 1 ảnh 360° (equirectangular) đại diện 1 điểm đứng thật trong
toà nhà (sảnh, hành lang, hoặc bên trong 1 phòng cụ thể) — là "đỉnh" (node) của đồ thị tour ảo.

- `id`, `building_id` (FK, xoá theo toà), `room_id` (FK, nullable — null = khu vực chung)
- `title`, `floor`, `image_path`, `thumbnail_path`
- `initial_yaw`, `initial_pitch` — góc nhìn ban đầu khi mở scene
- `sort_order`, `is_published`

Quan hệ: `belongsTo` Building, Room; `hasMany` PanoramaHotspot (điểm bấm chuyển cảnh ĐI RA từ scene
này).

### `minihouse_panorama_hotspots`
Model: `PanoramaHotspot` — KHÔNG xoá mềm. 1 điểm bấm trên 1 scene để nhảy sang scene khác — là "cạnh"
(edge) nối các đỉnh của đồ thị tour.

- `id`, `scene_id` (FK, xoá theo scene nguồn)
- `target_scene_id` (FK, nullable — xoá scene đích chỉ vô hiệu hoá điểm bấm này, không phá cả tour)
- `yaw`, `pitch` — vị trí góc trên ảnh 360°
- `label`, `sort_order`

Quan hệ: `belongsTo` PanoramaScene (2 lần: `scene` nguồn, `target` đích).

---

## 12. Tích hợp bên ngoài

### `minihouse_zalo_settings`
Model: `ZaloSetting`. Cấu hình Zalo OA/ZNS RIÊNG của MiniHouse — hoàn toàn tách biệt với Zalo OA của
Home (khác thương hiệu/kênh, tự làm mới token riêng). **Bảng đơn dòng (singleton, id=1)**, dùng qua
`ZaloSetting::current()`, không phải theo từng toà nhà.

- `id`, `app_id`, `app_secret` (mã hoá), `access_token`/`refresh_token` (mã hoá),
  `access_token_expires_at`
- 4 mẫu tin nhắn: `template_payment_reminder`, `template_contract_expiry`, `template_maintenance`,
  `template_otp`

### `minihouse_sms_settings`
Model: `SmsSetting`. Cấu hình SMS thương hiệu (eSMS.vn) RIÊNG của MiniHouse — cùng kiểu bảng đơn
dòng (singleton, id=1) như trên.

- `id`, `api_key` (mã hoá), `secret_key` (mã hoá), `brandname`

### `minihouse_zalo_notifications`
Model: `ZaloNotification` — KHÔNG xoá mềm. Lịch sử TỪNG lần gửi ZNS cho khách thuê (nhắc thu tiền,
sắp hết hạn hợp đồng, bảo trì, OTP) — độc lập với bảng `zns_notifications` của Home.

- `id`, `reminder_id` (FK, nullable)
- `phone_number`, `recipient_name`, `template_id`, `template_data` (json)
- `status` (`sent`|`failed`), `zalo_message_id`, `error_message`, `sent_at`

Quan hệ: `belongsTo` Reminder.

### `minihouse_sms_notifications`
Model: `SmsNotification` — KHÔNG xoá mềm. Y hệt `minihouse_zalo_notifications` nhưng cho kênh SMS.

- `id`, `reminder_id` (FK, nullable)
- `phone_number`, `recipient_name`, `content`
- `status` (`sent`|`failed`), `sms_id`, `error_message`, `sent_at`

Quan hệ: `belongsTo` Reminder.

> Thông tin cổng thanh toán (PayOS/MoMo/VNPay) KHÔNG có bảng riêng — nằm ngay trên
> `minihouse_buildings` (thông tin đăng nhập cổng của từng toà) và `minihouse_invoices` (trạng thái
> giao dịch của từng hoá đơn), xem mục 1 và 5.

---

## 13. Nhật ký

### `minihouse_activity_logs`
Model: `ActivityLog` — KHÔNG xoá mềm (bản thân là log, không sửa/xoá lại). Nhật ký thao tác toàn
module (ai tạo/sửa/xoá gì, lúc nào) — CỐ TÌNH không dùng chung bảng `AuditLog` của Home vì bảng đó
bắt buộc có `partner_id`, trong khi MiniHouse không có khái niệm đối tác (dùng toà nhà làm ranh giới
quyền thay thế). Model nào dùng trait `LogsMinihouseActivity` sẽ tự động ghi vào đây.

- `id`, `building_id` (FK, nullable), `user_id` (FK → users, nullable)
- `user_name` — CHỤP LẠI tên tài khoản tại thời điểm ghi log, để log vẫn đọc được dù tài khoản sau
  này bị đổi tên/xoá
- `action` (`created`|`updated`|`deleted`)
- `subject_type` (tên class đầy đủ), `subject_id`, `subject_label`
- `old_values`, `new_values` (json)

Quan hệ: `belongsTo` Building, User.

---

## 14. Phân quyền

### `minihouse_user_buildings` (bảng trung gian)
User ↔ Building, không có cột phụ (khoá chính ghép `user_id`+`building_id`). Cấp quyền quản lý MỘT
toà nhà cụ thể cho 1 tài khoản (qua `User::minihouseBuildings()`).

> **Lưu ý hành vi:** 1 tài khoản KHÔNG có dòng nào trong cả `minihouse_user_buildings` lẫn
> `minihouse_user_zones` nghĩa là **"chưa cấu hình giới hạn" → thấy TẤT CẢ toà nhà**, không phải
> "không thấy toà nào" — xem `User::rootBuildingIds()`. `minihouse_user_zones` mô tả ở mục 1.

---

## Tổng hợp bảng trung gian (không có Model riêng)

| Bảng | Nối | Cột phụ |
|---|---|---|
| `minihouse_room_amenity` | Room ↔ Amenity | không |
| `minihouse_contract_surcharges` | Contract ↔ Surcharge | không (KHÔNG chụp giá — luôn đọc giá mới nhất) |
| `minihouse_user_buildings` | User ↔ Building | không |
| `minihouse_user_zones` | User ↔ Zone | không |
| `minihouse_contract_tenants`* | Contract ↔ Tenant | `role`, `relationship_to_primary` |

\* `minihouse_contract_tenants` được thăng cấp thành Model thật (`ContractTenant`) vì mang dữ liệu ý
nghĩa, không phải pivot thuần — xem mục 3.

## Bảng đã "khai tử" (còn trong lịch sử migration, không còn trong schema hiện tại)

- **`minihouse_contract_occupants`** — tạo 2026-09-05, gộp vào `minihouse_tenants` +
  `minihouse_contract_tenants` NGAY TRONG NGÀY tạo. Xem ghi chú chi tiết ở mục 3.
