<?php

namespace Modules\BladeThemeV1\Types;

readonly class HeaderData
{
    public function __construct(
        public string $logo,
        public string $logoLightVersion,
        public array  $logoConfig = [],
        public array  $headerConfig = [],
        public array  $navConfig = [],
        public array  $actionConfig = [],
        public bool   $headerStyle,
        public array  $cartConfig = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            logo: $data['logo'] ?? '',
            logoLightVersion: $data['logo_light_version'] ?? '',
            logoConfig: $data['logo_config'] ?? [],
            headerConfig: $data['header_config'] ?? [],
            navConfig: $data['nav_config'] ?? [],
            actionConfig: $data['action_config'] ?? [],
            headerStyle: $data['headerStyle'],
            cartConfig: $data['show_cart_icon']
        );
    }
}
