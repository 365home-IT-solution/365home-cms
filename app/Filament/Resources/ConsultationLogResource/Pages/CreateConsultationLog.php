<?php

declare(strict_types=1);

namespace App\Filament\Resources\ConsultationLogResource\Pages;

use App\Filament\Resources\ConsultationLogResource;
use Filament\Resources\Pages\CreateRecord;

class CreateConsultationLog extends CreateRecord
{
    protected static string $resource = ConsultationLogResource::class;

    // Không tin field 'employee_id' gửi lên từ nhân viên thường (dù đã ẩn ở form) — luôn ép lại
    // đúng hồ sơ nhân viên của chính tài khoản đang đăng nhập để tránh ghi nhận hộ người khác.
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $currentEmployee = auth()->user()?->employee;

        if ($currentEmployee) {
            $data['employee_id'] = $currentEmployee->id;
        }

        return $data;
    }
}
