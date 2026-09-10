<?php

namespace Modules\Minihouse\App\Observers;

use Modules\Minihouse\App\Models\ContractTenant;
use Modules\Minihouse\App\Models\ResidenceDeclaration;
use Modules\Minihouse\App\Services\ResidenceDeclarationService;

// Khi 1 "Người ở cùng" được thêm/xoá qua Repeater trên trang Hợp đồng, chỉ có bảng trung gian
// minihouse_contract_tenants thay đổi — KHÔNG có sự kiện nào trên Contract hay Tenant tự fire cả
// (ContractObserver chỉ nghe sự kiện Contract, TenantObserver chỉ nghe sự kiện Tenant). Observer
// này lấp đúng khoảng trống đó: đồng bộ lại "phòng đang ở" + "Khai báo lưu trú" của người vừa được
// gắn/gỡ khỏi hợp đồng.
class ContractTenantObserver
{
    public function saved(ContractTenant $entry): void
    {
        app(ContractObserver::class)->syncTenant($entry->tenant_id);

        if ($entry->tenant) {
            app(ResidenceDeclarationService::class)->syncTenant($entry->tenant);
        }
    }

    public function deleted(ContractTenant $entry): void
    {
        app(ContractObserver::class)->syncTenant($entry->tenant_id);

        // Người này không còn liên quan tới hợp đồng nữa (gỡ khỏi "Người ở cùng") — xoá luôn bản
        // khai báo lưu trú tương ứng, tránh để lại 1 dòng KBTT "mồ côi" cho hợp đồng họ không còn
        // ở nữa.
        ResidenceDeclaration::where('contract_id', $entry->contract_id)
            ->where('tenant_id', $entry->tenant_id)
            ->delete();
    }
}
