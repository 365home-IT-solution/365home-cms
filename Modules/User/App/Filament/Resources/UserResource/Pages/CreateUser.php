<?php

declare(strict_types=1);

namespace Modules\User\App\Filament\Resources\UserResource\Pages;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Employee\Entities\Employee;
use Modules\User\App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\Models\Role;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    // Đánh dấu loại tài khoản vừa tạo — dùng lại ở afterCreate() để ép role 'partner'.
    private bool $isEmployeeAccount = true;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    // Trang này tạo 1 trong 2 loại tài khoản (chọn ở field 'account_type', xem UserForm):
    // - 'employee' (Nhân viên): tạo ĐỒNG THỜI 1 User (đăng nhập) + 1 Employee (hồ sơ) liên kết.
    // - 'partner' (Chủ đối tác): CHỈ tạo 1 User, không có hồ sơ Employee. Chỉ super_admin được
    //   phép tạo loại này — đối tác/nhân viên đăng nhập không có quyền tạo đối tác khác, nên ép
    //   'employee' bất kể giá trị gửi lên (phòng trường hợp field ẩn bị can thiệp từ client).
    protected function handleRecordCreation(array $data): Model
    {
        $authUser   = auth()->user();
        $partnerId  = $authUser->isSuperAdmin() ? ($data['partner_id'] ?? null) : $authUser->partner_id;
        $isEmployee = $authUser->isSuperAdmin()
            ? (($data['account_type'] ?? 'employee') === 'employee')
            : true;

        $this->isEmployeeAccount = $isEmployee;

        $userData                       = collect($data)->except([
            'media', 'passwordConfirmation', 'employee_code', 'account_type',
            'work_branch_ids', 'allowances', 'deductions',
        ])->toArray();
        $userData['partner_id']         = $partnerId;
        $userData['created_by']         = $authUser->id;
        $userData['email_verified_at']  = now();

        $user = User::create($userData);

        if (! $isEmployee) {
            return $user;
        }

        $employeeData               = collect($data)->except([
            'media', 'password', 'passwordConfirmation', 'account_type',
            'work_branch_ids', 'allowances', 'deductions', 'employee_code',
        ])->toArray();
        $employeeData['name']       = $data['fullname'] ?? $user->fullname;
        $employeeData['partner_id'] = $partnerId;
        $employeeData['user_id']    = $user->id;
        $employeeData['created_by'] = $authUser->id;

        $employee = Employee::create($employeeData);

        if (! empty($data['work_branch_ids'])) {
            $employee->workBranches()->sync($data['work_branch_ids']);
        }

        if (! empty($data['allowances'])) {
            $employee->allowanceTypes()->sync(
                collect($data['allowances'])
                    ->filter(fn ($row) => filled($row['id'] ?? null))
                    ->mapWithKeys(fn ($row) => [$row['id'] => ['amount' => $row['amount'] ?? 0]])
            );
        }

        if (! empty($data['deductions'])) {
            $employee->deductionTypes()->sync(
                collect($data['deductions'])
                    ->filter(fn ($row) => filled($row['id'] ?? null))
                    ->mapWithKeys(fn ($row) => [$row['id'] => ['amount' => $row['amount'] ?? 0]])
            );
        }

        return $user;
    }

    // Filament chạy saveRelationships() (đồng bộ field 'roles' đã chọn ở tab Phân quyền) SAU
    // handleRecordCreation() nhưng TRƯỚC afterCreate() — nên chỉ có thể đảm bảo "Chủ đối tác"
    // luôn có role 'partner' ở đây. Nếu không, khi super_admin quên chọn role 'partner' thủ
    // công ở tab Phân quyền, tài khoản sẽ không có role nào → không đăng nhập được
    // (User::canAccessPanel() yêu cầu ít nhất 1 role thật) và bị xếp nhầm vào tab "Khách hàng"
    // trong danh sách tài khoản.
    protected function afterCreate(): void
    {
        if (! $this->isEmployeeAccount) {
            $this->record->roles()->syncWithoutDetaching(
                Role::where('name', 'partner')->where('guard_name', 'web')->pluck('id')
            );
        }

        $this->logAssignedRoles();
    }

    // 'roles' là Select ->relationship()->multiple() — pivot được Filament tự đồng bộ ở
    // saveRelationships(), sau khi record đã tồn tại, nên UserObserver::created() (chỉ log
    // fullname/email/phone) không thấy được vai trò vừa gán. Ghi thêm 1 dòng log riêng.
    private function logAssignedRoles(): void
    {
        $record  = $this->record->fresh(['roles']);
        $roleIds = $record->roles->pluck('id')->all();

        if (empty($roleIds)) {
            return;
        }

        AuditLogger::log(
            action: 'update',
            module: 'User',
            record: $record,
            old: [],
            new: ['vai_tro_da_them' => Role::whereIn('id', $roleIds)->pluck('name')->implode(', ')],
            label: $record->fullname ?? $record->email,
        );
    }
}
