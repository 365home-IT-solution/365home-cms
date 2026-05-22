<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomSpecialResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Product\App\Filament\Resources\RoomSpecialResource;
use Modules\Product\App\Models\RoomSpecial;

class EditRoomSpecial extends EditRecord
{
    protected static string $resource = RoomSpecialResource::class;

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

            Repeater::make('specials')
                ->label('Danh sách điểm đặc biệt')
                ->schema([
                    TextInput::make('title')
                        ->label('Tiêu đề')
                        ->placeholder('VD: Miễn phí WiFi tốc độ cao, Ban công view đẹp')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(2),

                    TextInput::make('icon')
                        ->label('Icon name')
                        ->placeholder('VD: wifi, balcony, pool')
                        ->maxLength(150),

                    TextInput::make('sort_order')
                        ->label('Thứ tự')
                        ->numeric()
                        ->default(0)
                        ->minValue(0),

                    Textarea::make('short_description')
                        ->label('Mô tả ngắn')
                        ->placeholder('Mô tả ngắn về điểm đặc biệt này')
                        ->rows(2)
                        ->maxLength(500)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->minItems(1)
                ->addActionLabel('+ Thêm điểm đặc biệt')
                ->reorderable()
                ->columnSpanFull(),
        ]);
    }

    protected function fillForm(): void
    {
        $record = $this->getRecord();

        $specials = RoomSpecial::where('product_id', $record->product_id)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($s) => [
                'title'             => $s->title,
                'icon'              => $s->icon,
                'short_description' => $s->short_description,
                'sort_order'        => $s->sort_order,
            ])
            ->toArray();

        $this->form->fill([
            'product_name' => $record->product?->name,
            'specials'     => $specials,
        ]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $productId = $record->product_id;

        RoomSpecial::where('product_id', $productId)->delete();

        foreach ($data['specials'] ?? [] as $item) {
            RoomSpecial::create([
                'product_id'        => $productId,
                'title'             => $item['title'],
                'icon'              => $item['icon'] ?? null,
                'short_description' => $item['short_description'] ?? null,
                'sort_order'        => (int) ($item['sort_order'] ?? 0),
            ]);
        }

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
