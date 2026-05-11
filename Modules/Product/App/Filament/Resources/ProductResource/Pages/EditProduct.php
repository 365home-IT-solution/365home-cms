<?php
declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\ProductResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Modules\Product\App\Filament\Resources\ProductResource;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
        ];
    }

    protected function afterFill(): void
    {
        $data = $this->record->toArray();
        $data['categories'] = $this->record->categories->map(function ($category) {
            return [
                'id' => $category->id,
                'name' => $category->name,
            ];
        })->toArray();

        $this->form->fill($data);
    }
}