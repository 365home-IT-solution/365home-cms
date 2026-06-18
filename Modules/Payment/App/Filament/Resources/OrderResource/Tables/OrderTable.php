<?php

namespace Modules\Payment\App\Filament\Resources\OrderResource\Tables;

use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Actions\Action;
use PayOS\PayOS;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\ActionsPosition;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\HtmlString;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Payment\Traits\GHNServiceTrait;
use Modules\Payment\Traits\GHTKServiceTrait;
use Modules\Payment\App\Filament\Resources\OrderResource\Tables\Actions\OrderAction;
use Modules\Payment\App\Filament\Resources\OrderResource\Tables\BulkActions\OrderBulkAction;
use Modules\Payment\App\Filament\Resources\OrderResource\Tables\Filters\OrderFilter;
use Modules\Payment\Entities\Order;
use Illuminate\Support\Str;
use Filament\Tables\Enums\FiltersLayout;

class OrderTable
{
    use GHNServiceTrait, GHTKServiceTrait;
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function ($query) {
                $query->with(['items.product', 'services', 'accessCodes']);
                if (! (auth()->user()?->isSuperAdmin() ?? false)) {
                    $query->where('exclude_from_stats', false);
                }
            })
            ->columns([
                TextColumn::make('order_code')
                    ->label(__('payment::order.table.label.order_code'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),

                TextColumn::make('category.name')
                    ->label('Chi nhánh')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('primary')
                    ->icon('heroicon-m-building-office-2'),

                TextColumn::make('computed_total')
                    ->label('Tổng tiền')
                    ->weight(FontWeight::Bold)
                    ->getStateUsing(fn ($record) => static::computeOrderTotal($record))
                    ->formatStateUsing(fn ($state) => number_format((int)$state, 0, ',', '.') . ' ₫')
                    ->sortable(false),

                TextColumn::make('created_at')
                    ->label(__('payment::order.table.label.created_at'))
                    ->date('d/m/Y H:i:s')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),

                BadgeColumn::make('status')
                    ->label('Trạng thái')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'paid',
                        'primary' => 'deposit',
                        'danger'  => 'failed',
                        'gray'    => 'cancelled_payment',
                    ])
                    ->icons([
                        'heroicon-o-clock'            => 'pending',
                        'heroicon-o-check-circle'     => 'paid',
                        'heroicon-o-banknotes'        => 'deposit',
                        'heroicon-o-x-circle'         => 'failed',
                        'heroicon-o-no-symbol'        => 'cancelled_payment',
                    ])
                    ->formatStateUsing(function ($state) {
                        return __("payment::order.table.status.$state");
                    }),

                TextColumn::make('buyer_name')
                    ->searchable()
                    ->label(__('payment::order.table.label.buyer_name')),
                TextColumn::make('buyer_phone')
                    ->label('Số điện thoại')
                    ->searchable(),
                TextColumn::make('guest_count')
                    ->label('Số khách')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->alignCenter()
                    ->badge()
                    ->color('primary'),
                TextColumn::make('payment_method')
                    ->label(__('payment::order.table.label.payment_method'))
                    ->sortable()
                    ->weight(FontWeight::Bold)
                    ->formatStateUsing(function ($state) {
                        return match ($state) {
                            'PayOS' => 'Chuyển khoản',
                            'cod' => 'Cod',
                            default => 'Không xác định',
                        };
                    }),
               
                ImageColumn::make('cccd_front')
                    ->label('CCCD Trước')
                    ->disk('public')
                    ->height(40)
                    ->width(60)
                    ->defaultImageUrl('/images/no-image.png')
                    ->tooltip('Click để xem chi tiết'),

                ImageColumn::make('cccd_back')
                    ->label('CCCD Sau')
                    ->disk('public')
                    ->height(40)
                    ->width(60)
                    ->defaultImageUrl('/images/no-image.png')
                    ->tooltip('Click để xem chi tiết'),
                
                TextColumn::make('stay_checkin')
                    ->label('Ngày nhận phòng')
                    ->getStateUsing(function ($record) {
                        $item = $record->items->firstWhere('product_id', '!=', null);
                        if (!$item || ($item->product?->styles ?? 1) != 2) return null;
                        return $item->checkin_date?->format('d/m/Y H:i');
                    })
                    ->placeholder('')
                    ->icon('heroicon-o-arrow-right-on-rectangle')
                    ->color('success'),

                TextColumn::make('stay_checkout')
                    ->label('Ngày trả phòng')
                    ->getStateUsing(function ($record) {
                        $item = $record->items->firstWhere('product_id', '!=', null);
                        if (!$item || ($item->product?->styles ?? 1) != 2) return null;
                        return $item->checkout_date?->format('d/m/Y H:i');
                    })
                    ->placeholder('')
                    ->icon('heroicon-o-arrow-left-on-rectangle')
                    ->color('danger'),

                TextColumn::make('updated_at')
                    ->label('Cập nhật lần cuối')
                    ->date('d/m/Y H:i:s')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),

                TextColumn::make('access_code_display')
                    ->label('Mã cổng')
                    ->getStateUsing(function ($record) {
                        $record->loadMissing('accessCodes');
                        $code = $record->accessCodes->first();
                        return $code ? $code->code : null;
                    })
                    ->placeholder('— chưa có —')
                    ->copyable()
                    ->icon('heroicon-o-key')
                    ->badge()
                    ->color(fn($state) => $state ? 'success' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: false),

                IconColumn::make('exclude_from_stats')
                    ->label('Thống kê')
                    ->boolean()
                    ->trueIcon('heroicon-o-eye-slash')
                    ->falseIcon('heroicon-o-chart-bar')
                    ->trueColor('danger')
                    ->falseColor('success')
                    ->tooltip(fn ($record) => $record->exclude_from_stats ? 'Đang loại khỏi thống kê & xuất Excel' : 'Đang tính vào thống kê & xuất Excel')
                    ->visible(fn () => auth()->user()?->isSuperAdmin() ?? false)
                    ->toggleable(isToggledHiddenByDefault: false),

                IconColumn::make('unlock_anytime')
                    ->label('Mở cổng tự do')
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-open')
                    ->falseIcon('heroicon-o-lock-closed')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->tooltip(fn ($record) => $record->unlock_anytime ? 'Khách có thể mở cổng bất kỳ lúc nào' : 'Mở cổng giới hạn theo giờ đặt phòng')
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters(
                OrderFilter::filter(), 
                layout: FiltersLayout::AboveContent
            )
            ->filtersFormColumns(['default' => 1, 'sm' => 2, 'lg' => 5])
            ->actions(array_merge(
                [
                    // Action Thông tin thanh toán — chỉ hiện với styles=2
                    Action::make('payment_info')
                        ->label('Thanh toán')
                        ->color('success')
                        ->hidden(fn ($record) => ($record->items->firstWhere('product_id', '!=', null)?->product?->styles ?? 1) != 2)
                        ->modalHeading(fn ($record) => 'Thông tin thanh toán — Đơn ' . $record->order_code)
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Đóng')
                        ->modalWidth('lg')
                        ->modalContent(function ($record) {
                            $isDeposit   = $record->deposit_percent !== null;
                            $fullAmt     = (int)($record->full_amount ?? $record->amount);
                            $depositPctV = $isDeposit ? (int)$record->deposit_percent : 0;
                            if ($isDeposit && $depositPctV > 0) {
                                // full_amount với đơn cọc = tiền cọc, phải reconstruct tổng
                                $depositAmt   = $fullAmt;
                                $realTotalV   = (int) round($fullAmt * 100 / $depositPctV);
                                $remainingAmt = $realTotalV - $depositAmt;
                            } else {
                                $depositAmt   = null;
                                $realTotalV   = $fullAmt;
                                $remainingAmt = null;
                            }

                            $fmt = fn($dt) => $dt ? \Carbon\Carbon::parse($dt)->format('d/m/Y H:i') : null;

                            // Header badge
                            $statusLabel = match($record->status) {
                                'paid'              => ['label' => 'Đã thanh toán đầy đủ', 'bg' => '#dcfce7', 'color' => '#166534'],
                                'deposit'           => ['label' => 'Đã cọc — chờ thanh toán còn lại', 'bg' => '#fef9c3', 'color' => '#854d0e'],
                                'pending'           => ['label' => 'Chờ thanh toán', 'bg' => '#f3f4f6', 'color' => '#374151'],
                                'failed'            => ['label' => 'Thất bại / Đã hủy', 'bg' => '#fee2e2', 'color' => '#991b1b'],
                                'cancelled_payment' => ['label' => 'Hủy QR thanh toán', 'bg' => '#f3f4f6', 'color' => '#6b7280'],
                                default             => ['label' => ucfirst($record->status), 'bg' => '#f3f4f6', 'color' => '#374151'],
                            };

                            $html = '
                                <div style="font-family:sans-serif;padding:4px 0;">
                                    <!-- Trạng thái tổng quan -->
                                    <div style="display:inline-flex;align-items:center;gap:8px;background:' . $statusLabel['bg'] . ';color:' . $statusLabel['color'] . ';padding:8px 16px;border-radius:999px;font-weight:700;font-size:14px;margin-bottom:20px;">
                                        ' . ($record->status === 'paid'
                                            ? '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>'
                                            : ($record->status === 'deposit'
                                                ? '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>'
                                                : '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>')) . '
                                        ' . $statusLabel['label'] . '
                                    </div>';

                            // Loại đơn
                            if ($isDeposit) {
                                $html .= '
                                    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 16px;margin-bottom:16px;">
                                        <div style="font-weight:700;font-size:13px;color:#1d4ed8;margin-bottom:10px;display:flex;align-items:center;gap:6px;"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#1d4ed8" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg> Đơn đặt cọc theo ngày</div>
                                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
                                            <div style="text-align:center;background:#fff;border-radius:8px;padding:10px;border:1px solid #e0e7ff;">
                                                <div style="font-size:11px;color:#6b7280;margin-bottom:4px;">Tổng đơn</div>
                                                <div style="font-weight:700;font-size:15px;color:#111827;">' . number_format($realTotalV, 0, ',', '.') . 'đ</div>
                                            </div>
                                            <div style="text-align:center;background:#fff;border-radius:8px;padding:10px;border:1px solid #fef08a;">
                                                <div style="font-size:11px;color:#6b7280;margin-bottom:4px;">Tiền cọc (' . $record->deposit_percent . '%)</div>
                                                <div style="font-weight:700;font-size:15px;color:#d97706;">' . number_format($depositAmt, 0, ',', '.') . 'đ</div>
                                            </div>
                                            <div style="text-align:center;background:#fff;border-radius:8px;padding:10px;border:1px solid #bbf7d0;">
                                                <div style="font-size:11px;color:#6b7280;margin-bottom:4px;">Còn lại</div>
                                                <div style="font-weight:700;font-size:15px;color:#16a34a;">' . number_format($remainingAmt, 0, ',', '.') . 'đ</div>
                                            </div>
                                        </div>
                                    </div>';
                            } else {
                                $html .= '
                                    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 16px;margin-bottom:16px;">
                                        <div style="font-weight:700;font-size:13px;color:#15803d;margin-bottom:6px;display:flex;align-items:center;gap:6px;"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#15803d" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg> Đơn thanh toán đầy đủ</div>
                                        <div style="font-size:20px;font-weight:800;color:#111827;">' . number_format($fullAmt, 0, ',', '.') . 'đ</div>
                                    </div>';
                            }

                            // Timeline — SVG icon helper
                            $svg = static function (string $type, string $stroke): string {
                                $a = "xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"{$stroke}\" stroke-width=\"2.5\" stroke-linecap=\"round\" stroke-linejoin=\"round\"";
                                return match ($type) {
                                    'banknote' => "<svg {$a}><rect x=\"2\" y=\"5\" width=\"20\" height=\"14\" rx=\"2\"/><line x1=\"2\" y1=\"10\" x2=\"22\" y2=\"10\"/></svg>",
                                    'check'    => "<svg {$a}><path d=\"M22 11.08V12a10 10 0 1 1-5.93-9.14\"/><polyline points=\"22 4 12 14.01 9 11.01\"/></svg>",
                                    'file'     => "<svg {$a}><path d=\"M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z\"/><polyline points=\"14 2 14 8 20 8\"/></svg>",
                                    'qr'       => "<svg {$a}><rect x=\"3\" y=\"3\" width=\"7\" height=\"7\"/><rect x=\"14\" y=\"3\" width=\"7\" height=\"7\"/><rect x=\"14\" y=\"14\" width=\"7\" height=\"7\"/><rect x=\"3\" y=\"14\" width=\"7\" height=\"7\"/></svg>",
                                    default    => "<svg {$a}><circle cx=\"12\" cy=\"12\" r=\"10\"/></svg>",
                                };
                            };

                            if ($isDeposit) {
                                $remainingDone   = $record->status === 'paid' && $record->deposit_percent !== null;
                                $remainingTime   = $record->remaining_paid_at ? $fmt($record->remaining_paid_at) : null;
                                $payMethod       = $record->remaining_payment_method ?? null;
                                $qrCreated       = ! empty($record->remaining_checkout_url);

                                // Dùng remaining_payment_method để xác định nhãn chính xác
                                if ($remainingDone) {
                                    if ($payMethod === 'cash') {
                                        $remainingLabel = 'Admin thu tiền mặt';
                                    } elseif ($payMethod === 'payos') {
                                        $remainingLabel = 'Khách thanh toán qua PayOS';
                                    } else {
                                        $remainingLabel = $qrCreated ? 'Khách thanh toán qua QR/PayOS' : 'Admin xác nhận thu tiền mặt';
                                    }
                                } else {
                                    $remainingLabel = 'Chờ thanh toán phần còn lại';
                                }

                                $steps = [
                                    [
                                        'icon_type' => 'banknote',
                                        'label'     => 'Khách đặt cọc',
                                        'time'      => $fmt($record->created_at),
                                        'done'      => true,
                                        'color'     => '#f59e0b',
                                        'sub'       => $depositAmt ? number_format($depositAmt, 0, ',', '.') . 'đ (' . $record->deposit_percent . '%)' : null,
                                    ],
                                    [
                                        'icon_type' => 'qr',
                                        'label'     => $qrCreated ? 'QR thanh toán đã được tạo' : 'Chưa tạo QR thanh toán',
                                        'time'      => null,
                                        'done'      => $qrCreated,
                                        'color'     => '#f59e0b',
                                        'sub'       => null,
                                    ],
                                    [
                                        'icon_type' => $payMethod === 'cash' ? 'banknote' : 'check',
                                        'label'     => $remainingLabel,
                                        'time'      => $remainingTime,
                                        'done'      => $remainingDone,
                                        'color'     => '#22c55e',
                                        'sub'       => ($remainingDone && $remainingAmt) ? number_format($remainingAmt, 0, ',', '.') . 'đ' : null,
                                    ],
                                ];
                            } else {
                                $steps = [
                                    [
                                        'icon_type' => 'file',
                                        'label'     => 'Khách tạo đơn',
                                        'time'      => $fmt($record->created_at),
                                        'done'      => true,
                                        'color'     => '#3b82f6',
                                        'sub'       => null,
                                    ],
                                    [
                                        'icon_type' => 'check',
                                        'label'     => 'Thanh toán đầy đủ',
                                        'time'      => $record->status === 'paid' ? $fmt($record->updated_at) : null,
                                        'done'      => $record->status === 'paid',
                                        'color'     => '#22c55e',
                                        'sub'       => $fullAmt ? number_format($fullAmt, 0, ',', '.') . 'đ' : null,
                                    ],
                                ];
                            }

                            $html .= '<div style="margin-top:4px;">';
                            foreach ($steps as $i => $step) {
                                $isLast     = $i === count($steps) - 1;
                                $dotBg      = $step['done'] ? $step['color'] : '#e5e7eb';
                                $dotBorder  = $step['done'] ? '' : 'border:2px dashed #9ca3af;';
                                $dotStroke  = $step['done'] ? 'white' : '#9ca3af';
                                $labelColor = $step['done'] ? '#111827' : '#9ca3af';
                                $iconHtml   = $svg($step['icon_type'], $dotStroke);

                                $timeStr = $step['time'] && $step['time'] !== '(Không rõ thời điểm)'
                                    ? '<span style="font-size:12px;color:#6b7280;">' . $step['time'] . '</span>'
                                    : ($step['done']
                                        ? '<span style="font-size:12px;color:#9ca3af;font-style:italic;">Không rõ thời điểm</span>'
                                        : '<span style="font-size:12px;color:#d1d5db;font-style:italic;">Chưa thực hiện</span>');
                                $subStr = $step['sub']
                                    ? ' <span style="font-size:12px;background:#f3f4f6;color:#374151;padding:1px 7px;border-radius:999px;font-weight:600;">' . $step['sub'] . '</span>'
                                    : '';

                                $html .= '
                                    <div style="display:flex;align-items:flex-start;gap:12px;">
                                        <div style="display:flex;flex-direction:column;align-items:center;flex-shrink:0;">
                                            <div style="width:36px;height:36px;border-radius:50%;background:' . $dotBg . ';' . $dotBorder . 'display:flex;align-items:center;justify-content:center;">' . $iconHtml . '</div>
                                            ' . (!$isLast ? '<div style="width:2px;background:#e5e7eb;flex:1;min-height:20px;margin:3px 0;"></div>' : '') . '
                                        </div>
                                        <div style="padding-bottom:' . (!$isLast ? '14px' : '0') . ';padding-top:6px;">
                                            <div style="font-weight:600;font-size:14px;color:' . $labelColor . ';">' . $step['label'] . $subStr . '</div>
                                            <div style="margin-top:3px;">' . $timeStr . '</div>
                                        </div>
                                    </div>
                                ';
                            }
                            $html .= '</div></div>';

                            return new HtmlString($html);
                        })
                        ->extraModalFooterActions(function ($action) {
                            $record = $action->getRecord();
                            $actions = [];

                            if ($record && $record->status === 'deposit' && $record->deposit_percent !== null) {
                                $actions[] = Action::make('modal_tao_qr')
                                    ->label('Tạo QR còn lại')
                                    ->color('warning')
                                    ->icon('heroicon-o-qr-code')
                                    ->requiresConfirmation()
                                    ->modalHeading('Tạo link QR thanh toán còn lại')
                                    ->modalDescription('Mỗi lần bấm sẽ tạo link PayOS mới. Link cũ (nếu có) sẽ bị thay thế.')
                                    ->modalSubmitActionLabel('Tạo và mở trang QR')
                                    ->action(function () use ($record) {
                                        try {
                                            $remaining = max(0, (int) $record->amount - (int) $record->full_amount);

                                            if ($remaining <= 0) {
                                                Notification::make()->warning()->title('Đơn đã được thanh toán đủ, không cần tạo QR')->send();
                                                return;
                                            }

                                            $payos = new PayOS(
                                                Config::get('payos.client_id'),
                                                Config::get('payos.api_key'),
                                                Config::get('payos.checksum_key')
                                            );

                                            $orderCode = (int) (intval(substr(strval(microtime(true) * 10000), -6)) . rand(10, 99));
                                            $expiredAt = now()->addMinutes(30);
                                            $response  = $payos->createPaymentLink([
                                                'orderCode'   => $orderCode,
                                                'amount'      => $remaining,
                                                'description' => 'Tt con lai - ' . $record->order_code,
                                                'returnUrl'   => \Modules\Payment\App\Filament\Resources\OrderResource::getUrl('edit', ['record' => $record->id]) . '?remaining_done=1',
                                                'cancelUrl'   => \Modules\Payment\App\Filament\Resources\OrderResource::getUrl('edit', ['record' => $record->id]) . '?remaining_cancelled=1',
                                                'buyerName'   => $record->buyer_name ?? '',
                                                'buyerPhone'  => $record->buyer_phone ?? '',
                                                'expiredAt'   => $expiredAt->timestamp,
                                                'items'       => [[
                                                    'name'     => 'Tiền còn lại - ' . ($record->items->first()?->name ?? 'Phòng'),
                                                    'quantity' => 1,
                                                    'price'    => $remaining,
                                                ]],
                                            ]);

                                            $checkoutUrl = $response['checkoutUrl'] ?? null;
                                            if (! $checkoutUrl) {
                                                Notification::make()->danger()->title('Không thể tạo link thanh toán')->send();
                                                return;
                                            }

                                            $record->update([
                                                'remaining_payos_code'   => (string) $orderCode,
                                                'remaining_checkout_url' => $checkoutUrl,
                                            ]);

                                            AuditLogger::log(
                                                'update', 'Order', $record,
                                                [],
                                                ['Tạo QR' => number_format($remaining, 0, ',', '.') . 'đ', 'Mã PayOS' => (string) $orderCode],
                                                'Đơn #' . $record->order_code
                                            );

                                            return redirect()->away($checkoutUrl);

                                        } catch (\Throwable $e) {
                                            Notification::make()->danger()->title('Lỗi tạo QR: ' . $e->getMessage())->send();
                                        }
                                    });

                                $actions[] = Action::make('modal_thu_tien')
                                    ->label('Đã thu tiền mặt')
                                    ->color('success')
                                    ->icon('heroicon-o-banknotes')
                                    ->requiresConfirmation()
                                    ->modalHeading('Xác nhận thu tiền mặt')
                                    ->modalDescription('Xác nhận đã thu tiền mặt phần còn lại. Đơn sẽ cập nhật "Đã thanh toán đầy đủ" và cấp mã truy cập cho khách.')
                                    ->modalSubmitActionLabel('Xác nhận')
                                    ->action(function () use ($record) {
                                        try {
                                            $record->update([
                                                'status'                   => 'paid',
                                                'remaining_paid_at'        => now(),
                                                'remaining_payment_method' => 'cash',
                                            ]);

                                            $record->load('items.product');
                                            $firstItem    = $record->items->sortBy('checkin_date')->first();
                                            $checkinDate  = $record->items->min('checkin_date');
                                            $checkoutDate = $record->items->max('checkout_date');
                                            $product      = $firstItem?->product;

                                            if (! $record->hasAccessCode()) {
                                                app(\Modules\BladeThemeV1\Services\AccessCode\AccessCodeService::class)
                                                    ->assignCodeToOrder($record->id, $record->category_id, $checkinDate, $checkoutDate, $product);
                                            }

                                            AuditLogger::log(
                                                'update', 'Order', $record,
                                                ['status' => 'deposit', 'remaining_payment_method' => null],
                                                ['status' => 'paid', 'remaining_payment_method' => 'cash'],
                                                'Đơn #' . $record->order_code
                                            );

                                            Notification::make()
                                                ->success()
                                                ->title('Đã xác nhận và cấp mã truy cập')
                                                ->send();

                                        } catch (\Throwable $e) {
                                            Notification::make()->danger()->title('Lỗi: ' . $e->getMessage())->send();
                                        }
                                    });
                            }

                            return $actions;
                        }),

                    // Nút xem danh sách khung giờ — đặt đầu tiên (ẩn với đơn style=2)
                    Action::make('view_items')
                        ->label('Khung giờ')
                        ->color('info')
                        ->badge(fn ($record) => $record->items?->count() ?? 0)
                        ->hidden(fn ($record) => ($record->items->firstWhere('product_id', '!=', null)?->product?->styles ?? 1) == 2)
                        ->modalHeading(fn ($record) => 'Danh sách khung giờ — Đơn ' . $record->order_code)
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Đóng')
                        ->modalWidth('2xl')
                        ->modalContent(function ($record) {
                            $record->loadMissing(['items.product', 'services']);
                            $items = $record->items;

                            if ($items->isEmpty()) {
                                return new HtmlString('<p class="text-gray-500 text-sm py-4 text-center">Không có khung giờ nào.</p>');
                            }

                            // Tính bulk discount per product group
                            $bulkData = static::computeItemsBulkData($items);

                            $rows = $items->map(function ($item, $index) use ($bulkData) {
                                $checkin  = $item->checkin_date
                                    ? \Carbon\Carbon::parse($item->checkin_date)->format('d/m/Y H:i')
                                    : '—';
                                $checkout = $item->checkout_date
                                    ? \Carbon\Carbon::parse($item->checkout_date)->format('d/m/Y H:i')
                                    : '—';

                                $basePrice     = (float)($item->price ?? 0);
                                $discountPct   = $bulkData['item_discount_pct'][$item->id] ?? 0;
                                $discountedPrice = $discountPct > 0 ? round($basePrice * (1 - $discountPct / 100)) : $basePrice;
                                $extraFee      = (float)($item->extra_fee ?? 0);

                                $priceHtml = $discountPct > 0
                                    ? '<span class="line-through text-gray-400 text-xs">' . number_format($basePrice, 0, ',', '.') . 'đ</span> '
                                      . '<span class="font-semibold text-blue-700">' . number_format($discountedPrice, 0, ',', '.') . 'đ</span>'
                                      . ' <span class="text-xs text-blue-500">(-' . $discountPct . '%)</span>'
                                    : '<span class="font-semibold">' . number_format($basePrice, 0, ',', '.') . 'đ</span>';

                                $extraHtml = $extraFee > 0
                                    ? '<span class="text-orange-500 text-xs ml-1">(+' . number_format($extraFee, 0, ',', '.') . 'đ phụ thu)</span>'
                                    : '';

                                return '
                                    <tr class="border-b last:border-0 hover:bg-gray-50">
                                        <td class="py-3 px-4 text-sm font-semibold text-gray-700">' . ($index + 1) . '</td>
                                        <td class="py-3 px-4">
                                            <span class="font-semibold text-primary-600">' . e($item->name ?? '—') . '</span>
                                        </td>
                                        <td class="py-3 px-4 text-sm text-gray-600">
                                            <div class="flex items-center gap-1">
                                                <span class="text-blue-500">📥</span> ' . $checkin . '
                                            </div>
                                            <div class="flex items-center gap-1 mt-1">
                                                <span class="text-red-500">📤</span> ' . $checkout . '
                                            </div>
                                        </td>
                                        <td class="py-3 px-4 text-sm text-right">
                                            <div>' . $priceHtml . $extraHtml . '</div>
                                        </td>
                                    </tr>
                                ';
                            })->join('');

                            $grandTotal    = static::computeOrderTotal($record);
                            $totalExtraFee = $items->sum(fn ($i) => (float)($i->extra_fee ?? 0));
                            $serviceTotal  = $record->services->sum('subtotal');
                            $discountTotal = $bulkData['total_discount'];

                            $footerRows = '';
                            if ($discountTotal > 0) {
                                $footerRows .= '
                                    <tr>
                                        <td colspan="3" class="py-2 px-4 text-sm text-blue-600 text-right">Giảm giá đặt nhiều khung:</td>
                                        <td class="py-2 px-4 text-sm font-semibold text-blue-600 text-right">-' . number_format($discountTotal, 0, ',', '.') . 'đ</td>
                                    </tr>';
                            }
                            if ($totalExtraFee > 0) {
                                $footerRows .= '
                                    <tr>
                                        <td colspan="3" class="py-2 px-4 text-sm text-orange-500 text-right">Phụ thu khách thêm:</td>
                                        <td class="py-2 px-4 text-sm font-semibold text-orange-500 text-right">+' . number_format($totalExtraFee, 0, ',', '.') . 'đ</td>
                                    </tr>';
                            }
                            if ($serviceTotal > 0) {
                                $footerRows .= '
                                    <tr>
                                        <td colspan="3" class="py-2 px-4 text-sm text-orange-400 text-right">Dịch vụ thêm:</td>
                                        <td class="py-2 px-4 text-sm font-semibold text-orange-400 text-right">+' . number_format($serviceTotal, 0, ',', '.') . 'đ</td>
                                    </tr>';
                            }
                            $footerRows .= '
                                <tr class="bg-blue-50">
                                    <td colspan="3" class="py-3 px-4 text-sm font-bold text-gray-700 text-right">Tổng thanh toán:</td>
                                    <td class="py-3 px-4 text-sm font-bold text-primary-600 text-right">' . number_format($grandTotal, 0, ',', '.') . 'đ</td>
                                </tr>';

                            $html = '
                                <div class="overflow-hidden rounded-lg border border-gray-200">
                                    <table class="w-full text-left">
                                        <thead class="bg-gray-50 border-b border-gray-200">
                                            <tr>
                                                <th class="py-2 px-4 text-xs font-semibold text-gray-500 uppercase">#</th>
                                                <th class="py-2 px-4 text-xs font-semibold text-gray-500 uppercase">Phòng</th>
                                                <th class="py-2 px-4 text-xs font-semibold text-gray-500 uppercase">Thời gian</th>
                                                <th class="py-2 px-4 text-xs font-semibold text-gray-500 uppercase text-right">Giá</th>
                                            </tr>
                                        </thead>
                                        <tbody>' . $rows . '</tbody>
                                        <tfoot class="bg-gray-50 border-t border-gray-200">' . $footerRows . '</tfoot>
                                    </table>
                                </div>
                            ';

                            return new HtmlString($html);
                        }),

                Action::make('toggle_stats')
                    ->label(fn ($record) => $record->exclude_from_stats ? 'Bật thống kê' : 'Tắt thống kê')
                    ->icon(fn ($record) => $record->exclude_from_stats ? 'heroicon-o-chart-bar' : 'heroicon-o-eye-slash')
                    ->color(fn ($record) => $record->exclude_from_stats ? 'success' : 'danger')
                    ->visible(fn () => auth()->user()?->isSuperAdmin() ?? false)
                    ->requiresConfirmation()
                    ->modalHeading(fn ($record) => $record->exclude_from_stats ? 'Bật lại thống kê cho đơn này?' : 'Loại đơn này khỏi thống kê?')
                    ->modalDescription(fn ($record) => $record->exclude_from_stats
                        ? 'Đơn sẽ được tính lại vào dashboard và xuất Excel.'
                        : 'Đơn sẽ không hiển thị trong dashboard và không có trong file Excel xuất ra.')
                    ->action(function ($record) {
                        $record->update(['exclude_from_stats' => ! $record->exclude_from_stats]);
                        Notification::make()
                            ->title($record->exclude_from_stats ? 'Đã loại khỏi thống kê' : 'Đã bật lại thống kê')
                            ->success()
                            ->send();
                    }),

                Action::make('toggle_unlock_anytime')
                    ->label(fn ($record) => $record->unlock_anytime ? 'Khoá giờ mở cổng' : 'Cho mở cổng tự do')
                    ->icon(fn ($record) => $record->unlock_anytime ? 'heroicon-o-lock-closed' : 'heroicon-o-lock-open')
                    ->color(fn ($record) => $record->unlock_anytime ? 'gray' : 'warning')
                    ->requiresConfirmation()
                    ->modalHeading(fn ($record) => $record->unlock_anytime
                        ? 'Khoá lại giờ mở cổng?'
                        : 'Cho phép khách mở cổng bất kỳ lúc nào?')
                    ->modalDescription(fn ($record) => $record->unlock_anytime
                        ? 'Khách sẽ chỉ được mở cổng trong khung giờ đặt phòng (±30 phút).'
                        : 'Khách có thể nhấn mở cổng bất kỳ lúc nào, dù chưa đến hoặc đã quá giờ đặt phòng. Dùng khi khách đến sớm hoặc về muộn.')
                    ->modalSubmitActionLabel(fn ($record) => $record->unlock_anytime ? 'Khoá lại' : 'Cho phép')
                    ->action(function ($record) {
                        $record->update(['unlock_anytime' => ! $record->unlock_anytime]);
                        Notification::make()
                            ->title($record->unlock_anytime
                                ? 'Đã cho phép khách mở cổng tự do'
                                : 'Đã khoá lại theo giờ đặt phòng')
                            ->success()
                            ->send();
                    }),

                Action::make('view_services')
    ->label('Dịch vụ')
    ->color('warning')
    ->badge(fn ($record) => $record->services?->count() ?? 0)
    ->modalHeading(fn ($record) => 'Dịch vụ thêm — Đơn ' . $record->order_code)
    ->modalSubmitAction(false)
    ->modalCancelActionLabel('Đóng')
    ->modalWidth('lg')
    ->modalContent(function ($record) {
        $record->load('services');
        $services = $record->services;

        if ($services->isEmpty()) {
            return new HtmlString('<p class="text-gray-500 text-sm py-4 text-center">Không có dịch vụ thêm nào.</p>');
        }

        $rows = $services->map(function ($item, $index) {
            $price    = number_format($item->price ?? 0, 0, ',', '.') . ' đ';
            $subtotal = number_format($item->subtotal ?? 0, 0, ',', '.') . ' đ';

            return '
                <tr class="border-b last:border-0 hover:bg-gray-50">
                    <td class="py-3 px-4 text-sm font-semibold text-gray-700">' . ($index + 1) . '</td>
                    <td class="py-3 px-4">
                        <span class="font-semibold text-gray-800">' . e($item->service_name ?? '—') . '</span>
                    </td>
                    <td class="py-3 px-4 text-sm text-center text-gray-600">' . ($item->quantity ?? 1) . '</td>
                    <td class="py-3 px-4 text-sm text-right text-gray-600">' . $price . '</td>
                    <td class="py-3 px-4 text-sm text-right font-semibold text-orange-500">' . $subtotal . '</td>
                </tr>
            ';
        })->join('');

        $grandTotal = number_format($services->sum('subtotal'), 0, ',', '.') . ' đ';

        $html = '
            <div class="overflow-hidden rounded-lg border border-gray-200">
                <table class="w-full text-left">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="py-2 px-4 text-xs font-semibold text-gray-500 uppercase">#</th>
                            <th class="py-2 px-4 text-xs font-semibold text-gray-500 uppercase">Dịch vụ</th>
                            <th class="py-2 px-4 text-xs font-semibold text-gray-500 uppercase text-center">SL</th>
                            <th class="py-2 px-4 text-xs font-semibold text-gray-500 uppercase text-right">Đơn giá</th>
                            <th class="py-2 px-4 text-xs font-semibold text-gray-500 uppercase text-right">Thành tiền</th>
                        </tr>
                    </thead>
                    <tbody>' . $rows . '</tbody>
                    <tfoot class="bg-gray-50 border-t border-gray-200">
                        <tr>
                            <td colspan="4" class="py-3 px-4 text-sm font-bold text-gray-700 text-right">Tổng cộng:</td>
                            <td class="py-3 px-4 text-sm font-bold text-orange-500 text-right">' . $grandTotal . '</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        ';

        return new HtmlString($html);
    }),
                ],
                OrderAction::action()
            ), position: ActionsPosition::BeforeCells)
            ->bulkActions(OrderBulkAction::bulkActions());
    }

    /**
     * Tính tổng đơn hàng áp dụng bulk_discount_rules từ cài đặt phòng.
     * Mỗi order item = 1 khung giờ; số lượng items cùng product_id xác định mức giảm.
     */
    private static function computeOrderTotal(Order $record): int
    {
        // Đơn cọc và đơn thường: đều trả về full_amount
        // - Đơn cọc: full_amount = số tiền cọc khách cần thanh toán ngay
        // - Đơn thường: full_amount = tổng tiền thực sau discount
        return (int)($record->full_amount ?? $record->amount ?? 0);
    }

    /**
     * Trả về mảng thông tin bulk discount cho từng item (dùng trong modal).
     * ['item_discount_pct' => [item_id => pct], 'total_discount' => int]
     */
    private static function computeItemsBulkData($items): array
    {
        $itemDiscountPct = [];
        $totalDiscount   = 0;

        $itemsByProduct = $items
            ->filter(fn ($i) => $i->product_id)
            ->groupBy('product_id');

        foreach ($itemsByProduct as $productId => $groupItems) {
            $product = $groupItems->first()?->product;

            $slotCount       = $groupItems->count();
            $bulkDiscountPct = 0;

            if ($product && $slotCount >= 2) {
                $rules = $product->bulk_discount_rules ?? [];
                usort($rules, fn ($a, $b) => (int)($b['slots'] ?? 0) - (int)($a['slots'] ?? 0));
                foreach ($rules as $rule) {
                    if ($slotCount >= (int)($rule['slots'] ?? 0)) {
                        $bulkDiscountPct = (float)($rule['discount'] ?? 0);
                        break;
                    }
                }
            }

            foreach ($groupItems as $item) {
                $itemDiscountPct[$item->id] = $bulkDiscountPct;
                if ($bulkDiscountPct > 0) {
                    $totalDiscount += (int)round((float)($item->price ?? 0) * $bulkDiscountPct / 100);
                }
            }
        }

        return [
            'item_discount_pct' => $itemDiscountPct,
            'total_discount'    => $totalDiscount,
        ];
    }
}