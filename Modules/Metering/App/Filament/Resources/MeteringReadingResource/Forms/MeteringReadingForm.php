<?php

namespace Modules\Metering\App\Filament\Resources\MeteringReadingResource\Forms;

use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Support\HtmlString;
use Modules\Metering\App\Models\MeteringReading;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Room;

// Số đầu kỳ (electric_start/water_start) LUÔN HIỂN THỊ nhưng KHÔNG cho sửa tay (->disabled(), không
// dehydrate) — giá trị thật được model tự tính khi tạo log (xem MeteringReading::booted()), ở đây chỉ
// tính TRƯỚC để nhân viên xem ngay lúc đang chọn Phòng/Tháng (preview phía client), tránh nhầm là số
// gõ tay được.
class MeteringReadingForm
{
    public static function form(Form $form): Form
    {
        // Chia 2 cột: BÊN TRÁI là form ghi số như cũ (columnSpan 2/3), BÊN PHẢI (1/3) là danh sách
        // toàn bộ Số điện nước đã ghi của ĐÚNG phòng đang chọn — để nhân viên nhìn lại lịch sử ngay
        // khi đang nhập, không phải thoát ra danh sách rồi lọc lại theo phòng. Responsive: xếp dọc
        // (cột phải rơi xuống dưới) dưới màn hình 'xl'.
        return $form->columns(['default' => 1, 'xl' => 3])->schema([
            Group::make([
                Section::make('Số điện nước')
                    ->columns(2)
                    ->schema([
                        Select::make('room_id')
                            ->label('Phòng')
                            ->relationship('room', 'code')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set, string $operation) => static::previewStartFromPreviousMonth($get, $set, $operation)),
                        DatePicker::make('month')
                            ->label('Tháng ghi số')
                            ->displayFormat('m/Y')
                            ->native(false)
                            ->closeOnDateSelection()
                            ->default(now()->startOfMonth())
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set, string $operation) => static::previewStartFromPreviousMonth($get, $set, $operation)),

                        TextInput::make('electric_start')
                            ->label('Điện - Số đầu kỳ')
                            ->numeric()
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Tự động = số cuối kỳ tháng trước, không sửa được ở đây.'),
                        TextInput::make('electric_end')
                            ->label('Điện - Số cuối kỳ')
                            ->numeric()
                            ->required(),

                        TextInput::make('water_start')
                            ->label('Nước - Số đầu kỳ')
                            ->numeric()
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Tự động = số cuối kỳ tháng trước, không sửa được ở đây.'),
                        TextInput::make('water_end')
                            ->label('Nước - Số cuối kỳ')
                            ->numeric()
                            ->required(),

                        Textarea::make('note')
                            ->label('Ghi chú')
                            ->columnSpanFull(),
                    ]),
            ])->columnSpan(['default' => 1, 'xl' => 2]),

            static::historySidebar(),
        ]);
    }

    // Cột bên phải — toàn bộ Số điện nước đã ghi của đúng phòng đang chọn, mới nhất lên trước, để đối
    // chiếu ngay khi đang ghi số tháng mới. Chỉ đọc — sửa 1 tháng cũ thì bấm Sửa đúng dòng đó trong
    // danh sách chính, không sửa trực tiếp ở đây.
    private static function historySidebar(): Group
    {
        return Group::make([
            Section::make('Lịch sử ghi số — theo phòng')
                ->description('Đối chiếu các tháng đã ghi của phòng này.')
                ->schema([
                    Placeholder::make('history_list')
                        ->hiddenLabel()
                        ->content(fn (Get $get, ?MeteringReading $record) => static::historyListContent($get, $record)),
                ]),
        ])->columnSpan(['default' => 1, 'xl' => 1]);
    }

    private static function historyListContent(Get $get, ?MeteringReading $record): HtmlString
    {
        $roomId = $get('room_id');

        if (blank($roomId)) {
            return new HtmlString('<p class="text-sm text-gray-500 dark:text-gray-400">Chọn phòng để xem lịch sử ghi số.</p>');
        }

        // Tháng CŨ lên trước, tháng MỚI xuống dưới — đọc xuôi theo thời gian dễ theo dõi mức tiêu thụ
        // tăng/giảm qua từng tháng hơn là mới nhất lên đầu.
        $readings = MeteringReading::query()
            ->where('room_id', $roomId)
            ->orderBy('month')
            ->limit(24)
            ->get();

        if ($readings->isEmpty()) {
            return new HtmlString('<p class="text-sm text-gray-500 dark:text-gray-400">Phòng này chưa có Số điện nước nào.</p>');
        }

        [$electricPrice, $waterPrice] = static::unitPricesForRoom($roomId);

        $currentMonth = filled($get('month')) ? Carbon::parse($get('month'))->startOfMonth()->toDateString() : null;

        $rows = $readings->map(function (MeteringReading $reading) use ($currentMonth, $record, $electricPrice, $waterPrice) {
            // Đang Sửa đúng dòng này (record->id trùng) HOẶC đang Tạo mới cho đúng tháng này — tô nền
            // để phân biệt với các dòng lịch sử khác.
            $isCurrent = $record?->id === $reading->id || (! $record && $currentMonth && $reading->month->toDateString() === $currentMonth);
            $rowClass  = $isCurrent ? ' class="bg-primary-50 dark:bg-primary-500/10 font-semibold"' : '';

            $electricUsage = max(0, (float) $reading->electric_end - (float) $reading->electric_start);
            $waterUsage    = max(0, (float) $reading->water_end - (float) $reading->water_start);
            $amount        = $electricUsage * $electricPrice + $waterUsage * $waterPrice;

            $cell = fn (string $content, string $align = 'right') => '<td class="whitespace-nowrap px-3 py-2 text-' . $align . '">' . $content . '</td>';

            return "<tr{$rowClass}>"
                . $cell(e($reading->month->format('m/Y')), 'left')
                . $cell(e(number_format($reading->electric_start, 0, ',', '.')) . ' → ' . e(number_format($reading->electric_end, 0, ',', '.')))
                . $cell(e(number_format($reading->water_start, 0, ',', '.')) . ' → ' . e(number_format($reading->water_end, 0, ',', '.')))
                . $cell(e(number_format($amount, 0, ',', '.')) . 'đ')
                . '</tr>';
        })->implode('');

        $priceCaption = sprintf(
            'Đơn giá hiện tại: Điện %sđ/số · Nước %sđ/số',
            number_format($electricPrice, 0, ',', '.'),
            number_format($waterPrice, 0, ',', '.'),
        );

        $html = '<div class="space-y-2">'
            . '<p class="text-xs text-gray-500 dark:text-gray-400">' . e($priceCaption) . '</p>'
            . '<div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">'
            . '<table class="w-full text-sm">'
            . '<thead><tr class="border-b border-gray-200 bg-gray-50 text-xs text-gray-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-400">'
            . '<th class="px-3 py-2 text-left font-medium">Tháng</th>'
            . '<th class="px-3 py-2 text-right font-medium">Điện</th>'
            . '<th class="px-3 py-2 text-right font-medium">Nước</th>'
            . '<th class="px-3 py-2 text-right font-medium">Thành tiền</th>'
            . '</tr></thead>'
            . '<tbody class="divide-y divide-gray-200 dark:divide-white/10">' . $rows . '</tbody>'
            . '</table></div></div>';

        return new HtmlString($html);
    }

    // Đơn giá điện/nước THAM KHẢO cho phòng — Metering không quản lý giá (giữ nguyên bên Minihouse),
    // nên lấy đúng theo thứ tự ưu tiên hiện có: hợp đồng ĐANG HIỆU LỰC của phòng (nếu có giá riêng) ->
    // giá mặc định của Toà nhà, mirror đúng InvoiceGenerationService::buildInvoiceForContract(). Chỉ
    // để TÍNH THAM KHẢO "Thành tiền" trong bảng lịch sử — hoá đơn thật vẫn tự tính lại theo giá tại
    // đúng thời điểm lập, có thể khác nếu giá đã đổi từ đó tới nay.
    /** @return array{0: float, 1: float} */
    private static function unitPricesForRoom(string $roomId): array
    {
        $room = Room::withoutGlobalScopes()->with('building')->find($roomId);

        if (! $room) {
            return [0.0, 0.0];
        }

        $contract = Contract::withoutGlobalScopes()
            ->where('room_id', $roomId)
            ->where('status', Contract::STATUS_ACTIVE)
            ->first();

        $electricPrice = $contract?->electric_unit_price ?: $room->building?->electric_unit_price;
        $waterPrice    = $contract?->water_unit_price ?: $room->building?->water_unit_price;

        return [(float) ($electricPrice ?? 0), (float) ($waterPrice ?? 0)];
    }

    // Chỉ để XEM TRƯỚC lúc đang tạo mới (record thật sự được tính ở MeteringReading::booted() khi
    // lưu) — tra log gần nhất TRƯỚC tháng đang chọn của đúng phòng, lấy electric_end/water_end làm số
    // đầu kỳ hiển thị tạm. Không set khi đang Sửa 1 log đã có sẵn (record đã có số đầu kỳ thật từ DB,
    // không cần tính lại).
    private static function previewStartFromPreviousMonth(Get $get, Set $set, string $operation): void
    {
        // Chỉ xem trước lúc TẠO MỚI — đang Sửa thì field đã hiển thị đúng số đầu kỳ THẬT lưu trong DB
        // (Filament tự điền từ record), đổi Phòng/Tháng lúc Sửa (hiếm khi xảy ra) không được ghi đè
        // bằng số preview, tránh hiện sai giá trị thật đã lưu.
        if ($operation !== 'create') {
            return;
        }

        $roomId = $get('room_id');
        $month  = $get('month');

        if (blank($roomId) || blank($month)) {
            return;
        }

        $previous = MeteringReading::latestBefore((int) $roomId, Carbon::parse($month));

        $set('electric_start', $previous->electric_end ?? 0);
        $set('water_start', $previous->water_end ?? 0);
    }
}
