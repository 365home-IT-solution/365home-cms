<?php

declare(strict_types=1);

namespace Modules\Book\App\Filament\Resources\BookResource\Forms;

use Filament\Forms\Form;

class BookForm
{
    public static function form(Form $form): Form
    {
        return $form
            ->schema([]);
    }
}