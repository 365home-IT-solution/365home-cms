<?php

namespace Modules\BladeThemeV1\Types\Footer;

readonly class FooterBottomData
{
    public function __construct(
        public string $baseColor,
        public string $backgroundColor,
        public string $copyright,
        public array  $bottomMenu,
    ){}

    public static function fromArray(array $data): self
    {
        return new self(
            baseColor: $data['baseColor'],
            backgroundColor: $data['backgroundColor'],
            copyright: $data['copyright'],
            bottomMenu: $data['bottomMenu']
        );
    }

}
