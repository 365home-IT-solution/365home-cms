<?php

declare(strict_types=1);

namespace Modules\Category\App\Filament\Resources\CategoryResource\Pages;

use Modules\Category\App\Filament\Resources\CategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\MaxWidth;

class ListCategory extends ListRecords
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->modalWidth(MaxWidth::FourExtraLarge)
                // Tài khoản đối tác/nhân viên tạo chi nhánh: partner_id LUÔN lấy theo tài khoản
                // đang đăng nhập (ghi đè giá trị từ form nếu có) — field "Đối tác sở hữu" trên
                // form chỉ hiện cho super_admin nên user thường không tự chọn được.
                ->mutateFormDataUsing(function (array $data): array {
                    $user = auth()->user();

                    if ($user && ! $user->isSuperAdmin()) {
                        $data['partner_id'] = $user->partner_id;
                    }

                    return $data;
                }),
        ];
    }
}
