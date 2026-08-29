<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use App\Support\AdminPanelContext;
use Modules\AuditLog\Services\AuditLogger;

// Ghi log tự động (create/update/delete) cho MỌI model gắn trait này — cùng cơ chế "trait tự gắn"
// đang dùng ở BelongsToPartner/BelongsToBranch (chỉ cần `use LogsAuditTrail;`, không cần đăng ký gì
// thêm ở EventServiceProvider). Trước đây mỗi model muốn có audit log phải tự viết 1 class Observer
// riêng (app/Observers/*.php) — tốn công nên ~85% resource trong dự án bị bỏ sót hoàn toàn. Trait
// này thay thế cho MỌI model MỚI muốn có log (9 model đã có Observer viết tay từ trước — Product,
// Order, User, Role, Post, Branch, Coupon, Promotion, UserBranchPermission — vẫn giữ nguyên
// Observer cũ, không đổi, để tránh rủi ro hồi quy trên logic phức tạp sẵn có, đặc biệt OrderObserver).
//
// Mặc định: log TOÀN BỘ field thay đổi (trừ các field noise: timestamps, password, remember_token,
// media/cache-counter thường không có ý nghĩa audit) — không cần khai báo whitelist riêng mỗi model
// như cách cũ. Chỉ ghi log khi đang chạy TRONG admin panel Filament (AdminPanelContext::isActive())
// VÀ có user thực sự đăng nhập (AuditLogger::log() tự bỏ qua nếu auth()->user() null) — tránh ghi
// nhầm log cho request phía khách hàng/API công khai/cron dùng chung code base.
//
// Model có thể tuỳ biến (không bắt buộc) bằng cách override các method static/instance dưới đây:
// - auditModuleName(): tên module hiển thị trong bảng audit_logs (mặc định = short class name).
// - auditLabel(): nhãn hiển thị cho 1 bản ghi cụ thể (mặc định thử theo thứ tự name/title/code/...).
// - auditExcludedFields(): field KHÔNG log khi update (mặc định hợp nhất với danh sách chung).
trait LogsAuditTrail
{
    // Bật khi code đang chủ động chạy 1 vòng lặp tạo/sửa/xoá HÀNG LOẠT bản ghi của model này (vd
    // SettingBook::save() xoá-tạo-lại từng khung giờ của 1 phòng) mà muốn tự ghi 1 dòng audit log
    // TÓM TẮT thay vì để mỗi bản ghi tự bắn 1 dòng riêng (rất nhiều dòng vụn cho 1 lần Lưu). Property
    // static khai báo TRONG trait — mỗi model dùng trait này có 1 bản RIÊNG (PHP: static property
    // của trait không dùng chung giữa các class khác nhau cùng use nó), nên gọi
    // RoomTimeSlot::withoutAuditLog(...) không ảnh hưởng model khác trong cùng đoạn code.
    private static bool $auditLoggingSuppressed = false;

    public static function withoutAuditLog(\Closure $callback): mixed
    {
        $previous = self::$auditLoggingSuppressed;
        self::$auditLoggingSuppressed = true;

        try {
            return $callback();
        } finally {
            self::$auditLoggingSuppressed = $previous;
        }
    }

    protected static function bootLogsAuditTrail(): void
    {
        static::created(function ($model) {
            if (! static::shouldLogAuditTrail()) {
                return;
            }

            AuditLogger::log(
                action: 'create',
                module: $model->auditModuleName(),
                record: $model,
                new: $model->auditableAttributes($model->getAttributes()),
                label: $model->auditLabel(),
            );
        });

        static::updated(function ($model) {
            if (! static::shouldLogAuditTrail()) {
                return;
            }

            $changed = $model->auditableAttributes($model->getChanges());
            if (empty($changed)) {
                return;
            }

            $original = $model->auditableAttributes(array_intersect_key($model->getOriginal(), $changed));

            AuditLogger::log(
                action: 'update',
                module: $model->auditModuleName(),
                record: $model,
                old: $original,
                new: $changed,
                label: $model->auditLabel(),
            );
        });

        static::deleted(function ($model) {
            if (! static::shouldLogAuditTrail()) {
                return;
            }

            AuditLogger::log(
                action: 'delete',
                module: $model->auditModuleName(),
                record: $model,
                old: $model->auditableAttributes($model->getAttributes()),
                label: $model->auditLabel(),
            );
        });
    }

    private static function shouldLogAuditTrail(): bool
    {
        return AdminPanelContext::isActive() && auth()->user() instanceof User && ! self::$auditLoggingSuppressed;
    }

    // Lọc bỏ field noise khỏi 1 mảng attribute trước khi đưa vào old/new_values, đồng thời thay
    // các cột khoá ngoại (partner_id, branch_id, created_by...) bằng TÊN HIỂN THỊ thay vì để nguyên
    // UUID/id thô — trước đây log dạng "branch_id: 167" rất khó đọc, giờ hiện thẳng tên chi nhánh.
    // 'id' luôn bị loại — đã có sẵn ở cột "target_id"/"Đối tượng" riêng, lặp lại trong bảng giá trị
    // chỉ gây rối.
    protected function auditableAttributes(array $attributes): array
    {
        $filtered = array_diff_key(
            $attributes,
            array_flip(array_merge(['id'], static::auditExcludedFields()))
        );

        $resolvers = static::auditForeignKeyResolvers();

        foreach ($filtered as $field => $value) {
            if (isset($resolvers[$field])) {
                $filtered[$field] = $this->resolveAuditForeignKey($value, $resolvers[$field]);
            }
        }

        return $filtered;
    }

    protected static function auditExcludedFields(): array
    {
        return ['created_at', 'updated_at', 'deleted_at', 'password', 'remember_token'];
    }

    // Khoá ngoại thường gặp trong dự án -> [class model liên quan, danh sách attribute thử lấy làm
    // tên hiển thị theo thứ tự ưu tiên]. Model nào có khoá ngoại đặc thù riêng (vd
    // warehouse_category_id, warehouse_unit_id) có thể override thêm bằng cách merge vào mảng này.
    protected static function auditForeignKeyResolvers(): array
    {
        return [
            'partner_id'             => [\App\Models\Partner::class, ['name']],
            'branch_id'              => [\Modules\Category\Entities\Category::class, ['name']],
            'category_id'            => [\Modules\Category\Entities\Category::class, ['name']],
            'created_by'             => [\App\Models\User::class, ['fullname', 'email']],
            'updated_by'             => [\App\Models\User::class, ['fullname', 'email']],
            'user_id'                => [\App\Models\User::class, ['fullname', 'email']],
            'customer_id'            => [\App\Models\Customer::class, ['fullname', 'name', 'phone']],
            'room_id'                => [\Modules\Product\App\Models\Product::class, ['name']],
            'product_id'             => [\Modules\Product\App\Models\Product::class, ['name']],
            'timeslot_id'            => [\Modules\Product\App\Models\TimeSlot::class, ['label']],
            'price_board_id'         => [\Modules\Product\App\Models\PriceBoard::class, ['name']],
            'membership_tier_id'     => [\App\Models\MembershipTier::class, ['name']],
            'auto_issue_tier_id'     => [\App\Models\MembershipTier::class, ['name']],
            'warehouse_category_id'  => [\Modules\Warehouse\App\Models\WarehouseCategory::class, ['name']],
            'warehouse_unit_id'      => [\Modules\Warehouse\App\Models\WarehouseUnit::class, ['name']],
            'warehouse_item_id'      => [\Modules\Warehouse\App\Models\WarehouseItem::class, ['name']],
            'order_id'               => [\Modules\Payment\Entities\Order::class, ['order_code']],
            'province_id'            => [\App\Models\Province::class, ['name']],
            'coupon_id'              => [\Modules\Promotion\App\Models\Coupon::class, ['name', 'code']],
            'employee_id'            => [\Modules\Employee\Entities\Employee::class, ['name']],
            'form_id'                => [\Modules\Form\Entities\Form::class, ['name']],
            'component_id'           => [\Modules\Page\Entities\Component::class, ['label', 'name']],
            'page_id'                => [\Modules\Page\Entities\Page::class, ['title']],
            'parent_id'              => [\Modules\Category\Entities\Category::class, ['name']],
            'access_code_id'         => [\Modules\AccessCode\Entities\AccessCode::class, ['code']],
        ];
    }

    // Tra cứu 1 giá trị khoá ngoại ra tên hiển thị — không tìm được (model không tồn tại, đã bị
    // xoá, hoặc field liên quan trống) thì giữ nguyên giá trị gốc, không báo lỗi.
    private function resolveAuditForeignKey($value, array $resolver)
    {
        if (blank($value)) {
            return $value;
        }

        [$modelClass, $labelAttributes] = $resolver;

        if (! class_exists($modelClass)) {
            return $value;
        }

        $related = $modelClass::find($value);
        if (! $related) {
            return $value;
        }

        foreach ($labelAttributes as $attribute) {
            $labelValue = $related->{$attribute} ?? null;
            if (filled($labelValue)) {
                return (string) $labelValue;
            }
        }

        return $value;
    }

    protected static function auditModuleName(): string
    {
        return class_basename(static::class);
    }

    // Nhãn hiển thị cho 1 bản ghi cụ thể (cột "target_label" trong audit_logs) — thử lần lượt các
    // attribute thường dùng làm tên hiển thị, cái nào có giá trị thì dùng luôn.
    protected function auditLabel(): string
    {
        foreach (['name', 'title', 'code', 'label', 'fullname', 'email'] as $attribute) {
            $value = $this->{$attribute} ?? null;
            if (filled($value)) {
                return (string) $value;
            }
        }

        return '#' . $this->getKey();
    }
}
