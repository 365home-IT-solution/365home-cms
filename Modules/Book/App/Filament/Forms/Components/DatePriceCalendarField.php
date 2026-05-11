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
}
