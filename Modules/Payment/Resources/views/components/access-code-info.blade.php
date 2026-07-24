@if(!$code)
<div class="p-4 rounded bg-gray-100 text-sm text-gray-700">Chưa có mã cổng</div>
@else
<div
    style="background: linear-gradient(135deg, #4e6b4c 0%, #0fc65c 100%); border-radius: 12px; padding: 24px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
    <style>
        .access-code-container {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .access-code-header {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6b7280;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .access-code-value {
            font-size: 32px;
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: #667eea;
            letter-spacing: 3px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .copy-btn {
            background: #f3f4f6;
            border: none;
            border-radius: 6px;
            padding: 8px 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #6b7280;
        }

        .copy-btn:hover {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
        }

        .time-info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-top: 16px;
        }

        .time-card {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 8px;
            padding: 16px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .time-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .time-icon.start {
            background: #d1fae5;
            color: #059669;
        }

        .time-icon.end {
            background: #fef3c7;
            color: #d97706;
        }

        .time-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .time-date {
            font-size: 14px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 2px;
        }

        .time-hour {
            font-size: 12px;
            color: #6b7280;
        }

        .info-banner {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            padding: 12px;
            margin-top: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: white;
            font-size: 13px;
        }

        .info-icon {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
        }

        /* Dark mode support */
        .dark .access-code-container,
        .dark .time-card {
            background: #1f2937;
        }

        .dark .access-code-header,
        .dark .time-label,
        .dark .time-hour {
            color: #9ca3af;
        }

        .dark .time-date,
        .dark .access-code-value {
            color: #f3f4f6;
        }

        @media (max-width: 768px) {
            .time-info-grid {
                grid-template-columns: 1fr;
            }

            .access-code-value {
                font-size: 24px;
            }
        }
    </style>

    <!-- Mã cổng chính -->
    <div class="access-code-container">
        <div class="access-code-header">Mã cổng của bạn</div>
        <div class="access-code-value">
            <span id="accessCode">{{ $code->code ?? '—' }}</span>
            <span class="copy-btn" onclick="copyCode()" title="Sao chép mã">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>
            </span>
        </div>
    </div>

    <!-- Thông tin thời gian -->
    @if($code->valid_from || $code->valid_until)
    <div class="time-info-grid">
        @if($code->valid_from)
        <div class="time-card">
            <div class="time-icon start">
                <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd"
                        d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                        clip-rule="evenodd" />
                </svg>
            </div>
            <div>
                <div class="time-label">Hiệu lực từ</div>
                <div class="time-date">{{ $code->valid_from->format('d/m/Y') }}</div>
                <div class="time-hour">{{ $code->valid_from->format('H:i') }}</div>
            </div>
        </div>
        @endif

        @if($code->valid_until)
        <div class="time-card">
            <div class="time-icon end">
                <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd"
                        d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z"
                        clip-rule="evenodd" />
                </svg>
            </div>
            <div>
                <div class="time-label">Hết hạn</div>
                <div class="time-date">{{ $code->valid_until->format('d/m/Y') }}</div>
                <div class="time-hour">{{ $code->valid_until->format('H:i') }}</div>
            </div>
        </div>
        @endif
    </div>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-2 mt-3">
        {{-- Status --}}
        <div class="info-banner">
            <p class="text-xs text-white dark:text-white mb-1">Trạng thái:</p>
            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium
                @if($code->status === 'active') text-green-800 dark:text-green-200
                @elseif($code->status === 'expired') text-red-800 dark:text-red-200
                @else text-gray-800 dark:text-gray-200
                @endif">
                @if($code->status === 'active')
                ✓ Đang hoạt động
                @elseif($code->status === 'expired')
                ⏱ Hết hạn
                @elseif($code->status === 'disabled')
                ⊗ Vô hiệu hóa
                @else
                {{ $code->status }}
                @endif
            </span>
        </div>

        {{-- Usage Count --}}
        <div class="info-banner">
            <p class="text-xs text-white dark:text-white mb-1">Đã sử dụng:</p>
            <p class="text-sm font-semibold text-white dark:text-white">
                {{ $code->usage_count }} lần
                @if($code->max_uses)
                <span class="text-white">/ {{ $code->max_uses }}</span>
                @endif
            </p>
        </div>

        {{-- Gate Location --}}
        @if($code->gate_location)
        <div class="info-banner col-span-2">
            <p class="text-xs text-white dark:text-white mb-1">Vị trí cổng:</p>
            <p class="text-sm font-medium text-white dark:text-white flex items-center gap-2">
                <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                {{ $code->gate_location }}
            </p>
        </div>
        @endif
    </div>

    {{-- Chi nhánh --}}
    @if($code->category)
    <div class="mt-4 info-banner">
        <p class="text-xs text-white dark:text-white mb-1">Chi nhánh:</p>
        <p class="text-sm font-medium text-white dark:text-white flex items-center gap-2">
            <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4">
                </path>
            </svg>
            {{ $code->category->name }}
        </p>
    </div>
    @endif

    <!-- Banner thông tin -->
    <div class="info-banner">
        <svg class="info-icon" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd"
                d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z"
                clip-rule="evenodd" />
        </svg>
        <span>Vui lòng lưu mã này để truy cập vào hệ thống</span>
    </div>

    <script>
        function copyCode() {
            const code = document.getElementById('accessCode').textContent;
            navigator.clipboard.writeText(code).then(() => {
                const btn = event.target.closest('.copy-btn');
                const originalBg = btn.style.background;
                btn.style.background = '#10b981';
                btn.style.color = 'white';
                setTimeout(() => {
                    btn.style.background = originalBg;
                    btn.style.color = '#6b7280';
                }, 1000);
            });
        }
    </script>
</div>
@endif