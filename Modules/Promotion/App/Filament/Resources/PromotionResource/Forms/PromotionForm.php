<?php

declare(strict_types=1);

namespace Modules\Promotion\App\Filament\Resources\PromotionResource\Forms;

use Carbon\Carbon;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Illuminate\Support\HtmlString;

class PromotionForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            // SECTION 1: THÔNG TIN CƠ BẢN
            Section::make('Thông tin khuyến mãi')
                ->description('Nhập tên và mô tả cho khuyến mãi.')
                ->schema([
                    TextInput::make('name')
                        ->label('Tên khuyến mãi')
                        ->required()
                        ->maxLength(255),

                    Textarea::make('description')
                        ->label('Mô tả')
                        ->rows(3)
                        ->maxLength(500)
                        ->placeholder('Ví dụ: Áp dụng cho các khung giờ từ 14h - 17h...'),
                ])
                ->columns(1)
                ->collapsible(),

            // SECTION 2: THỜI GIAN
            Section::make('Thời gian áp dụng')
                ->description('Cấu hình thời gian bắt đầu và kết thúc chương trình.')
                ->schema([
                    DateTimePicker::make('start_at')
                        ->label('Thời gian bắt đầu')
                        ->required()
                        ->live()
                        ->seconds(false),

                    DateTimePicker::make('end_at')
                        ->label('Thời gian kết thúc')
                        ->required()
                        ->live()
                        ->seconds(false)
                        ->after('start_at'),

                    // FIELD: CALENDAR PREVIEW (Chỉ hiển thị Tổng quan lịch trình)
                    Placeholder::make('calendar_preview')
                        ->label('')
                        ->content(function (Get $get) {
                            $startAt = $get('start_at');
                            $endAt = $get('end_at');

                            if (!$startAt || !$endAt) {
                                return new HtmlString('
                                    <div class="flex flex-col items-center justify-center p-6 border-2 border-dashed border-gray-300 rounded-xl bg-gray-50/50 text-gray-500">
                                        <span class="font-medium">Vui lòng chọn thời gian bắt đầu và kết thúc để xem lịch trình</span>
                                    </div>
                                ');
                            }

                            Carbon::setLocale('vi');
                            $start = Carbon::parse($startAt);
                            $end = Carbon::parse($endAt);

                            $totalDays = $start->diffInDays($end);
                            $durationText = ($totalDays > 0 ? "{$totalDays} ngày" : "Trong ngày");
                            $hours = $end->diffInHours($start) % 24;
                            if ($hours > 0) $durationText .= " {$hours} giờ";

                            $statusColor = $end->isPast() ? 'gray' : ($start->isFuture() ? 'blue' : 'green');
                            $statusText = $end->isPast() ? 'Đã kết thúc' : ($start->isFuture() ? 'Sắp diễn ra' : 'Đang hoạt động');

                            $html = '<div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mt-2">';

                            // Header
                            $html .= '
                            <div class="px-6 py-3 border-b border-gray-100 bg-gray-50/50 flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <div class="p-1.5 bg-'. $statusColor .'-100 text-'. $statusColor .'-600 rounded-md">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                    </div>
                                    <span class="font-bold text-gray-800">Tổng quan lịch trình</span>
                                </div>
                                <span class="px-2.5 py-1 text-xs font-semibold rounded-full bg-'. $statusColor .'-100 text-'. $statusColor .'-700 border border-'. $statusColor .'-200">
                                    '. $statusText .'
                                </span>
                            </div>';

                            // Grid Content
                            $html .= '<div class="p-6 grid grid-cols-1 md:grid-cols-7 gap-6 items-center">';

                            // Start
                            $html .= '
                            <div class="md:col-span-2 flex items-center gap-4">
                                <div class="flex-shrink-0 w-14 h-14 rounded-xl bg-blue-50 border border-blue-100 flex flex-col items-center justify-center text-blue-700 font-bold">
                                    <span class="text-[10px] uppercase">T' . $start->format('m') . '</span>
                                    <span class="text-xl leading-none">' . $start->format('d') . '</span>
                                </div>
                                <div>
                                    <div class="text-xs text-gray-500 uppercase font-medium">Bắt đầu</div>
                                    <div class="text-base font-bold text-gray-900">' . $start->format('H:i') . '</div>
                                    <div class="text-xs text-gray-500">' . $start->translatedFormat('l, Y') . '</div>
                                </div>
                            </div>';

                            // Duration Bar
                            $html .= '
                            <div class="md:col-span-3 flex flex-col items-center justify-center px-4">
                                <div class="text-xs font-bold text-indigo-600 bg-indigo-50 px-3 py-1 rounded-full mb-2">
                                    '. $durationText .'
                                </div>
                                <div class="w-full h-1 bg-gray-100 rounded-full overflow-hidden relative">
                                    <div class="absolute left-0 top-0 h-full bg-indigo-500 w-full opacity-20"></div>
                                    <div class="absolute left-0 top-0 h-full bg-indigo-500" style="width: 100%"></div>
                                </div>
                            </div>';

                            // End
                            $html .= '
                            <div class="md:col-span-2 flex items-center gap-4 justify-end text-right">
                                <div>
                                    <div class="text-xs text-gray-500 uppercase font-medium">Kết thúc</div>
                                    <div class="text-base font-bold text-gray-900">' . $end->format('H:i') . '</div>
                                    <div class="text-xs text-gray-500">' . $end->translatedFormat('l, Y') . '</div>
                                </div>
                                <div class="flex-shrink-0 w-14 h-14 rounded-xl bg-indigo-50 border border-indigo-100 flex flex-col items-center justify-center text-indigo-700 font-bold">
                                    <span class="text-[10px] uppercase">T' . $end->format('m') . '</span>
                                    <span class="text-xl leading-none">' . $end->format('d') . '</span>
                                </div>
                            </div>';

                            $html .= '</div></div>';
                            return new HtmlString($html);
                        })
                        ->columnSpanFull()
                        ->visible(fn (Get $get) => $get('start_at') && $get('end_at')),
                ])
                ->columns(2)
                ->collapsible(),

            // SECTION 3: CẤU HÌNH
            Section::make('Cấu hình khuyến mãi')
                ->description('Loại và giá trị khuyến mãi áp dụng.')
                ->schema([
                    Select::make('type')
                        ->label('Loại khuyến mãi')
                        ->options([
                            'percentage' => 'Giảm theo phần trăm (%)',
                            'fixed' => 'Giảm số tiền cố định (vnđ)',
                            'increase_percentage' => 'Tăng số tiền theo phần trăm (%)',
                            'increase_fixed' => 'Tăng số tiền cố định (vnđ)'
                        ])
                        ->required()
                        ->native(false)
                        ->live(),

                    TextInput::make('value')
                        ->label(fn (Get $get) => in_array($get('type'), ['increase_percentage', 'increase_fixed']) ? 'Giá trị tăng' : 'Giá trị giảm')
                        ->numeric()
                        ->suffix(fn (Get $get) => in_array($get('type'), ['percentage', 'increase_percentage']) ? '%' : 'vnđ')
                        ->required()
                        ->live(onBlur: true)
                        ->minValue(0)
                        ->maxValue(fn (Get $get) => in_array($get('type'), ['percentage', 'increase_percentage']) ? 100 : null),

                    Toggle::make('is_active')
                        ->label('Đang kích hoạt')
                        ->default(true),
                ])
                ->columns(3)
                ->collapsible(),

            // SECTION 3.5: THÔNG TIN HIỂN THỊ CHO KHÁCH HÀNG (hiển thị cho tất cả type)
            Section::make('Thông tin hiển thị cho khách hàng')
                ->description('Nhãn và hình ảnh hiển thị cho khách hàng khi áp dụng khuyến mãi.')
                ->schema([
                    TextInput::make('lable_client')
                        ->label('Nhãn hiển thị')
                        ->placeholder('VD: ⭐ Giờ vàng, 🔥 Hot Deal, 💎 Premium, ⚡ Flash Sale')
                        ->helperText('Bạn có thể sử dụng emoji và ký tự đặc biệt: ⭐ 🔥 💎 ⚡ 🎉 🎁 ✨ 💰 🏆 👑')
                        ->maxLength(100),

                    FileUpload::make('image')
                        ->label('Hình ảnh khuyến mãi')
                        ->image()
                        ->imageEditor()
                        ->imageEditorAspectRatios([
                            '16:9',
                            '4:3',
                            '1:1',
                        ])
                        ->maxSize(2048)
                        ->directory('promotions')
                        ->helperText('Tải lên hình ảnh minh họa cho khuyến mãi (tối đa 2MB)')
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->collapsible(),
                // Hiển thị cho tất cả loại khuyến mãi (cả giảm lẫn tăng)

            // SECTION 4: PREVIEW GIÁ (Giữ nguyên logic xem trước giá)
            Section::make('Xem trước giá sau khuyến mãi')
                ->description('Danh sách giá của các khung giờ sau khi áp dụng khuyến mãi')
                ->schema([
                    Tabs::make('PreviewTabs')
                        ->tabs(function (Get $get, $record) {
                            $type = $get('type');
                            $value = $get('value');

                            if (!$type || !$value || !$record) {
                                return [
                                    Tabs\Tab::make('Thông báo')
                                        ->schema([
                                            Placeholder::make('notice')
                                                ->content('Vui lòng lưu khuyến mãi trước để xem trước giá.')
                                        ])
                                ];
                            }

                            $roomTimeSlots = $record->roomTimeSlots()
                                ->with(['room', 'timeSlot'])
                                ->get()
                                ->groupBy('room_id');

                            if ($roomTimeSlots->isEmpty()) {
                                return [
                                    Tabs\Tab::make('Thông báo')
                                        ->schema([
                                            Placeholder::make('notice')
                                                ->content('Chưa có khung giờ nào được liên kết với khuyến mãi này.')
                                        ])
                                ];
                            }

                            $isIncrease = in_array($type, ['increase_percentage', 'increase_fixed']);
                            $mainTabs = [];

                            $renderPricePreview = function($slots) use ($type, $value) {
                                $html = '<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">';
                                foreach ($slots as $slot) {
                                    $originalPrice = (float) $slot->price;
                                    $newPrice = 0;
                                    $isIncreaseType = in_array($type, ['increase_percentage', 'increase_fixed']);

                                    if ($type === 'percentage') $newPrice = $originalPrice - ($originalPrice * ($value / 100));
                                    elseif ($type === 'fixed') $newPrice = max(0, $originalPrice - $value);
                                    elseif ($type === 'increase_percentage') $newPrice = $originalPrice + ($originalPrice * ($value / 100));
                                    elseif ($type === 'increase_fixed') $newPrice = $originalPrice + $value;

                                    $timeStart = $slot->timeSlot?->start_time ?? 'N/A';
                                    $timeEnd = $slot->timeSlot?->end_time ?? 'N/A';
                                    $timeSlotName = "$timeStart - $timeEnd";

                                    $diff = abs($newPrice - $originalPrice);
                                    $percent = $originalPrice > 0 ? round(($diff / $originalPrice) * 100) : 0;

                                    $colorClass = $isIncreaseType ? 'blue' : 'red';
                                    $sign = $isIncreaseType ? '+' : '-';
                                    $label = $isIncreaseType ? 'Tăng thêm' : 'Tiết kiệm';

                                    $html .= '<div class="p-4 border border-gray-200 rounded-lg bg-white shadow-sm hover:shadow-md transition-shadow">';
                                    $html .= '<div class="flex items-center justify-between mb-2">';
                                    $html .= '<div class="text-base font-bold text-gray-800">' . $timeSlotName . '</div>';
                                    $html .= '<span class="px-2 py-0.5 text-xs font-bold rounded bg-'.$colorClass.'-50 text-'.$colorClass.'-600">'.$sign.$percent.'%</span>';
                                    $html .= '</div>';
                                    $html .= '<div class="space-y-0.5">';
                                    $html .= '<div class="text-xs text-gray-500">Giá gốc: <span class="line-through">' . number_format($originalPrice, 0, ',', '.') . ' đ</span></div>';
                                    $html .= '<div class="text-lg font-bold text-'.$colorClass.'-600">' . number_format($newPrice, 0, ',', '.') . ' đ</div>';
                                    $html .= '<div class="text-xs text-'.$colorClass.'-600 font-medium">'.$label.': ' . number_format($diff, 0, ',', '.') . ' đ</div>';
                                    $html .= '</div></div>';
                                }
                                $html .= '</div>';
                                return new HtmlString($html);
                            };

                            if (!$isIncrease) {
                                $discountRoomTabs = [];
                                foreach ($roomTimeSlots as $roomId => $slots) {
                                    $roomName = $slots->first()->room->name ?? 'Phòng ' . $roomId;
                                    $discountRoomTabs[] = Tabs\Tab::make($roomName)
                                        ->badge($slots->count())
                                        ->schema([
                                            Placeholder::make('d_r_'.$roomId)->content(fn() => $renderPricePreview($slots))
                                        ]);
                                }
                                $mainTabs[] = Tabs\Tab::make('Xem trước giá sau giảm')
                                    ->icon('heroicon-o-arrow-trending-down')
                                    ->schema([Tabs::make('DiscountRoomTabs')->tabs($discountRoomTabs)->contained(false)]);
                            }

                            if ($isIncrease) {
                                $increaseRoomTabs = [];
                                foreach ($roomTimeSlots as $roomId => $slots) {
                                    $roomName = $slots->first()->room->name ?? 'Phòng ' . $roomId;
                                    $increaseRoomTabs[] = Tabs\Tab::make($roomName)
                                        ->badge($slots->count())
                                        ->schema([
                                            Placeholder::make('i_r_'.$roomId)->content(fn() => $renderPricePreview($slots))
                                        ]);
                                }
                                $mainTabs[] = Tabs\Tab::make('Xem trước giá sau tăng')
                                    ->icon('heroicon-o-arrow-trending-up')
                                    ->schema([Tabs::make('IncreaseRoomTabs')->tabs($increaseRoomTabs)->contained(false)]);
                            }

                            return $mainTabs;
                        })
                        ->contained(false)
                ])
                ->columns(1)
                ->collapsible()
                ->collapsed(false)
                ->visible(fn ($record) => $record !== null),
        ]);
    }
}