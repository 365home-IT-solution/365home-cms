<?php

namespace Modules\Payment\App\Filament\Resources\BranchPayOsAccountResource\Pages;

use App\Models\Partner;
use App\Services\Payment\PartnerPayOsChannelService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Category\Entities\Category;
use Modules\Payment\App\Filament\Resources\BranchPayOsAccountResource;

class CreateBranchPayOsAccount extends CreateRecord
{
    protected static string $resource = BranchPayOsAccountResource::class;

    // Lưu qua service dùng chung với API: gọi thử PayOS, đối chiếu chủ tài khoản, ghi lịch sử hồ sơ, đăng ký webhook. Chi nhánh không thuộc đối tác nào
    // (của hệ thống) không có chủ để đối chiếu nên giữ cách lưu trực tiếp như trước.
    protected function handleRecordCreation(array $data): Model
    {
        $branch = Category::query()->findOrFail($data['category_id']);
        $partner = $branch->partner_id ? Partner::query()->find($branch->partner_id) : null;

        if (! $partner) {
            return parent::handleRecordCreation($data);
        }

        return app(PartnerPayOsChannelService::class)->saveBranch($partner, $branch, $data, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
