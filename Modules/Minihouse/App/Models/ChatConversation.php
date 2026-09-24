<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Category\Entities\Category;

// Cuộc trò chuyện khách thuê <-> nhân viên toà nhà — mirror App\Models\ChatConversation (Home).
// CHỈ dành cho khách ĐÃ KÝ HỢP ĐỒNG (tenant_id bắt buộc, unique — 1 khách thuê đúng 1 conversation
// trong suốt vòng đời) — khách tiềm năng/chưa ký hợp đồng KHÔNG chat, xem
// Modules\Minihouse\App\Services\MinihouseChatService.
class ChatConversation extends Model
{
    use HasUlids;

    protected $table = 'minihouse_chat_conversations';

    public const STATUS_OPEN   = 'open';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'tenant_id', 'building_id', 'contract_id', 'status',
        'last_message_preview', 'last_message_at', 'admin_unread', 'tenant_unread',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'admin_unread'    => 'integer',
        'tenant_unread'   => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'building_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id')->orderBy('created_at');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(ChatMessage::class, 'conversation_id')->latestOfMany();
    }
}
