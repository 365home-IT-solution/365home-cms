<?php

namespace Modules\Minihouse\App\Observers;

use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\ContractTenant;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Services\ResidenceDeclarationService;

// Room.status ("Trống"/"Đã thuê") và Tenant.room_id ("phòng đang ở") trước đây là field nhập tay,
// không có gì tự cập nhật theo vòng đời hợp đồng — tạo hợp đồng mới không tự chuyển phòng sang "Đã
// thuê", kết thúc/huỷ hợp đồng không tự trả phòng về "Trống"/xoá "phòng đang ở" của khách. Observer
// này tự đồng bộ lại mỗi khi 1 Hợp đồng được tạo/sửa/xoá, tránh dữ liệu lệch dần theo thời gian
// (ảnh hưởng trực tiếp tỷ lệ lấp đầy trên Dashboard). Đồng thời tự tạo/cập nhật "Khai báo lưu trú"
// cho người đứng tên hợp đồng ngay khi tạo/sửa hợp đồng — xem ResidenceDeclarationService — và tự
// mirror Contract.tenant_id vào minihouse_contract_tenants (role=primary) để bảng đó luôn đầy đủ.
class ContractObserver
{
    public function created(Contract $contract): void
    {
        $this->syncRoom($contract->room_id);
        $this->syncPrimaryPivot($contract);
        $this->syncTenant($contract->tenant_id);
        app(ResidenceDeclarationService::class)->syncContract($contract);
    }

    public function updated(Contract $contract): void
    {
        if ($contract->wasChanged(['status', 'room_id'])) {
            $this->syncRoom($contract->room_id);

            if ($contract->wasChanged('room_id')) {
                $this->syncRoom($contract->getOriginal('room_id'));
            }
        }

        if ($contract->wasChanged('tenant_id')) {
            $this->syncPrimaryPivot($contract);
        }

        if ($contract->wasChanged(['status', 'tenant_id', 'room_id'])) {
            $this->syncTenant($contract->tenant_id);

            if ($contract->wasChanged('tenant_id')) {
                $this->syncTenant($contract->getOriginal('tenant_id'));
            }
        }

        if ($contract->wasChanged(['tenant_id', 'room_id', 'start_date', 'end_date'])) {
            app(ResidenceDeclarationService::class)->syncContract($contract);
        }
    }

    public function deleted(Contract $contract): void
    {
        $this->syncRoom($contract->room_id);
        $this->syncTenant($contract->tenant_id);
    }

    // Giữ đúng 1 dòng role=primary khớp Contract.tenant_id hiện tại — xoá dòng primary cũ nếu
    // tenant_id vừa đổi (không đụng các dòng role=occupant, vẫn giữ nguyên người ở cùng).
    private function syncPrimaryPivot(Contract $contract): void
    {
        ContractTenant::where('contract_id', $contract->id)
            ->where('role', ContractTenant::ROLE_PRIMARY)
            ->where('tenant_id', '!=', $contract->tenant_id)
            ->delete();

        if ($contract->tenant_id) {
            ContractTenant::firstOrCreate(
                ['contract_id' => $contract->id, 'tenant_id' => $contract->tenant_id],
                ['role' => ContractTenant::ROLE_PRIMARY]
            );
        }
    }

    private function syncRoom(?int $roomId): void
    {
        $room = Room::find($roomId);

        // Không tự đổi trạng thái "Đã khoá" — đó là cờ chủ nhà tự set tay, độc lập với hợp đồng.
        if (! $room || $room->status === Room::STATUS_REPAIR) {
            return;
        }

        $hasActiveContract = Contract::query()
            ->where('room_id', $room->id)
            ->where('status', Contract::STATUS_ACTIVE)
            ->exists();

        $targetStatus = $hasActiveContract ? Room::STATUS_RENTED : Room::STATUS_EMPTY;

        if ($room->status !== $targetStatus) {
            $room->update(['status' => $targetStatus]);
        }
    }

    // "Phòng đang ở" của 1 khách — giờ phải xét CẢ hợp đồng đứng tên chính LẪN hợp đồng đang ở
    // cùng (người ở cùng giờ cũng là Tenant thật, xem ContractTenant), không chỉ tenant_id trực
    // tiếp như trước khi gộp Người ở cùng vào Khách thuê. Public — ContractTenantObserver cũng gọi
    // lại đúng hàm này khi 1 dòng "người ở cùng" được thêm/sửa/xoá (không đi qua sự kiện Contract).
    public function syncTenant(?int $tenantId): void
    {
        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            return;
        }

        // minihouse_contracts CŨNG có cột tenant_id (người đứng tên) — phải chỉ rõ tên bảng cho
        // mọi cột `tenant_id`/`status` sau khi join, nếu không MySQL báo "ambiguous column".
        $activeContractId = ContractTenant::query()
            ->join('minihouse_contracts', 'minihouse_contracts.id', '=', 'minihouse_contract_tenants.contract_id')
            ->where('minihouse_contract_tenants.tenant_id', $tenant->id)
            ->where('minihouse_contracts.status', Contract::STATUS_ACTIVE)
            ->whereNull('minihouse_contracts.deleted_at')
            ->orderByDesc('minihouse_contracts.start_date')
            ->value('minihouse_contract_tenants.contract_id');

        $targetRoomId = $activeContractId ? Contract::find($activeContractId)?->room_id : null;

        if ($tenant->room_id !== $targetRoomId) {
            $tenant->update(['room_id' => $targetRoomId]);
        }
    }
}
