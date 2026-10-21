<?php

declare(strict_types=1);

namespace App\Filament\Resources\PhoneBranchBlockResource\Pages;

use App\Filament\Resources\PhoneBranchBlockResource;
use App\Services\CustomerBranchBlockService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListPhoneBranchBlocks extends ListRecords
{
    protected static string $resource = PhoneBranchBlockResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('block_phones')
                ->label('Thêm chặn SĐT')
                ->icon('heroicon-o-plus')
                ->visible(fn () => PhoneBranchBlockResource::canCreate())
                ->modalHeading('Chặn SĐT đặt phòng theo chi nhánh')
                ->modalDescription('Mỗi item: chọn khách hàng và/hoặc nhập nhiều SĐT, chọn 1 hoặc nhiều chi nhánh. Các SĐT trong item sẽ không đặt được phòng ở các chi nhánh đã chọn (app, web, khách vãng lai, nhân viên đặt hộ).')
                ->modalWidth('4xl')
                ->modalSubmitActionLabel('Lưu')
                ->form(PhoneBranchBlockResource::blockItemsSchema())
                ->action(function (array $data): void {
                    $service = app(CustomerBranchBlockService::class);
                    $saved   = 0;

                    foreach ($data['items'] ?? [] as $item) {
                        $phones = array_merge((array) ($item['customer_phones'] ?? []), (array) ($item['phones'] ?? []));
                        $saved += $service->blockPhones($phones, (array) ($item['branch_ids'] ?? []), $item['reason'] ?? null, auth()->user())['saved'];
                    }

                    Notification::make()->title("Đã lưu {$saved} lượt chặn (SĐT × chi nhánh)")->success()->send();
                }),
        ];
    }
}
