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

        // reason_for_stay/custom_reason: sửa hợp đồng CŨ để bổ sung "Lý do lưu trú" (trước đây bỏ
        // trống) cũng phải đồng bộ lại — thiếu 2 trường này ở đây thì tính năng tự điền lý do vào
        // Khai báo lưu trú (xem ContractForm) chỉ có tác dụng với hợp đồng MỚI, không áp dụng lại
        // được cho hợp đồng đã tồn tại từ trước khi chỉ sửa mỗi trường này.
        //
        // status/checkout_at: Thanh lý/Huỷ/Chuyển phòng đổi 2 field này (KHÔNG đổi end_date) nhưng
        // trước đây KHÔNG nằm trong danh sách kích hoạt đồng bộ ở đây — khiến "Ngày đi dự kiến"
        // (ResidenceDeclaration.checked_out_at, map từ contract->end_date, xem
        // ResidenceDeclarationService::upsertFromTenant()) không bao giờ được cập nhật khi khách trả
        // phòng/huỷ hợp đồng SỚM (trước end_date gốc) — audit phát hiện 2026-09-11.
        if ($contract->wasChanged(['tenant_id', 'room_id', 'start_date', 'end_date', 'reason_for_stay', 'custom_reason', 'status', 'checkout_at'])) {
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

    // Public — RefreshRoomStatusCommand (cron hàng ngày) cũng gọi lại đúng hàm này để tự chuyển
    // "Đã đặt cọc" -> "Đã thuê" đúng ngày start_date tới, dù không có sự kiện sửa Hợp đồng nào xảy ra
    // đúng ngày đó (VD tạo hợp đồng start_date tương lai 1 tuần trước, không ai đụng vào hợp đồng
    // giữa chừng — nếu chỉ dựa vào sự kiện Contract thì phòng sẽ mãi kẹt ở "Đã đặt cọc" quá ngày dọn
    // vào thật).
    public function syncRoom(?int $roomId): void
    {
        $room = Room::find($roomId);

        // Không tự đổi trạng thái "Đã khoá" — đó là cờ chủ nhà tự set tay, độc lập với hợp đồng.
        if (! $room || $room->status === Room::STATUS_REPAIR) {
            return;
        }

        $activeContracts = Contract::query()
            ->where('room_id', $room->id)
            ->where('status', Contract::STATUS_ACTIVE)
            ->get(['start_date']);

        $today = now()->startOfDay();

        // Có hợp đồng đang hiệu lực và ĐÃ tới ngày dọn vào -> "Đã thuê" thật sự. Có hợp đồng đang
        // hiệu lực nhưng ngày dọn vào còn ở TƯƠNG LAI (khách mới đặt cọc giữ chỗ, chưa tới ở) ->
        // "Đã đặt cọc" — khác "Trống" (đừng cho khách khác thuê nhầm) và khác "Đã thuê" (chưa ai ở
        // thật, tỷ lệ lấp đầy trên Dashboard không nên tính vào đây).
        $targetStatus = match (true) {
            $activeContracts->contains(fn (Contract $c) => ! $c->start_date || $c->start_date->lte($today)) => Room::STATUS_RENTED,
            $activeContracts->isNotEmpty() => Room::STATUS_RESERVED,
            default => Room::STATUS_EMPTY,
        };

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
