<?php

namespace Modules\Book\App\Filament\Forms\Components;

use Filament\Forms\Components\Field;

class DatePriceCalendarField extends Field
{
    protected string $view = 'book::filament.forms.components.date-price-calendar';

    protected mixed $roomId = null;
    protected int|float $basePrice = 0;

    public function roomId(mixed $roomId): static
    {
        $this->roomId = $roomId;

        return $this;
    }

    public function getRoomId(): mixed
    {
        return $this->roomId;
    }

    public function basePrice(int|float $price): static
    {
        $this->basePrice = (int) $price;
        return $this;
    }

    public function getBasePrice(): int
    {
        return (int) $this->basePrice;
    }

    protected string $defaultCheckin  = '14:00';
    protected string $defaultCheckout = '12:00';

    /** Giờ nhận/trả mặc định của phòng — ngày không đặt giờ riêng sẽ dùng giờ này. */
    public function defaultTimes(?string $checkin, ?string $checkout): static
    {
        $this->defaultCheckin  = substr($checkin ?: '14:00', 0, 5);
        $this->defaultCheckout = substr($checkout ?: '12:00', 0, 5);

        return $this;
    }

    public function getDefaultCheckin(): string
    {
        return $this->defaultCheckin;
    }

    public function getDefaultCheckout(): string
    {
        return $this->defaultCheckout;
    }
}
