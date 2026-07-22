<?php

declare(strict_types=1);

namespace Modules\User\App\Filament\Resources\UserResource\Pages;

use Modules\User\App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Forms;
use Filament\Support;
use Filament\Support\Enums\Alignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use JoseEspinal\RecordNavigation\Traits\HasRecordNavigation;
use Modules\Employee\Entities\Employee;
use Modules\User\App\Filament\Resources\UserResource\Forms\UserForm;
use Spatie\Permission\Models\Role;

class EditUser extends EditRecord
{
    use HasRecordNavigation;

    protected static string $resource = UserResource::class;

    // Nạp lại field hồ sơ nhân viên (bảng employees) liên kết với tài khoản này vào form —
    // UserForm đã gộp các field này vào tab "Thông tin"/"Thiết lập lương". 'account_type' phản
    // ánh loại tài khoản THỰC TẾ đã tạo (radio bị disabled khi sửa — xem UserForm), không cho
    // đổi qua lại giữa "Nhân viên" và "Chủ đối tác" sau khi đã tạo.
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return UserForm::fillEmployeeData($this->getRecord(), $data);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $authUser   = auth()->user();
        $partnerId  = $authUser->isSuperAdmin() ? ($data['partner_id'] ?? $record->partner_id) : $authUser->partner_id;
        $isEmployee = ($data['account_type'] ?? ($record->employee ? 'employee' : 'partner')) === 'employee';

        $userUpdate               = collect($data)->except([
            'media', 'passwordConfirmation', 'employee_code', 'account_type',
            'work_branch_ids', 'allowances', 'deductions',
        ])->toArray();
        $userUpdate['partner_id'] = $partnerId;

        $record->update($userUpdate);

        if (! $isEmployee) {
            return $record;
        }

        $employeeData               = collect($data)->except([
            'media', 'password', 'passwordConfirmation', 'account_type',
            'work_branch_ids', 'allowances', 'deductions', 'employee_code',
        ])->toArray();
        $employeeData['name']       = $data['fullname'] ?? $record->fullname;
        $employeeData['partner_id'] = $partnerId;

        $employee = $record->employee;

        if ($employee) {
            $employee->update($employeeData);
        } else {
            $employee = Employee::create([
                ...$employeeData,
                'user_id'    => $record->id,
                'created_by' => $authUser->id,
            ]);
        }

        $employee->workBranches()->sync($data['work_branch_ids'] ?? []);

        $employee->allowanceTypes()->sync(
            collect($data['allowances'] ?? [])
                ->filter(fn ($row) => filled($row['id'] ?? null))
                ->mapWithKeys(fn ($row) => [$row['id'] => ['amount' => $row['amount'] ?? 0]])
        );

        $employee->deductionTypes()->sync(
            collect($data['deductions'] ?? [])
                ->filter(fn ($row) => filled($row['id'] ?? null))
                ->mapWithKeys(fn ($row) => [$row['id'] => ['amount' => $row['amount'] ?? 0]])
        );

        return $record;
    }

    // Với Filament, saveRelationships() (đồng bộ field 'roles' đã chọn ở tab Phân quyền) chạy
    // BÊN TRONG $this->form->getState() — tức là chạy TRƯỚC handleRecordUpdate(), rồi afterSave()
    // mới chạy sau cùng. Đảm bảo ở đây: tài khoản "Chủ đối tác" (không có hồ sơ Employee) luôn
    // giữ role 'partner', phòng trường hợp super_admin lỡ bỏ chọn role này khi sửa — nếu không,
    // tài khoản sẽ mất hết role, không đăng nhập được và bị xếp nhầm vào tab "Khách hàng".
    protected function afterSave(): void
    {
        if ($this->record->employee) {
            return;
        }

        $this->record->roles()->syncWithoutDetaching(
            Role::where('name', 'partner')->where('guard_name', 'web')->pluck('id')
        );
    }

    protected function getHeaderActions(): array
    {
        $actions = [
            Actions\ActionGroup::make([
                Actions\EditAction::make()
                    ->label(__('user::user.form.more_action.change_password'))
                    ->form([
                        Forms\Components\TextInput::make('password')
                            ->label(__('user::user.form.action.label.password'))
                            ->password()
                            ->dehydrateStateUsing(fn(string $state): string => Hash::make($state))
                            ->dehydrated(fn(?string $state): bool => filled($state))
                            ->revealable()
                            ->required(),
                        Forms\Components\TextInput::make('passwordConfirmation')
                            ->label(__('user::user.form.action.label.confirm_password'))
                            ->password()
                            ->dehydrateStateUsing(fn(string $state): string => Hash::make($state))
                            ->dehydrated(fn(?string $state): bool => filled($state))
                            ->revealable()
                            ->same('password')
                            ->required(),
                    ])
                    ->modalWidth(Support\Enums\MaxWidth::Medium)
                    ->modalHeading(__('user::user.form.action.update_password'))
                    ->modalDescription(fn($record) => $record->email)
                    ->modalAlignment(Alignment::Center)
                    ->modalCloseButton(false)
                    ->modalSubmitActionLabel(__('user::user.form.action.submit'))
                    ->modalCancelActionLabel(__('user::user.form.action.cancel')),

                Actions\DeleteAction::make()
                    ->extraAttributes(["class" => "border-b"]),

                Actions\CreateAction::make()
                    ->label(__('user::user.form.more_action.create'))
                    ->url(fn(): string => static::$resource::getNavigationUrl() . '/create'),
            ])
                ->icon('heroicon-m-ellipsis-horizontal')
                ->hiddenLabel()
                ->button()
                ->tooltip(__('user::user.form.more_action.more_action'))
                ->color('gray')
        ];

        return array_merge($this->getNavigationActions(), $actions);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    public function getTitle(): string|Htmlable
    {
        $title = $this->record->name;
        $badge = $this->getBadgeStatus();

        return new HtmlString("
            <div class='flex items-center space-x-2'>
                <div>$title</div>
                $badge
            </div>
        ");
    }

    public function getBadgeStatus(): string|Htmlable
    {
        if (empty($this->record->email_verified_at)) {
            $badge = "<span class='inline-flex items-center px-2 py-1 text-xs font-semibold rounded-md text-danger-700 bg-danger-50 ring-1 ring-inset ring-danger-600/20'>Unverified</span>";
        } else {
            $badge = "<span class='inline-flex items-center px-2 py-1 text-xs font-semibold rounded-md text-success-700 bg-success-50 ring-1 ring-inset ring-success-600/20'>Verified</span>";
        }

        return $badge;
    }
}
