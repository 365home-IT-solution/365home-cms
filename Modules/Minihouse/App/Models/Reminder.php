<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;
use Modules\Minihouse\App\Support\ActiveBuildingScope;

class Reminder extends Model
{
    use SoftDeletes;
    use LogsMinihouseActivity;

    // room_id/contract_id ĐỀU nullable (nhắc việc chung, không gắn phòng/hợp đồng nào — vd "Đóng
    // thuế quý") — không dùng chung được ScopedToActiveBuildingViaRoom/ViaContract (sẽ ẩn LUÔN các
    // reminder chung này khỏi tài khoản bị giới hạn, dù chúng không thuộc riêng toà nào để mà lộ).
    // Chỉ ẩn khi reminder THỰC SỰ gắn 1 phòng/hợp đồng thuộc toà nhà ngoài phạm vi được phép.
    protected static function booted(): void
    {
        static::addGlobalScope('activeBuilding', function (Builder $builder) {
            if (! ActiveBuildingScope::shouldFilter()) {
                return;
            }

            $buildingIds = ActiveBuildingScope::activeBuildingIds();

            // withoutGlobalScopes() ở whereHas('contract'/'room') — mặc định tự áp CẢ scope
            // SoftDeletes của Contract/Room, khiến 1 nhắc việc gắn hợp đồng ĐÃ XOÁ MỀM BIẾN MẤT KHỎI
            // TOÀN BỘ danh sách của tài khoản bị giới hạn theo toà (không rơi vào bất kỳ nhánh OR nào
            // ở dưới) — dù nhắc việc đó vẫn còn giá trị (VD nhắc "hợp đồng quá hạn" tự sinh bởi
            // CheckOverdueContractsCommand, hoặc nhắc đóng tiền cũ) và nhân viên vẫn cần xử lý/xem
            // lại được.
            $builder->where(function (Builder $query) use ($buildingIds) {
                $query->where(fn (Builder $q) => $q->whereNull('room_id')->whereNull('contract_id'))
                    ->orWhereHas('room', fn (Builder $q) => $q->withoutGlobalScopes()->whereIn('building_id', $buildingIds))
                    ->orWhereHas('contract', fn (Builder $q) => $q->withoutGlobalScopes()->whereHas(
                        'room',
                        fn (Builder $q2) => $q2->withoutGlobalScopes()->whereIn('building_id', $buildingIds),
                    ));
            });
        });
    }

    // Building_id thật của reminder này (qua Phòng liên quan trực tiếp, hoặc qua Hợp đồng liên
    // quan) — dùng withoutGlobalScopes() ở cả 2 tầng để KHÔNG bị mất phạm vi toà nhà chỉ vì hợp
    // đồng/phòng liên quan đã bị xoá mềm (cùng lỗi lớp SoftDeletes đã gặp nhiều lần). Dùng chung cho
    // ReminderNotificationService (chuông nội bộ) và MinihouseZaloService (Zalo) — cả 2 kênh thông
    // báo đều cần biết đúng toà nhà để gửi đúng người/đúng phạm vi.
    public function resolveBuildingId(): ?int
    {
        if ($this->room_id) {
            return Room::withoutGlobalScopes()->find($this->room_id)?->building_id;
        }

        if ($this->contract_id) {
            return Contract::withoutGlobalScopes()
                ->with(['room' => fn ($q) => $q->withoutGlobalScopes()])
                ->find($this->contract_id)
                ?->room?->building_id;
        }

        return null;
    }

    public const TYPE_PAYMENT     = 'thu_tien';
    public const TYPE_CONTRACT    = 'het_han_hop_dong';
    public const TYPE_MAINTENANCE = 'bao_tri';
    public const TYPE_OTHER       = 'khac';

    protected $table = 'minihouse_reminders';

    protected $fillable = ['title', 'content', 'remind_date', 'type', 'repeat_interval_days', 'room_id', 'contract_id', 'invoice_id', 'assigned_to', 'is_done', 'notified_at'];

    protected $casts = [
        'remind_date' => 'date',
        'is_done'     => 'boolean',
        'notified_at' => 'datetime',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    // Chỉ dùng cho loại "Nhắc đóng tiền" — biết ĐÚNG hoá đơn nào đang nhắc (1 hợp đồng có thể có
    // nhiều hoá đơn qua từng tháng) để gửi Zalo kèm đủ chi tiết tiền phòng/điện/nước/nợ cũ giống hệt
    // phiếu in, xem MinihouseZaloService::buildTemplateData().
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    // Nhân viên được giao xử lý — chủ yếu cho "Nhắc bảo trì" (VD giao đúng thợ điện cho ca sửa máy
    // lạnh phòng nào đó). Dùng chung bảng users với Home (xem UserResource), không tách riêng.
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'assigned_to');
    }
}
