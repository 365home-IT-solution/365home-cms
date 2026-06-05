<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thông Báo Đơn Hàng</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #f5f5f5;
            color: #333;
            line-height: 1.6;
        }

        .email-wrapper {
            max-width: 600px;
            margin: 20px auto;
            background-color: #ffffff;
            border: 1px solid #e0e0e0;
        }

        .header {
            background-color: #4E6B4C;
            color: #ffffff;
            padding: 30px 40px;
        }

        .header h1 {
            font-size: 22px;
            font-weight: 600;
            margin: 0;
        }

        .header p {
            font-size: 14px;
            color: #bdc3c7;
            margin-top: 5px;
        }

        .content {
            padding: 40px;
        }

        .section {
            margin-bottom: 30px;
        }

        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e0e0e0;
        }

        .info-row {
            display: table;
            width: 100%;
            margin-bottom: 10px;
            font-size: 14px;
        }

        .info-label {
            display: table-cell;
            width: 140px;
            color: #7f8c8d;
            font-weight: 500;
        }

        .info-value {
            display: table-cell;
            color: #2c3e50;
        }

        .info-value strong {
            font-weight: 600;
        }

        .amount {
            font-size: 24px;
            font-weight: 600;
            color: #2c3e50;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            font-size: 13px;
            font-weight: 500;
            border-radius: 3px;
        }

        .status-paid {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .status-pending {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        .note-box {
            background-color: #f8f9fa;
            border-left: 3px solid #3498db;
            padding: 15px;
            margin-top: 15px;
            font-size: 14px;
        }

        .note-box strong {
            display: block;
            margin-bottom: 8px;
            color: #2c3e50;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 14px;
        }

        table th {
            background-color: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #e0e0e0;
        }

        table td {
            padding: 12px;
            border-bottom: 1px solid #e0e0e0;
            color: #2c3e50;
        }

        table tr:last-child td {
            border-bottom: none;
        }

        table th:nth-child(2),
        table td:nth-child(2) {
            text-align: center;
        }

        table th:nth-child(3),
        table td:nth-child(3) {
            text-align: right;
        }

        .item-name {
            font-weight: 500;
        }

        .item-date {
            font-size: 13px;
            color: #7f8c8d;
            margin-top: 4px;
        }

        .footer {
            background-color: #f8f9fa;
            padding: 25px 40px;
            border-top: 1px solid #e0e0e0;
            font-size: 13px;
            color: #7f8c8d;
            text-align: center;
        }

        .footer a {
            color: #3498db;
            text-decoration: none;
        }

        .footer a:hover {
            text-decoration: underline;
        }

        @media only screen and (max-width: 600px) {
            .email-wrapper {
                margin: 0;
                border: none;
            }

            .header,
            .content,
            .footer {
                padding: 20px;
            }

            .info-row {
                display: block;
            }

            .info-label {
                display: block;
                width: 100%;
                margin-bottom: 3px;
            }

            .info-value {
                display: block;
                margin-bottom: 12px;
            }

            table {
                font-size: 13px;
            }

            table th,
            table td {
                padding: 8px;
            }
        }
    </style>
</head>

<body>
    <div class="email-wrapper">
        <!-- Header -->
        <div class="header">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                    <td valign="middle">
                        <h1 style="margin: 0; color: #ffffff;">Đơn Hàng Mới</h1>
                    </td>
                    <td valign="middle" align="right">
                        <div style="text-align: right;">
                            @if($order->status === 'paid')
                            <span class="status-badge status-paid" style="display: inline-block; margin-bottom: 5px;">Đã thanh toán</span>
                            @else
                            <span class="status-badge status-pending" style="display: inline-block; margin-bottom: 5px;">Đang xử lý</span>
                            @endif
                            <br>
                            <span style="font-size: 13px; color: #e0e0e0;">{{ $order->created_at->format('d/m/Y H:i') }}</span>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Content -->
        <div class="content">
            <!-- Order Details -->
            <div class="section">
                <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 15px; padding-bottom: 8px; border-bottom: 1px solid #e0e0e0;">
                    <tr>
                        <td valign="middle">
                            <div class="section-title" style="margin: 0; padding: 0; border: none;">Thông Tin Đơn Hàng</div>
                        </td>
                        <td valign="middle" align="right" style="white-space: nowrap;">
                            <span style="font-size: 14px; color: #7f8c8d;">Mã: <strong style="color: #2c3e50;">#{{ $order->order_code }}</strong></span>
                        </td>
                    </tr>
                </table>
                <table>
                    <tbody>
                        <tr>
                            <td style="width: 30%; font-weight: 500; color: #7f8c8d;">Khách hàng</td>
                            <td><strong>{{ $order->buyer_name }}</strong></td>
                        </tr>
                        <tr>
                            <td style="font-weight: 500; color: #7f8c8d;">Liên hệ</td>
                            <td>{{ collect([$order->buyer_phone, $order->buyer_email])->filter()->implode(' | ') }}</td>
                        </tr>
                        @if($order->buyer_address)
                        <tr>
                            <td style="font-weight: 500; color: #7f8c8d;">Địa chỉ</td>
                            <td>{{ collect([$order->buyer_address, $order->ward_name, $order->district_name, $order->province_name])->filter()->implode(', ') }}</td>
                        </tr>
                        @endif

                        @foreach($order->items as $item)
                        <tr style="border-top: 2px solid #e0e0e0;">
                            <td style="font-weight: 500; color: #7f8c8d;">Phòng/Dịch vụ</td>
                            <td><strong style="color: #2c3e50; font-size: 15px;">{{ $item->name }}</strong></td>
                        </tr>
                        @if($item->checkin_date && $item->checkout_date)
                        <tr>
                            <td style="font-weight: 500; color: #7f8c8d;">Thời gian</td>
                            <td>
                                {{ \Carbon\Carbon::parse($item->checkin_date)->format('d/m/Y') }}
                                @if($item->checkin_datetime) {{ \Carbon\Carbon::parse($item->checkin_datetime)->format('H:i') }}@endif
                                -
                                {{ \Carbon\Carbon::parse($item->checkout_date)->format('d/m/Y') }}
                                @if($item->checkout_datetime) {{ \Carbon\Carbon::parse($item->checkout_datetime)->format('H:i') }}@endif
                                @if($item->slot_label) | <span style="color: #7f8c8d;">{{ $item->slot_label }}</span>@endif
                            </td>
                        </tr>
                        @endif
                        <tr>
                            <td style="font-weight: 500; color: #7f8c8d;">Số khách & giá</td>
                            <td>
                                {{ collect([
                                    ($item->guest_count ?? $order->guest_count) ? ($item->guest_count ?? $order->guest_count) . ' người' : null,
                                    $item->quantity . ' ' . ($item->quantity > 1 ? 'phòng' : 'phòng') . ' × ' . number_format($item->price, 0, ',', '.') . ' ₫',
                                    $item->discount ? '<span style="color: #e74c3c;">(-' . number_format($item->discount, 0, ',', '.') . ' ₫)</span>' : null,
                                    $item->extra_fee ? '<span style="color: #7f8c8d;">(+' . number_format($item->extra_fee, 0, ',', '.') . ' ₫ phụ phí)</span>' : null
                                ])->filter()->implode(' | ') }}
                            </td>
                        </tr>
                        @if(!$loop->last)
                        <tr>
                            <td colspan="2" style="padding: 8px 0;"></td>
                        </tr>
                        @endif
                        @endforeach

                        @if($order->payment_method)
                        <tr>
                            <td style="font-weight: 500; color: #7f8c8d; padding-top: 15px; border-top: 1px solid #e0e0e0;">Thanh toán</td>
                            <td style="padding-top: 15px; border-top: 1px solid #e0e0e0;">{{ ucfirst($order->payment_method) }}</td>
                        </tr>
                        @endif

                        <tr style="border-top: 2px solid #2c3e50;">
                            <td style="text-align: right; padding-top: 15px; font-weight: 600; color: #2c3e50;">Tổng tiền:</td>
                            <td style="text-align: right; padding-top: 15px;">
                                <strong style="font-size: 20px; color: #2c3e50;">{{ number_format($order->full_amount, 0, ',', '.') }} ₫</strong>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Notes -->
            @if($order->note_for_admin)
            <div class="section">
                <div class="note-box">
                    <strong>Ghi chú từ khách hàng</strong>
                    {{ $order->note_for_admin }}
                </div>
            </div>
            @endif
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>&copy; {{ date('Y') }} 365 Home</p>
        </div>
    </div>
</body>