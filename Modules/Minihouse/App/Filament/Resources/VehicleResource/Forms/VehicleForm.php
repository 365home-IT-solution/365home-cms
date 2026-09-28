<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\VehicleResource\Forms;

use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Vehicle;
use Modules\Minihouse\App\Services\VehicleService;
use Modules\Minihouse\App\Support\ActiveBuildingScope;

class VehicleForm
{
    // Hợp đồng ĐANG HIỆU LỰC của 1 Toà nhà, nhãn "Phòng — Khách thuê".
    public static function contractOptions(?int $buildingId): array
    {
        if (! $buildingId) {
            return [];
        }

        return Contract::withoutGlobalScopes()
            ->where('status', Contract::STATUS_ACTIVE)
            ->whereHas('room', fn ($q) => $q->withoutGlobalScopes()->where('building_id', $buildingId))
            ->with(['room' => fn ($q) => $q->withoutGlobalScopes(), 'tenant' => fn ($q) => $q->withoutGlobalScopes()])
            ->get()
            ->mapWithKeys(fn (Contract $c) => [$c->id => ($c->room?->code ?? '—') . ' — ' . ($c->tenant?->fullname ?? '—')])
            ->all();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Chủ xe')->schema([
                Select::make('building_id')
                    ->label('Toà nhà')
                    ->options(fn () => Building::withoutGlobalScope('activeBuilding')
                        ->whereIn('id', ActiveBuildingScope::permittedBuildingIds())
                        ->orderBy('name')->pluck('name', 'id'))
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set) {
                        $set('contract_id', null);
                        $set('tenant_id', null);
                    }),

                Select::make('contract_id')
                    ->label('Hợp đồng (phòng — khách thuê)')
                    ->options(fn (Get $get) => self::contractOptions($get('building_id') ? (int) $get('building_id') : null))
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set, $state) {
                        $set('tenant_id', $state ? Contract::withoutGlobalScopes()->whereKey($state)->value('tenant_id') : null);
                    }),

                Hidden::make('tenant_id')->required(),
            ])->columns(2),

            Section::make('Thông tin xe')->schema([
                TextInput::make('plate_display')
                    ->label('Biển số')
                    ->required()
                    ->maxLength(30)
                    ->placeholder('VD: 59A1-123.45')
                    ->helperText('Không trùng biển số đang gửi/chờ duyệt khác trong cùng toà (không phân biệt dấu chấm, gạch, khoảng trắng).')
                    ->rules([
                        fn (Get $get, ?Vehicle $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record) {
                            $plate = VehicleService::normalizePlate((string) $value);

                            if ($plate === '') {
                                $fail('Biển số không hợp lệ.');

                                return;
                            }

                            if ($get('building_id') && VehicleService::plateInUse((int) $get('building_id'), $plate, $record?->id)) {
                                $fail('Biển số này đã có xe đang gửi/chờ duyệt trong toà nhà.');
                            }
                        },
                    ]),

                Select::make('vehicle_type')->label('Loại xe')->options(Vehicle::TYPES)->default(Vehicle::TYPE_MOTORBIKE)->required()->live(),
                TextInput::make('name')->label('Tên xe')->required()->maxLength(100)->placeholder('VD: Honda Vision, Toyota Vios'),
            ])->columns(3),

            Section::make('Giấy tờ xe')->schema([
                FileUpload::make('document_photo')
                    ->label('Ảnh giấy tờ xe (cà-vẹt/đăng ký xe)')
                    ->image()
                    ->disk('public')
                    ->directory('minihouse/vehicles')
                    ->maxSize(5120)
                    ->columnSpanFull(),
            ]),

            Section::make('Trạng thái')->schema([
                Select::make('status')->label('Trạng thái')->options(Vehicle::STATUSES)->default(Vehicle::STATUS_ACTIVE)->required(),
                Textarea::make('reject_reason')->label('Lý do từ chối')->rows(2)->columnSpanFull()
                    ->visible(fn (Get $get) => $get('status') === Vehicle::STATUS_REJECTED),
            ])->columns(2),
        ]);
    }
}
