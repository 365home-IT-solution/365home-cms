<?php

declare(strict_types=1);

namespace Modules\Category\App\Filament\Resources\CategoryResource\Pages;

use Modules\Category\App\Filament\Resources\CategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ListRecords\Tab;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Builder;

class ListCategory extends ListRecords
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->modalWidth(MaxWidth::Full)
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

    public function getTabs(): array
    {
        $baseQuery = static::getResource()::getEloquentQuery();

        return [
            'product' => Tab::make('Phòng')
                ->icon('heroicon-o-home')
                ->badge($baseQuery->clone()->where('category_type', 'product')->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('category_type', 'product')),

            'post' => Tab::make('Bài viết')
                ->icon('heroicon-o-document-text')
                ->badge($baseQuery->clone()->where('category_type', 'post')->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('category_type', 'post')),
        ];
    }
}
