<div class="relative max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="grid gap-6" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
        @foreach ($metrics as $item)
            <div class="group relative p-6 bg-white border border-gray-200 rounded-lg shadow-md hover:shadow-xl transition-all duration-300 flex flex-col items-center justify-center text-center overflow-hidden">
                <h1 class="text-3xl sm:text-4xl font-bold text-primary mb-2 counter" data-target="{{ $item['value'] }}">0 {{ $item['unit'] }}</h1>
                <p class="text-orange-500 font-medium">{{ $item['label'] }}</p>
                <span class="absolute inset-0 bg-green-100 opacity-0 group-hover:opacity-20 transition-opacity duration-300"></span>
            </div>
        @endforeach
    </div>

    <style>
        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-in-down {
            animation: fadeInDown 0.8s ease-out forwards;
        }

        @media (max-width: 640px) {
            .grid {
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)) !important;
            }
        }
    </style>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const counters = document.querySelectorAll('.counter');

        const countUp = (element) => {
            const target = parseFloat(element.getAttribute('data-target'));
            let count = 0;
            const increment = target / 100;
            const unit = element.textContent.split(' ')[1];

            const updateCounter = () => {
                count += increment;
                if (count >= target) {
                    count = target;
                    clearInterval(counterInterval);
                }
                element.textContent = target >= 1000
                    ? Math.round(count).toLocaleString('vi-VN') + ' ' + unit
                    : count.toFixed(2) + ' ' + unit;
            };

            const counterInterval = setInterval(updateCounter, 20);
        };

        const observer = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    countUp(entry.target);
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.5 });

        counters.forEach(counter => observer.observe(counter));
    });
</script>
