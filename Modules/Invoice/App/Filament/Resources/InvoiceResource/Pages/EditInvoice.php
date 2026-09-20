<?php

declare(strict_types=1);

namespace Modules\Invoice\App\Filament\Resources\InvoiceResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Modules\Invoice\App\Filament\Resources\InvoiceResource;
use Modules\Invoice\App\Filament\Support\InvoicePrinter;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadDraft')
                ->label('Tải PDF (bản nháp)')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => InvoicePrinter::draft($this->record)),
        ];
    }
}
