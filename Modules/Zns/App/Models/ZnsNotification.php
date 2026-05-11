<?php

namespace Modules\Zns\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AccessCode\Entities\AccessCode;
use Modules\Payment\Entities\Order;

class ZnsNotification extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'order_id',
        'access_code_id',
        'phone_number',
        'recipient_name',
        'template_id',
        'template_name',
        'template_data',
        'status',
        'zns_message_id',
        'error_code',
        'error_message',
        'response_data',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
        'retry_count',
        'max_retries',
        'next_retry_at',
        'notification_type',
        'metadata',
        'admin_notes',
    ];

    protected $casts = [
        'template_data' => 'array',
        'response_data' => 'array',
        'metadata' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'failed_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'retry_count' => 'integer',
        'max_retries' => 'integer',
        'error_code' => 'integer',
    ];

    protected $appends = [
        'status_label',
        'status_color',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function accessCode()
    {
        return $this->belongsTo(AccessCode::class);
    }

    /**
     * Scope: Chỉ lấy notifications đang chờ gửi
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope: Chỉ lấy notifications đã gửi thành công
     */
    public function scopeSent($query)
    {
        return $query->where('status', 'sent');
    }

    /**
     * Scope: Chỉ lấy notifications thất bại
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope: Chỉ lấy notifications đã được giao
     */
    public function scopeDelivered($query)
    {
        return $query->where('status', 'delivered');
    }

    /**
     * Scope: Lấy notifications cần retry
     */
    public function scopeNeedsRetry($query)
    {
        return $query->where('status', 'failed')
                    ->where('retry_count', '<', 'max_retries')
                    ->where(function($q) {
                        $q->whereNull('next_retry_at')
                          ->orWhere('next_retry_at', '<=', now());
                    });
    }

    /**
     * Scope: Lọc theo loại notification
     */
    public function scopeByType($query, $type)
    {
        return $query->where('notification_type', $type);
    }

    /**
     * Scope: Lấy notifications trong khoảng thời gian
     */
    public function scopeDateRange($query, $from, $to)
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    /**
     * Scope: Lấy notifications của hôm nay
     */
    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }

    /**
     * Scope: Lấy notifications của tháng này
     */
    public function scopeThisMonth($query)
    {
        return $query->whereYear('created_at', now()->year)
                    ->whereMonth('created_at', now()->month);
    }

    public function getStatusLabelAttribute()
    {
        return match($this->status) {
            'pending' => 'Chờ gửi',
            'sent' => 'Đã gửi',
            'delivered' => 'Đã nhận',
            'read' => 'Đã đọc',
            'failed' => 'Thất bại',
            default => 'Không xác định',
        };
    }

    public function getStatusColorAttribute()
    {
        return match($this->status) {
            'pending' => 'warning',
            'sent' => 'info',
            'delivered' => 'success',
            'read' => 'primary',
            'failed' => 'danger',
            default => 'secondary',
        };
    }

    public function getFormattedPhoneAttribute()
    {
        $phone = $this->phone_number;
        // Convert 84xxxxxxxxx to 0xxxxxxxxx
        if (substr($phone, 0, 2) === '84') {
            $phone = '0' . substr($phone, 2);
        }
        // Format: 0901 234 567
        if (strlen($phone) === 10) {
            return substr($phone, 0, 4) . ' ' . substr($phone, 4, 3) . ' ' . substr($phone, 7);
        }
        
        return $phone;
    }

    /**
     * Accessor: Kiểm tra có đang trong retry window không
     */
    public function getIsInRetryWindowAttribute()
    {
        return $this->status === 'failed' 
            && $this->next_retry_at 
            && $this->next_retry_at->isFuture();
    }

    /**
     * Đánh dấu notification là đã gửi thành công
     */
    public function markAsSent($messageId, $responseData = null)
    {
        $this->update([
            'status' => 'sent',
            'zns_message_id' => $messageId,
            'response_data' => $responseData,
            'sent_at' => now(),
            'failed_at' => null,
            'error_code' => null,
            'error_message' => null,
        ]);

        return $this;
    }

    /**
     * Đánh dấu notification là thất bại
     */
    public function markAsFailed($errorMessage, $errorCode = null, $responseData = null)
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'error_code' => $errorCode,
            'response_data' => $responseData,
            'failed_at' => now(),
            'retry_count' => $this->retry_count + 1,
            'next_retry_at' => $this->calculateNextRetryTime(),
        ]);

        return $this;
    }

    /**
     * Đánh dấu notification là đã giao (delivered)
     */
    public function markAsDelivered()
    {
        $this->update([
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);

        return $this;
    }

    /**
     * Đánh dấu notification là đã đọc
     */
    public function markAsRead()
    {
        $this->update([
            'status' => 'read',
            'read_at' => now(),
        ]);

        return $this;
    }

    /**
     * Kiểm tra có thể retry không
     */
    public function canRetry()
    {
        return $this->status === 'failed' 
            && $this->retry_count < $this->max_retries
            && (!$this->next_retry_at || $this->next_retry_at->isPast());
    }

    /**
     * Reset để retry lại
     */
    public function resetForRetry()
    {
        if (!$this->canRetry()) {
            return false;
        }

        $this->update([
            'status' => 'pending',
            'error_message' => null,
            'error_code' => null,
            'failed_at' => null,
            'next_retry_at' => null,
        ]);
        return true;
    }

    /**
     * Tính thời gian retry tiếp theo (exponential backoff)
     */
    protected function calculateNextRetryTime()
    {
        if ($this->retry_count >= $this->max_retries) {
            return null;
        }

        // Exponential backoff: 5 min, 15 min, 30 min, 60 min
        $delays = [5, 15, 30, 60];
        $delayMinutes = $delays[$this->retry_count] ?? 60;
        
        return now()->addMinutes($delayMinutes);
    }

    /**
     * Lấy thông tin template data dạng formatted
     */
    public function getFormattedTemplateData()
    {
        if (!is_array($this->template_data)) {
            return [];
        }

        $formatted = [];
        foreach ($this->template_data as $key => $value) {
            $formatted[] = [
                'key' => $key,
                'value' => is_array($value) ? json_encode($value) : $value,
            ];
        }

        return $formatted;
    }

    /**
     * Kiểm tra notification có hết hạn không (quá 1 ngày không gửi được)
     */
    public function isExpired()
    {
        return $this->status === 'pending' 
            && $this->created_at->addDays(1)->isPast();
    }

    /**
     * Đánh dấu là expired nếu quá lâu không gửi được
     */
    public function markAsExpiredIfNeeded()
    {
        if ($this->isExpired()) {
            $this->update([
                'status' => 'failed',
                'error_message' => 'Thông báo đã hết hạn sau 1 ngày chờ gửi',
                'failed_at' => now(),
            ]);
            
            return true;
        }

        return false;
    }
}
