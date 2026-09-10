<?php

namespace Modules\Minihouse\App\Observers;

use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Services\ResidenceDeclarationService;
use Modules\Minihouse\App\Support\CccdScanMapper;

// Quét CCCD tự động khi tải ảnh CCCD của Khách thuê — DÙNG LẠI đúng CccdScannerService của Home
// (Modules\Payment\App\Services\CccdScannerService, QR chip → OCR fallback) qua CccdScanMapper,
// không viết lại logic quét. Đây là lớp phòng hộ CUỐI (sau khi lưu) — quét ngay lúc điền form Tạo/
// Sửa (trước khi lưu) đã có ở TenantForm; observer này đảm bảo dù ảnh được gán bằng cách khác
// (import, tinker, API...) thì vẫn được quét, và luôn đồng bộ lại "Khai báo lưu trú" sau khi lưu.
//
// Quy tắc điền: field nào ĐANG TRỐNG mới được quét điền vào — không đè lên dữ liệu nhân viên đã tự
// nhập/sửa tay. NGOẠI LỆ: nếu khách đã có ảnh CCCD từ trước và lần này THAY ẢNH KHÁC (sửa lại ảnh,
// không phải tải lần đầu) thì coi là cập nhật giấy tờ mới — cho phép ghi đè theo đúng ảnh mới quét
// được, kể cả field đã có giá trị.
class TenantObserver
{
    public function saved(Tenant $tenant): void
    {
        // wasChanged() KHÔNG tính "đã đổi" ngay lúc TẠO MỚI (Laravel chỉ syncChanges() ở
        // performUpdate(), không gọi ở performInsert()) — phải tự kiểm tra thêm wasRecentlyCreated,
        // nếu không ảnh CCCD tải lên ngay lúc tạo Khách thuê sẽ không được quét.
        $imagesTouched = $tenant->wasRecentlyCreated || $tenant->wasChanged(['id_card_front', 'id_card_back']);

        if ($imagesTouched && ($tenant->id_card_front || $tenant->id_card_back)) {
            // Ảnh cũ (trước lần lưu này) đã có sẵn -> đây là THAY ảnh khác, không phải tải lần đầu.
            $isReplacingImage = (bool) ($tenant->getOriginal('id_card_front') || $tenant->getOriginal('id_card_back'));

            $this->scanAndFill($tenant, overwrite: $isReplacingImage);
        }

        // Đồng bộ lại KBTT cho mọi hợp đồng của khách này (đứng tên chính LẪN ở cùng) — kể cả khi
        // không quét được gì mới (vd nhân viên tự sửa tay SĐT/nơi thường trú), để KBTT luôn khớp
        // hồ sơ mới nhất.
        app(ResidenceDeclarationService::class)->syncTenant($tenant);
    }

    /** @return array<string, mixed> field => giá trị vừa cập nhật (rỗng nếu không quét được/không có gì để điền) */
    public function scanAndFill(Tenant $tenant, bool $overwrite = false): array
    {
        $scan = CccdScanMapper::scan($tenant->id_card_front, $tenant->id_card_back);

        if (! $scan) {
            return [];
        }

        $candidates = CccdScanMapper::mapToTenantFields($scan);

        $updates = [];

        foreach ($candidates as $field => $value) {
            // Chỉ điền field đang trống — trừ khi $overwrite (vừa thay ảnh CCCD khác) thì ghi đè
            // luôn theo đúng ảnh mới, kể cả field đã có giá trị từ trước.
            if ($overwrite || blank($tenant->{$field})) {
                $updates[$field] = $value;
            }
        }

        if ($updates) {
            // updateQuietly() — tránh gọi lại saved() đệ quy khi gọi từ đây; nơi gọi (saved() gốc,
            // hoặc action "Quét CCCD" thủ công) tự lo phần đồng bộ tiếp theo.
            $tenant->updateQuietly($updates);
        }

        return $updates;
    }
}
