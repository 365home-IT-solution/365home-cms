<?php

declare(strict_types=1);

namespace App\Filament\Resources\TermsVersionResource\Pages;

use App\Filament\Resources\TermsVersionResource;
use App\Models\TermsVersion;
use App\Services\TermsService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListTermsVersions extends ListRecords
{
    protected static string $resource = TermsVersionResource::class;

    public function getSubheading(): ?string
    {
        return app(TermsService::class)->required(TermsVersion::typeForCurrentPanel())
            ? 'Đang BẮT BUỘC khách tick đồng ý Điều khoản khi đăng ký.'
            : 'Đang TẮT: đăng ký không yêu cầu đồng ý Điều khoản và không ghi lịch sử đồng ý.';
    }

    protected function getHeaderActions(): array
    {
        $type = TermsVersion::typeForCurrentPanel();
        $required = app(TermsService::class)->required($type);

        return [
            // Công tắc bắt buộc đồng ý Điều khoản khi đăng ký (riêng từng panel). Tắt: đăng ký chạy như trước, dữ liệu phiên bản/lịch sử đã có vẫn giữ nguyên.
            Action::make('toggleRequired')
                ->label($required ? 'Tắt yêu cầu đồng ý Điều khoản' : 'Bật yêu cầu đồng ý Điều khoản')
                ->icon($required ? 'heroicon-o-no-symbol' : 'heroicon-o-check-circle')
                ->color($required ? 'danger' : 'success')
                ->requiresConfirmation()
                ->modalHeading($required ? 'Tắt yêu cầu đồng ý Điều khoản?' : 'Bật yêu cầu đồng ý Điều khoản?')
                ->modalDescription($required
                    ? 'Khách đăng ký sẽ KHÔNG còn thấy ô tick Điều khoản và API đăng ký không đòi accept_terms; không ghi thêm lịch sử đồng ý. Phiên bản và lịch sử đã có được giữ nguyên.'
                    : 'Khách đăng ký sẽ bắt buộc tick đồng ý Điều khoản (phiên bản hiệu lực) mới đăng ký được; mỗi lần đồng ý được ghi lịch sử.')
                ->action(function () use ($type, $required): void {
                    app(TermsService::class)->setRequired($type, ! $required);
                    Notification::make()->title($required ? 'Đã TẮT yêu cầu đồng ý Điều khoản.' : 'Đã BẬT yêu cầu đồng ý Điều khoản.')->success()->send();
                    $this->redirect(static::getResource()::getUrl('index'));
                })
                ->visible(fn () => auth()->user()?->isSuperAdmin() ?? false),
            CreateAction::make()->label('Tạo phiên bản mới'),
        ];
    }
}
