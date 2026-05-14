<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;

class FormModal extends Component
{
    public $isOpen = false;
    public $planName = '';
    public $form;

    protected $listeners = [
        'openProductContactModal' => 'openModal',
        'closeProductContactModal' => 'closeModal'
    ];

    public function openModal($data = null)
    {
        $this->isOpen = true;
        $this->planName = $data['name'] ?? '';
        $this->form = $data['form'] ?? null;
        
        // Add validation
        if (!$this->form) {
            throw new \Exception('Form data is required');
        }
    }
    public function closeModal()
    {
        $this->isOpen = false;
        $this->reset(['planName', 'form']);
    }

    public function render()
    {
        return view('bladethemev1::livewire.form-modal');
    }
}
