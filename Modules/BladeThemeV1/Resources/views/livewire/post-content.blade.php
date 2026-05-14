<div class="max-w-screen-xl mx-auto px-4 py-8">
    @if ($post)
        <article class="bg-white border-[1px] rounded-xl overflow-hidden">
            <h1 class="text-3xl font-bold text-gray-900 p-6 text-center">{{ $post->title }}</h1>

            <div class="p-6">
                <div class="flex flex-wrap items-center text-sm text-gray-600 mb-4">
                    <span class="mr-4">
                        <i class="far fa-calendar-alt mr-1"></i>
                        {{ \Carbon\Carbon::parse($post->created_at)->format('d/m/Y H:i') }}
                    </span>
                </div>

                <div class="post-summary text-gray-700 mb-6">
                    {!! $post->summary !!}
                </div>

                <div class="toc-wrap">
                    <div class="toc-title">Mục lục</div>
                    <div id="toc"></div>
                </div>

                <div class="post-content prose max-w-none">
                    {!! $post->content !!}
                </div>

                <div class="flex">

                </div>
                <div>
                    @if ($post->tags->isNotEmpty())
                        <div class="mt-6 pt-2 border-t border-gray-200">
                            <span class="text-sm font-semibold text-gray-700 mr-2">Thẻ:</span>
                            @foreach ($post->tags as $tag)
                                <spab class="inline-block rounded-full px-3 py-1 text-sm font-semibold mr-2 mb-2"
                                      style="color: {{ $primaryColor }};">
                                    {{ $tag->name }}
                                </spab>
                            @endforeach
                        </div>
                    @endif

                    @if ($post->categories->isNotEmpty())
                        <div class="pt-2">
                            <span class="text-sm font-semibold text-gray-700 mr-2">Danh mục:</span>
                            @foreach ($post->categories as $category)
                                <spab class="inline-block rounded-full px-3 py-1 text-sm font-semibold mr-2 mb-2"
                                      style="color: {{ $primaryColor }};">
                                    {{ $category->name }}
                                </spab>
                            @endforeach
                        </div>
                    @endif
                </div>

            </div>

        </article>

    @else
        <p class="text-center text-gray-600">Không tìm thấy bài viết.</p>
    @endif
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const figures = document.querySelectorAll('figure');
        figures.forEach(function(figure) {
            var img = figure.querySelector('img');
            var figcaption = figure.querySelector('figcaption');

            if (img && figcaption) {
                img.setAttribute('alt', figcaption.textContent.trim());
            }
        });

        const headings = document.querySelectorAll(
            '.post-content h1, .post-content h2, .post-content h3, .post-content h4, .post-content h5, .post-content h6'
        );
        const tocContainer = document.querySelector('#toc');
        const tocWrap = document.querySelector('.toc-wrap');

        if (headings.length === 0 || !tocContainer || !tocWrap) {
            console.log('No headings found or TOC elements missing, hiding TOC');
            if (tocWrap) {
                tocWrap.style.display = 'none';
            }
            return;
        }

        const startingLevel = headings[0].tagName[1];
        const toc = document.createElement('ul');
        const prevLevels = [0, 0, 0, 0, 0, 0];

        for (let i = 0; i < headings.length; i++) {
            const heading = headings[i];
            const level = parseInt(heading.tagName[1]);

            prevLevels[level - 1]++;
            for (let j = level; j < prevLevels.length; j++) {
                prevLevels[j] = 0;
            }

            const sectionNumber = prevLevels.slice(startingLevel - 1, level).join('.').replace(/\.0/g, "");

            const newHeadingId = `${heading.textContent.toLowerCase().replace(/ /g, '-')}`;
            heading.id = newHeadingId;

            const anchor = document.createElement('a');
            anchor.setAttribute('href', `#${newHeadingId}`);
            anchor.textContent = heading.textContent;

            anchor.addEventListener('click', (event) => {
                event.preventDefault();
                const targetId = event.target.getAttribute('href').slice(1);
                const targetElement = document.getElementById(targetId);

                const offset = 70;
                const elementPosition = targetElement.getBoundingClientRect().top;
                const offsetPosition = elementPosition + window.pageYOffset - offset;

                window.scrollTo({
                    top: offsetPosition,
                    behavior: "smooth"
                });

                history.pushState(null, null, `#${targetId}`);
            });

            const listItem = document.createElement('li');
            listItem.textContent = sectionNumber + ' ';
            listItem.appendChild(anchor);

            const className = `toc-${heading.tagName.toLowerCase()}`;
            listItem.classList.add('toc-item');
            listItem.classList.add(className);

            toc.appendChild(listItem);
        }

        tocContainer.innerHTML = '';
        tocContainer.appendChild(toc);
    });
</script>