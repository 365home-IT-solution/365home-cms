<?php

declare(strict_types=1);

namespace Modules\Tag\App\Filament\Resources\TagResource\Pages;

use Modules\Tag\App\Filament\Resources\TagResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTag extends ListRecords
{
    protected static string $resource = TagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Đối tác tạo = tiện ích riêng của đối tác (type product); super_admin không chọn đối tác
            // tạo = mẫu hệ thống dùng chung.
            Actions\CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $user = auth()->user();

                    if ($user && ! $user->seesAllPartners()) {
                        $data['partner_id'] = $user->partner_id;
                        $data['type']       = 'product';
                    }

                    return $data;
                }),
        ];
    }
}
