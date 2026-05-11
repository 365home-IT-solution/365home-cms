<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\LiveChat\Entities\ContactLink as ContactLinkModel;
use Modules\ThemeSetting\App\Models\ThemeSection;
use Modules\BladeThemeV1\Enums\ContactLinkSection;
use Modules\BladeThemeV1\Traits\HandleSectionCfgTrait;

class ContactLink extends Component
{
    use HandleSectionCfgTrait;

    public array $contact_button = [];

    public ThemeSection $section;

    public function mount(): void
    {
        $this->section = $this->getContactLinkConfigs();
        $this->contact_button = $this->getChildSectionConfigs(ContactLinkSection::CONTACT_LINK_DETAIL->value);
    }

    private function getContactLinkConfigs()
    {
        return ThemeSection::where('name', 'contact_buttons')
            ->with(['children'])
            ->first();
    }

    public function render()
    {
        return view('bladethemev1::livewire.contact-link');
    }
}
