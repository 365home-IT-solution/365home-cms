<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    protected $fillable = ['name', 'guard_name', 'created_by'];

    // Tài khoản đã tạo role này — role của đối tác thì created_by = tài khoản chủ đối tác đó
    // (xem UserForm::createRolesTab()); từ đó suy ra đối tác qua creator->partner.
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
