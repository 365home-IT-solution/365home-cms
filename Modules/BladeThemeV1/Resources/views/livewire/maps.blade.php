<style>
    .entry-header-meta {
        align-items: center;
        color: #fff;
        display: flex;
        font-size: 24px;
        font-weight: 400;
        overflow: hidden;
        flex-wrap: wrap;
    }

    .entry-header-meta > span {
        margin-right: 25px;
    }

    .entry-header-meta .entry-header-location {
        padding-left: 32px;
        position: relative;
        display: flex;
        align-items: center;
        min-width: fit-content;
    }

    .entry-header-meta .entry-header-location::before {
        background: url('https://localhome.vn/gwzv/themes/localhome/images/location-1.svg') no-repeat center;
        background-size: contain;
        content: "";
        height: 23px;
        width: 23px;
        left: 0;
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
    }

    .entry-header-meta .entry-header-map {
        padding-left: 32px;
        position: relative;
        display: flex;
        align-items: center;
        min-width: fit-content;
    }

    .entry-header-meta .entry-header-map::before {
        background: url('https://localhome.vn/gwzv/themes/localhome/images/find-map.svg') no-repeat center;
        background-size: contain;
        content: "";
        height: 23px;
        width: 23px;
        left: 0;
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
    }

    .entry-header-meta .entry-header-map a {
        color: #fff;
        text-decoration: none;
    }

    .archive-title {
        display: -webkit-box;
        font-weight: 700;
        line-height: 1.2;
        margin-top: 10px;
        word-break: break-word;
    }

    /* Responsive styles */
    @media (max-width: 768px) {
        .entry-header-meta {
            font-size: 18px;
            gap: 10px;
        }

        .entry-header-meta .entry-header-location,
        .entry-header-meta .entry-header-map {
            padding-left: 28px;
        }

        .entry-header-meta .entry-header-location::before,
        .entry-header-meta .entry-header-map::before {
            height: 20px;
            width: 20px;
        }
    }

    @media (max-width: 640px) {
        .entry-header-meta {
            font-size: 16px;
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
        }

        .entry-header-meta > span {
            margin-bottom: 5px;
        }

        .entry-header-meta .entry-header-location,
        .entry-header-meta .entry-header-map {
            padding-left: 25px;
        }

        .entry-header-meta .entry-header-location::before,
        .entry-header-meta .entry-header-map::before {
            height: 18px;
            width: 18px;
        }
    }

    @media (max-width: 480px) {
        .entry-header-meta {
            font-size: 14px;
        }

        .entry-header-meta .entry-header-location,
        .entry-header-meta .entry-header-map {
            padding-left: 22px;
        }

        .entry-header-meta .entry-header-location::before,
        .entry-header-meta .entry-header-map::before {
            height: 16px;
            width: 16px;
        }
    }
    .tooltip-box {
        position: absolute;
        background-color: #1f2937; /* gray-800 */
        color: white;
        padding: 6px 10px;
        border-radius: 6px;
        font-size: 14px;
        white-space: nowrap;
        z-index: 1000;
        opacity: 0;
        transition: opacity 0.3s ease;
        pointer-events: none;
    }
</style>


<div class="md:px-8 px-4 mx-auto">
    <div class="entry-header-meta mb-[16px]">
        @if($nameCate1)
        <span class="entry-header-location">
            {{ $nameCate1->name }}
        </span>
        @endif

        @if($placeUrl)
        <span class="entry-header-map">
            <a href="{{ $placeUrl }}"
               class="tooltip-link relative cursor-pointer"
               data-tooltip="Xem đường đi đến Home trên bản đồ">
                Xem bản đồ
            </a>
        </span>
        @endif
    </div>

    <h1 class="archive-title text-xl sm:text-xl md:text-4xl lg:text-[48px] text-white">
        {{ $nameCate1->name ?? '' }} HOME
    </h1>

    @if($descriptionMap)
    <div class="maps-description mt-4 text-white text-base sm:text-lg leading-relaxed opacity-90">
        {!! $descriptionMap !!}
    </div>
    @endif
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.tooltip-link').forEach(el => {
            const tooltipText = el.getAttribute('data-tooltip');
            if (!tooltipText) return;

            const tooltip = document.createElement('div');
            tooltip.className = 'tooltip-box';
            tooltip.innerText = tooltipText;
            document.body.appendChild(tooltip);

            el.addEventListener('mouseenter', (e) => {
                const rect = el.getBoundingClientRect();
                tooltip.style.top = (window.scrollY + rect.top - tooltip.offsetHeight - 8) + 'px';
                tooltip.style.left = (window.scrollX + rect.left + rect.width / 2 - tooltip.offsetWidth / 2) + 'px';
                tooltip.style.opacity = 1;
            });

            el.addEventListener('mouseleave', () => {
                tooltip.style.opacity = 0;
            });
        });
    });
</script>