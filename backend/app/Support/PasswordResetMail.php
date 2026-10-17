<?php

namespace App\Support;

final class PasswordResetMail
{
    /** Configuration readiness only; actual provider delivery must still succeed. */
    public static function configured(): bool
    {
        $from = config('mail.from.address');
        if (! is_string($from) || ! filter_var($from, FILTER_VALIDATE_EMAIL)
            || preg_match('/@(example\.(com|org|net|test)|localhost)$/i', $from)) {
            return false;
        }

        $mailer = config('mail.mailers.'.config('mail.default'), []);
        if (($mailer['transport'] ?? null) === 'sendmail') {
            $path = trim((string) ($mailer['path'] ?? ''));
            $executable = preg_split('/\s+/', $path)[0] ?? '';
            return str_starts_with($executable, '/') && is_file($executable) && is_executable($executable);
        }
        if (($mailer['transport'] ?? null) !== 'smtp') {
            return false;
        }

        $url = filled($mailer['url'] ?? null) ? parse_url($mailer['url']) : [];
        if ($url === false) {
            return false;
        }
        $host = strtolower(trim((string) ($url['host'] ?? $mailer['host'] ?? '')));
        $username = $url['user'] ?? $mailer['username'] ?? null;
        $password = $url['pass'] ?? $mailer['password'] ?? null;
        return $host !== '' && ! in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]', '0.0.0.0'], true)
            && ! str_ends_with($host, '.test') && ! str_ends_with($host, '.example')
            && filled($username) && filled($password);
    }
}
