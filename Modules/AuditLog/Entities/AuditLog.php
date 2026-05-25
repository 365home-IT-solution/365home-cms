<?php

declare(strict_types=1);

namespace Modules\AuditLog\Entities;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'audit_logs';

    protected $fillable = [
        'user_id',
        'user_name',
        'user_email',
        'performer_role',
        'action',
        'module',
        'target_id',
        'target_label',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    // Nhãn hiển thị cho module
    public static function moduleLabels(): array
    {
        return [
            'Role'                 => 'Vai trò',
            'User'                 => 'Nhân viên',
            'UserBranchPermission' => 'Phân quyền chi nhánh',
            'Post'                 => 'Bài viết',
            'Product'              => 'Phòng',
            'Order'                => 'Booking',
            'Branch'               => 'Chi nhánh',
            'Coupon'               => 'Mã giảm giá',
            'Promotion'            => 'Khuyến mãi',
        ];
    }

    // Nhãn hiển thị cho action
    public static function actionLabels(): array
    {
        return [
            'create' => 'Tạo mới',
            'update' => 'Cập nhật',
            'delete' => 'Xóa',
        ];
    }

    public function getModuleLabelAttribute(): string
    {
        return static::moduleLabels()[$this->module] ?? $this->module;
    }

    public function getActionLabelAttribute(): string
    {
        return static::actionLabels()[$this->action] ?? $this->action;
    }
}
