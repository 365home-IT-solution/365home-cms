# Đăng ký hợp tác & ký hợp đồng đối tác — Tài liệu API cho FE

Luồng đăng ký hợp tác công khai cho Homestay (MiniHouse có ghi chú riêng ở mục 10) và các API admin cho hợp đồng ký tay. Mỗi API có đủ tham số, response thành công, ý nghĩa từng field và các lỗi thường gặp. Trang web tham chiếu: `/dang-ky-hop-tac`.

**Luồng:** Đăng ký → Giấy tờ pháp lý → Thông tin → Ký hợp đồng → Chờ duyệt

_Cập nhật 23/10/2026. File này được sinh cùng bản Word và trang web từ một đặc tả chung._

## Mục lục

0. [Thay đổi ngày 23/10/2026 — FE cần cập nhật](#0-thay-doi)
1. [Quy ước chung](#1-quy-uoc)
2. [Luồng & các bước trên màn hình](#2-luong)
3. [Bước 0 — Đăng ký](#3-dang-ky)
4. [Trạng thái hồ sơ](#4-trang-thai)
5. [Bước 1 — Giấy tờ pháp lý](#5-giay-to)
6. [Bước 2 — Thông tin ký hợp đồng (đối chiếu)](#6-thong-tin)
7. [Bước 3 — Ký hợp đồng](#7-ky)
8. [Gửi duyệt, rút hồ sơ, xem lại hợp đồng](#8-gui-duyet)
9. [Tóm tắt API theo bước](#9-tom-tat)
10. [Ghi chú MiniHouse](#10-minihouse)
11. [API admin — hợp đồng ký tay](#11-admin)

---

<a id="0-thay-doi"></a>
## 0. Thay đổi ngày 23/10/2026 — FE cần cập nhật

| # | Thay đổi | Client cần làm |
|---|---|---|
| 1 | Bước Đăng ký **không còn hỏi** tên cơ sở, địa chỉ, tỉnh/thành, phường/xã, căn hộ/tầng, toà nhà, mã bưu điện, ghi chú. | Bỏ các ô này khỏi form. API **vẫn nhận** nếu client cũ còn gửi. |
| 2 | Thông tin ký hợp đồng (tên pháp lý, địa chỉ kinh doanh, MST, người đại diện, CCCD…) được **server tự điền từ giấy tờ đã nộp**. | Sau khi nộp giấy tờ, gọi lại API trạng thái và điền form Thông tin từ `data.partner`. |
| 3 | Thêm `data.contract_info_sources`: giá trị đọc từ giấy tờ cho từng ô Thông tin, kèm `matches`. | Hiện "Khớp/Khác <giấy tờ>: <giá trị>" dưới từng ô (mục 6). |
| 4 | Giấy phép kinh doanh có thêm ô `dkkd_business_name`; API quét tự đọc ô này. | Form dựng theo `legal-document-types` thì tự có. Ô đứng **đầu** danh sách: client đọc ô theo vị trí phải đổi sang đọc theo `key`. |
| 5 | Bước ký có thêm hình thức **ký tay**: `POST /api/partner-contracts/{signing_token}/confirm-manual`. | Màn ký có 2 lựa chọn: **Ký điện tử** (OTP / CKS như cũ) và **Ký tay** (mục 7). |
| 6 | `sign_methods` có trong `data.contract` của API trạng thái và trong `GET /api/partner-contracts/{token}`. | Dùng `sign_methods` để hiện/ẩn lựa chọn ký, không đặt cứng. |
| 7 | Luồng ký trước: sửa giấy tờ làm đổi thông tin in trong hợp đồng thì hợp đồng được **tạo lại**; `signing_token` cũ hết hiệu lực. | Sau khi sửa giấy tờ hoặc Thông tin, luôn lấy lại `data.contract.signing_token`. |
| 8 | API admin: `partner_manual_files`, `GET …/contract/partner-manual-files`, `POST …/contract/partner-manual-upload`. | App admin: xem, tải, tải lên hộ hợp đồng ký tay (mục 11). |

---

<a id="1-quy-uoc"></a>
## 1. Quy ước chung

- Gửi header `Accept: application/json` cho mọi request. Upload tệp dùng `multipart/form-data`. Ngày gửi lên dạng `YYYY-MM-DD`; thời điểm trả về dạng ISO 8601.
- **Mã hồ sơ** (`onboarding_token`, 48 ký tự) trả về khi đăng ký là chìa khoá của hồ sơ. Các API sau gọi theo dạng `/api/public/partner-onboarding/{token}/…`. Client **phải lưu lại** mã này (web lưu ở `localStorage`). Mất mã thì dùng API lấy lại hồ sơ (mục 3).
- **Mã ký** (`signing_token`) khác mã hồ sơ: chỉ có khi đang có hợp đồng chờ ký (`data.contract.signing_token`), dùng cho `/api/partner-contracts/{signing_token}/…`.
- Các API không cần đăng nhập, trừ API admin ở mục 11 (Bearer token).

### Định dạng lỗi

```json
// 422 — lỗi dữ liệu: hiện lỗi dưới đúng ô theo key
{ "message": "Số điện thoại không hợp lệ.", "errors": { "phone": ["Số điện thoại không hợp lệ."] } }

// 404 / 409 / 410 / 503 — chỉ có message
{ "message": "Hồ sơ pháp lý chưa đủ điều kiện ký." }
```

| HTTP | Ý nghĩa |
|---|---|
| 422 | Lỗi dữ liệu hoặc nghiệp vụ, có `errors` theo key |
| 404 | Sai mã hồ sơ / mã ký, mã ký hết hiệu lực, hoặc chưa có dữ liệu |
| 409 | Hồ sơ không ở trạng thái cho phép (API ký OTP) |
| 410 | OTP đang tắt — `code: "OTP_DISABLED"` |
| 429 | Gửi quá nhanh |
| 503 | Dịch vụ phụ chưa sẵn sàng (quét giấy tờ chưa cấu hình, không gửi được email) |

### Giới hạn tần suất

| Nhóm API | Giới hạn |
|---|---|
| Đăng ký, lấy lại hồ sơ, mua gói MiniHouse | 5 lần/phút và 20 lần/giờ mỗi IP |
| Các API theo mã hồ sơ | 60 lần/phút |
| Quét giấy tờ | 10 lần/phút |
| `/api/partner-contracts/*` | 30 lần/phút |
| Lấy lại hồ sơ (gửi email) | Tối đa 3 email/giờ cho mỗi SĐT (vượt thì vẫn trả 200 nhưng không gửi) |

---

<a id="2-luong"></a>
## 2. Luồng & các bước trên màn hình

Có 2 thứ tự ký, đọc ở `data.flow.sign_before_review`:

| Bước | `sign_before_review = true` (đang dùng) | `false` (luồng cũ) |
|---|---|---|
| 0 | Đăng ký | Đăng ký |
| 1 | Giấy tờ | Giấy tờ |
| 2 | Thông tin | Thông tin |
| 3 | **Ký hợp đồng** | Gửi duyệt |
| 4 | Chờ duyệt (tự gửi duyệt sau khi ký) | Ký hợp đồng (sau khi 365 HOME duyệt) |

Từ `data.stage`, mở đúng bước khi khách vào lại hồ sơ:

| `stage` | Ý nghĩa | Bước (ký trước) | Bước (luồng cũ) |
|---|---|---|---|
| `registered` | Mới đăng ký, chưa có giấy tờ | 1 | 1 |
| `documents_uploaded` | Có giấy tờ, chưa đủ thông tin / giấy tờ bắt buộc | `steps.documents_uploaded ? 2 : 1` | như bên trái |
| `ready_to_submit` | Đủ giấy tờ + thông tin | `steps.contract_signed ? 4 : 2` | 3 |
| `contract_sent` | Có hợp đồng chờ đối tác ký | 3 | 4 |
| `pending_review` | Đã gửi, chờ 365 HOME duyệt | 4 | 3 |
| `changes_requested` | 365 HOME yêu cầu bổ sung giấy tờ | 4 (nút "Nộp lại giấy tờ" về bước 1) | 3 |
| `approved` | Giấy tờ đã duyệt, đang chuẩn bị hợp đồng | 4 | 3 |
| `contract_signed` | Đối tác đã ký, chờ 365 HOME ký | 4 | 4 |
| `active` | Hợp đồng có hiệu lực, đã cấp tài khoản | 4 | 4 |
| `rejected` | Hồ sơ bị từ chối | 4 | 3 |

**Hồ sơ còn sửa được** (`editable`) khi `stage` là `registered`, `documents_uploaded`, `ready_to_submit` hoặc `changes_requested`.

### Quay lại bước trước

- Thanh bước bấm được vào **bước đã qua**. Bước Đăng ký (0) luôn xem lại được nhưng chỉ ở chế độ xem (loại hình, SĐT, email, tên hồ sơ, nút "Tiếp tục hồ sơ") — **không** gọi lại API đăng ký vì sẽ tạo hồ sơ mới. Bước Giấy tờ (1) và Thông tin (2) chỉ mở lại khi `editable`.
- Mỗi bước có nút "Quay lại": Giấy tờ → Đăng ký, Thông tin → Giấy tờ, Ký → Thông tin (chỉ khi ký trước và `editable`).
- Hồ sơ đã gửi duyệt (`pending_review`) muốn sửa thì phải rút hồ sơ (mục 8) rồi mới quay lại được.
- API không lưu bước hiện tại: client tự tính bước từ `stage`, nên quay lại không cần gọi API.

### Realtime

`data.realtime` trả kênh để nhận tín hiệu khi 365 HOME duyệt, yêu cầu bổ sung hoặc ký (Reverb/Pusher cho web, Socket.IO cho app). Tín hiệu không mang dữ liệu: nhận được thì gọi lại API trạng thái. Không kết nối được thì khoảng 20 giây hỏi lại một lần trong các giai đoạn đang chờ 365 HOME (`pending_review`, `approved`, `contract_signed`).

```json
{
  "key": "8f1c…",
  "reverb": { "channel": "partner-onboarding.8f1c…", "event": "status-changed" },
  "socket": { "subscribe": "subscribe:partner-onboarding", "unsubscribe": "unsubscribe:partner-onboarding", "event": "partner_onboarding.updated" }
}
```

---

<a id="3-dang-ky"></a>
## 3. Bước 0 — Đăng ký

### 3.1 Lấy Điều khoản dịch vụ hiện hành

```
GET /api/public/terms/{slug}
```

Hiển thị trước khi khách tick đồng ý. Gửi `id` vào `terms_version_id` khi đăng ký.

**Tham số**

| Tên | Vị trí | Kiểu | Bắt buộc | Mô tả |
|---|---|---|---|---|
| slug | path | string | Có | `homestay` \| `minihouse` |

**Response thành công — 200**

```json
{
  "data": {
    "id": 12, "type": "partner_homestay", "type_label": "Đối tác Homestay", "version": "1.3",
    "title": "Điều khoản dịch vụ đối tác Homestay", "content": "1. …\n2. …",
    "content_hash": "a91f…", "effective_at": "2026-09-01T00:00:00+07:00", "created_at": "2026-08-28T10:00:00+07:00",
    "required": true
  }
}
```

**Ý nghĩa các field**

| Field | Kiểu | Mô tả |
|---|---|---|
| required | bool | `true` = phải tick đồng ý mới đăng ký được; `false` = ẩn ô đồng ý |
| content | string | Nội dung thuần văn bản (giữ xuống dòng) |

**Lỗi thường gặp**

| HTTP | Field / mã | Thông báo / khi nào |
|---|---|---|
| 404 | — | Sai `slug` hoặc chưa có Điều khoản |

### 3.2 Tạo hồ sơ đăng ký

```
POST /api/public/partner-onboarding
Content-Type: application/json
```

**Tham số**

| Tên | Vị trí | Kiểu | Bắt buộc | Mô tả |
|---|---|---|---|---|
| partner_type | body | string | Có | `homestay` (MiniHouse dùng API mua gói, mục 10) |
| full_name | body | string ≤ 255 | Có | Họ tên người đăng ký. Tạm làm tên hồ sơ cho tới khi có Giấy phép kinh doanh |
| phone | body | string | Có | `0xxxxxxxxx` hoặc `+84xxxxxxxxx` (server lưu dạng `0…`) |
| email | body | email ≤ 255 | Có | Nhận link hồ sơ, OTP ký, tài khoản quản trị |
| accept_terms | body | bool | Khi Điều khoản `required` | Phải là `true` |
| terms_version_id | body | int | Không | Phiên bản Điều khoản khách đã xem. Gửi thì phải còn là bản hiệu lực |
| business_name, address, address_street, address_province_code, address_ward_code, address_unit, address_building, postal_code, note | body | — | Không | **Không cần gửi nữa.** Vẫn được nhận nếu client cũ còn gửi |

**Request mẫu**

```json
{
  "partner_type": "homestay",
  "full_name": "Trần Thị Bình",
  "phone": "0912345678",
  "email": "binh@example.com",
  "accept_terms": true,
  "terms_version_id": 12
}
```

**Response thành công — 201**

```json
{
  "message": "Đã tạo hồ sơ đăng ký hợp tác. Lưu lại mã hồ sơ để tiếp tục nộp giấy tờ và ký hợp đồng.",
  "data": {
    "onboarding_token": "Xy8u…(48 ký tự)",
    "terms_acceptance": { "id": 340, "version": "1.3", "accepted_at": "2026-10-23T08:30:00+07:00" },
    "stage": "registered",
    "…": "các field còn lại giống API trạng thái hồ sơ (mục 4)"
  }
}
```

**Ý nghĩa các field**

| Field | Kiểu | Mô tả |
|---|---|---|
| onboarding_token | string | Mã hồ sơ — **lưu lại**, chỉ trả một lần |
| terms_acceptance | object | Chỉ có khi Điều khoản bật: lịch sử đồng ý vừa ghi |

**Lỗi thường gặp**

| HTTP | Field / mã | Thông báo / khi nào |
|---|---|---|
| 422 | phone | "Số điện thoại không hợp lệ." / "Số điện thoại này đã đăng ký hợp tác. Vui lòng dùng mã hồ sơ đã nhận hoặc liên hệ 365 Home." |
| 422 | email | "Email này đã được dùng cho một tài khoản khác. Vui lòng dùng email khác." |
| 422 | partner_type | Gửi `minihouse` khi MiniHouse không dùng hợp đồng: dùng API mua gói |
| 422 | accept_terms | "Bạn cần đọc và đồng ý Điều khoản dịch vụ để đăng ký." |
| 422 | terms_version_id | Điều khoản vừa có bản mới: tải lại Điều khoản, bỏ tick, cho khách đồng ý lại |

**Lưu ý**

- Server gửi email xác nhận kèm link `…/dang-ky-hop-tac?ma=<mã hồ sơ>` để khách quay lại hồ sơ.

### 3.3 Lấy lại hồ sơ (mất mã hồ sơ)

```
POST /api/public/partner-onboarding/recover
Content-Type: application/json
```

**Tham số**

| Tên | Vị trí | Kiểu | Bắt buộc | Mô tả |
|---|---|---|---|---|
| phone | body | string | Có | SĐT đã đăng ký |
| email | body | email | Có | Email đã đăng ký (không phân biệt hoa thường) |

**Request mẫu**

```json
{ "phone": "0912345678", "email": "binh@example.com" }
```

**Response thành công — 200**

```json
{ "message": "Nếu thông tin khớp hồ sơ đã đăng ký, liên kết tiếp tục hồ sơ đã được gửi về email đó." }
```

**Lỗi thường gặp**

| HTTP | Field / mã | Thông báo / khi nào |
|---|---|---|
| 422 | phone / email | Sai định dạng |

**Lưu ý**

- Luôn trả 200 cùng một thông báo, kể cả khi không khớp (không lộ ai đã đăng ký).
- Khi khớp, server **cấp mã hồ sơ mới** và gửi link `…/dang-ky-hop-tac?ma=<mã mới>`. **Mã cũ hết hiệu lực** — client đang giữ mã cũ sẽ nhận 404.
- Hồ sơ đã có hiệu lực hoặc đã cấp tài khoản thì không gửi (đăng nhập trang quản trị).

---

<a id="4-trang-thai"></a>
## 4. Trạng thái hồ sơ

### 4.1 Lấy trạng thái hồ sơ

```
GET /api/public/partner-onboarding/{token}
```

API trung tâm của luồng: gọi khi mở hồ sơ, sau mỗi thao tác (nộp giấy tờ, lưu thông tin, ký…) và khi có tín hiệu realtime. Nhiều API khác (đăng ký, lưu thông tin, tạo hợp đồng, gửi duyệt, rút hồ sơ) trả về **đúng cấu trúc này** trong `data`.

**Tham số**

| Tên | Vị trí | Kiểu | Bắt buộc | Mô tả |
|---|---|---|---|---|
| token | path | string | Có | Mã hồ sơ |

**Response thành công — 200**

```json
{
  "data": {
    "stage": "ready_to_submit",
    "flow": { "sign_before_review": true },
    "realtime": {
      "key": "8f1c…",
      "reverb": { "channel": "partner-onboarding.8f1c…", "event": "status-changed" },
      "socket": { "subscribe": "subscribe:partner-onboarding", "unsubscribe": "unsubscribe:partner-onboarding", "event": "partner_onboarding.updated" }
    },
    "partner_type": "homestay",
    "partner": {
      "name": "CÔNG TY TNHH AN BÌNH",
      "phone": "0912345678",
      "partner_type": "homestay",
      "legal_name": "CÔNG TY TNHH AN BÌNH",
      "tax_code": "1800000047",
      "address": "252 Xuân Thủy, Phường An Bình, Thành phố Cần Thơ",
      "email": "binh@example.com",
      "representative_name": "NGUYỄN VĂN AN",
      "representative_position": null,
      "representative_id_number": "092088000001",
      "representative_id_issued_at": "2021-07-10",
      "representative_id_issued_place": "Cục Cảnh sát QLHC về TTXH",
      "representative_dob": "1990-01-01",
      "business_license_date": "2021-09-28",
      "business_license_issuer": "PHÒNG ĐĂNG KÝ KINH DOANH, SỞ TÀI CHÍNH, THÀNH PHỐ CẦN THƠ"
    },
    "steps": {
      "registered": true, "documents_uploaded": true, "contract_info_ready": true, "submitted": false,
      "documents_approved": false, "contract_sent": true, "contract_signed": false, "active": false
    },
    "missing_contract_fields": [],
    "contract_info_sources": {
      "legal_name": {
        "value": "CÔNG TY TNHH AN BÌNH", "document_id": 51, "document_type": "business_license",
        "document_label": "Giấy phép kinh doanh", "field": "dkkd_business_name", "matches": true
      },
      "address": {
        "value": "252 Xuân Thủy, Phường An Bình, Thành phố Cần Thơ", "document_id": 51, "document_type": "business_license",
        "document_label": "Giấy phép kinh doanh", "field": "dkkd_business_address", "matches": true
      }
    },
    "required_documents": [
      { "type": "business_license", "label": "Giấy phép kinh doanh", "uploaded": true },
      { "type": "citizen_id", "label": "Căn cước công dân (CCCD)", "uploaded": true }
    ],
    "documents": [ { "…": "xem cấu trúc một giấy tờ ở mục 5" } ],
    "contract": {
      "version_id": 9,
      "content_hash": "3b5d…",
      "signing_active": true,
      "signing_token": "kQ2…",
      "signing_url": "https://365home.vn/hop-dong/ky/kQ2…",
      "partner_confirmed_at": null,
      "partner_signed_by": null,
      "partner_sign_method": null,
      "platform_signed_at": null,
      "has_signed_pdf": false,
      "sign_methods": ["cks", "manual"]
    },
    "account": { "created": false, "login_url": null },
    "verification": { "status": "pending", "submitted_at": null, "verified_at": null, "note": null }
  }
}
```

**Ý nghĩa các field**

| Field | Kiểu | Mô tả |
|---|---|---|
| stage | string | Giai đoạn hồ sơ — bảng ở mục 2 |
| flow.sign_before_review | bool | `true` = đối tác ký hợp đồng trước rồi mới gửi duyệt (đang dùng); `false` = 365 HOME duyệt xong mới gửi hợp đồng |
| realtime | object \| null | Kênh nhận tín hiệu cập nhật (mục 2) |
| partner_type | string | `homestay` \| `minihouse` |
| partner | object | Thông tin hồ sơ / ký hợp đồng (đã được điền sẵn từ giấy tờ). Ngày dạng `YYYY-MM-DD` |
| steps.* | bool | `documents_uploaded` = đủ giấy tờ bắt buộc; `contract_info_ready` = đủ thông tin; `submitted`; `documents_approved`; `contract_sent` = đã có hợp đồng; `contract_signed` = đối tác đã ký; `active` |
| missing_contract_fields | string[] | Ô thông tin bắt buộc còn trống (key của `partner`) |
| contract_info_sources | object | **Mới.** Theo từng ô thông tin: giá trị đọc từ giấy tờ (mục 6). Chỉ gồm ô có giá trị trên giấy tờ |
| contract_info_sources.*.value | string | Giá trị trên giấy tờ (ngày dạng `YYYY-MM-DD`) |
| contract_info_sources.*.document_id / document_type / document_label | int / string / string | Giấy tờ đã dùng (bản mới nhất, bỏ bản bị từ chối) |
| contract_info_sources.*.field | string | Ô giấy tờ, vd `dkkd_business_address` |
| contract_info_sources.*.matches | bool | Giá trị đã lưu trong hồ sơ có khớp giấy tờ không (bỏ khoảng trắng thừa, không phân biệt hoa thường) |
| required_documents[] | array | `{ type, label, uploaded }` — giấy tờ bắt buộc và đã nộp chưa |
| documents[] | array | Giấy tờ đã nộp, mới nhất trước (cấu trúc ở mục 5) |
| contract | object \| null | `null` khi chưa có hợp đồng |
| contract.signing_active / signing_token / signing_url | bool / string \| null | Đang chờ đối tác ký thì có mã ký; đã ký hoặc chưa có thì `null` |
| contract.partner_confirmed_at / partner_signed_by | ISO 8601 \| null / string \| null | Thời điểm và người ký phía đối tác |
| contract.partner_sign_method | string \| null | **Mới.** `otp` \| `cks` \| `manual` \| `null` (chưa ký) |
| contract.platform_signed_at | ISO 8601 \| null | 365 HOME đã ký số lúc nào |
| contract.has_signed_pdf | bool | Có PDF đã ký số để tải (`GET …/contract/signed-pdf`) |
| contract.sign_methods | string[] | **Mới.** Cách ký đang mở khi chờ ký: `otp` (khi cấu hình cho phép), `cks`, `manual`. Không chờ ký thì `[]` |
| account.created / login_url | bool / string \| null | Đã cấp tài khoản quản trị chưa; link đăng nhập khi hợp đồng có hiệu lực |
| verification.status | string | `pending` \| `approved` \| `rejected` \| `suspended` |
| verification.submitted_at / verified_at | ISO 8601 \| null | Thời điểm gửi duyệt / được duyệt |
| verification.note | string \| null | Ghi chú của 365 HOME (vd lý do từ chối) |

**Lỗi thường gặp**

| HTTP | Field / mã | Thông báo / khi nào |
|---|---|---|
| 404 | — | Sai mã hồ sơ (hoặc mã đã bị thay khi dùng API lấy lại hồ sơ) |

---

<a id="5-giay-to"></a>
## 5. Bước 1 — Giấy tờ pháp lý

### 5.1 Danh mục giấy tờ & bộ ô nhập

```
GET /api/public/legal-document-types
```

Dựng form giấy tờ theo `forms`, **đừng đặt cứng danh sách ô**.

**Response thành công — 200**

```json
{
  "data": {
    "types": [
      { "type": "business_license", "label": "Giấy phép kinh doanh", "has_form": true },
      { "type": "citizen_id", "label": "Căn cước công dân (CCCD)", "has_form": true },
      { "type": "security_order", "label": "Giấy chứng nhận an ninh, trật tự", "has_form": true },
      { "type": "fire_safety", "label": "Hồ sơ phòng cháy chữa cháy", "has_form": true },
      { "type": "tax_registration", "label": "Giấy đăng ký thuế", "has_form": false },
      { "type": "accommodation_license", "label": "Giấy phép/công nhận cơ sở lưu trú", "has_form": false },
      { "type": "signing_authorization", "label": "Giấy ủy quyền người ký", "has_form": false },
      { "type": "property_ownership_or_use", "label": "Giấy tờ sở hữu hoặc quyền khai thác tòa nhà", "has_form": false },
      { "type": "other", "label": "Giấy tờ khác", "has_form": false }
    ],
    "forms": [
      {
        "type": "business_license", "label": "Giấy phép kinh doanh", "prefix": "dkkd",
        "fields": [
          { "key": "dkkd_business_name", "label": "Tên doanh nghiệp/hộ kinh doanh", "input": "text" },
          { "key": "dkkd_document_number", "label": "Mã số doanh nghiệp", "input": "text" },
          { "key": "dkkd_issued_at", "label": "Ngày cấp", "input": "date" },
          { "key": "dkkd_issuer", "label": "Nơi cấp/Cơ quan cấp", "input": "text" },
          { "key": "dkkd_business_address", "label": "Địa chỉ kinh doanh", "input": "text" },
          { "key": "dkkd_business_lines", "label": "Ngành, nghề kinh doanh", "input": "textarea" },
          { "key": "dkkd_legal_representative", "label": "Người đại diện theo pháp luật", "input": "text" },
          { "key": "dkkd_representative_id_number", "label": "Số định danh cá nhân", "input": "text" },
          { "key": "dkkd_phone", "label": "Số điện thoại", "input": "tel" }
        ]
      },
      { "type": "security_order", "prefix": "antt", "fields": [ "…" ] },
      { "type": "fire_safety", "prefix": "pccc", "fields": [ "…" ] },
      { "type": "citizen_id", "prefix": "cccd", "fields": [ "…" ] }
    ],
    "required": { "homestay": ["business_license", "citizen_id", "security_order"], "minihouse": ["business_license", "citizen_id"] },
    "fire_safety_stages": { "design_approved": "Thẩm duyệt: Chưa hoạt động", "accepted": "Đã nghiệm thu: Chuẩn bị hoạt động" }
  }
}
```

**Ý nghĩa các field**

| Field | Kiểu | Mô tả |
|---|---|---|
| types[].has_form | bool | `true` = có bộ ô riêng trong `forms`; `false` = dùng form chung (`name`, `document_number`, `issuer`, `issued_at`, `expires_at`) |
| forms[].fields[].input | string | `text` \| `date` \| `tel` \| `textarea` |
| required | object | Giấy tờ bắt buộc theo loại đối tác (theo cấu hình; giá trị ví dụ). Với hồ sơ cụ thể, dùng `required_documents` trong API trạng thái |

| Loại | Các ô (theo thứ tự) |
|---|---|
| `business_license` (dkkd) | `dkkd_business_name` (**mới**), `dkkd_document_number`, `dkkd_issued_at`, `dkkd_issuer`, `dkkd_business_address`, `dkkd_business_lines`, `dkkd_legal_representative`, `dkkd_representative_id_number`, `dkkd_phone` |
| `security_order` (antt) | `antt_document_number`, `antt_issued_at`, `antt_issuer`, `antt_business_name`, `antt_business_address`, `antt_responsible_person`, `antt_responsible_id_number` |
| `fire_safety` (pccc) | `pccc_document_number`, `pccc_issued_at`, `pccc_issuer`, `pccc_investor`, `pccc_representative`, `pccc_representative_title`, `pccc_site_address` |
| `citizen_id` (cccd) | `cccd_document_number`, `cccd_full_name`, `cccd_dob`, `cccd_gender`, `cccd_address`, `cccd_issued_at` (6 ô lấy từ mã QR, chỉ đọc), `cccd_issuer` (khách tự nhập) |

### 5.2 Quét giấy tờ (gợi ý giá trị, không lưu)

```
POST /api/public/partner-onboarding/{token}/documents/scan
Content-Type: multipart/form-data
```

Khách chọn tệp → gọi API này → điền `fields` vào form để khách kiểm tra → khách bấm nộp mới gọi API nộp giấy tờ.

**Tham số**

| Tên | Vị trí | Kiểu | Bắt buộc | Mô tả |
|---|---|---|---|---|
| token | path | string | Có | Mã hồ sơ |
| type | body | string | Có | `business_license` \| `security_order` \| `fire_safety` \| `citizen_id` |
| file | body | file | Có | PDF/JPG/JPEG/PNG/WEBP ≤ 10 MB. CCCD: ảnh **mặt trước** (chỉ ảnh) |
| file_back | body | file | CCCD | Ảnh **mặt sau** CCCD, JPG/PNG/WEBP ≤ 10 MB |

**Response thành công — 200**

```json
{
  "message": "Đã đọc được 8/9 ô. Vui lòng kiểm tra lại trước khi nộp.",
  "data": {
    "type": "business_license",
    "detected_type": "business_license",
    "type_matches": true,
    "fields": {
      "dkkd_business_name": "CÔNG TY TNHH AN BÌNH",
      "dkkd_document_number": "1800000047",
      "dkkd_issued_at": "2021-09-28",
      "dkkd_issuer": "PHÒNG ĐĂNG KÝ KINH DOANH, SỞ TÀI CHÍNH, THÀNH PHỐ CẦN THƠ",
      "dkkd_business_address": "252 Xuân Thủy, Phường An Bình, Thành phố Cần Thơ",
      "dkkd_business_lines": null,
      "dkkd_legal_representative": "NGUYỄN VĂN AN",
      "dkkd_representative_id_number": "092088000001",
      "dkkd_phone": "0900000188"
    },
    "found": 8,
    "total": 9,
    "fire_safety_stage": null,
    "warnings": ["Chưa đọc được: Ngành, nghề kinh doanh. Vui lòng kiểm tra giấy tờ và tự nhập các ô này (ô không có trên giấy thì để trống)."]
  }
}
```

**Ý nghĩa các field**

| Field | Kiểu | Mô tả |
|---|---|---|
| fields | object | key → giá trị gợi ý, `null` = không đọc được |
| detected_type / type_matches | string \| null / bool \| null | Loại giấy tờ máy nhận ra; `false` = tệp có vẻ không đúng loại đã chọn (có cảnh báo) |
| found / total | int | Số ô đọc được / tổng số ô |
| warnings[] | string[] | Cảnh báo để hiện cho khách |
| qr, qr_side | bool, string | Chỉ CCCD: đọc được mã QR chưa, ở mặt nào |
| fire_safety_stage | object \| null | Chỉ PCCC: tình trạng suy từ số văn bản |

**Lỗi thường gặp**

| HTTP | Field / mã | Thông báo / khi nào |
|---|---|---|
| 422 | type | Loại không quét được |
| 422 | file | Sai định dạng / quá dung lượng. CCCD: "Không đọc được mã QR trên CCCD (đã thử cả mặt trước và mặt sau)…" — khách phải chụp lại, không cho nhập tay |
| 422 | file_back | "CCCD phải có ảnh mặt sau." |
| 503 | — | "Chức năng quét giấy tờ chưa được cấu hình. Vui lòng tự nhập thông tin." — cho khách tự nhập |
| 429 | — | Quá 10 lần/phút |
| 404 | — | Sai mã hồ sơ (hoặc mã đã bị thay khi dùng API lấy lại hồ sơ) |

### 5.3 Nộp giấy tờ

```
POST /api/public/partner-onboarding/{token}/documents
Content-Type: multipart/form-data
```

**Tham số**

| Tên | Vị trí | Kiểu | Bắt buộc | Mô tả |
|---|---|---|---|---|
| token | path | string | Có | Mã hồ sơ |
| type | body | string | Có | Một loại trong `types` (mục trên) |
| file | body | file | Có | PDF/JPG/JPEG/PNG/WEBP ≤ 10 MB (CCCD: ảnh mặt trước) |
| file_back | body | file | CCCD | Ảnh mặt sau CCCD |
| dkkd_* / antt_* / pccc_* / cccd_* | body | string \| date | Không | Các ô riêng của đúng loại giấy tờ (danh sách ở `GET /api/public/legal-document-types`). Ô của loại khác gửi kèm bị bỏ qua. Định dạng: `*_issued_at` ngày ≤ hôm nay; `*_dob` ngày < hôm nay; `*_id_number`, `cccd_document_number` 9 hoặc 12 chữ số; `*_phone` `0xxxxxxxxx`; `*_address` ≤ 500; `dkkd_business_lines` ≤ 2000; còn lại ≤ 255 |
| name | body | string ≤ 255 | Không | Tên giấy tờ (loại không có bộ ô riêng) |
| document_number, issuer, issued_at | body | string \| date | Không | Dùng cho loại không có bộ ô riêng. `issued_at` ≤ hôm nay |
| expires_at | body | date | Không | Phải sau hôm nay và ≥ `issued_at` |

**Request mẫu**

```text
type=business_license
file=<dkkd.pdf>
dkkd_business_name=CÔNG TY TNHH AN BÌNH
dkkd_document_number=1800000047
dkkd_issued_at=2021-09-28
dkkd_business_address=252 Xuân Thủy, Phường An Bình, Thành phố Cần Thơ
```

**Response thành công — 201**

```json
{
  "message": "Đã nộp giấy tờ.",
  "data": {
    "id": 51,
    "type": "business_license",
    "type_label": "Giấy phép kinh doanh",
    "name": null,
    "document_number": "1800000047",
    "issuer": "PHÒNG ĐĂNG KÝ KINH DOANH, SỞ TÀI CHÍNH, THÀNH PHỐ CẦN THƠ",
    "issued_at": "2021-09-28",
    "expires_at": null,
    "fields": [
      { "key": "dkkd_business_name", "label": "Tên doanh nghiệp/hộ kinh doanh", "value": "CÔNG TY TNHH AN BÌNH" },
      { "key": "dkkd_document_number", "label": "Mã số doanh nghiệp", "value": "1800000047" },
      { "key": "dkkd_issued_at", "label": "Ngày cấp", "value": "2021-09-28" },
      { "key": "dkkd_issuer", "label": "Nơi cấp/Cơ quan cấp", "value": "PHÒNG ĐĂNG KÝ KINH DOANH, …" },
      { "key": "dkkd_business_address", "label": "Địa chỉ kinh doanh", "value": "252 Xuân Thủy, Phường An Bình, Thành phố Cần Thơ" },
      { "key": "dkkd_business_lines", "label": "Ngành, nghề kinh doanh", "value": null },
      { "key": "dkkd_legal_representative", "label": "Người đại diện theo pháp luật", "value": "NGUYỄN VĂN AN" },
      { "key": "dkkd_representative_id_number", "label": "Số định danh cá nhân", "value": "092088000001" },
      { "key": "dkkd_phone", "label": "Số điện thoại", "value": "0900000188" }
    ],
    "fire_safety_stage": null,
    "status": "draft",
    "status_label": "Bản nháp",
    "review_note": null,
    "file_name": "dkkd.pdf",
    "file_size": 102400,
    "needs_back_side": false,
    "file_back_name": null,
    "file_back_size": null
  }
}
```

**Ý nghĩa các field**

| Field | Kiểu | Mô tả |
|---|---|---|
| id | int | Mã giấy tờ (dùng cho sửa / xoá / tải) |
| type, type_label | string | Loại giấy tờ và tên hiển thị |
| document_number, issuer, issued_at | string \| date | Bản tóm tắt số / nơi cấp / ngày cấp, chép từ ô riêng của loại đó |
| expires_at | date \| null | Ngày hết hạn (nếu có) |
| fields[] | array | `{ key, label, value }` — đủ các ô riêng của loại theo thứ tự form (`[]` với loại không có bộ ô riêng) |
| fire_safety_stage | object \| null | Chỉ PCCC: `{ code: design_approved \| accepted, label }` suy từ số văn bản |
| status, status_label | string | `draft` Bản nháp · `pending_review` Chờ duyệt · `approved` Đã duyệt · `changes_requested` Cần bổ sung · `rejected` Từ chối |
| review_note | string \| null | Lý do 365 HOME yêu cầu bổ sung / từ chối |
| file_name, file_size | string, int | Tệp đã nộp (CCCD: mặt trước) |
| needs_back_side | bool | `true` với CCCD (phải có mặt sau) |
| file_back_name, file_back_size | string \| null, int \| null | CCCD: ảnh mặt sau |

**Lỗi thường gặp**

| HTTP | Field / mã | Thông báo / khi nào |
|---|---|---|
| 422 | <ô giấy tờ> | Sai định dạng ô, vd "Số định danh cá nhân phải gồm 9 hoặc 12 chữ số.", "Ngày cấp không được ở tương lai." |
| 422 | expires_at | "Giấy tờ đã hết hạn — vui lòng nộp giấy tờ còn hiệu lực." |
| 422 | file / file_back | Thiếu tệp, sai định dạng; CCCD không đọc được mã QR |
| 422 | type | MiniHouse (luồng hợp đồng): giấy tờ PCCC / ANTT / quyền khai thác bổ sung sau khi tạo toà nhà |
| 422 | status | Hồ sơ không còn sửa được: "Hồ sơ đã được duyệt. Vui lòng đăng nhập trang quản trị để cập nhật." / "Hồ sơ đã bị từ chối…" / đang chờ duyệt (phải rút hồ sơ trước) |
| 404 | — | Sai mã hồ sơ (hoặc mã đã bị thay khi dùng API lấy lại hồ sơ) |

**Lưu ý**

- CCCD: số, họ tên, ngày sinh, giới tính, thường trú, ngày cấp **luôn lấy từ mã QR** (giá trị client gửi bị bỏ qua).
- Sau khi nộp, server **tự điền thông tin hồ sơ** từ giấy tờ (bảng cuối mục này) — gọi lại API trạng thái để lấy `partner` và `contract_info_sources` mới.

### 5.4 Sửa giấy tờ tại chỗ

```
POST /api/public/partner-onboarding/{token}/documents/{document}
Content-Type: multipart/form-data
```

Sửa giấy tờ chưa duyệt mà không phải xoá rồi nộp lại. Gửi ô nào cập nhật ô đó; không đổi được `type`. Sửa xong giấy tờ về `draft`, xoá ghi chú duyệt cũ.

**Tham số**

| Tên | Vị trí | Kiểu | Bắt buộc | Mô tả |
|---|---|---|---|---|
| token, document | path | string, int | Có | Mã hồ sơ, id giấy tờ |
| dkkd_* / antt_* / pccc_* / cccd_* | body | string \| date | Không | Các ô riêng của đúng loại giấy tờ (danh sách ở `GET /api/public/legal-document-types`). Ô của loại khác gửi kèm bị bỏ qua. Định dạng: `*_issued_at` ngày ≤ hôm nay; `*_dob` ngày < hôm nay; `*_id_number`, `cccd_document_number` 9 hoặc 12 chữ số; `*_phone` `0xxxxxxxxx`; `*_address` ≤ 500; `dkkd_business_lines` ≤ 2000; còn lại ≤ 255 |
| name, document_number, issuer, issued_at, expires_at | body | string \| date | Không | Như API nộp |
| file | body | file | Không | Thay tệp (CCCD: chỉ ảnh). Không gửi = giữ tệp cũ |
| file_back | body | file | Không | Thay ảnh mặt sau CCCD |

**Response thành công — 200**

```json
{ "message": "Đã cập nhật giấy tờ.", "data": { "…": "cấu trúc một giấy tờ như API nộp" } }
```

**Lỗi thường gặp**

| HTTP | Field / mã | Thông báo / khi nào |
|---|---|---|
| 422 | document | "Chỉ sửa được giấy tờ nháp, cần bổ sung hoặc bị từ chối." |
| 422 | <ô giấy tờ> / file | Như API nộp |
| 422 | status | Hồ sơ không còn sửa được: "Hồ sơ đã được duyệt. Vui lòng đăng nhập trang quản trị để cập nhật." / "Hồ sơ đã bị từ chối…" / đang chờ duyệt (phải rút hồ sơ trước) |
| 404 | — | Sai mã hồ sơ hoặc giấy tờ không thuộc hồ sơ |

**Lưu ý**

- CCCD: đọc lại mã QR từ ảnh mới (mặt nào không gửi thì dùng ảnh đã lưu) và ghi đè các ô lấy từ QR.
- Luồng ký trước: nếu sửa làm đổi thông tin in trong hợp đồng thì hợp đồng được tạo lại — lấy lại `signing_token`.

### 5.5 Xoá giấy tờ

```
DELETE /api/public/partner-onboarding/{token}/documents/{document}
```

**Tham số**

| Tên | Vị trí | Kiểu | Bắt buộc | Mô tả |
|---|---|---|---|---|
| token, document | path | string, int | Có | Mã hồ sơ, id giấy tờ |

**Response thành công — 200**

```json
{ "message": "Đã xoá giấy tờ." }
```

**Lỗi thường gặp**

| HTTP | Field / mã | Thông báo / khi nào |
|---|---|---|
| 422 | document | "Chỉ xoá được giấy tờ nháp, cần bổ sung hoặc bị từ chối." |
| 422 | status | Hồ sơ không còn sửa được: "Hồ sơ đã được duyệt. Vui lòng đăng nhập trang quản trị để cập nhật." / "Hồ sơ đã bị từ chối…" / đang chờ duyệt (phải rút hồ sơ trước) |
| 404 | — | Sai mã hồ sơ / id giấy tờ |

### 5.6 Tải lại tệp giấy tờ đã nộp

```
GET /api/public/partner-onboarding/{token}/documents/{document}/download
```

**Tham số**

| Tên | Vị trí | Kiểu | Bắt buộc | Mô tả |
|---|---|---|---|---|
| token, document | path | string, int | Có | Mã hồ sơ, id giấy tờ |
| side | query | string | Không | `back` = ảnh mặt sau CCCD |

**Response thành công — 200**

```text
Tệp nhị phân (Content-Type theo tệp, Content-Disposition: attachment)
```

**Lỗi thường gặp**

| HTTP | Field / mã | Thông báo / khi nào |
|---|---|---|
| 404 | — | "Không tìm thấy tệp." (vd giấy tờ không có mặt sau) |

### Server tự điền thông tin hồ sơ từ giấy tờ

| Giấy tờ | Ô hồ sơ (`partner.*`) được ghi | Quy tắc |
|---|---|---|
| Giấy phép kinh doanh | `legal_name` (+ `name`), `address` (+ tự nhận tỉnh/thành), `tax_code` (khi mã số doanh nghiệp đúng định dạng MST), `business_license_date`, `business_license_issuer` | Ghi đè khi giấy tờ có giá trị |
| Giấy phép kinh doanh | `representative_name`, `representative_id_number` | Chỉ khi hồ sơ **chưa có CCCD** |
| CCCD (QR) | `representative_name`, `representative_id_number`, `representative_id_issued_at`, `representative_id_issued_place`, `representative_dob` | Ưu tiên hơn Giấy phép kinh doanh |
| GCN an ninh trật tự | `legal_name`, `address` | Chỉ bù khi hồ sơ còn trống |

Ô nào trên giấy tờ để trống thì giữ nguyên giá trị đang có.

---

<a id="6-thong-tin"></a>
## 6. Bước 2 — Thông tin ký hợp đồng (đối chiếu)

1. Điền form từ `data.partner` của API trạng thái (đã được điền sẵn từ giấy tờ).
2. Dưới mỗi ô, nếu `data.contract_info_sources[<ô>]` có giá trị thì hiện "Khớp <document_label>: <value>" (chữ xám), hoặc "Khác <document_label>: <value>" (viền/chữ vàng) kèm nút **"Dùng giá trị này"** để điền lại `value`.
3. Nên so khớp ngay trên client với giá trị khách đang gõ (bỏ khoảng trắng thừa, không phân biệt hoa thường). `matches` của server là kết quả so với giá trị **đã lưu**.
4. Ô không có trong `contract_info_sources` (`email`, `representative_position`) không lấy từ giấy tờ.

| Ô thông tin | Lấy từ ô giấy tờ (theo thứ tự ưu tiên) |
|---|---|
| `legal_name` | `dkkd_business_name` → `antt_business_name` |
| `tax_code` | `dkkd_document_number` |
| `address` | `dkkd_business_address` → `antt_business_address` |
| `representative_name` | `cccd_full_name` → `dkkd_legal_representative` |
| `representative_id_number` | `cccd_document_number` → `dkkd_representative_id_number` |
| `representative_id_issued_at` | `cccd_issued_at` |
| `representative_id_issued_place` | `cccd_issuer` |
| `representative_dob` | `cccd_dob` |
| `business_license_date` | `dkkd_issued_at` |
| `business_license_issuer` | `dkkd_issuer` |

### 6.1 Lưu thông tin ký hợp đồng

```
PUT /api/public/partner-onboarding/{token}/contract-info
Content-Type: application/json
```

**Tham số**

| Tên | Vị trí | Kiểu | Bắt buộc | Mô tả |
|---|---|---|---|---|
| token | path | string | Có | Mã hồ sơ |
| legal_name | body | string ≤ 255 | Có | Tên pháp lý (công ty / hộ kinh doanh) |
| tax_code | body | string | Không | MST: 10 số, 13 số (`0312345678-001`) hoặc 12 số (cá nhân / hộ kinh doanh dùng số CCCD) |
| address | body | string ≤ 500 | Có | Địa chỉ đăng ký kinh doanh |
| email | body | email ≤ 255 | Có | Nhận hợp đồng, OTP, tài khoản. Không được trùng tài khoản khác |
| representative_name | body | string ≤ 255 | Có | Người đại diện ký hợp đồng |
| representative_id_number | body | string | Có | Số CMND/CCCD: 9 hoặc 12 chữ số |
| representative_position | body | string ≤ 100 | Không | Chức vụ (mặc định khi in: Chủ cơ sở) |
| representative_id_issued_at | body | date | Có | Ngày cấp CMND/CCCD, ≤ hôm nay |
| representative_id_issued_place | body | string ≤ 255 | Không | Nơi cấp (mặc định khi in: Cục Cảnh sát QLHC về TTXH) |
| representative_dob | body | date | Không | Ngày sinh — người đại diện phải đủ 18 tuổi |
| business_license_date | body | date | Không | Ngày cấp giấy phép kinh doanh, ≤ hôm nay |
| business_license_issuer | body | string ≤ 255 | Không | Nơi cấp giấy phép kinh doanh |

**Request mẫu**

```json
{
  "legal_name": "CÔNG TY TNHH AN BÌNH",
  "tax_code": "1800000047",
  "address": "252 Xuân Thủy, Phường An Bình, Thành phố Cần Thơ",
  "email": "binh@example.com",
  "representative_name": "NGUYỄN VĂN AN",
  "representative_id_number": "092088000001",
  "representative_position": "Giám đốc",
  "representative_id_issued_at": "2021-07-10",
  "representative_id_issued_place": "Cục Cảnh sát QLHC về TTXH",
  "representative_dob": "1990-01-01",
  "business_license_date": "2021-09-28",
  "business_license_issuer": "PHÒNG ĐĂNG KÝ KINH DOANH, SỞ TÀI CHÍNH, THÀNH PHỐ CẦN THƠ"
}
```

**Response thành công — 200**

```json
{ "message": "Đã lưu thông tin ký hợp đồng.", "data": { "…": "trạng thái hồ sơ (mục 4)" } }
```

**Lỗi thường gặp**

| HTTP | Field / mã | Thông báo / khi nào |
|---|---|---|
| 422 | representative_id_number | "Số CMND/CCCD phải gồm 9 hoặc 12 chữ số." |
| 422 | tax_code | "Mã số thuế gồm 10 số (doanh nghiệp), 13 số (đơn vị phụ thuộc, vd 0312345678-001) hoặc 12 số (cá nhân / hộ kinh doanh dùng số CCCD)." |
| 422 | representative_dob | "Người đại diện phải đủ 18 tuổi." |
| 422 | representative_id_issued_at | "Vui lòng nhập ngày cấp CMND/CCCD." / "Ngày cấp CMND/CCCD không được ở tương lai." |
| 422 | email | Email đã thuộc tài khoản khác |
| 422 | status | Hồ sơ không còn sửa được: "Hồ sơ đã được duyệt. Vui lòng đăng nhập trang quản trị để cập nhật." / "Hồ sơ đã bị từ chối…" / đang chờ duyệt (phải rút hồ sơ trước) |
| 404 | — | Sai mã hồ sơ (hoặc mã đã bị thay khi dùng API lấy lại hồ sơ) |

**Lưu ý**

- Luồng ký trước: lưu xong gọi tiếp "Tạo hợp đồng để ký" (mục 7). Nếu đã có hợp đồng mà thông tin in trong đó đổi, server tạo bản mới — khách phải ký lại.
- Luồng cũ: chuyển sang bước Gửi duyệt.

---

<a id="7-ky"></a>
## 7. Bước 3 — Ký hợp đồng

### 7.1 Tạo hợp đồng để ký (luồng ký trước)

```
POST /api/public/partner-onboarding/{token}/contract
```

Tạo hợp đồng điều khoản chuẩn để đối tác ký ngay. Đã có bản đang chờ ký hoặc đã ký thì giữ nguyên.

**Tham số**

| Tên | Vị trí | Kiểu | Bắt buộc | Mô tả |
|---|---|---|---|---|
| token | path | string | Có | Mã hồ sơ |

**Response thành công — 200**

```json
{ "message": "Hợp đồng đã sẵn sàng để ký.", "data": { "…": "trạng thái hồ sơ; data.contract.signing_token + data.contract.sign_methods" } }
```

**Lỗi thường gặp**

| HTTP | Field / mã | Thông báo / khi nào |
|---|---|---|
| 422 | contract | "Hợp đồng sẽ được gửi sau khi 365 Home duyệt hồ sơ." (hồ sơ dùng luồng cũ) |
| 422 | documents | "Phải nộp đủ giấy tờ bắt buộc (có tệp). Còn thiếu: …" |
| 422 | contract_info | "Thiếu thông tin ký hợp đồng: …" |
| 422 | status | Hồ sơ không còn sửa được: "Hồ sơ đã được duyệt. Vui lòng đăng nhập trang quản trị để cập nhật." / "Hồ sơ đã bị từ chối…" / đang chờ duyệt (phải rút hồ sơ trước) |

### 7.2 Xem hợp đồng cần ký

```
GET /api/partner-contracts/{signing_token}
```

**Tham số**

| Tên | Vị trí | Kiểu | Bắt buộc | Mô tả |
|---|---|---|---|---|
| signing_token | path | string | Có | `data.contract.signing_token` của API trạng thái |

**Response thành công — 200**

```json
{
  "data": {
    "version_id": 9,
    "partner_name": "CÔNG TY TNHH AN BÌNH",
    "content": "<div class=\"hd-wrap\">… toàn văn hợp đồng …</div>",
    "content_body": "<p>… thân hợp đồng …</p>",
    "content_hash": "3b5d…",
    "can_confirm": true,
    "partner_confirmed_at": null,
    "platform_signed_at": null,
    "can_sign_cks": true,
    "can_sign_manual": true,
    "sign_methods": ["cks", "manual"],
    "partner_sign_method": null,
    "partner_certificate": null
  }
}
```

**Ý nghĩa các field**

| Field | Kiểu | Mô tả |
|---|---|---|
| content | string (HTML) | Toàn văn hợp đồng như bản in (quốc hiệu, thân, khung ĐẠI DIỆN BÊN A/B) — hiển thị trong khung cuộn |
| can_confirm / can_sign_cks / can_sign_manual | bool | Còn ký được bằng OTP / CKS / ký tay không (`false` khi đã ký hoặc hồ sơ chưa đủ điều kiện) |
| sign_methods | string[] | **Mới.** Cách ký đang mở: `otp` (khi cấu hình cho phép), `cks`, `manual` |
| partner_sign_method | string \| null | `otp` \| `cks` \| `manual` sau khi ký |
| partner_certificate | object \| null | Khi ký CKS: `{ subject, organization, issuer, valid_to }` |

**Lỗi thường gặp**

| HTTP | Field / mã | Thông báo / khi nào |
|---|---|---|
| 404 | — | Sai mã ký, hoặc mã ký đã hết hiệu lực vì có phiên bản hợp đồng mới hơn |

### Chọn hình thức ký

| Lựa chọn | Hiện khi | Bên trong |
|---|---|---|
| **Ký hợp đồng điện tử** | `sign_methods` có `otp` hoặc `cks` | Giữ luồng cũ: chọn tiếp *OTP email* (nếu có `otp`) hoặc *Chữ ký số (CKS)* |
| **Ký tay hợp đồng giấy** (mới) | `sign_methods` có `manual` | Tải PDF → in, ký → tải bản đã ký lên |

### 7.3 Tải PDF hợp đồng để ký (CKS và ký tay)

```
GET /api/partner-contracts/{signing_token}/pdf
```

**Tham số**

| Tên | Vị trí | Kiểu | Bắt buộc | Mô tả |
|---|---|---|---|---|
| signing_token | path | string | Có | Mã ký |

**Response thành công — 200**

```text
Tệp PDF (application/pdf), tên dạng "hop-dong-{version}-doi-tac-can-ky.pdf". Mọi lần tải đều trả cùng một tệp.
```

**Lỗi thường gặp**

| HTTP | Field / mã | Thông báo / khi nào |
|---|---|---|
| 422 | contract | "Hồ sơ pháp lý của đối tác chưa được phê duyệt hoặc cần xác minh lại." / "Hợp đồng này đã được đối tác xác nhận trước đó." / "Phiên bản hợp đồng này đã được thay bằng phiên bản mới hơn." |
| 404 | — | Sai mã ký, hoặc mã ký đã hết hiệu lực vì có phiên bản hợp đồng mới hơn |

### 7.4 Ký điện tử — gửi mã OTP

```
POST /api/partner-contracts/{signing_token}/otp
```

**Tham số**

| Tên | Vị trí | Kiểu | Bắt buộc | Mô tả |
|---|---|---|---|---|
| signing_token | path | string | Có | Mã ký |

**Response thành công — 200**

```json
{ "message": "Đã gửi OTP.", "data": { "email": "bi***@example.com" } }
```

**Lỗi thường gặp**

| HTTP | Field / mã | Thông báo / khi nào |
|---|---|---|
| 410 | code: OTP_DISABLED | OTP đang tắt — chỉ ký CKS hoặc ký tay |
| 409 | — | "Hồ sơ pháp lý chưa đủ điều kiện ký." / "Hợp đồng đã được đối tác xác nhận." |
| 429 | — | "Vui lòng chờ trước khi yêu cầu mã mới." (chờ 60 giây) |
| 422 | — | "Đối tác chưa có email nhận OTP." |
| 503 | — | Không gửi được email |
| 404 | — | Sai mã ký, hoặc mã ký đã hết hiệu lực vì có phiên bản hợp đồng mới hơn |

### 7.5 Ký điện tử — xác nhận OTP

```
POST /api/partner-contracts/{signing_token}/confirm
Content-Type: application/json
```

**Tham số**

| Tên | Vị trí | Kiểu | Bắt buộc | Mô tả |
|---|---|---|---|---|
| signing_token | path | string | Có | Mã ký |
| otp | body | string | Có | Đúng 6 ký tự |
| signer_name | body | string ≤ 255 | Có | Họ tên người ký (web điền sẵn `partner.representative_name`) |
| agree | body | bool | Có | Phải là `true` |

**Request mẫu**

```json
{ "otp": "123456", "signer_name": "Nguyễn Văn An", "agree": true }
```

**Response thành công — 200**

```json
{
  "message": "Đã xác nhận hợp đồng.",
  "data": { "partner_confirmed_at": "2026-10-23T09:12:00+07:00", "is_fully_signed": false, "contract_status": "draft" }
}
```

**Ý nghĩa các field**

| Field | Kiểu | Mô tả |
|---|---|---|
| is_fully_signed | bool | `true` khi 365 HOME cũng đã ký → hợp đồng có hiệu lực |
| contract_status | string | `active` khi có hiệu lực |

**Lỗi thường gặp**

| HTTP | Field / mã | Thông báo / khi nào |
|---|---|---|
| 422 | — (chỉ message) | "OTP không đúng hoặc đã hết hạn." — **không có `errors`**, hiện ở thông báo chung |
| 422 | otp / signer_name / agree | Thiếu / sai định dạng |
| 410 | code: OTP_DISABLED | OTP đang tắt |
| 409 | — | Hồ sơ chưa đủ điều kiện ký / hợp đồng đã ký |
| 404 | — | Sai mã ký, hoặc mã ký đã hết hiệu lực vì có phiên bản hợp đồng mới hơn |

### 7.6 Ký điện tử — chữ ký số (CKS)

```
POST /api/partner-contracts/{signing_token}/confirm-cks
Content-Type: multipart/form-data
```

Khách tải PDF (API trên), ký bằng phần mềm chữ ký số (đặt chữ ký vào ô ĐẠI DIỆN BÊN B, không sửa nội dung), rồi tải file đã ký lên.

**Tham số**

| Tên | Vị trí | Kiểu | Bắt buộc | Mô tả |
|---|---|---|---|---|
| signing_token | path | string | Có | Mã ký |
| signed_pdf | body | file | Có | PDF đã ký số ≤ 20 MB — đúng file tải từ hệ thống |
| signer_name | body | string ≤ 255 | Không | Để trống = lấy theo chứng thư |
| agree | body | bool | Có | `1` |

**Response thành công — 200**

```json
{
  "message": "Đã kiểm tra chữ ký số và ghi nhận hợp đồng.",
  "data": {
    "partner_confirmed_at": "2026-10-23T09:12:00+07:00",
    "partner_sign_method": "cks",
    "partner_certificate": { "subject": "CÔNG TY TNHH AN BÌNH", "organization": "…", "issuer": "VNPT-CA", "tax_code": "1800000047", "valid_to": "2027-05-01" }
  }
}
```

**Lỗi thường gặp**

| HTTP | Field / mã | Thông báo / khi nào |
|---|---|---|
| 422 | signed_pdf | Chưa tải PDF để ký; chữ ký / nội dung không hợp lệ; chứng thư tự ký; "Chứng thư số không khớp hồ sơ đối tác (mã số thuế hoặc CCCD người đại diện)…"; nền tảng đã ký sau khi khách tải file (tải lại file mới) |
| 422 | contract | Hồ sơ chưa đủ điều kiện ký / đã ký / có phiên bản mới hơn |
| 404 | — | Sai mã ký, hoặc mã ký đã hết hiệu lực vì có phiên bản hợp đồng mới hơn |

### 7.7 Ký tay — tải lên hợp đồng đã ký (mới)

```
POST /api/partner-contracts/{signing_token}/confirm-manual
Content-Type: multipart/form-data
```

Khách tải PDF (API "Tải PDF hợp đồng để ký"), in ra, ký và ghi rõ họ tên vào ô **ĐẠI DIỆN BÊN B** ở trang cuối (đóng dấu nếu có), rồi scan thành 1 PDF hoặc chụp **đủ tất cả các trang** và tải lên.

**Tham số**

| Tên | Vị trí | Kiểu | Bắt buộc | Mô tả |
|---|---|---|---|---|
| signing_token | path | string | Có | Mã ký |
| signed_files[] | body | file[] | Có | 1–10 tệp; PDF/JPG/JPEG/PNG/WEBP; mỗi tệp ≤ 20 MB. Gửi nhiều tệp bằng nhiều phần `signed_files[]` |
| signer_name | body | string ≤ 255 | Có | Họ tên người ký (web điền sẵn `partner.representative_name`) |
| agree | body | bool | Có | `1` — checkbox "Tôi đã đọc, đồng ý với toàn bộ nội dung hợp đồng và đã ký tay vào bản hợp đồng tải lên" |

**Request mẫu**

```text
signed_files[]=<trang-1.jpg>
signed_files[]=<trang-2.jpg>
signer_name=Nguyễn Văn An
agree=1
```

**Response thành công — 200**

```json
{
  "message": "Đã nhận hợp đồng ký tay.",
  "data": {
    "partner_confirmed_at": "2026-10-23T09:12:00+07:00",
    "partner_sign_method": "manual",
    "is_fully_signed": false,
    "contract_status": "draft"
  }
}
```

**Ý nghĩa các field**

| Field | Kiểu | Mô tả |
|---|---|---|
| partner_sign_method | string | Luôn `manual` |
| is_fully_signed / contract_status | bool / string | Như API xác nhận OTP |

**Lỗi thường gặp**

| HTTP | Field / mã | Thông báo / khi nào |
|---|---|---|
| 422 | signed_files | "Vui lòng tải lên hợp đồng đã ký." / "Tối đa 10 tệp." |
| 422 | signed_files.N | "Chỉ nhận PDF hoặc ảnh JPG, PNG, WEBP." / "Mỗi tệp tối đa 20 MB." (N = vị trí tệp, từ 0) |
| 422 | signer_name / agree | Thiếu |
| 422 | contract | "Hợp đồng này đã được đối tác xác nhận trước đó." / "Phiên bản hợp đồng này đã được thay bằng phiên bản mới hơn." / hồ sơ chưa đủ điều kiện ký |
| 404 | — | Sai mã ký, hoặc mã ký đã hết hiệu lực vì có phiên bản hợp đồng mới hơn |

**Lưu ý**

- Ký tay không được máy kiểm tra như CKS: 365 HOME đối chiếu bản scan khi duyệt và vẫn ký số trên bản PDF do hệ thống tạo. Trong văn bản hợp đồng, ô Bên B hiện "ĐÃ KÝ TAY (BẢN SCAN ĐÃ NỘP) BỞI…".

> Sau khi ký bằng bất kỳ cách nào, gọi lại API trạng thái. Luồng ký trước: hồ sơ **tự gửi duyệt** (`stage = pending_review`). Luồng cũ: `stage = contract_signed`, chờ 365 HOME ký.

---

<a id="8-gui-duyet"></a>
## 8. Gửi duyệt, rút hồ sơ, xem lại hợp đồng

### 8.1 Gửi hồ sơ chờ duyệt

```
POST /api/public/partner-onboarding/{token}/submit
```

Luồng cũ: khách bấm gửi. Luồng ký trước: server tự gửi sau khi ký (vẫn gọi được để gửi lại sau khi rút hồ sơ).

**Tham số**

| Tên | Vị trí | Kiểu | Bắt buộc | Mô tả |
|---|---|---|---|---|
| token | path | string | Có | Mã hồ sơ |

**Response thành công — 200**

```json
{
  "message": "Đã gửi hồ sơ cho 365 Home. Sau khi giấy tờ được duyệt, hợp đồng sẽ được gửi về email của bạn để ký.",
  "data": { "stage": "pending_review", "…": "trạng thái hồ sơ (mục 4)" }
}
```

**Lỗi thường gặp**

| HTTP | Field / mã | Thông báo / khi nào |
|---|---|---|
| 422 | status | "Hồ sơ không ở trạng thái chờ hoàn thiện." |
| 422 | documents | "Phải nộp đủ giấy tờ bắt buộc (có tệp). Còn thiếu: …" |
| 422 | contract_info | "Thiếu thông tin ký hợp đồng: …" |
| 422 | contract | "Vui lòng ký hợp đồng trước khi gửi hồ sơ cho 365 Home duyệt." (luồng ký trước) |
| 404 | — | Sai mã hồ sơ (hoặc mã đã bị thay khi dùng API lấy lại hồ sơ) |

### 8.2 Rút hồ sơ để chỉnh sửa

```
POST /api/public/partner-onboarding/{token}/withdraw
```

Đưa hồ sơ đang chờ duyệt về bản nháp. Chữ ký giữ nguyên nếu sau đó không đổi thông tin in trong hợp đồng.

**Tham số**

| Tên | Vị trí | Kiểu | Bắt buộc | Mô tả |
|---|---|---|---|---|
| token | path | string | Có | Mã hồ sơ |

**Response thành công — 200**

```json
{ "message": "Đã rút hồ sơ về bản nháp. Bạn có thể chỉnh sửa rồi gửi duyệt lại.", "data": { "stage": "ready_to_submit", "…": "trạng thái hồ sơ" } }
```

**Lỗi thường gặp**

| HTTP | Field / mã | Thông báo / khi nào |
|---|---|---|
| 422 | status | "Hồ sơ không ở trạng thái chờ duyệt nên không thể rút lại." / "Hồ sơ đã được duyệt giấy tờ và gửi hợp đồng, không thể rút lại." |
| 404 | — | Sai mã hồ sơ (hoặc mã đã bị thay khi dùng API lấy lại hồ sơ) |

### 8.3 Xem lại hợp đồng (kể cả sau khi ký)

```
GET /api/public/partner-onboarding/{token}/contract
```

Sau khi ký, `signing_token` trong trạng thái là `null`; dùng API này để khách xem lại đúng bản hợp đồng mới nhất.

**Tham số**

| Tên | Vị trí | Kiểu | Bắt buộc | Mô tả |
|---|---|---|---|---|
| token | path | string | Có | Mã hồ sơ |

**Response thành công — 200**

```json
{
  "data": {
    "version_id": 9,
    "contract_code": "HD-365-0009",
    "partner_name": "CÔNG TY TNHH AN BÌNH",
    "content": "<div class=\"hd-wrap\">… toàn văn hợp đồng (HTML) …</div>",
    "content_body": "<p>… thân hợp đồng …</p>",
    "content_hash": "3b5d…",
    "signing_active": false,
    "signing_token": null,
    "partner_confirmed_at": "2026-10-23T09:12:00+07:00",
    "partner_signed_by": "Nguyễn Văn An",
    "sign_methods": [],
    "partner_manual_files": 2,
    "partner_sign_method": "manual",
    "partner_certificate": null,
    "platform_signed_at": null,
    "is_fully_signed": false,
    "contract_status": "draft",
    "contract_expires_at": null,
    "has_signed_pdf": false
  }
}
```

**Ý nghĩa các field**

| Field | Kiểu | Mô tả |
|---|---|---|
| content / content_body / content_hash | string | Toàn văn (HTML) / thân hợp đồng / mã băm nội dung |
| signing_active / signing_token | bool / string \| null | Còn chờ ký không |
| sign_methods | string[] | Cách ký đang mở (rỗng khi không chờ ký) |
| partner_sign_method | string \| null | `otp` \| `cks` \| `manual` |
| partner_manual_files | int | **Mới.** Số tệp ký tay đã nộp (`0` nếu không ký tay) |
| partner_certificate | object \| null | Chứng thư khi ký CKS: `{ subject, organization, issuer, tax_code, valid_to }` |
| platform_signed_at / is_fully_signed | ISO 8601 \| null / bool | 365 HOME đã ký chưa; đủ 2 bên chưa |
| contract_status / contract_expires_at | string / date \| null | Trạng thái hợp đồng; ngày hết hạn (khi 365 HOME đã ký) |
| has_signed_pdf | bool | Có PDF đã ký số để tải |

**Lỗi thường gặp**

| HTTP | Field / mã | Thông báo / khi nào |
|---|---|---|
| 404 | — | "Hồ sơ chưa có hợp đồng." / sai mã hồ sơ |

### 8.4 Tải PDF hợp đồng đã ký số

```
GET /api/public/partner-onboarding/{token}/contract/signed-pdf
```

**Tham số**

| Tên | Vị trí | Kiểu | Bắt buộc | Mô tả |
|---|---|---|---|---|
| token | path | string | Có | Mã hồ sơ |

**Response thành công — 200**

```text
Tệp PDF (application/pdf) — chỉ có sau khi 365 HOME ký
```

**Lỗi thường gặp**

| HTTP | Field / mã | Thông báo / khi nào |
|---|---|---|
| 404 | — | "Hợp đồng chưa có bản PDF đã ký." |

---

<a id="9-tom-tat"></a>
## 9. Tóm tắt API theo bước

| Bước | API |
|---|---|
| 0 Đăng ký | `GET /api/public/terms/homestay`, `POST /api/public/partner-onboarding`, `POST …/recover` |
| 1 Giấy tờ | `GET /api/public/legal-document-types`, `POST …/{token}/documents/scan`, `POST …/{token}/documents`, `POST …/{token}/documents/{id}`, `DELETE …/{token}/documents/{id}`, `GET …/{token}/documents/{id}/download` |
| 2 Thông tin | `GET …/{token}` (`partner`, `contract_info_sources`), `PUT …/{token}/contract-info` |
| 3 Ký | `POST …/{token}/contract`, `GET /api/partner-contracts/{st}`, `GET …/pdf`, `POST …/otp`, `POST …/confirm`, `POST …/confirm-cks`, **`POST …/confirm-manual`** |
| 4 Chờ duyệt | `GET …/{token}` + realtime, `POST …/submit`, `POST …/withdraw`, `GET …/{token}/contract`, `GET …/{token}/contract/signed-pdf` |

`…` = `/api/public/partner-onboarding`; `{st}` = `signing_token`.

---

<a id="10-minihouse"></a>
## 10. Ghi chú MiniHouse

MiniHouse **không ký hợp đồng**: mua gói qua `POST /api/public/minihouse-purchase`, xem trạng thái đơn qua `GET /api/public/minihouse-purchase/{purchase_token}`.

| Field | Bắt buộc | Ghi chú |
|---|---|---|
| `full_name`, `phone`, `email` | Có | Như API đăng ký Homestay |
| `plan_id`, `periods` | Không | Gói và số kỳ (`1`, `3`, `6`, `9`, `12`). Lần đầu được tặng dùng thử thì không cần |
| `accept_terms`, `terms_version_id` | Khi Điều khoản MiniHouse bật | Lấy từ `GET /api/public/terms/minihouse` |
| `business_name`, `address`, `address_*`, `postal_code` | Không | **Không còn bắt buộc.** Không gửi tên cơ sở thì tên hồ sơ tạm là `full_name` |

Khi đăng ký dùng thử có bước giấy tờ, nộp giấy tờ dùng chung API ở mục 5 với `purchase_token` làm mã hồ sơ, và giấy tờ cũng tự điền thông tin hồ sơ.

---

<a id="11-admin"></a>
## 11. API admin — hợp đồng ký tay

Header `Authorization: Bearer <token admin>` (đăng nhập qua `POST /api/admin/login`).

### 11.1 Thông tin hợp đồng (field mới)

```
GET /api/admin/partners/{partner}/contract
```

API đã có; bổ sung `partner_manual_files`. Super Admin hoặc chủ đối tác.

**Tham số**

| Tên | Vị trí | Kiểu | Bắt buộc | Mô tả |
|---|---|---|---|---|
| partner | path | string | Có | Mã đối tác |

**Response thành công — 200**

```json
{
  "data": {
    "…": "các field cũ",
    "partner_sign_method": "manual",
    "partner_manual_files": [
      { "id": 812, "file_name": "hop-dong-9-doi-tac-ky-tay-1.jpg", "mime_type": "image/jpeg", "size": 254311 },
      { "id": 813, "file_name": "hop-dong-9-doi-tac-ky-tay-2.jpg", "mime_type": "image/jpeg", "size": 248020 }
    ]
  }
}
```

**Ý nghĩa các field**

| Field | Kiểu | Mô tả |
|---|---|---|
| partner_manual_files[] | array | `{ id, file_name, mime_type, size }` — `[]` khi không ký tay |

### 11.2 Tải hợp đồng đối tác ký tay

```
GET /api/admin/partners/{partner}/contract/partner-manual-files
```

Super Admin hoặc chủ đối tác.

**Tham số**

| Tên | Vị trí | Kiểu | Bắt buộc | Mô tả |
|---|---|---|---|---|
| partner | path | string | Có | Mã đối tác |
| file | query | int | Không | id một tệp trong `partner_manual_files`. Không gửi: 1 tệp trả nguyên tệp, nhiều tệp gộp ZIP |

**Response thành công — 200**

```text
Tệp nhị phân: ảnh/PDF, hoặc "hop-dong-{version}-ky-tay.zip"
```

**Lỗi thường gặp**

| HTTP | Field / mã | Thông báo / khi nào |
|---|---|---|
| 404 | — | "Không có hợp đồng ký tay." / MiniHouse không dùng hợp đồng |
| 403 | — | Không có quyền với đối tác này |

### 11.3 Tải lên hộ hợp đồng ký tay

```
POST /api/admin/partners/{partner}/contract/partner-manual-upload
Content-Type: multipart/form-data
```

Super Admin. Dùng khi đối tác gửi bản giấy / scan ngoài hệ thống (Zalo, bưu điện…). Ghi nhận giống hệt đối tác tự ký tay.

**Tham số**

| Tên | Vị trí | Kiểu | Bắt buộc | Mô tả |
|---|---|---|---|---|
| partner | path | string | Có | Mã đối tác |
| signed_files[] | body | file[] | Có | 1–10 tệp; PDF/JPG/JPEG/PNG/WEBP; mỗi tệp ≤ 20 MB |
| signer_name | body | string ≤ 255 | Có | Họ tên người ký phía đối tác |

**Response thành công — 200**

```json
{
  "message": "Đã ghi nhận hợp đồng đối tác ký tay.",
  "data": {
    "partner_confirmed_at": "2026-10-23T10:05:00+07:00",
    "partner_sign_method": "manual",
    "partner_manual_files": [ { "id": 820, "file_name": "hop-dong-9-doi-tac-ky-tay-1.pdf", "mime_type": "application/pdf", "size": 81920 } ],
    "is_fully_signed": false,
    "contract_status": "draft"
  }
}
```

**Lỗi thường gặp**

| HTTP | Field / mã | Thông báo / khi nào |
|---|---|---|
| 422 | signed_files / signed_files.N / signer_name | Như API ký tay của đối tác |
| 422 | contract | Đối tác đã ký / có phiên bản mới hơn / hồ sơ chưa đủ điều kiện ký |
| 404 | — | "Đối tác chưa có hợp đồng." / MiniHouse |
| 403 | — | Không phải Super Admin |
