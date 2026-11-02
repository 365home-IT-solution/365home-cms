<?php

namespace Modules\Payment\Entities;

use App\Models\Partner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerSePayAccount extends Model
{
    // Tên bảng theo migration (Eloquent tự suy ra "se_pay" nên không khớp).
    protected $table = 'partner_sepay_accounts';

    public const PROVIDER_SEPAY = 'sepay';

    public const PROVIDER_PAY2S = 'pay2s';

    // Không dùng cổng báo biến động số dư: khách vẫn chuyển khoản vào tài khoản đối tác (QR như SePay/Pay2S) nhưng đối tác tự bấm "xác nhận đã nhận tiền".
    public const PROVIDER_MANUAL = 'manual';

    public const PROVIDERS = [self::PROVIDER_SEPAY => 'SePay', self::PROVIDER_PAY2S => 'Pay2S', self::PROVIDER_MANUAL => 'Xác nhận thủ công'];

    public function isManual(): bool
    {
        return $this->provider === self::PROVIDER_MANUAL;
    }

    // category_id: trống = tài khoản mặc định của cả đối tác; có giá trị = tài khoản riêng của chi nhánh (và các khu vực con) đó.
    protected $fillable = ['partner_id', 'category_id', 'provider', 'is_active', 'bank_code', 'bank_bin', 'account_number', 'account_holder', 'note', 'webhook_token', 'updated_by'];

    // webhook_token: token Pay2S sinh cho webhook của tài khoản này (mã hoá khi lưu, không bao giờ trả ra API/form).
    protected $casts = ['is_active' => 'boolean', 'webhook_token' => \App\Casts\TolerantEncrypted::class];

    protected $hidden = ['webhook_token'];

    public function providerLabel(): string
    {
        return self::PROVIDERS[$this->provider] ?? ucfirst((string) $this->provider);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(\Modules\Category\Entities\Category::class)->withoutGlobalScopes();
    }

    /** Tên phạm vi áp dụng để hiển thị: tên chi nhánh, hoặc "Cả đối tác" với tài khoản mặc định. */
    public function scopeLabel(): string
    {
        return $this->category_id ? ($this->category?->name ?: 'Chi nhánh #' . $this->category_id) : 'Cả đối tác (mặc định)';
    }
}
