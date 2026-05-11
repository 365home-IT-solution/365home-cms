<?php

namespace App\ValueObjects;

use Guava\Calendar\ValueObjects\CalendarEvent;
use Carbon\Carbon;

class CustomCalendarEvent extends CalendarEvent
{
    protected ?string $borderColor = null;

    /**
     * Set border color for the event
     */
    public function borderColor(?string $color): static
    {
        $this->borderColor = $color;
        return $this;
    }

    /**
     * Get border color
     */
    public function getBorderColor(): ?string
    {
        return $this->borderColor;
    }

    /**
     * Convert event to array for FullCalendar
     */
    public function toArray(): array
    {
        $array = [
            'title' => $this->getTitle(),
            'start' => $this->getStart()->format('Y-m-d\TH:i:s'),
            'end' => $this->getEnd()->format('Y-m-d\TH:i:s'),
            'allDay' => $this->getAllDay(),
            'backgroundColor' => $this->getBackgroundColor(),
            'textColor' => $this->getTextColor(),
            'styles' => $this->getStyles(),
            'classNames' => $this->getClassNames(),
            'resourceIds' => $this->getResourceIds(),
            'extendedProps' => $this->getExtendedProps(),
        ];

        // Thêm borderColor nếu có
        if ($this->borderColor !== null) {
            $array['borderColor'] = $this->borderColor;
        }

        if (($editable = $this->getEditable()) !== null) {
            $array['editable'] = $editable;
        }

        if (($startEditable = $this->getStartEditable()) !== null) {
            $array['startEditable'] = $startEditable;
        }

        if (($durationEditable = $this->getDurationEditable()) !== null) {
            $array['durationEditable'] = $durationEditable;
        }

        if (($display = $this->getDisplay()) !== null) {
            $array['display'] = $display;
        }

        return $array;
    }
}