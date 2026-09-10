<?php

namespace Modules\Minihouse\App\Models;

use Filament\Models\Contracts\HasName;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;
use Modules\Minihouse\App\Models\Concerns\ScopedToActiveBuildingViaRoom;

// Portal khách thuê (đăng nhập bằng OTP qua SĐT, KHÔNG có mật khẩu — xem TenantOtpService) — Tenant
// giờ CŨNG là 1 "tài khoản đăng nhập" qua guard riêng "tenant" (xem config/auth.php), tách biệt HOÀN
// TOÀN khỏi guard "web" (App\Models\User, nhân viên/chủ nhà). Authenticatable (trait chuẩn của
// Laravel) chỉ cần cột remember_token có sẵn — KHÔNG cần getAuthPassword() vì đăng nhập không qua
// mật khẩu, chỉ dùng Auth::guard('tenant')->login($tenant) trực tiếp sau khi xác minh OTP đúng.
//
// implements HasName: Laravel's Authenticate middleware tự gọi Auth::shouldUse('tenant') ngay khi
// 1 route auth:tenant xác thực thành công — đổi GUARD MẶC ĐỊNH của CẢ REQUEST đó sang "tenant" (hành
// vi chuẩn, có chủ đích của Laravel để code gọi auth()->user() không cần chỉ rõ guard vẫn đúng theo
// ngữ cảnh request). Hệ quả: package z3d0x/filament-logger tự ghi log MỌI thay đổi model (VD Invoice
// khi khách thanh toán ở Portal) bằng auth()->user() KHÔNG chỉ rõ guard, rồi gọi thẳng
// Filament::getUserName($user) — hàm này ép kiểu trả về "string", ném TypeError nếu $user không
// implement HasName và cũng không có cột "name" (Tenant chỉ có "fullname"). Không implement thì MỌI
// hành động của khách thuê ở Portal làm thay đổi 1 model có bật filament-logger (Invoice, Contract,
// ...) đều 500 lỗi này — xác nhận thật khi test thanh toán MoMo/VNPay qua Portal.
class Tenant extends Model implements AuthenticatableContract, HasName
{
    use Authenticatable;
    // HasApiTokens — cho phép Tenant tự có token Sanctum RIÊNG (bảng personal_access_tokens vốn đã
    // đa hình sẵn qua tokenable_type/tokenable_id, không cần migration mới) để API Portal khách thuê
    // (app di động/bên thứ 3) đăng nhập bằng Bearer token thay vì cookie phiên như web — hoàn toàn
    // TÁCH BIỆT khỏi token của App\Models\User (nhân viên), dù dùng chung 1 bảng vật lý.
    use HasApiTokens;
    use SoftDeletes;
    use ScopedToActiveBuildingViaRoom;
    use LogsMinihouseActivity;

    public const GENDER_MALE   = 'nam';
    public const GENDER_FEMALE = 'nu';
    public const GENDER_OTHER  = 'khac';

    protected $table = 'minihouse_tenants';

    protected $fillable = [
        'fullname', 'phone', 'password', 'id_card_number', 'id_card_front', 'id_card_back',
        'date_of_birth', 'gender', 'hometown', 'permanent_address', 'occupation', 'workplace',
        'emergency_contact_name', 'emergency_contact_phone',
        'residence_declared', 'residence_declared_at',
        'room_id', 'note',
    ];

    // 'password' KHÔNG bao giờ hiện trong form quản trị (TenantForm không có field này) — chỉ được
    // ghi qua đúng 1 nơi: TenantPortalController::updatePassword(), khách thuê tự đặt cho CHÍNH mình
    // sau khi đã đăng nhập (bằng OTP hoặc mật khẩu cũ). Nullable — khách chưa từng đặt vẫn đăng nhập
    // OTP bình thường, Portal luôn cho chọn 1 trong 2 cách (xem TenantAuthController).
    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
        'date_of_birth'          => 'date',
        'residence_declared'     => 'boolean',
        'residence_declared_at'  => 'date',
        'password'               => 'hashed',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    // Xem giải thích "implements HasName" ở đầu file — chỉ dùng để filament-logger không vỡ khi ghi
    // log lúc khách thuê đang đăng nhập Portal, KHÔNG liên quan gì đến hiển thị trong panel Filament
    // (Tenant chưa từng và không đăng nhập được panel nào).
    public function getFilamentName(): string
    {
        return $this->fullname;
    }

    // Hợp đồng đứng tên chính (Contract.tenant_id trỏ thẳng tới khách này) — dùng cho các nghiệp
    // vụ cần biết "đang đứng tên hợp đồng nào" (vd đồng bộ Tenant.room_id).
    public function primaryContracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    // TOÀN BỘ hợp đồng khách này có liên quan — đứng tên chính LẪN ở cùng — dùng cho "Lịch sử
    // thuê" và các báo cáo chung, vì người ở cùng giờ cũng là Khách thuê thật (xem
    // App\Models\ContractTenant).
    public function contracts(): BelongsToMany
    {
        return $this->belongsToMany(Contract::class, 'minihouse_contract_tenants')
            ->withPivot(['role', 'relationship_to_primary']);
    }

    // Hợp đồng "Đang hiệu lực" của khách này (đứng tên chính HOẶC ở cùng) — cùng logic đúng như
    // ContractObserver::syncTenant() dùng để tính room_id ở trên, để nút "Đi đến hợp đồng" ở trang
    // Sửa khách thuê luôn trỏ đúng hợp đồng đang khiến room_id được đồng bộ như hiện tại.
    public function activeContract(): ?Contract
    {
        $activeContractId = ContractTenant::query()
            ->join('minihouse_contracts', 'minihouse_contracts.id', '=', 'minihouse_contract_tenants.contract_id')
            ->where('minihouse_contract_tenants.tenant_id', $this->id)
            ->where('minihouse_contracts.status', Contract::STATUS_ACTIVE)
            ->whereNull('minihouse_contracts.deleted_at')
            ->orderByDesc('minihouse_contracts.start_date')
            ->value('minihouse_contract_tenants.contract_id');

        return $activeContractId ? Contract::withoutGlobalScopes()->find($activeContractId) : null;
    }
}
