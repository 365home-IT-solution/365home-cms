<?php

namespace App\Filament\Pages\Auth;

use Filament\Forms\Form;
use Filament\Pages\Auth\Login as BasePage;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Login extends BasePage
{
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                $this->getEmailFormComponent()->label('Email'),
                $this->getPasswordFormComponent(),
                $this->getRememberFormComponent(),
            ]);
    }

    public function getHeading(): string | Htmlable
    {
        return 'Đăng nhập';
    }

    protected function throwFailureValidationException(): never
    {
        $key = 'filament.login.' . Str::lower($this->data['email'] ?? '') . '|' . request()->ip();

        RateLimiter::hit($key, 60);

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'data.email' => __('filament-panels::pages/auth/login.messages.throttled', [
                    'seconds' => $seconds,
                    'minutes' => ceil($seconds / 60),
                ]) ?: "Quá nhiều lần thử. Vui lòng đợi {$seconds} giây.",
            ]);
        }

        throw ValidationException::withMessages([
            'data.email' => __('filament-panels::pages/auth/login.messages.failed'),
        ]);
    }
}
