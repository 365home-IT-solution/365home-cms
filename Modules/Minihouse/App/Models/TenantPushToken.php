<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Push token (Web Push/FCM hoặc Expo) của 1 khách thuê — xem migration create_minihouse_tenant_
// push_tokens_table để biết lý do tách hẳn khỏi bảng push token của Home.
class TenantPushToken extends Model
{
    protected $table = 'minihouse_tenant_push_tokens';

    protected $fillable = ['tenant_id', 'token', 'platform'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
