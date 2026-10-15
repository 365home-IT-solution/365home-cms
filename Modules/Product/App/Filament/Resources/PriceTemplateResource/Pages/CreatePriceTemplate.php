<?php

namespace Modules\Product\App\Filament\Resources\PriceTemplateResource\Pages;

use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Modules\Product\App\Filament\Resources\PriceTemplateResource;

class CreatePriceTemplate extends CreateRecord
{
    protected static string $resource = PriceTemplateResource::class;

    // Mẫu thuộc đối tác đang đăng nhập; super_admin (không chọn đối tác) tạo mẫu hệ thống.
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        $data['partner_id'] = ($user instanceof User && ! $user->seesAllPartners()) ? $user->partner_id : null;

        return $data;
    }
}
