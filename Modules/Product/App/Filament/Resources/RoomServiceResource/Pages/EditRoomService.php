<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomServiceResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Product\App\Filament\Resources\RoomServiceResource;
use Modules\Product\App\Models\RoomService;

class EditRoomService extends EditRecord
{
    protected static string $resource = RoomServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Placeholder::make('product_name')
                ->label('Phòng')
                ->content(fn () => $this->getRecord()?->product?->name ?? '—')
                ->columnSpanFull(),

            Repeater::make('services')
                ->label('Danh sách dịch vụ')
                ->schema([
                    TextInput::make('name')
                        ->label('Tên dịch vụ')
                        ->placeholder('VD: Coffee, Nước suối, Khăn tắm thêm')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(2),

                    TextInput::make('icon')
                        ->label('Icon name')
                        ->placeholder('VD: coffee, water, towel')
                        ->maxLength(150),

                    TextInput::make('price')
                        ->label('Giá')
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->suffix('VND'),

                    TextInput::make('unit')
                        ->label('Đơn vị')
                        ->placeholder('VD: người/ngày, lần, ly')
                        ->maxLength(100),

                    TextInput::make('sort_order')
                        ->label('Thứ tự')
                        ->numeric()
                        ->default(0)
                        ->minValue(0),

                    Textarea::make('description')
                        ->label('Mô tả')
                        ->placeholder('Mô tả chi tiết dịch vụ')
                        ->rows(2)
                        ->maxLength(1000)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->minItems(1)
                ->addActionLabel('+ Thêm dịch vụ')
                ->reorderable()
                ->columnSpanFull(),
        ]);
    }

    protected function fillForm(): void
    {
        $record = $this->getRecord();

        $services = RoomService::where('product_id', $record->product_id)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($s) => [
                'name'        => $s->name,
                'icon'        => $s->icon,
                'description' => $s->description,
                'price'       => $s->price,
                'unit'        => $s->unit,
                'sort_order'  => $s->sort_order,
            ])
            ->toArray();

        $this->form->fill([
            'product_name' => $record->product?->name,
            'services'     => $services,
        ]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $productId = $record->product_id;

        RoomService::where('product_id', $productId)->delete();

        foreach ($data['services'] ?? [] as $item) {
            RoomService::create([
                'product_id'  => $productId,
                'name'        => $item['name'],
                'icon'        => $item['icon'] ?? null,
                'description' => $item['description'] ?? null,
                'price'       => (int) ($item['price'] ?? 0),
                'unit'        => $item['unit'] ?? null,
                'sort_order'  => (int) ($item['sort_order'] ?? 0),
            ]);
        }

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
