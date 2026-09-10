<?php

namespace Modules\Minihouse\App\Filament\Resources\AnnouncementResource\Forms;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Modules\Minihouse\App\Models\Building;

class AnnouncementForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông báo gửi khách thuê')
                ->description('Hiện ngay trong mục "Thông báo" của Portal khách thuê — không gửi qua Zalo/SMS (đọc trong Portal là đủ, không tốn phí gửi).')
                ->schema([
                    Select::make('building_id')
                        ->label('Gửi cho')
                        ->options(Building::withoutGlobalScopes()->pluck('name', 'id'))
                        ->placeholder('Tất cả toà nhà')
                        ->searchable(),
                    TextInput::make('title')
                        ->label('Tiêu đề')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Textarea::make('body')
                        ->label('Nội dung')
                        ->required()
                        ->rows(4)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
