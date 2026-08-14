<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CccdDeclaration;
use App\Models\User;
use App\Services\CccdDeclarationExcelExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * PUT|POST /api/admin/cccd-declarations/{id}
     * Sửa thông tin khai báo lưu trú — khớp các field sửa được trên form CMS
     * (CccdDeclarationResource::form()), KHÔNG gồm declared_at/declared_by (đánh dấu "đã khai
     * báo" là 1 hành động riêng, không phải sửa thông tin — tương tự confirm-cleaning của phòng).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $declaration = $this->visibleDeclarationsQuery($request->user())->find($id);

        if (! $declaration) {
            return response()->json(['message' => 'Không tìm thấy bản ghi khai báo lưu trú.'], 404);
        }

        $data = $request->validate($this->rules());

        // Kiểm tra trên TRẠNG THÁI CUỐI CÙNG sau khi merge với dữ liệu đang lưu (không chỉ dữ liệu
        // gửi lên trong request này) — vd client chỉ sửa full_name, không đụng reason_for_stay lẫn
        // custom_reason, trong khi bản ghi ĐANG lưu reason_for_stay="Mục đích khác" thì vẫn phải
        // còn custom_reason hợp lệ, không thể chỉ dựa vào Rule::requiredIf() (chỉ thấy được dữ liệu
        // của riêng request này, không biết được giá trị đã lưu trước đó của field còn lại).
        $finalReasonForStay = $data['reason_for_stay'] ?? $declaration->reason_for_stay;
        $finalCustomReason  = array_key_exists('custom_reason', $data) ? $data['custom_reason'] : $declaration->custom_reason;
        if ($finalReasonForStay === '20 - Mục đích khác' && blank($finalCustomReason)) {
            return response()->json(['message' => 'Trường "Nhập lý do" (custom_reason) là bắt buộc khi lý do lưu trú là "Mục đích khác".'], 422);
        }

        $declaration->update($data);

        return response()->json(['data' => $this->toDetailItem($declaration->fresh(['order:id,order_code', 'declaredBy:id,fullname']))]);
    }

    /**
     * DELETE /api/admin/cccd-declarations/{id}
     * Xoá vĩnh viễn (bảng không dùng soft delete) — LƯU Ý: đây là hồ sơ phục vụ khai báo lưu trú
     * theo Luật Cư trú, xoá đi sẽ mất dấu vết đã từng khai báo/chưa khai báo cho lượt khách đó.
     * Filament CMS KHÔNG có nút xoá cho resource này (chỉ Sửa + đánh dấu đã khai báo) — cân nhắc kỹ
     * trước khi gọi trên dữ liệu thật.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $declaration = $this->visibleDeclarationsQuery($request->user())->find($id);

        if (! $declaration) {
            return response()->json(['message' => 'Không tìm thấy bản ghi khai báo lưu trú.'], 404);
        }

        $declaration->delete();

        return response()->json(['message' => 'Đã xoá bản ghi khai báo lưu trú.']);
    }

    /**
     * GET /api/admin/cccd-declarations/export
     * Xuất Excel đúng mẫu chính thức "Thông báo lưu trú" của Bộ Công an — CHỈ nhóm "cần khai báo
     * hôm nay" (chưa khai báo + đã/đang tới hạn trong ngày), giới hạn đúng phạm vi đối tác đang
     * gọi (super_admin xuất hết) — cùng nội dung với nút "Xuất Excel" ở CMS
     * (ListCccdDeclarations::exportExcel()), chỉ khác là API này lọc thêm theo đối tác.
     */
    public function export(Request $request): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        $ids = $this->visibleDeclarationsQuery($user)
            ->whereIn('id', CccdDeclaration::idsNeedingDeclarationToday())
            ->pluck('id')
            ->all();

        return app(CccdDeclarationExcelExporter::class)
            ->stream('ThongBaoLuuTru_' . now()->format('Ymd_His') . '.xlsx', $ids);
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

    // Khớp CHÍNH XÁC các option chuẩn của model (trích từ mẫu Bộ Công an) — sai giá trị sẽ hỏng khi
    // xuất Excel (CccdDeclarationExcelExporter) nên validate chặt bằng Rule::in() thay vì string tự do.
    // custom_reason bắt buộc khi reason_for_stay="Mục đích khác" — kiểm tra riêng ở update() (trên
    // TRẠNG THÁI CUỐI CÙNG sau khi merge với dữ liệu đang lưu), không đặt Rule::requiredIf() ở đây
    // vì nó chỉ thấy được dữ liệu của riêng request này, không biết giá trị đã lưu của field kia.
    private function rules(): array
    {
        return [
            'full_name'          => 'sometimes|nullable|string|max:255',
            // Lưu dạng chuỗi "DD/MM/YYYY" (không cast date) — khớp placeholder trên form CMS.
            'date_of_birth'      => 'sometimes|nullable|string|max:20',
            'gender'             => ['sometimes', 'nullable', Rule::in(array_keys(CccdDeclaration::GENDER_OPTIONS))],
            'nationality'        => ['sometimes', 'nullable', Rule::in(array_keys(CccdDeclaration::NATIONALITY_OPTIONS))],
            'document_type'      => ['sometimes', 'nullable', Rule::in(array_keys(CccdDeclaration::DOCUMENT_TYPE_OPTIONS))],
            'cccd_number'        => 'sometimes|nullable|string|max:20',
            'phone_number'       => 'sometimes|nullable|string|max:20',
            'info'               => 'sometimes|nullable|string|max:255',

            'room_number'        => 'sometimes|nullable|string|max:255',
            'stay_address'       => 'sometimes|nullable|string|max:255',
            'reason_for_stay'    => ['sometimes', 'nullable', Rule::in(array_keys(CccdDeclaration::REASON_FOR_STAY_OPTIONS))],
            'custom_reason'      => 'sometimes|nullable|string|max:255',
            'checked_in_at'      => 'sometimes|nullable|date',
            'checked_out_at'     => 'sometimes|nullable|date',

            'current_residence'  => 'sometimes|nullable|string|max:255',
            'residence_type'     => ['sometimes', 'nullable', Rule::in(array_keys(CccdDeclaration::RESIDENCE_TYPE_OPTIONS))],
            'province'           => 'sometimes|nullable|string|max:255',
            'ward'               => 'sometimes|nullable|string|max:255',
            'address_detail'     => 'sometimes|nullable|string',
            'notes'              => 'sometimes|nullable|string',
        ];
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
