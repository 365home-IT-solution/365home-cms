<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Nhật ký mở/tải link chia sẻ hợp đồng — căn cứ khi cần biết ai đã xem/tải hợp đồng, lúc nào.
class ContractShareLinkAccess extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = [
        'view' => 'Mở link',
        'preview' => 'Xem file',
        'download' => 'Tải xuống',
        'password_failed' => 'Nhập sai mật khẩu',
    ];

    protected $fillable = ['contract_share_link_id', 'action', 'ip', 'user_agent'];

    public function link(): BelongsTo
    {
        return $this->belongsTo(ContractShareLink::class, 'contract_share_link_id');
    }
}
