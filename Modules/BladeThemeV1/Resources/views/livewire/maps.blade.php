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