<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;

class Notification extends Component
{
    public $showMessage = false;
    public $message = '';
    public $type = '';

    protected $listeners = ['notify' => 'show'];

    /**
     * Nhận thông báo từ 2 nguồn khác nhau:
     * - Livewire dispatch('notify', ['message' => ..., 'type' => ...]) => $message là mảng.
     * - Trình duyệt phát CustomEvent('notify', { detail: { message, type } }) khiến Livewire
     *   gọi hàm này với named parameters ($message, $type).
     */
    public function show($message = null, $type = null, $data = null)
    {
        if (is_array($message)) {
            $data = $message;
            $message = $data['message'] ?? '';
            $type = $data['type'] ?? '';
        } elseif (is_array($data)) {
            $message = $data['message'] ?? $message;
            $type = $data['type'] ?? $type;
        }

        $this->showMessage = true;
        $this->message = $message ?? '';
        $this->type = $type ?? '';

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
