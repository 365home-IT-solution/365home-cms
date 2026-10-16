<?php

namespace Modules\Product\App\Filament\Resources\PriceTemplateResource\Pages;

use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Modules\Product\App\Filament\Resources\PriceTemplateResource;

class CreatePriceTemplate extends CreateRecord
{
    protected static string $resource = PriceTemplateResource::class;

    // Đối tác tạo thì mẫu luôn là của chính họ. Super_admin chọn ở ô "Áp dụng cho": để trống = mẫu hệ
    // thống (mọi đối tác thấy), chọn đối tác = mẫu riêng của đối tác đó.
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        if ($user instanceof User && $user->isSuperAdmin()) {
            $data['partner_id'] = filled($data['partner_id'] ?? null) ? $data['partner_id'] : null;

            return $data;
        }

        $data['partner_id'] = ($user instanceof User && ! $user->seesAllPartners()) ? $user->partner_id : null;

        return $data;
    }
}
