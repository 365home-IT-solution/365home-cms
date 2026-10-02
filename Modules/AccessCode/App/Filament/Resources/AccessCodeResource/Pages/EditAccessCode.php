<?php

declare(strict_types=1);

namespace Modules\AccessCode\App\Filament\Resources\AccessCodeResource\Pages;

use Modules\AccessCode\App\Filament\Resources\AccessCodeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAccessCode extends EditRecord
{
    protected static string $resource = AccessCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->successRedirectUrl(fn () => \App\Filament\Pages\GateLockManagement::getUrl()),
        ];
    }

    // Bảng Pass Cổng giờ nằm trong trang gộp "Khóa cổng" — lưu xong quay về đó.
    protected function getRedirectUrl(): string
    {
        return \App\Filament\Pages\GateLockManagement::getUrl();
    }
}