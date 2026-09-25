<?php

namespace Modules\Payment\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Category\Entities\Category;

// Tài khoản PayOS riêng của 1 chi nhánh Homestay — xem migration create_branch_payos_accounts_table
// và App\Services\Payment\PayOsAccountResolver. Cố tình KHÔNG dùng LogsAuditTrail: audit log lưu
// nguyên giá trị cột, sẽ làm lộ api_key/checksum_key đã giải mã ra bảng log.
class BranchPayOsAccount extends Model
{
    protected $table = 'branch_payos_accounts';

    protected $fillable = [
        'category_id',
        'is_active',
        'client_id',
        'api_key',
        'checksum_key',
        'account_holder',
        'note',
        'webhook_confirmed_at',
    ];

    protected $casts = [
        'is_active'            => 'boolean',
        'client_id'            => 'encrypted',
        'api_key'              => 'encrypted',
        'checksum_key'         => 'encrypted',
        'webhook_confirmed_at' => 'datetime',
    ];

    protected $hidden = ['api_key', 'checksum_key'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function isComplete(): bool
    {
        return filled($this->client_id) && filled($this->api_key) && filled($this->checksum_key);
    }

    /** @return array{0: string, 1: string, 2: string} */
    public function credentials(): array
    {
        return [$this->client_id, $this->api_key, $this->checksum_key];
    }
}
