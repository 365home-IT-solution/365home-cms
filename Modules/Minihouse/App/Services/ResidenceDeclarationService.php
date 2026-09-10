<?php

namespace Modules\Minihouse\App\Services;

use App\Services\AdminNotificationService;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\ResidenceDeclaration;
use Modules\Minihouse\App\Models\Tenant;

// Tự tạo/cập nhật "Khai báo lưu trú" ngay khi Hợp đồng được tạo/sửa, hoặc khi Khách thuê (đứng tên
// chính LẪN người ở cùng — đều là Tenant thật, xem App\Models\ContractTenant) được thêm/sửa. Điền
// sẵn mọi thông tin ĐÃ CÓ từ hồ sơ Khách thuê/Hợp đồng, các trường mẫu chính thức yêu cầu nhưng hệ
// thống CHƯA thu thập (lý do lưu trú, tỉnh/thành, phường/xã...) để trống — nhân viên tự bổ sung khi
// khai báo (xem ResidenceDeclarationResource).
class ResidenceDeclarationService
{
    public function syncContract(Contract $contract): void
    {
        $contract->loadMissing(['room.building', 'tenants']);

        foreach ($contract->tenants as $tenant) {
            $this->upsertFromTenant($contract, $tenant);
        }
    }

    // Gọi khi 1 Khách thuê (đứng tên chính hoặc ở cùng) được quét CCCD/sửa hồ sơ — đồng bộ lại tất
    // cả hợp đồng người đó có liên quan.
    public function syncTenant(Tenant $tenant): void
    {
        foreach ($tenant->contracts as $contract) {
            $contract->loadMissing('room.building');
            $this->upsertFromTenant($contract, $tenant);
        }
    }

    private function upsertFromTenant(Contract $contract, Tenant $tenant): void
    {
        $existing = ResidenceDeclaration::where('contract_id', $contract->id)->where('tenant_id', $tenant->id)->first();

        ResidenceDeclaration::updateOrCreate(
            ['contract_id' => $contract->id, 'tenant_id' => $tenant->id],
            [
                'full_name'         => $tenant->fullname,
                'date_of_birth'     => $tenant->date_of_birth?->format('d/m/Y'),
                'gender'            => $this->mapGender($tenant->gender) ?: $existing?->gender,
                'cccd_number'       => $tenant->id_card_number,
                'phone_number'      => $tenant->phone ?: $existing?->phone_number,
                'current_residence' => $tenant->permanent_address ?: $existing?->current_residence,
                'nationality'       => $existing?->nationality ?: ResidenceDeclaration::NATIONALITY_DEFAULT,
                'document_type'     => $existing?->document_type ?: '1 - Thẻ CCCD',
                'checked_in_at'     => $contract->start_date,
                'checked_out_at'    => $contract->end_date,
                'room_number'       => $contract->room?->code,
                'stay_address'      => $contract->room?->building?->address,
                // Tỉnh/Thành phố, Phường/Xã, địa chỉ chi tiết của "Nơi cư trú" = nơi khách ĐANG Ở
                // (đúng bản chất khai báo tạm trú: khai nơi đang lưu trú, không phải quê quán) — lấy
                // từ Toà nhà của Phòng đang thuê (BuildingForm mới có 2 field này). Chỉ điền khi
                // TRỐNG — không ghi đè nếu nhân viên đã tự chọn tay khác đi (VD khách ở phòng này
                // nhưng khai theo địa chỉ khác vì lý do riêng).
                //
                // reason_for_stay/custom_reason: ưu tiên giá trị nhân viên đã tự chọn TRÊN CHÍNH
                // khai báo này, rơi về giá trị đã chọn sẵn ở Hợp đồng (ContractForm) nếu khai báo
                // chưa có — trước đây luôn để trống ở đây, bắt phải bổ sung tay từng khai báo mới
                // "Đánh dấu đã khai báo" được (xem ResidenceDeclaration::REQUIRED_FIELD_LABELS).
                'reason_for_stay'  => $existing?->reason_for_stay ?: $contract->reason_for_stay,
                'custom_reason'    => $existing?->custom_reason ?: $contract->custom_reason,
                'residence_type'   => $existing?->residence_type ?: '1 - Thường trú',
                'province'         => $existing?->province ?: $contract->room?->building?->province,
                'ward'             => $existing?->ward ?: $contract->room?->building?->ward,
                'address_detail'   => $existing?->address_detail ?: $contract->room?->building?->address,
                'notes'            => $existing?->notes,
            ]
        );
    }

    // Model đã có sẵn isOverdue()/isDueSoon()/needsDeclarationToday() nhưng KHÔNG có cron nào dùng
    // tới trước đây — hạn khai báo tạm trú là nghĩa vụ pháp lý (Luật Cư trú), trễ hạn có thể bị phạt,
    // nên không thể chỉ trông chờ nhân viên tự nhớ vào xem bộ lọc. Gửi lại MỖI NGÀY (khác Reminder
    // thường chỉ bắn 1 lần) cho tới khi đánh dấu "Đã khai báo" — dùng last_reminded_at để không gửi
    // trùng nhiều lần trong CÙNG 1 ngày nếu lệnh lỡ chạy lại.
    public function notifyDue(): void
    {
        $declarations = ResidenceDeclaration::query()
            ->withoutGlobalScopes()
            ->whereNull('declared_at')
            ->where(fn ($q) => $q->whereNull('last_reminded_at')->orWhereDate('last_reminded_at', '<', now()->toDateString()))
            ->with(['contract' => fn ($q) => $q->withoutGlobalScopes(), 'contract.room' => fn ($q) => $q->withoutGlobalScopes()])
            ->get()
            ->filter(fn (ResidenceDeclaration $d) => $d->needsDeclarationToday());

        foreach ($declarations as $declaration) {
            $buildingId = $declaration->contract?->room?->building_id;
            $recipients = ReminderNotificationService::recipientsForBuilding($buildingId);

            if ($recipients->isNotEmpty()) {
                $label = $declaration->isOverdue() ? 'ĐÃ QUÁ HẠN' : 'đến hạn hôm nay';

                app(AdminNotificationService::class)->notify(
                    $recipients,
                    'Khai báo lưu trú ' . $label . ': ' . $declaration->full_name,
                    'Phòng ' . ($declaration->room_number ?: '—') . ' — hạn khai báo: ' . $declaration->declarationDeadline()?->format('H:i d/m/Y'),
                    ['type' => 'minihouse_residence_declaration', 'residence_declaration_id' => $declaration->id],
                    'heroicon-o-identification',
                    $declaration->isOverdue() ? 'danger' : 'warning',
                    url('/minihouse-admin/residence-declarations/' . $declaration->id . '/edit'),
                );
            }

            $declaration->updateQuietly(['last_reminded_at' => now()]);
        }
    }

    private function mapGender(?string $rawGender): ?string
    {
        return match ($rawGender) {
            'nam' => 'M - Nam',
            'nu'  => 'F - Nữ',
            default => null,
        };
    }
}
