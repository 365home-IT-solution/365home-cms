<?php

namespace Modules\BladeThemeV1\Traits;

use Illuminate\Support\Facades\Session;

trait CartTrait
{
    public function loadCart()
    {
        $sessionId = Session::get('cart_session_id');
        $this->cart = $sessionId ? Session::get("cart_{$sessionId}", []) : [];
    }

    public function getTotalAmount()
    {
        return array_reduce($this->cart, function ($carry, $item) {
            if (empty($item['discount'])) {
                return $carry + ($item['quantity'] * $item['price']);
            }
            return $carry + ($item['quantity'] * $item['discount']);
        }, 0);
    }

    public function clearCart()
    {
        $sessionId = Session::get('cart_session_id');
        if ($sessionId) {
            Session::forget("cart_{$sessionId}");
        }
        $this->cart = [];
        $this->dispatch('cartUpdated');
        return $this->cart;
    }
}