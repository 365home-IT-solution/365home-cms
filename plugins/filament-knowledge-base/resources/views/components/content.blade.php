<article
    {{ $attributes->class([
        'gu-kb-article prose prose-lg max-w-none dark:prose-invert',
        'dark:text-white',
    ]) }}
    style="
        & p { line-height: 2rem; }
        & li { line-height: 2rem; }
        & ul { line-height: 2rem; }
        & ol { line-height: 2rem; }
        & blockquote { line-height: 2rem; }
        & h1 { line-height: 2rem; }
        & h2 { line-height: 2rem; }
        & h3 { line-height: 2rem; }
        & h4 { line-height: 2rem; }
        & summary { line-height: 2rem; }
    "
    x-ignore
    ax-load
    ax-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('anchors-component', 'guava/filament-knowledge-base') }}"
    x-data="anchorsComponent()"
>
    {{ $slot }}
</article>