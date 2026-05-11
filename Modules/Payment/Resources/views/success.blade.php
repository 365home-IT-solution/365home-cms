<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đặt phòng thành công</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .success-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            max-width: 900px;
            width: 100%;
            overflow: hidden;
            animation: slideUp 0.6s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .success-header {
            background: linear-gradient(135deg, #4CAF50, #45a049);
            color: white;
            padding: 40px 20px;
            text-align: center;
            position: relative;
        }

        .success-header.deposit {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }

        .success-icon {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.05);
            }

            100% {
                transform: scale(1);
            }
        }

        .success-icon i {
            font-size: 40px;
        }

        .success-title {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .success-subtitle {
            font-size: 16px;
            opacity: 0.9;
            font-weight: 300;
        }

        .order-details {
            padding: 30px;
        }

        .detail-section {
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #f0f0f0;
        }

        .detail-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #4CAF50;
            width: 20px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .info-grid2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            font-weight: 500;
            margin-bottom: 5px;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-size: 15px;
            color: #333;
            font-weight: 500;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 8px 15px;
            background: #4CAF50;
            color: white;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
        }

        .room-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            border: 1px solid #e9ecef;
        }

        .room-card:last-child {
            margin-bottom: 0;
        }

        .room-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #dee2e6;
        }

        .room-name {
            font-size: 16px;
            font-weight: 600;
            color: #333;
        }

        .room-price {
            font-size: 16px;
            font-weight: 600;
            color: #4CAF50;
        }

        .room-time {
            font-size: 13px;
            color: #666;
            margin-top: 5px;
        }

        .room-badge {
            display: inline-block;
            background: #fff3cd;
            color: #856404;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            margin-top: 8px;
        }

        .price-summary {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-top: 15px;
        }

        .price-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding: 8px 0;
            font-size: 15px;
        }

        .price-row.original {
            color: #666;
        }

        .price-row.original .price-value {
            text-decoration: line-through;
        }

        .price-row.discount {
            color: #28a745;
        }

        .price-row.bulk-discount {
            color: #007bff;
            background: #e7f3ff;
            margin: 0 -10px;
            padding: 8px 10px;
            border-radius: 5px;
        }

        .price-row.extra-fee {
            color: #fd7e14;
            background: #fff3e0;
            margin: 0 -10px;
            padding: 8px 10px;
            border-radius: 5px;
        }

        .price-row.total {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px solid #4CAF50;
            font-weight: 700;
            font-size: 18px;
            color: #4CAF50;
        }

        .success-notice {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .success-notice i {
            font-size: 20px;
        }

        .discount-note {
            background: #e8f5e9;
            border-left: 4px solid #4caf50;
            padding: 12px;
            margin-top: 15px;
            border-radius: 4px;
            font-size: 13px;
            color: #2e7d32;
        }

        .actions {
            padding: 30px;
            background: #f8f9fa;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn {
            flex: 1;
            min-width: 150px;
            padding: 15px 25px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary {
            background: #4CAF50;
            color: white;
        }

        .btn-primary:hover {
            background: #45a049;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(76, 175, 80, 0.3);
        }

        @media (max-width: 768px) {
            .success-container {
                margin: 10px;
            }

            .success-header {
                padding: 30px 20px;
            }

            .order-details {
                padding: 20px;
            }

            .info-grid,
            .info-grid2 {
                grid-template-columns: 1fr;
            }

            .actions {
                padding: 20px;
                flex-direction: column;
            }

            .btn {
                min-width: auto;
            }

            .room-header {
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
</head>

<body>
    <div class="success-container">
        <!-- Header -->
        @php $isDepositOrder = isset($order) && $order && $order->deposit_percent !== null && $order->status === 'deposit'; @endphp
        <div class="success-header {{ $isDepositOrder ? 'deposit' : '' }}">
            <div class="success-icon">
                <i class="fas {{ $isDepositOrder ? 'fa-piggy-bank' : 'fa-check' }}"></i>
            </div>
            @if($isDepositOrder)
            <h1 class="success-title">Đặt cọc thành công!</h1>
            <p class="success-subtitle">Cọc {{ $order->deposit_percent }}% đã được xác nhận — vui lòng thanh toán phần còn lại khi nhận phòng</p>
            @else
            <h1 class="success-title">Đặt phòng thành công!</h1>
            <p class="success-subtitle">Cảm ơn bạn đã tin tưởng dịch vụ của chúng tôi</p>
            @endif
        </div>

        <!-- Order Details -->
        <div class="order-details">
            <!-- Order Info -->
            <div class="detail-section">
                <h3 class="section-title">
                    <i class="fas fa-receipt"></i>
                    Thông tin đơn hàng
                </h3>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Mã đơn hàng</span>
                        <span class="info-value">{{ $order->order_code ?? 'Không có' }}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Trạng thái</span>
                        @if($isDepositOrder)
                        <span class="status-badge" style="background:#f59e0b;">
                            <i class="fas fa-piggy-bank"></i>
                            Đã đặt cọc {{ $order->deposit_percent }}%
                        </span>
                        @else
                        <span class="status-badge">
                            <i class="fas fa-check-circle"></i>
                            {{ $order->status == 'paid' ? 'Đã thanh toán' : 'Đang xử lý' }}
                        </span>
                        @endif
                    </div>
                    <div class="info-item">
                        <span class="info-label">Ngày đặt</span>
                        <span class="info-value">{{ $order->created_at ? $order->created_at->format('d/m/Y H:i') : 'Không có' }}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Phương thức thanh toán</span>
                        <span class="info-value">{{ $order->payment_method ?? 'Chuyển khoản ngân hàng' }}</span>
                    </div>
                </div>
            </div>

            <!-- Customer Info -->
            <div class="detail-section">
                <h3 class="section-title">
                    <i class="fas fa-user"></i>
                    Thông tin khách hàng
                </h3>
                <div class="info-grid2">
                    <div class="info-item">
                        <span class="info-label">Họ và tên</span>
                        <span class="info-value">{{ $order->buyer_name ?? 'Không có' }}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Số điện thoại</span>
                        <span class="info-value">{{ $order->buyer_phone ?? 'Không có' }}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Số lượng khách</span>
                        <span class="info-value">{{ $order->guest_count ?? 1 }} người</span>
                    </div>
                </div>
            </div>

            <!-- Room Info -->
            @if($order->items && $order->items->count() > 0)
            <div class="detail-section">
                <h3 class="section-title">
                    <i class="fas fa-bed"></i>
                    Chi tiết phòng đã đặt ({{ $order->items->count() }} khung giờ)
                </h3>

                @foreach($order->items as $index => $item)
                @php
                    $checkinDateTime = $item->checkin_date ? \Carbon\Carbon::parse($item->checkin_date) : null;
                    $checkoutDateTime = $item->checkout_date ? \Carbon\Carbon::parse($item->checkout_date) : null;
                @endphp

                <div class="room-card">
                    <div class="room-header">
                        <div>
                            <div class="room-name">{{ $index + 1 }}. {{ $item->name ?? 'Phòng' }}</div>
                            @if($checkinDateTime && $checkoutDateTime)
                            <div class="room-time">
                                <i class="far fa-clock"></i>
                                {{ $checkinDateTime->format('d/m/Y H:i') }} — {{ $checkoutDateTime->format('d/m/Y H:i')
                                }}
                            </div>
                            @endif

                            @if($item->description)
                                @php
                                    $desc = json_decode($item->description, true);
                                    $overNight = $desc['over_night'] ?? 0;
                                @endphp
                                @if($overNight == 1)
                                    span class="room-badge"><i class="fas fa-moon"></i> Qua đêm</span>
                                @endif
                            @endif
                        </div>
                        <div class="room-price">{{ number_format($item->price, 0, ',', '.') }}đ</div>
                    </div>

                    @if($item->extra_fee > 0)
                    <div style="font-size: 13px; color: #fd7e14; margin-top: 5px;">
                        <i class="fas fa-user-plus"></i> Bao gồm phụ phí: {{ number_format($item->extra_fee, 0, ',','.') }}đ
                    </div>
                    @endif
                </div>
                @endforeach
            </div>
            @else
            <div class="detail-section">
                <h3 class="section-title">
                    <i class="fas fa-bed"></i>
                    Thông tin phòng
                </h3>
                <div class="room-card">
                    <div class="room-name">Không có thông tin phòng</div>
                </div>
            </div>
            @endif
            <div class="detail-section">
                <h3 class="section-title">
                    <i class="fas fa-credit-card"></i>
                    Chi tiết thanh toán
                </h3>

                @php
                $itemCount = $order->items->count();
                $originalTotal = $order->items->sum('price');
                $totalExtraFee = $order->items->sum('extra_fee');
                $bulkDiscountRate = 0;
                if ($itemCount === 2) {
                $bulkDiscountRate = 0.05;
                } elseif ($itemCount >= 3) {
                $bulkDiscountRate = 0.10;
                }

                $bulkDiscount = ($originalTotal - $totalExtraFee) * $bulkDiscountRate;
                $finalTotal = $originalTotal - $bulkDiscount;
                @endphp

                <div class="price-summary">
                    @if($isDepositOrder)
                    {{-- Deposit breakdown --}}
                    @php
                        $depositFullAmount  = $order->full_amount ?? $order->amount;
                        $depositPaidAmount  = $order->amount;
                        $depositRemaining   = $depositFullAmount - $depositPaidAmount;
                    @endphp
                    <div class="price-row">
                        <span>Tổng tiền phòng ({{ $order->items->count() > 1 ? $order->items->count() . ' đêm' : '1 đêm' }}):</span>
                        <span>{{ number_format($depositFullAmount, 0, ',', '.') }}đ</span>
                    </div>
                    <div class="price-row" style="color:#f59e0b; background:#fffbeb; margin:0 -10px; padding:8px 10px; border-radius:5px;">
                        <span><i class="fas fa-piggy-bank"></i> Tiền cọc thanh toán ngay ({{ $order->deposit_percent }}%):</span>
                        <span style="font-weight:700;">{{ number_format($depositPaidAmount, 0, ',', '.') }}đ</span>
                    </div>
                    <div class="price-row" style="color:#6b7280; border-top:1px dashed #e5e7eb; margin-top:8px; padding-top:8px;">
                        <span>⏳ Còn lại thanh toán khi nhận phòng:</span>
                        <span style="font-weight:600;">{{ number_format($depositRemaining, 0, ',', '.') }}đ</span>
                    </div>
                    <div style="background:#fffbeb; border:1px solid #fbbf24; border-radius:8px; padding:12px; margin-top:12px; font-size:13px; color:#92400e;">
                        <i class="fas fa-info-circle"></i>
                        Số tiền còn lại <strong>{{ number_format($depositRemaining, 0, ',', '.') }}đ</strong> sẽ được thanh toán khi bạn tra cứu đơn hoặc khi nhận phòng. Mã mở khóa cổng sẽ được cấp sau khi thanh toán đủ.
                    </div>
                    @else
                    {{-- Full payment breakdown --}}
                    @if($bulkDiscount > 0)
                    <div class="price-row original">
                        <span>Tổng giá gốc ({{ $itemCount }} khung):</span>
                        <span class="price-value">{{ number_format($originalTotal - $totalExtraFee, 0, ',', '.') }}đ</span>
                    </div>

                    <div class="price-row bulk-discount">
                        <span>
                                <i class="fas fa-percent"></i>
                                Giảm đặt {{ $itemCount }} khung ({{ $bulkDiscountRate * 100 }}%):
                            </span>
                        <span>-{{ number_format($bulkDiscount, 0, ',', '.') }}đ</span>
                    </div>
                    @endif

                    @if($totalExtraFee > 0)
                    <div class="price-row extra-fee">
                        <span>
                                <i class="fas fa-user-plus"></i>
                                Phụ phí khách thêm:
                            </span>
                        <span>+{{ number_format($totalExtraFee, 0, ',', '.') }}đ</span>
                    </div>
                    @endif

                    <div class="price-row total">
                        <span><strong>Tổng thanh toán</strong></span>
                        <span><strong>{{ number_format($finalTotal, 0, ',', '.') }}đ</strong></span>
                    </div>

                    @if($itemCount >= 2)
                    <div class="discount-note">
                        <i class="fas fa-info-circle"></i>
                        Bạn đã tiết kiệm {{ number_format($bulkDiscount, 0, ',', '.') }}đ nhờ ưu đãi đặt nhiều khung
                        giờ!
                    </div>
                    @endif
                    @endif
                </div>

                <div class="success-notice" @if($isDepositOrder) style="background:#fffbeb; border-color:#fbbf24; color:#92400e;" @endif>
                    <i class="fas @if($isDepositOrder) fa-piggy-bank @else fa-check-circle @endif"></i>
                    @if($isDepositOrder)
                    <span>Tiền cọc đã được xác nhận. Vui lòng tra cứu đơn để thanh toán phần còn lại và nhận mã cổng.</span>
                    @else
                    <span>Thanh toán đã được xác nhận. Chúng tôi sẽ liên hệ với bạn trong thời gian sớm nhất.</span>
                    @endif
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="actions">
            <a href="/" class="btn btn-primary">
                <i class="fas fa-home"></i>
                Về trang chủ
            </a>
            <a target="_blank" href="{{ route('booking.detail', ['code' => $order->order_code]) }}" class="btn btn-secondary">
                <i class="fas fa-search"></i>
                Tra cứu đơn đặt phòng ngay
            </a>
        </div>
    </div>
</body>

</html>