<?php

namespace Modules\Minihouse\App\Models\Concerns;

use App\Models\User;
use Modules\Minihouse\App\Models\ActivityLog;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Support\ActiveBuildingScope;

// Ghi log tự động (tạo/sửa/xoá) cho MỌI model gắn trait này — mirror đúng cơ chế "trait tự gắn"
// App\Models\Concerns\LogsAuditTrail bên Home, nhưng viết vào bảng riêng của MiniHouse
// (minihouse_activity_logs, lọc theo building_id) thay vì bảng audit_logs bắt buộc partner_id của
// Home — MiniHouse không có khái niệm partner.
//
// Chỉ ghi khi đang chạy TRONG panel minihouse-admin (ActiveBuildingScope::isPanelActive()) VÀ có
// user thật đăng nhập — tránh ghi nhầm log cho lệnh console (VD minihouse:generate-invoices chạy
// theo lịch, không có ai đăng nhập) hay observer tự đồng bộ dữ liệu ngoài panel.
trait LogsMinihouseActivity
{
    private static bool $activityLoggingSuppressed = false;

    // Dùng khi 1 đoạn code chủ động tạo/sửa/xoá HÀNG LOẠT bản ghi (VD InvoiceGenerationService lập
    // hàng chục hoá đơn 1 lượt) mà không muốn mỗi bản ghi tự bắn 1 dòng log riêng.
    public static function withoutActivityLog(\Closure $callback): mixed
    {
        $previous = self::$activityLoggingSuppressed;
        self::$activityLoggingSuppressed = true;

        try {
            return $callback();
        } finally {
            self::$activityLoggingSuppressed = $previous;
        }
    }

    protected static function bootLogsMinihouseActivity(): void
    {
        static::created(function ($model) {
            if (! static::shouldLogActivity()) {
                return;
            }

            static::writeActivityLog(ActivityLog::ACTION_CREATED, $model, null, $model->activityLoggableAttributes($model->getAttributes()));
        });

        static::updated(function ($model) {
            if (! static::shouldLogActivity()) {
                return;
            }

            $changed = $model->activityLoggableAttributes($model->getChanges());

            if (empty($changed)) {
                return;
            }

            $original = $model->activityLoggableAttributes(array_intersect_key($model->getOriginal(), $changed));

            static::writeActivityLog(ActivityLog::ACTION_UPDATED, $model, $original, $changed);
        });

        static::deleted(function ($model) {
            if (! static::shouldLogActivity()) {
                return;
            }

            static::writeActivityLog(ActivityLog::ACTION_DELETED, $model, $model->activityLoggableAttributes($model->getAttributes()), null);
        });
    }

    private static function shouldLogActivity(): bool
    {
        return ActiveBuildingScope::isPanelActive() && auth()->user() instanceof User && ! self::$activityLoggingSuppressed;
    }

    private static function writeActivityLog(string $action, $model, ?array $old, ?array $new): void
    {
        $user = auth()->user();

        ActivityLog::create([
            'building_id'   => $model->activityBuildingId(),
            'user_id'       => $user->id,
            'user_name'     => $user->fullname ?? $user->name ?? $user->email,
            'action'        => $action,
            'subject_type'  => static::class,
            'subject_id'    => $model->getKey(),
            'subject_label' => $model->activityLabel(),
            'old_values'    => $old ?: null,
            'new_values'    => $new ?: null,
        ]);
    }

    // Suy ra toà nhà theo chuỗi khoá ngoại phổ biến nhất của module (building_id trực tiếp -> qua
    // phòng -> qua hợp đồng -> qua hoá đơn). Model không khớp chuỗi nào (VD Building tự thân) thì tự
    // override lại method này.
    protected function activityBuildingId(): ?int
    {
        if (array_key_exists('building_id', $this->attributes)) {
            return $this->building_id;
        }

        if (array_key_exists('room_id', $this->attributes) && method_exists($this, 'room')) {
            return $this->room?->building_id;
        }

        // withoutGlobalScopes() — Contract dùng SoftDeletes riêng, quan hệ mặc định trả null nếu hợp
        // đồng liên quan đã bị xoá mềm TRƯỚC LÚC model này bị sửa/xoá (VD sửa 1 Reminder/Transaction
        // cũ sau khi hợp đồng đã thanh lý) — sẽ ghi log building_id=NULL, làm dòng log biến mất khỏi
        // Nhật ký hoạt động khi lọc theo toà, dù vẫn cần tra cứu được (cùng lỗi lớp SoftDeletes đã
        // gặp nhiều lần trong module).
        if (array_key_exists('contract_id', $this->attributes) && method_exists($this, 'contract')) {
            return Contract::withoutGlobalScopes()
                ->with(['room' => fn ($q) => $q->withoutGlobalScopes()])
                ->find($this->contract_id)
                ?->room?->building_id;
        }

        if (array_key_exists('invoice_id', $this->attributes) && method_exists($this, 'invoice')) {
            $invoice = Invoice::withoutGlobalScopes()->find($this->invoice_id);

            return $invoice?->contract_id
                ? Contract::withoutGlobalScopes()
                    ->with(['room' => fn ($q) => $q->withoutGlobalScopes()])
                    ->find($invoice->contract_id)
                    ?->room?->building_id
                : null;
        }

        return null;
    }

    protected function activityLabel(): string
    {
        foreach (['code', 'name', 'fullname', 'title'] as $attribute) {
            $value = $this->{$attribute} ?? null;

            if (filled($value)) {
                return (string) $value;
            }
        }

        return '#' . $this->getKey();
    }

    protected function activityLoggableAttributes(array $attributes): array
    {
        return array_diff_key(
            $attributes,
            array_flip(array_merge(['id'], static::activityExcludedFields()))
        );
    }

    protected static function activityExcludedFields(): array
    {
        return ['created_at', 'updated_at', 'deleted_at'];
    }
}
