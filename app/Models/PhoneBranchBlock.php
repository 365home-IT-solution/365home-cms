<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Category\Entities\Category;

// 1 SĐT không được đặt phòng ở 1 chi nhánh gốc — xem App\Services\CustomerBranchBlockService.
class PhoneBranchBlock extends Model
{
    use LogsAuditTrail;

    protected $fillable = ['phone', 'category_id', 'reason', 'blocked_by'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }

    protected function auditLabel(): string
    {
        return $this->phone;
    }
}
