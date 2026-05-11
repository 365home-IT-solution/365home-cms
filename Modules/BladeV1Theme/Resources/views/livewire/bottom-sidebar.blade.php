{{-- resources/views/bladethemev1/components/bottom-navigation.blade.php --}}
@php
    $currentUrl = request()->getPathInfo();
@endphp
<style>
    .bottom-navigation-1 {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        z-index: 1000;
    }

    .bottom-nav-1 {
        background: white;
        border-top: 1px solid #e0e0e0;
        display: flex;
        justify-content: space-around;
        align-items: center;
        padding: 8px 0 12px 0;
        box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
    }

    .nav-item-1 {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        flex: 1;
        color: #999;
        transition: color 0.3s ease;
        padding: 4px 8px;
        cursor: pointer;
        text-decoration: none;
    }

    .nav-item-1:hover {
        color: var(--color-primary);
    }

    .nav-item-1.active {
        color: var(--color-primary);
    }

    .nav-icon-1 {
        width: 24px;
        height: 24px;
        margin-bottom: 4px;
        fill: currentColor;
        stroke: currentColor;
        stroke-width: 2;
    }

    .nav-label-1 {
        font-size: 10px;
        font-weight: 500;
        text-align: center;
        white-space: nowrap;
    }

    .nav-item-1.active .nav-icon-1 {
        transform: scale(1.1);
    }

    body {
        padding-bottom: 80px;
    }

    @media (max-width: 480px) {
        .nav-label-1 {
            font-size: 9px;
        }
        .nav-icon-1 {
            width: 20px;
            height: 20px;
        }
    }

    @media (min-width: 1024px) {
        .bottom-navigation-1 {
            display: none;
        }
        body {
            padding-bottom: 0;
        }
    }
</style>

<div class="bottom-navigation-1">
    <div class="bottom-nav-1">
        <a href="/" class="nav-item-1 {{ $currentUrl === '/' ? 'active' : '' }}">
            <div class="nav-icon-1">
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                    <polyline points="9,22 9,12 15,12 15,22"/>
                </svg>
            </div>
            <span class="nav-label-1">365HOME</span>
        </a>

        <a data-drawer-toggle="drawer-navigation" class="nav-item-1 {{ $currentUrl === '/danh-muc' ? 'active' : '' }}">
            <div class="nav-icon-1">
                <svg viewBox="0 0 24 24" fill="none">
                    <line x1="3" y1="6" x2="21" y2="6"/>
                    <line x1="3" y1="12" x2="21" y2="12"/>
                    <line x1="3" y1="18" x2="21" y2="18"/>
                </svg>
            </div>
            <span class="nav-label-1">Danh mục</span>
        </a>

        <a href="/ticket-booking" class="nav-item-1 {{ $currentUrl === '/ticket-booking' ? 'active' : '' }}">
            <div class="nav-icon-1">
                <svg viewBox="0 0 24 24" fill="none">
                    <circle cx="11" cy="11" r="8"/>
                    <path d="m21 21-4.35-4.35"/>
                </svg>
            </div>
            <span class="nav-label-1">Tra cứu</span>
        </a>

        <a href="tel:+84939174365" class="nav-item-1 {{ $currentUrl === '/hotline' ? 'active' : '' }}">
            <div class="nav-icon-1">
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
                </svg>
            </div>
            <span class="nav-label-1">Hotline</span>
        </a>
    </div>
</div>

