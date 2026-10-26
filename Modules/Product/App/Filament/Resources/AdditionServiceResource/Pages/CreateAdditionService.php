<?php

namespace Modules\Product\App\Filament\Resources\AdditionServiceResource\Pages;

use App\Services\BranchServiceSync;
use Filament\Resources\Pages\CreateRecord;
use Modules\Category\Entities\Category;
use Modules\Product\App\Filament\Resources\AdditionServiceResource;

class CreateAdditionService extends CreateRecord
{
    protected static string $resource = AdditionServiceResource::class;

    // Super admin tạo hộ: dịch vụ thuộc đối tác của chi nhánh được chọn (không phải đối tác của
    // người tạo) — nếu không, dịch vụ mang partner_id của super admin rồi gắn vào chi nhánh đối tác
    // khác, BranchServiceSync bỏ qua liên kết chéo đó và phòng không nhận được dịch vụ.
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        if ($user && $user->seesAllPartners()) {
            $partnerId = Category::withoutGlobalScopes()
                ->whereIn('id', (array) ($this->data['branches'] ?? []))
                ->value('partner_id');

            if ($partnerId) {
                $data['partner_id'] = $partnerId;
            }
        }

        return $data;
    }

    // Ô "Chi nhánh áp dụng" được Filament sync pivot SAU khi tạo bản ghi — đồng bộ phòng ở đây.
    protected function afterCreate(): void
    {
        app(BranchServiceSync::class)->syncBranches($this->record->branches()->pluck('categories.id')->all());
    }
}
