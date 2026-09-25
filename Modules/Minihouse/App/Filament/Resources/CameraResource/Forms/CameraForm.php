<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\CameraResource\Forms;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Illuminate\Validation\Rules\Unique;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Support\ActiveBuildingScope;
use Modules\Minihouse\App\Support\HomestayBridge;

// Mirror ĐÚNG App\Filament\Resources\CameraResource::form() (Home) — cùng field, cùng validate,
// cùng helper text. Khác đúng 2 chỗ: (1) "Chi nhánh" đổi thành "Toà nhà", chọn trong đúng phạm vi
// toà MiniHouse tài khoản được quản lý (ActiveBuildingScope::permittedBuildingIds()) thay vì mọi
// Category gốc của Home; (2) partner_id LUÔN CỐ ĐỊNH = HomestayBridge::PARTNER_ID (MiniHouse chỉ có
// đúng 1 "đối tác" nội bộ, không cần tự suy theo chi nhánh như Home).
class CameraForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->label('Tên camera')
                ->placeholder('VD: Cổng chính, Hành lang tầng 1')
                ->required()
                ->maxLength(255),

            TextInput::make('stream_key')
                ->label('Tên nguồn (stream key)')
                ->placeholder('VD: mh-a-101')
                ->helperText('Phải khớp CHÍNH XÁC (kể cả hoa/thường, dấu cách) với tên camera đã có sẵn trong Frigate — xem ở Settings > Enable/Disable Cameras trong Frigate. Chỉ cần duy nhất TRONG CÙNG 1 Toà nhà (khác toà nhà = khác server, được phép trùng tên với Home hoặc Toà nhà khác).')
                ->required()
                ->maxLength(255)
                // Chỉ cần duy nhất TRONG CÙNG 1 building_id (= cùng 1 server go2rtc/Frigate, xem
                // Camera::resolveCameraSettings()) — KHÔNG dùng ->unique() mặc định (kiểm tra CẢ bảng
                // "cameras", tức trùng cả với camera của Home) vì mỗi Toà nhà/đối tác giờ có server
                // RIÊNG, tên nguồn trùng nhau ở 2 server khác nhau không xung đột gì thật sự — lỗi
                // thật đã gặp: đặt tên đã dùng bên Home bị chặn dù 2 bên chạy 2 server hoàn toàn khác.
                ->unique(table: 'cameras', ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('branch_id', $get('branch_id'))),

            TextInput::make('frigate_camera_name')
                ->label('Tên camera trong Frigate (chỉ điền nếu KHÁC tên nguồn ở trên)')
                ->placeholder('VD: mh-a-101-cam')
                ->helperText('Dùng cho API xem lại lịch sử/ghi hình — Frigate có thể đặt tên camera khác với "Tên nguồn" go2rtc ở trên. Để trống nếu 2 tên giống hệt nhau.')
                ->maxLength(255),

            TextInput::make('rtsp_url')
                ->label('Địa chỉ RTSP camera (chỉ điền nếu camera CHƯA có trong Frigate)')
                ->placeholder('rtsp://admin:matkhau@192.168.1.20:554/cam/realmonitor?channel=1&subtype=0')
                ->helperText('Để trống nếu camera này đã được khai báo sẵn trong Frigate (đa số trường hợp) — chỉ cần đúng "Tên nguồn" ở trên là xem được ngay.')
                ->password()
                ->revealable()
                ->maxLength(2000),

            Select::make('branch_id')
                ->label('Toà nhà')
                ->options(fn () => Building::withoutGlobalScope('activeBuilding')
                    ->whereIn('id', ActiveBuildingScope::permittedBuildingIds())
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->required(),

            Hidden::make('partner_id')->default(HomestayBridge::PARTNER_ID)->dehydrated(),

            Toggle::make('status')
                ->label('Đang hoạt động')
                ->default(true)
                ->helperText('Tắt để tạm ẩn camera này khỏi trang "Xem camera" (VD camera đang bảo trì) mà không cần xoá.'),

            Textarea::make('note')
                ->label('Ghi chú')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }
}
