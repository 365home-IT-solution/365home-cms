<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleDomainTrait;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

class DomainLookup extends Component
{
    use HandleConfigTrait, HandleDomainTrait;

    public string $domain = '';

    protected array $rules = [
        'domain' => 'required|regex:/^(xn--)?[a-zA-Z0-9\-]+(\.[a-zA-Z0-9\-]+)*$/',
    ];

    protected array $messages = [
        'domain.required' => '* Vui lòng nhập tên miền.',
        'domain.regex' => '* Tên miền không đúng định dạng hợp lệ.',
    ];

    public function mount($config): void {
        $this->setConfig($config);
    }
    
    public function redirectToDomainLookupDetail(): void
    {
        $this->validate();
        $this->redirect(route('domain-lookup.detail', [
            'ten-mien' => $this->domain
        ]));
    }


    public function render()
    {
        return view('bladethemev1::livewire.domain-lookup');
    }
}
