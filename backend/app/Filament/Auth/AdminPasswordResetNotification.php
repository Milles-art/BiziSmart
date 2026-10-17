<?php

namespace App\Filament\Auth;

/** Synchronous notification: no reset token is placed in a queued job. */
class AdminPasswordResetNotification extends \Illuminate\Auth\Notifications\ResetPassword
{
    public function __construct(#[\SensitiveParameter] string $token, private readonly string $url)
    {
        parent::__construct($token);
    }

    protected function resetUrl($notifiable): string
    {
        return $this->url;
    }

    protected function buildMailMessage($url)
    {
        return (new \Illuminate\Notifications\Messages\MailMessage)
            ->subject('Reset your BiziSmart administrator password')
            ->line('We received a password-reset request for your administrator account.')
            ->action('Reset password', $url)
            ->line('This private link expires in '.config('auth.passwords.admins.expire').' minutes and can be used once.')
            ->line('If you did not request this, you can ignore this email.');
    }
}
