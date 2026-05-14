<style>
    @keyframes shrink {
        from {
            width: 100%;
        }

        to {
            width: 0%;
        }
    }

    .promotion-corner-image {
        position: absolute;
        top: -7px;
        right: -7px;
        z-index: 30;
        width: 18px;
        height: 18px;
        overflow: hidden;
        animation: bounce-corner 2s ease-in-out infinite;
    }

    .corner-img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        transition: transform 0.3s ease;
    }

    .selectable:hover .corner-img {
        transform: scale(1.15);
    }

    @keyframes bounce-corner {

        0%,
        100% {
            transform: translateY(0) scale(1);
        }

        50% {
            transform: translateY(-3px) scale(1.05);
        }
    }

    .promotion-center-label {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        z-index: 10;
        font-size: 10px;
        font-weight: bold;
        text-align: center;
        line-height: 1.2;
        color: #333;
        text-shadow: 0 0 2px rgba(255, 255, 255, 0.8);
        white-space: nowrap;
        pointer-events: none;
    }

    .promo-increase {
        background-color: rgba(255, 200, 100, 0.15) !important;
        border: 1px solid rgba(255, 150, 0, 0.3);
    }

    .selectable.active.promo-increase {
        background-color: rgba(255, 200, 100, 0.35) !important;
        border: 2px solid rgba(255, 150, 0, 0.6);
    }

    .selectable:hover {
        transform: scale(1.02);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    }

    .border-2.p-2.relative {
        overflow: visible !important;
    }

    .selectable {
        position: relative;
        z-index: 10;
        height: 36px;
        padding: 0 2rem;
        font-weight: bold;
        color: #333;
        background-color: #fff;
        border-radius: 8px;
        border: 1px solid var(--color-primary);
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: visible;
        transition: all 0.2s ease;
    }

    .selectable.active::after {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: inherit;
        background-color: var(--color-tickGray);
    }

    .selectable.booked::after {
        content: "";
        position: absolute;
        inset: 0;
        border-radius: inherit;
        background-color: var(--color-primary);
        z-index: 2;
    }

    /* Khi booked có thêm promo, vẫn giữ màu booked */
    .selectable.booked.promo::after {
        background-color: var(--color-primary) !important;
        z-index: 2;
    }

    .selectable.pending::after {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: inherit;
        background-color: var(--color-tickGray);
    }

    .selectable.pending::after {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: inherit;
        background-color: var(--color-tickGray);
    }

    .selectable.past-time::after {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: inherit;
        background-color: #f3f4f6;
    }

    .selectable.past-time {
        border: 1px solid #d1d5db;
        /* Tailwind gray-300 */
    }

    /* ★★★ BLOCKED DATE STYLE ★★★ */
    .selectable.blocked {
        cursor: not-allowed !important;
        pointer-events: none;
    }

    .selectable.blocked::after {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: inherit;
        background-color: #111827;
        z-index: 5;
    }

    .selectable.blocked::before {
        content: "🔒";
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        font-size: 10px;
        z-index: 10;
        pointer-events: none;
    }

    /* ★★★ END BLOCKED DATE STYLE ★★★ */

    #errorModal {
        transition: opacity 0.3s ease;
    }

    #errorModal.hidden {
        opacity: 0;
        pointer-events: none;
    }

    #errorModal:not(.hidden) {
        opacity: 1;
    }

    /* Animation cho progress bar */
    @keyframes progress {
        0% {
            width: 0%;
        }

        50% {
            width: 70%;
        }

        100% {
            width: 100%;
        }
    }

    .animate-progress {
        animation: progress 2s ease-in-out infinite;
    }

    /* Hiệu ứng shimmer cho skeleton */
    @keyframes shimmer {
        0% {
            background-position: -200px 0;
        }

        100% {
            background-position: 200px 0;
        }
    }

    .animate-shimmer {
        background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
        background-size: 200px 100%;
        animation: shimmer 1.5s infinite;
    }

    .animated-img {
        z-index: 20;
        animation: zoomInOut 1s infinite;
    }

    .selectable {
        position: relative;
        z-index: 10;
        height: 36px;
        padding: 0 2rem;
        font-weight: bold;
        color: #333;
        background-color: #fff;
        border-radius: 8px;
        border: 1px solid var(--color-primary);
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: visible;
        transition: all 0.2s ease;
    }

    /* Chỉ có hiệu ứng khi có khuyến mãi */
    .selectable.promo::before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: inherit;
        background: linear-gradient(270deg,
                #ff0000,
                #ff9900,
                #33ff00,
                #00ffff,
                #3300ff,
                #ff00cc,
                #ff0000);
        background-size: 300% 300%;
        animation: borderFlow 10s linear infinite;

        z-index: -10;
        transform: scale(1, 01);
        filter: blur(5px);
        opacity: 1;
    }

    .selectable.promo::after {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: inherit;
        background: #fff;
    }

    .selectable-mini {
        position: relative;
        z-index: 1;
        background-color: #fff;
        border: 2px solid #ff66aa;
        overflow: visible;
    }

    .promo-mini::before {
        content: "";
        position: absolute;
        top: -4px;
        left: -4px;
        right: -4px;
        bottom: -4px;
        border-radius: inherit;
        background: linear-gradient(270deg,
                #ff0000,
                #ff9900,
                #33ff00,
                #00ffff,
                #3300ff,
                #ff00cc,
                #ff0000);
        background-size: 200% 200%;
        animation: borderFlow 15s linear infinite;
        z-index: -1;
        filter: blur(3px);
        opacity: 1;
    }

    .promo-mini::after {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: inherit;
        background: #fff;
    }

    .selectable.active::after {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: inherit;
        background-color: var(--color-tickGray);
    }

    .active-tab {
        border: #ff566b 2px solid;
        color: #ff566b;
    }

    .active-tab:hover {
        border: #ff566b 2px solid;
        color: #ff566b;
    }

    @keyframes zoomInOut {
        0% {
            transform: scale(1);
        }

        50% {
            transform: scale(1.2);
        }

        100% {
            transform: scale(1);
        }
    }

    @keyframes borderFlow {
        0% {
            background-position: 0% 50%;
        }

        100% {
            background-position: 300% 50%;
        }
    }

    .swiper-slide {
        background-size: cover;
        background-position: center;
    }

    .thumbSwiper .swiper-slide {
        width: 15%;
        height: 15%;
        opacity: 0.4;
    }

    .thumbSwiper .swiper-slide-thumb-active {
        opacity: 1;

        border: 2px solid {
                {
                $primaryColor
            }
        }

        ;
        border-radius: 0.5rem;
    }

    [role="tab"][aria-selected="true"] {
        border-bottom: 2px solid {
                {
                $primaryColor
            }
        }

        ;

        color: {
                {
                $primaryColor
            }
        }

        ;
        background-color: #fff;
    }

    [role="tab"]:hover .absolute {
        transform: scaleX(1);
    }

    .product-content {
        color: #333;
        /* Màu chữ tối ưu cho khả năng đọc */
        line-height: 1.6;
        /* Khoảng cách dòng dễ đọc */
    }

    /* Định dạng tiêu đề h1 - h6 */
    .product-content h1,
    .product-content h2,
    .product-content h3,
    .product-content h4,
    .product-content h5,
    .product-content h6 {
        font-weight: 700;
        /* Chữ đậm cho tiêu đề */
        color: #1a1a1a;
        /* Màu chữ tiêu đề nổi bật */
        margin: 1.5rem 0 1rem;
        /* Khoảng cách trên dưới */
        transition: color 0.3s ease;
        /* Hiệu ứng chuyển màu khi hover */
    }

    .product-content h1 {
        font-size: 2rem;
        /* Kích thước lớn nhất cho h1 */
    }

    .product-content h2 {
        font-size: 1.75rem;
    }

    .product-content h3 {
        font-size: 1.5rem;
    }

    .product-content h4 {
        font-size: 1.25rem;
    }

    .product-content h5 {
        font-size: 1.125rem;
    }

    .product-content h6 {
        font-size: 1rem;
    }

    .product-content h1:hover,
    .product-content h2:hover,
    .product-content h3:hover,
    .product-content h4:hover,
    .product-content h5:hover,
    .product-content h6:hover {
        color: {
                {
                $primaryColor
            }
        }

        -500;
        /* Màu chính của giao diện khi hover */
    }

    /* Định dạng danh sách ul, ol, li */
    .product-content ul,
    .product-content ol {
        margin: 1rem 0;
        /* Khoảng cách trên dưới */
        padding-left: 2rem;
        /* Thụt đầu dòng */
    }

    .product-content ul li,
    .product-content ol li {
        margin-bottom: 0.5rem;
        /* Khoảng cách giữa các mục */
        font-size: 1rem;
        position: relative;
        transition: color 0.3s ease;
    }

    .product-content ul li::before {
        content: '•';

        /* Dấu đầu dòng cho ul */
        color: {
                {
                $primaryColor
            }
        }

        -500;
        /* Màu dấu đầu dòng */
        font-size: 1.5rem;
        position: absolute;
        left: -1.5rem;
        top: -0.1rem;
    }

    .product-content ul li:hover,
    .product-content ol li:hover {
        color: {
                {
                $primaryColor
            }
        }

        -500;
        /* Màu khi hover */
    }

    /* Định dạng hình ảnh */
    .product-content img {
        max-width: 100%;
        /* Hình ảnh không vượt quá chiều rộng container */
        height: auto;
        /* Giữ tỷ lệ hình ảnh */
        border-radius: 8px;
        /* Bo góc nhẹ */
        margin: 1rem 0;
        /* Khoảng cách trên dưới */
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        /* Đổ bóng nhẹ */
    }

    /* Định dạng bảng (nếu có) */
    .product-content table {
        width: 100%;
        border-collapse: collapse;
        margin: 1.5rem 0;
    }

    .product-content p,
    .product-content span {
        text-align: justify;
        margin-bottom: 1.5rem;
    }

    .product-content table th,
    .product-content table td {
        border: 1px solid #ddd;
        padding: 0.75rem;
        text-align: left;
    }

    .product-content table th {
        background-color: {
                {
                $primaryColor
            }
        }

        -100;
        /* Màu nền nhẹ cho tiêu đề bảng */
        font-weight: 600;
    }

    .product-content table tr:nth-child(even) {
        background-color: #f9f9f9;
        /* Màu nền xen kẽ cho hàng */
    }

    /* Định dạng liên kết */
    .product-content a {
        color: {
                {
                $primaryColor
            }
        }

        -500;
        /* Màu liên kết */
        text-decoration: underline;
        transition: color 0.3s ease;
    }

    .product-content a:hover {
        color: {
                {
                $primaryColor
            }
        }

        -700;
        /* Màu khi hover */
    }

    /* Responsive design */
    @media (max-width: 768px) {
        .product-content h1 {
            font-size: 1.75rem;
        }

        .product-content h2 {
            font-size: 1.5rem;
        }

        .product-content h3 {
            font-size: 1.25rem;
        }

        .product-content p,
        .product-content ul li,
        .product-content ol li {
            font-size: 0.95rem;
        }

        .product-content img {
            margin: 0.75rem 0;
        }
    }

    @media (max-width: 640px) {
        .product-content h1 {
            font-size: 1.5rem;
        }

        .product-content h2 {
            font-size: 1.25rem;
        }

        .product-content h3 {
            font-size: 1.125rem;
        }

        .product-content p,
        .product-content ul li,
        .product-content ol li {
            font-size: 0.9rem;
        }
    }
</style>