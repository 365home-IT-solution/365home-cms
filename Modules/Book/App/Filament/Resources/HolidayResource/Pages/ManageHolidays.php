<?php

declare(strict_types=1);

namespace Modules\Book\App\Filament\Resources\HolidayResource\Pages;

use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Modules\Book\App\Filament\Resources\HolidayResource;

class ManageHolidays extends ManageRecords
{
    protected static string $resource = HolidayResource::class;

    // Filament chỉ ẩn menu theo canViewAny(), không chặn vào thẳng URL — tự chặn ở đây.
    protected function authorizeAccess(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    /** @var User $user */
                    $user = auth()->user();

                    // Super_admin chưa chọn đối tác → ngày lễ DÙNG CHUNG; còn lại là ngày lễ riêng của đối tác đang làm việc.
                    return HolidayResource::toStorageData($data) + [
                        'partner_id' => $user->seesAllPartners() ? null : ($user->partner_id ? (string) $user->partner_id : null),
                    ];
                }),
        ];
    }
}
