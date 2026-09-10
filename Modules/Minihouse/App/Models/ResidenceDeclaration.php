<?php

namespace Modules\Minihouse\App\Models;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;
use Modules\Minihouse\App\Models\Concerns\ScopedToActiveBuildingViaContract;

// "Khai báo lưu trú" — mang từ Home qua (xem App\Models\CccdDeclaration bên hệ Home, dùng cho
// khách đặt phòng ngắn hạn). Cùng khung pháp lý (Luật Cư trú), cùng mẫu chính thức của Bộ Công an
// (tblt_vn_import.xlsx ở gốc dự án — dùng CHUNG cho cả Home lẫn MiniHouse, không nhân bản).
//
// Ghi chú quan trọng: bảng này CHỈ lưu dữ liệu tham chiếu nội bộ, KHÔNG tự động gửi cho ASM/dịch
// vụ công/công an — chủ trọ vẫn phải tự nộp thủ công theo đúng quy định (trước 23h ngày khách đến,
// hoặc trước 8h sáng hôm sau nếu khách đến sau 23h). 'declared_at'/'declared_by' chỉ để đánh dấu
// nội bộ đã nộp xong, giúp theo dõi hạn — tránh quên dẫn tới bị phạt.
class ResidenceDeclaration extends Model
{
    use ScopedToActiveBuildingViaContract;
    use LogsMinihouseActivity;

    protected $table = 'minihouse_residence_declarations';

    protected $fillable = [
        'contract_id', 'tenant_id',
        'full_name', 'date_of_birth', 'gender', 'cccd_number', 'nationality', 'document_type', 'phone_number',
        'checked_in_at', 'checked_out_at', 'room_number', 'stay_address',
        'reason_for_stay', 'custom_reason',
        'current_residence', 'residence_type', 'province', 'ward', 'address_detail', 'notes',
        'declared_at', 'declared_by', 'last_reminded_at',
    ];

    protected $casts = [
        'checked_in_at'     => 'datetime',
        'checked_out_at'    => 'datetime',
        'declared_at'       => 'datetime',
        'last_reminded_at'  => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function declaredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declared_by');
    }

    // "primary" (đứng tên hợp đồng) hay "occupant" (ở cùng) — tra theo bảng trung gian
    // minihouse_contract_tenants, không lưu trực tiếp ở đây để tránh 2 nguồn sự thật lệch nhau.
    public function roleInContract(): string
    {
        return ContractTenant::where('contract_id', $this->contract_id)
            ->where('tenant_id', $this->tenant_id)
            ->value('role') ?? ContractTenant::ROLE_PRIMARY;
    }

    public function isDeclared(): bool
    {
        return ! is_null($this->declared_at);
    }

    // Hạn khai báo theo đúng quy định: trước 23h NGÀY khách đến; nếu khách đến từ 23h trở đi thì
    // hạn dời sang 8h sáng hôm sau.
    public function declarationDeadline(): ?Carbon
    {
        if (! $this->checked_in_at) {
            return null;
        }

        $checkin = $this->checked_in_at;
        $cutoff  = $checkin->copy()->setTime(23, 0);

        return $checkin->lt($cutoff)
            ? $cutoff
            : $checkin->copy()->addDay()->setTime(8, 0);
    }

    public function isOverdue(): bool
    {
        if ($this->isDeclared()) {
            return false;
        }

        $deadline = $this->declarationDeadline();

        return $deadline && now()->gt($deadline);
    }

    public function isDueSoon(): bool
    {
        if ($this->isDeclared() || $this->isOverdue()) {
            return false;
        }

        $deadline = $this->declarationDeadline();

        return $deadline && now()->diffInMinutes($deadline) <= 120;
    }

    public function isDeclarationDueToday(): bool
    {
        $deadline = $this->declarationDeadline();

        return (bool) $deadline?->isToday();
    }

    public function needsDeclarationToday(): bool
    {
        return ! $this->isDeclared() && ($this->isOverdue() || $this->isDeclarationDueToday());
    }

    public function isUpcomingDeclaration(): bool
    {
        return ! $this->isDeclared() && ! $this->isOverdue() && ! $this->isDeclarationDueToday();
    }

    public static function idsNeedingDeclarationToday(): array
    {
        return static::whereNull('declared_at')
            ->get()
            ->filter(fn (self $d) => $d->needsDeclarationToday())
            ->pluck('id')
            ->all();
    }

    public static function idsUpcomingDeclaration(): array
    {
        return static::whereNull('declared_at')
            ->get()
            ->filter(fn (self $d) => $d->isUpcomingDeclaration())
            ->pluck('id')
            ->all();
    }

    public static function idsForDateRangeExport(string $from, string $until, array $buildingIds = []): array
    {
        return static::query()
            ->when(! empty($buildingIds), fn ($q) => $q->whereHas(
                'contract.room',
                fn ($rq) => $rq->whereIn('building_id', $buildingIds)
            ))
            ->whereDate('checked_in_at', '>=', $from)
            ->whereDate('checked_in_at', '<=', $until)
            ->orderBy('checked_in_at')
            ->pluck('id')
            ->all();
    }

    // Các trường THEO ĐÚNG "Nội dung thông báo" mà Luật Cư trú yêu cầu — dùng để chặn KHÔNG cho
    // đánh dấu "đã khai báo" khi dữ liệu còn thiếu.
    private const REQUIRED_FIELD_LABELS = [
        'full_name'         => 'Họ và tên',
        'date_of_birth'     => 'Ngày sinh',
        'gender'            => 'Giới tính',
        'nationality'       => 'Quốc tịch',
        'document_type'     => 'Loại giấy tờ',
        'cccd_number'       => 'Số giấy tờ (CCCD)',
        'checked_in_at'     => 'Ngày đến',
        'room_number'       => 'Số phòng',
        'stay_address'      => 'Địa chỉ lưu trú',
        'reason_for_stay'   => 'Lý do lưu trú',
        'current_residence' => 'Nơi thường trú',
        'province'          => 'Tỉnh/Thành phố',
        'ward'              => 'Phường/Xã',
    ];

    // Danh mục giá trị CHUẨN — trích nguyên văn mẫu chính thức tblt_vn_import.xlsx (sheet
    // DANH_MUC), giống hệt bên Home để khi xuất Excel khớp đúng dropdown, không cần chuyển đổi.
    public const GENDER_OPTIONS = [
        'M - Nam' => 'M - Nam',
        'F - Nữ'  => 'F - Nữ',
    ];

    public const DOCUMENT_TYPE_OPTIONS = [
        '1 - Thẻ CCCD'                       => '1 - Thẻ CCCD',
        '2 - Thẻ CMND'                       => '2 - Thẻ CMND',
        '3 - Giấy phép lái xe'               => '3 - Giấy phép lái xe',
        '4 - Hộ chiếu'                       => '4 - Hộ chiếu',
        '5 - Giấy khai sinh'                 => '5 - Giấy khai sinh',
        '6 - Thẻ BHYT'                       => '6 - Thẻ BHYT',
        '7 - Thông báo số định danh cá nhân' => '7 - Thông báo số định danh cá nhân',
        '8 - Thẻ Căn Cước'                   => '8 - Thẻ Căn Cước',
        '9 - Giấy Tờ Khác'                   => '9 - Giấy Tờ Khác',
    ];

    public const RESIDENCE_TYPE_OPTIONS = [
        '1 - Thường trú' => '1 - Thường trú',
        '2 - Tạm trú'    => '2 - Tạm trú',
        '3 - Khác'       => '3 - Khác',
    ];

    public const REASON_FOR_STAY_OPTIONS = [
        '1 - Du lịch'              => '1 - Du lịch',
        '2 - Công tác'             => '2 - Công tác',
        '3 - Học tập'              => '3 - Học tập',
        '4 - Thăm viếng'           => '4 - Thăm viếng',
        '5 - Hội nghị'             => '5 - Hội nghị',
        '6 - Thăm thân'            => '6 - Thăm thân',
        '7 - Từ thiện'             => '7 - Từ thiện',
        '8 - Tổ chức quốc tế'      => '8 - Tổ chức quốc tế',
        '9 - Kết hôn'              => '9 - Kết hôn',
        '10 - Lãnh sự quán'        => '10 - Lãnh sự quán',
        '11 - Viện trợ'            => '11 - Viện trợ',
        '12 - Đại sứ quán'         => '12 - Đại sứ quán',
        '13 - Định cư'             => '13 - Định cư',
        '14 - Tiếp thị'            => '14 - Tiếp thị',
        '15 - Báo chí, phóng viên' => '15 - Báo chí, phóng viên',
        '16 - Thương mại'          => '16 - Thương mại',
        '17 - Gia hạn thị thực'    => '17 - Gia hạn thị thực',
        '18 - Chữa bệnh'           => '18 - Chữa bệnh',
        '19 - Lao động'            => '19 - Lao động',
        '20 - Mục đích khác'       => '20 - Mục đích khác',
    ];

    public const NATIONALITY_DEFAULT = 'VNM - Viet Nam';

    public const NATIONALITY_OPTIONS = \App\Models\CccdDeclaration::NATIONALITY_OPTIONS;

    public function missingRequiredFieldLabels(): array
    {
        $missing = [];

        foreach (self::REQUIRED_FIELD_LABELS as $field => $label) {
            if (blank($this->{$field})) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    public function isDataComplete(): bool
    {
        return empty($this->missingRequiredFieldLabels());
    }

    protected function activityLabel(): string
    {
        return $this->full_name ?: ('#' . $this->id);
    }
}
