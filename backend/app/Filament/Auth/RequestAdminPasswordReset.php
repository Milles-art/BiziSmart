<?php

namespace App\Filament\Auth;

use App\Models\AdminUser;
use App\Support\PasswordResetMail;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Password;

class RequestAdminPasswordReset extends \Filament\Auth\Pages\PasswordReset\RequestPasswordReset
{
    public function request(): void
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();
            return;
        }

        $data = $this->form->getState();
        if (! PasswordResetMail::configured()) {
            $this->unavailable();
            return;
        }

        try {
            Password::broker('admins')->sendResetLink(
                ['email' => strtolower(trim($data['email'])), 'is_active' => true],
                function (AdminUser $user, #[\SensitiveParameter] string $token): void {
                    $user->notifyNow(new AdminPasswordResetNotification($token, Filament::getResetPasswordUrl($token, $user)));
                },
            );
        } catch (\Throwable) {
            // Provider exceptions can contain credentials or reset URLs. Never log them.
            // Retire an uncertain submission. A transport failure must not reveal
            // whether the supplied administrator address exists.
            try {
                $user = AdminUser::where('email', strtolower(trim($data['email'])))->first();
                if ($user) { Password::broker('admins')->deleteToken($user); }
            } catch (\Throwable) {}
        }

        Notification::make()->title('If an active administrator account matches, a reset link will be sent.')->success()->send();
        $this->form->fill();
    }

    private function unavailable(): void
    {
        Notification::make()->title('Password-reset email is unavailable. Contact the system administrator.')->danger()->send();
    }
}
