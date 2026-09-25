<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\PortalBroadcastResource\Forms;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\PortalBroadcast;
use Modules\Minihouse\App\Models\Tenant;

// Mirror App\Filament\Resources\NotificationFcmResource (Home) ở phần form — "all" = mọi khách thuê
// đang có ít nhất 1 thiết bị đăng ký nhận push (xem PushNotificationController::resolveTenantIds()),
// tự giới hạn theo đúng toà nhà tài khoản đang đăng nhập được quản lý (áp ở
// PortalBroadcastResource::getEloquentQuery() + trong 2 trang Create/Edit, không lặp lại ở đây).
class PortalBroadcastForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Nội dung thông báo')->schema([
                TextInput::make('title')
                    ->label('Tiêu đề')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('Ví dụ: Bảo trì thang máy toà A'),

                Textarea::make('body')
                    ->label('Nội dung')
                    ->required()
                    ->rows(4)
                    ->maxLength(1000),

                TextInput::make('link')
                    ->label('Đường dẫn mở khi bấm vào')
                    ->helperText('Không bắt buộc — VD /minihouse/portal/announcements.')
                    ->maxLength(500),
            ]),

            Section::make('Người nhận')->schema([
                Radio::make('sent_for')
                    ->label('Gửi đến')
                    ->options([
                        PortalBroadcast::SENT_FOR_ALL     => 'Tất cả khách thuê (có đăng ký nhận thông báo)',
                        PortalBroadcast::SENT_FOR_TENANTS => 'Chọn từng khách',
                    ])
                    ->default(PortalBroadcast::SENT_FOR_TENANTS)
                    ->inline()
                    ->live()
                    ->required(),

                Placeholder::make('all_count_hint')
                    ->label('')
                    ->content(function (): string {
                        $count = Tenant::whereHas('pushTokens')->count();

                        return "Sẽ gửi đến {$count} khách thuê đang có thiết bị đăng ký nhận thông báo.";
                    })
                    ->visible(fn (Get $get): bool => $get('sent_for') === PortalBroadcast::SENT_FOR_ALL),

                Select::make('tenant_ids')
                    ->label('Chọn khách thuê')
                    ->multiple()
                    ->searchable()
                    ->required(fn (Get $get) => $get('sent_for') === PortalBroadcast::SENT_FOR_TENANTS)
                    ->visible(fn (Get $get): bool => $get('sent_for') === PortalBroadcast::SENT_FOR_TENANTS)
                    ->getSearchResultsUsing(function (string $search): array {
                        return Tenant::where(function ($q) use ($search) {
                            $q->where('fullname', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        })
                            ->with('room.building')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (Tenant $t) => [$t->id => self::tenantLabel($t)])
                            ->toArray();
                    })
                    ->getOptionLabelsUsing(function (array $values): array {
                        return Tenant::whereIn('id', $values)
                            ->with('room.building')
                            ->get()
                            ->mapWithKeys(fn (Tenant $t) => [$t->id => self::tenantLabel($t)])
                            ->toArray();
                    })
                    ->noSearchResultsMessage('Không tìm thấy khách thuê.')
                    ->placeholder('Tìm theo tên hoặc số điện thoại...'),
            ]),

            Section::make('Lịch gửi')->schema([
                DateTimePicker::make('scheduled_at')
                    ->label('Gửi vào lúc')
                    ->helperText('Để trống nếu muốn gửi ngay. Chọn thời điểm trong tương lai để lên lịch.')
                    ->nullable()
                    ->minDate(now())
                    ->seconds(false)
                    ->native(false)
                    ->displayFormat('d/m/Y H:i')
                    ->timezone(config('app.timezone', 'Asia/Ho_Chi_Minh')),
            ]),
        ]);
    }

    private static function tenantLabel(Tenant $t): string
    {
        $room = $t->room;
        $roomLabel = $room ? " — {$room->code}" . ($room->building ? " ({$room->building->name})" : '') : '';

        return "{$t->fullname} — {$t->phone}{$roomLabel}";
    }
}
