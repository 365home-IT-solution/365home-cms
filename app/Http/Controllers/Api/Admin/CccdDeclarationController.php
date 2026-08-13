<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CccdDeclaration;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Danh sách KHAI BÁO LƯU TRÚ (bảng cccd_declarations) — tương ứng
 * App\Filament\Resources\CccdDeclarationResource ở CMS, cùng 4 tab (today/upcoming/declared/all),
 * cùng phạm vi lọc (chỉ khách của đơn ĐÃ XÁC NHẬN — paid/deposit/shipped, đơn pending có thể tự
 * huỷ nên không tính). CHỈ đọc — API xác nhận "đã khai báo" (mark-declared) làm riêng khi cần, xem
 * ghi chú ở CccdDeclaration model: hệ thống KHÔNG tự gửi khai báo cho ASM/dịch vụ công, nhân viên
 * vẫn phải tự nộp thủ công bên ngoài, đây chỉ là nơi tra cứu ai cần khai báo.
 */
class CccdDeclarationController extends Controller
{
    private const TABS = ['today', 'upcoming', 'declared', 'all'];

    /**
     * GET /api/admin/cccd-declarations
     * Query params:
     *  - tab      : today (mặc định — cần khai báo hôm nay) | upcoming (sắp tới hạn) |
     *               declared (đã khai báo) | all (tất cả)
     *  - search   : họ tên / số CCCD / mã đơn
     *  - from, until : lọc theo "ngày đến" (checked_in_at), định dạng yyyy-mm-dd
     *  - per_page : mặc định 20
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tab  = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'today';

        $query = $this->visibleDeclarationsQuery($user)->with('order:id,order_code');

        match ($tab) {
            'today'    => $query->whereIn('id', CccdDeclaration::idsNeedingDeclarationToday()),
            'upcoming' => $query->whereIn('id', CccdDeclaration::idsUpcomingDeclaration()),
            'declared' => $query->whereNotNull('declared_at'),
            'all'      => null,
        };

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function (Builder $q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('cccd_number', 'like', "%{$search}%")
                    ->orWhereHas('order', fn ($q2) => $q2->where('order_code', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('checked_in_at', '>=', $request->date('from'));
        }
        if ($request->filled('until')) {
            $query->whereDate('checked_in_at', '<=', $request->date('until'));
        }

        $declarations = $query->orderBy('checked_in_at')->paginate($request->integer('per_page', 20));
        $declarations->getCollection()->transform(fn (CccdDeclaration $d) => $this->toListItem($d));

        // idsNeedingDeclarationToday()/idsUpcomingDeclaration() tự tính GLOBAL (không lọc đối
        // tác) — giao (whereIn) với visibleDeclarationsQuery() để badge số lượng cũng đúng phạm vi
        // đối tác của user như phần data ở trên.
        return response()->json(array_merge($declarations->toArray(), [
            'tab_counts' => [
                'today'    => $this->visibleDeclarationsQuery($user)->whereIn('id', CccdDeclaration::idsNeedingDeclarationToday())->count(),
                'upcoming' => $this->visibleDeclarationsQuery($user)->whereIn('id', CccdDeclaration::idsUpcomingDeclaration())->count(),
                'declared' => $this->visibleDeclarationsQuery($user)->whereNotNull('declared_at')->count(),
                'all'      => $this->visibleDeclarationsQuery($user)->count(),
            ],
        ]));
    }

    /**
     * GET /api/admin/cccd-declarations/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $declaration = $this->visibleDeclarationsQuery($request->user())
            ->with(['order:id,order_code', 'declaredBy:id,fullname'])
            ->find($id);

        if (! $declaration) {
            return response()->json(['message' => 'Không tìm thấy bản ghi khai báo lưu trú.'], 404);
        }

        return response()->json(['data' => $this->toDetailItem($declaration)]);
    }

    // Chỉ khách của đơn ĐÃ XÁC NHẬN (paid/deposit/shipped — CONFIRMED_ORDER_STATUSES) mới tính, và
    // chỉ thuộc đơn của đúng đối tác user đang quản lý (super_admin xem hết) — cùng phạm vi với
    // CccdDeclarationResource::table()->modifyQueryUsing() ở Filament.
    private function visibleDeclarationsQuery(User $user): Builder
    {
        return CccdDeclaration::query()->whereHas('order', function (Builder $q) use ($user) {
            $q->whereIn('status', CccdDeclaration::CONFIRMED_ORDER_STATUSES);

            if (! $user->isSuperAdmin()) {
                $q->where('partner_id', $user->partner_id);
            }
        });
    }

    private function statusOf(CccdDeclaration $d): string
    {
        if ($d->isDeclared()) {
            return 'declared';
        }
        if (! $d->isDataComplete()) {
            return 'missing_info';
        }
        if ($d->isOverdue()) {
            return 'overdue';
        }
        if ($d->isDueSoon()) {
            return 'due_soon';
        }

        return 'not_declared';
    }

    private function toListItem(CccdDeclaration $d): array
    {
        return [
            'id'                   => $d->id,
            'order_code'           => $d->order?->order_code,
            'guest_index'          => $d->guest_index,
            'guest_label'          => $d->guest_index >= 2 ? "Khách {$d->guest_index}" : 'Khách chính',
            'full_name'            => $d->full_name,
            'cccd_number'          => $d->cccd_number,
            'date_of_birth'        => $d->date_of_birth,
            'gender'               => $d->gender,
            'nationality'          => $d->nationality,
            'room_number'          => $d->room_number,
            'stay_address'         => $d->stay_address,
            'checked_in_at'        => optional($d->checked_in_at)->format('Y-m-d H:i:s'),
            'checked_out_at'       => optional($d->checked_out_at)->format('Y-m-d H:i:s'),
            'declaration_deadline' => optional($d->declarationDeadline())->format('Y-m-d H:i:s'),
            'is_declared'          => $d->isDeclared(),
            'is_data_complete'     => $d->isDataComplete(),
            'missing_fields'       => $d->missingRequiredFieldLabels(),
            'status'               => $this->statusOf($d),
        ];
    }

    private function toDetailItem(CccdDeclaration $d): array
    {
        return array_merge($this->toListItem($d), [
            'info'               => $d->info,
            'document_type'      => $d->document_type,
            'phone_number'       => $d->phone_number,
            'reason_for_stay'    => $d->reason_for_stay,
            'custom_reason'      => $d->custom_reason,
            'current_residence'  => $d->current_residence,
            'residence_type'     => $d->residence_type,
            'province'           => $d->province,
            'ward'               => $d->ward,
            'address_detail'     => $d->address_detail,
            'notes'              => $d->notes,
            'declared_at'        => optional($d->declared_at)->format('Y-m-d H:i:s'),
            'declared_by'        => $d->declaredBy ? ['id' => $d->declaredBy->id, 'fullname' => $d->declaredBy->fullname] : null,
            'created_at'         => optional($d->created_at)->toISOString(),
        ]);
    }
}
