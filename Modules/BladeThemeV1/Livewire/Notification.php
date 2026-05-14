<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;

class Notification extends Component
{
    public $showMessage = false;
    public $message = '';
    public $type = '';

    protected $listeners = ['notify' => 'show'];

    public function show($data)
    {
        $this->showMessage = true;
        $this->message = $data['message'];
        $this->type = $data['type'];

        dispatch(function() {
            $this->hide();
        })->delay(now()->addSeconds(5));
        
        $this->js("setTimeout(() => { 
            \$wire.hide()
        }, 4000)");
    }

    public function hide()
    {
        $this->showMessage = false;
    }


    public function render()
    {
        return view('bladethemev1::livewire.notification');
    }
}
