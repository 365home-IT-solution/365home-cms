<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thanh toán không thành công</title>
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

        .cancel-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            max-width: 800px;
            width: 100%;
            overflow: hidden;
            border: 1px solid #f0f0f0;
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

        .cancel-header {
            background: linear-gradient(135deg, #ff6b6b, #ee5a52);
            color: white;
            padding: 40px 30px;
            text-align: center;
            position: relative;
        }

        .cancel-icon {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            animation: shake 0.5s ease-in-out;
        }

        @keyframes shake {

            0%,
            100% {
                transform: translateX(0);
            }

            25% {
                transform: translateX(-5px);
            }

            75% {
                transform: translateX(5px);
            }
        }

        .cancel-icon i {
            font-size: 40px;
        }

        .cancel-title {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .cancel-subtitle {
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
            color: #ff6b6b;
            width: 20px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
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
            background: #ff6b6b;
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
            color: #ff6b6b;
        }

        .room-time {
            font-size: 13px;
            color: #666;
            margin-top: 5px;
        }

        .room-badge {
            display: inline-block;
            background: #e3f2fd;
            color: #1976d2;
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
            border-top: 2px solid #dee2e6;
            font-weight: 700;
            font-size: 18px;
            color: #ff6b6b;
        }

        .cancel-notice {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .cancel-notice i {
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

        .btn-secondary {
            background: white;
            color: #333;
            border: 2px solid #e9ecef;
        }

        .btn-secondary:hover {
            background: #f8f9fa;
            border-color: #ff6b6b;
            color: #ff6b6b;
        }

        @media (max-width: 768px) {
            .cancel-container {
                margin: 10px;
            }

            .cancel-header {
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
    <div class="cancel-container">
        <!-- Header -->
        <div class="cancel-header">
            <div class="cancel-icon">
                <i class="fas fa-times"></i>
            </div>
            <h1 class="cancel-title">Thanh toán không thành công</h1>
            <p class="cancel-subtitle">Đơn hàng của bạn chưa được hoàn tất</p>
        </div>

        <!-- Order Details -->
        <div class="order-details">
            <!-- Order Info -->
            <div class="detail-section">
                <h3 class="section-title">
                    <i class="fas fa-receipt"></i>
                    Thông tin đơn đặt phòng
                </h3>
                <div class="info-grid2">
                    <div class="info-item">
                        <span class="info-label">Mã đặt phòng</span>
                        <span class="info-value">{{ $order->order_code ?? 'Không có' }}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Trạng thái</span>
                        <span class="status-badge">
                            <i class="fas fa-times-circle"></i>
                            {{ $order->status == 'failed' ? 'Đã hủy' : 'Thanh toán thất bại' }}
                        </span>
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
                <div class="info-grid">
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
                    Chi tiết phòng đã chọn ({{ $order->items->count() }} khung giờ)
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
                            <span class="room-badge">
                                            <i class="fas fa-moon"></i> Qua đêm
                                        </span>
                            @endif
                            @endif
                        </div>
                        <div class="room-price">{{ number_format($item->price, 0, ',', '.') }}đ</div>
                    </div>

                    @if($item->extra_fee > 0)
                    <div style="font-size: 13px; color: #fd7e14; margin-top: 5px;">
                        <i class="fas fa-user-plus"></i> Bao gồm phụ phí: {{ number_format($item->extra_fee, 0, ',',
                        '.') }}đ
                    </div>
                    @endif
                </div>
                @endforeach
            </div>
            @endif

            <!-- Payment Info -->
            <div class="detail-section">
                <h3 class="section-title">
                    <i class="fas fa-credit-card"></i>
                    Chi tiết thanh toán
                </h3>

                @php
                $itemCount = $order->items->count();
                $originalTotal = $order->items->sum('price');
                $totalExtraFee = $order->items->sum('extra_fee');

                // Tính giảm giá bulk
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
                        <span><strong>Tổng cộng (Đã hủy)</strong></span>
                        <span>
                            <strong style="text-decoration: line-through; color: #999;">
                                {{ number_format($finalTotal, 0, ',', '.') }}đ
                            </strong>
                        </span>
                    </div>

                    @if($itemCount >= 2)
                    <div class="discount-note">
                        <i class="fas fa-info-circle"></i>
                        Ưu đãi: Giảm 5% khi đặt 2 khung giờ, giảm 10% khi đặt từ 3 khung giờ trở lên
                    </div>
                    @endif
                </div>

                <div class="cancel-notice">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Đơn hàng đã bị hủy. Không có khoản thanh toán nào được thực hiện.</span>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="actions">
            <a href="/" class="btn btn-secondary">
                <i class="fas fa-home"></i>
                Về trang chủ
            </a>
        </div>
    </div>
</body>

</html>