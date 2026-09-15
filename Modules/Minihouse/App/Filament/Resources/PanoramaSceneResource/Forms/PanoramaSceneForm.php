<?php

namespace Modules\Minihouse\App\Filament\Resources\PanoramaSceneResource\Forms;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Support\Facades\Storage;
use Modules\Minihouse\App\Models\PanoramaScene;
use Modules\Minihouse\App\Models\Room;

class PanoramaSceneForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin điểm 360°')
                ->columns(2)
                ->schema([
                    Select::make('building_id')
                        ->label('Toà nhà')
                        ->relationship('building', 'name')
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required()
                        // Đổi toà nhà thì phòng đã chọn (thuộc toà CŨ) không còn hợp lệ — reset lại,
                        // tránh lưu nhầm room_id của toà khác.
                        ->afterStateUpdated(fn (Set $set) => $set('room_id', null)),
                    Select::make('room_id')
                        ->label('Phòng (để trống nếu là sảnh/hành lang chung)')
                        ->options(fn (Get $get) => Room::query()
                            ->where('building_id', $get('building_id'))
                            ->pluck('code', 'id'))
                        ->searchable()
                        ->disabled(fn (Get $get) => blank($get('building_id')))
                        ->helperText('Để trống = điểm đứng chung (sảnh, hành lang, cầu thang...) — không gắn với 1 phòng cụ thể.'),
                    TextInput::make('title')
                        ->label('Tên điểm')
                        ->placeholder('VD: Sảnh chính, Hành lang tầng 2, Phòng A-01')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('floor')
                        ->label('Tầng')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('Chỉ để sắp xếp/nhóm danh sách bên dưới, không ảnh hưởng cách khách di chuyển giữa các điểm.'),
                    FileUpload::make('image_path')
                        ->label('Ảnh 360° (equirectangular)')
                        ->image()
                        ->required()
                        ->directory('minihouse/panoramas')
                        ->disk('public')
                        // 8MB đủ dư cho ảnh 360° đã nén tốt (khuyến nghị 4096x2048, JPEG chất lượng
                        // ~75) — chặn ảnh gốc chưa nén (thường 20-50MB) để tránh tour tải rất chậm
                        // trên điện thoại và tốn dung lượng lưu trữ.
                        ->maxSize(8192)
                        ->helperText('Ảnh toàn cảnh 360° tỉ lệ 2:1 (VD 4096×2048). Nên nén trước khi tải lên (dưới 2-3MB/ảnh) để khách xem mượt trên điện thoại — dung lượng càng nhỏ, tour càng tải nhanh.')
                        ->columnSpanFull(),
                    TextInput::make('initial_yaw')
                        ->label('Góc nhìn ban đầu (trái/phải)')
                        ->numeric()
                        ->default(0)
                        ->helperText('Độ, từ -180 đến 180 — góc khách nhìn thấy đầu tiên khi vào điểm này.'),
                    TextInput::make('initial_pitch')
                        ->label('Góc nhìn ban đầu (trên/dưới)')
                        ->numeric()
                        ->default(0)
                        ->helperText('Độ, từ -90 (nhìn xuống) đến 90 (nhìn lên) — để 0 là nhìn ngang.'),
                    TextInput::make('sort_order')
                        ->label('Thứ tự hiển thị')
                        ->numeric()
                        ->default(0),
                    Toggle::make('is_published')
                        ->label('Công khai cho khách xem')
                        ->default(true)
                        ->helperText('Tắt nếu đang chụp dở/chưa muốn khách thấy điểm này trong tour.'),
                ]),

            Section::make('Điểm nóng (đường dẫn sang điểm khác)')
                ->description('Mỗi dòng là 1 điểm bấm trên ảnh 360° để khách "đi" sang điểm khác — góc yaw/pitch xác định vị trí điểm bấm nằm ở đâu trên ảnh.')
                ->visible(fn (?PanoramaScene $record) => $record !== null)
                ->schema([
                    Repeater::make('hotspots')
                        ->relationship('hotspots')
                        ->label('')
                        ->schema([
                            // Bấm thẳng lên ảnh 360° của ĐIỂM NÀY để đặt vị trí điểm nóng — thay cho
                            // việc phải tự đoán/gõ tay toạ độ yaw/pitch. Xem chú thích trong file view
                            // để biết vì sao công thức tuyến tính đơn giản là đủ chính xác cho ảnh
                            // equirectangular (không cần dựng cả trình xem 360° trong form).
                            ViewField::make('picker')
                                ->label('Chọn vị trí trên ảnh')
                                ->view('minihouse::filament.forms.panorama-hotspot-picker')
                                ->viewData(fn ($livewire) => [
                                    'imageUrl' => $livewire->getRecord()?->image_path
                                        ? Storage::disk('public')->url($livewire->getRecord()->image_path)
                                        : null,
                                ])
                                ->dehydrated(false)
                                ->columnSpanFull(),
                            Select::make('target_scene_id')
                                ->label('Đi tới điểm')
                                // KHÔNG type-hint ?PanoramaScene $record — bên trong Repeater
                                // ->relationship('hotspots'), Filament tự bind $record thành đúng
                                // ITEM đang sửa (PanoramaHotspot), không phải bản ghi PanoramaScene ở
                                // FORM GỐC, gây lỗi kiểu dữ liệu. Lấy bản ghi gốc qua $livewire (trang
                                // Sửa) thay vì qua tham số $record.
                                ->options(function ($livewire) {
                                    $scene = $livewire->getRecord();

                                    return PanoramaScene::query()
                                        ->where('building_id', $scene?->building_id)
                                        ->when($scene, fn ($q) => $q->whereKeyNot($scene->id))
                                        ->get()
                                        ->mapWithKeys(fn (PanoramaScene $s) => [$s->id => $s->label()]);
                                })
                                ->searchable()
                                ->required(),
                            TextInput::make('label')
                                ->label('Nhãn hiện khi trỏ vào')
                                ->placeholder('VD: Vào phòng A-01')
                                ->maxLength(255),
                            TextInput::make('yaw')
                                ->label('Góc yaw')
                                ->numeric()
                                ->required()
                                ->live()
                                ->helperText('-180 đến 180 — tự điền khi bấm lên ảnh phía trên.'),
                            TextInput::make('pitch')
                                ->label('Góc pitch')
                                ->numeric()
                                ->required()
                                ->live()
                                ->helperText('-90 đến 90 — tự điền khi bấm lên ảnh phía trên.'),
                        ])
                        ->columns(4)
                        ->addActionLabel('Thêm điểm nóng')
                        ->defaultItems(0)
                        ->collapsible()
                        ->itemLabel(fn (array $state) => $state['label'] ?? null),
                ]),
        ]);
    }
}
