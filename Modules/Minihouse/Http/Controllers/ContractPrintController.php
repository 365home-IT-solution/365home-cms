<?php

namespace Modules\Minihouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\PdfSigning\ContractPdfRenderer;
use Illuminate\Http\Request;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Services\ContractContentRenderer;
use Symfony\Component\HttpFoundation\Response;

// Xuất PDF/In hợp đồng — dùng ĐÚNG nội dung đang lưu ở Contract.contract_content (không tự sinh
// lại từ dữ liệu ở đây; muốn cập nhật theo dữ liệu mới nhất thì bấm "Cập nhật nội dung hợp đồng"
// ở trang Sửa hợp đồng trước). Route thường (không qua Livewire action) vì Livewire chỉ hỗ trợ
// sẵn trả về file qua response()->streamDownload() (tải xuống) — không có cơ chế mở PDF INLINE ở
// tab mới đáng tin cậy, nên tách route riêng, mở qua thẻ <a target="_blank"> (xem ContractResource/
// Pages/EditContract.php).
class ContractPrintController extends Controller
{
    public function show(Request $request, Contract $contract): Response
    {
        $user = $request->user();

        abort_unless($user?->isSuperAdmin() || $user?->can('view_any_contracts'), 403);

        // Route THƯỜNG (ngoài panel Filament) — Contract::ScopedToActiveBuildingViaRoom chỉ lọc khi
        // ActiveBuildingScope::isPanelActive() (Filament::getCurrentPanel() === 'minihouse-admin'),
        // luôn FALSE ở đây nên global scope KHÔNG tự áp dụng cho việc route-model-binding
        // {contract} phía trên — nếu chỉ dựa permission chung 'view_any_contracts' (không phân biệt
        // toà nhà), 1 tài khoản bị giới hạn chỉ quản lý Toà A vẫn đổi được id trên URL để xem/tải
        // hợp đồng của Toà B. Phải tự kiểm tra thêm đúng ranh giới toà nhà ở đây.
        if (! $user->isSuperAdmin()) {
            // Room dùng SoftDeletes riêng — quan hệ mặc định sẽ trả về null nếu phòng bị xoá mềm
            // (dù hợp đồng vẫn còn), chặn nhầm quyền xem hợp đồng lịch sử hợp lệ (cùng lỗi lớp đã
            // gặp và sửa ở InvoiceContentRenderer/InvoicePrintController).
            $buildingId = Room::withoutGlobalScopes()->find($contract->room_id)?->building_id;

            abort_unless($buildingId && in_array($buildingId, $user->rootBuildingIds()), 403);
        }

        $pdf = ContractPdfRenderer::render(ContractContentRenderer::renderPrintable($contract));

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"hop-dong-{$contract->id}.pdf\"",
        ]);
    }
}
