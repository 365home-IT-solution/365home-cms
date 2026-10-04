<?php

declare(strict_types=1);

namespace App\Filament\Resources\TermsVersionResource\Pages;

use App\Filament\Resources\TermsVersionResource;
use App\Models\TermsVersion;
use App\Services\TermsService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class CreateTermsVersion extends CreateRecord
{
    protected static string $resource = TermsVersionResource::class;

    protected static ?string $title = 'Tạo phiên bản Điều khoản mới';

    // Đi qua TermsService để băm nội dung, kiểm tra trùng số phiên bản và gắn người tạo — không bao giờ ghi đè bản cũ.
    protected function handleRecordCreation(array $data): Model
    {
        return app(TermsService::class)->createVersion(
            TermsVersion::typeForCurrentPanel(), $data['title'], $data['content'], $data['version'] ?? null,
            isset($data['effective_at']) ? Carbon::parse($data['effective_at']) : null, auth()->user(),
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
