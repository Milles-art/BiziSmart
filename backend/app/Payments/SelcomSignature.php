<?php

namespace App\Payments;

use DateTimeImmutable;
use InvalidArgumentException;

final class SelcomSignature
{
    public function headers(array $payload, string $key, string $secret, string $timestamp): array
    {
        return [
            'Authorization' => 'SELCOM '.base64_encode($key),
            'Digest-Method' => 'HS256',
            'Timestamp' => $timestamp,
            'Signed-Fields' => implode(',', array_keys($payload)),
            'Digest' => $this->digest($payload, array_keys($payload), $secret, $timestamp),
        ];
    }

    public function digest(array $payload, array $fields, string $secret, string $timestamp): string
    {
        $text = 'timestamp='.$timestamp;
        foreach ($fields as $field) {
            if (! is_string($field) || ! preg_match('/^[a-z_]+$/D', $field) || ! array_key_exists($field, $payload)
                || ! (is_string($payload[$field]) || is_int($payload[$field]))) {
                throw new InvalidArgumentException('Invalid signed payment fields.');
            }
            $text .= '&'.$field.'='.$payload[$field];
        }
        return base64_encode(hash_hmac('sha256', $text, $secret, true));
    }

    /** Only authenticated notification identifiers/status are returned. Amount/phone require a status query. */
    public function notification(array $payload, array $headers, string $key, string $secret, DateTimeImmutable $now): ?array
    {
        if (trim($key) === '' || trim($secret) === '') return null;
        $headers = array_change_key_case($headers, CASE_LOWER);
        foreach (['authorization','digest-method','timestamp','signed-fields','digest'] as $name) {
            if (! is_string($headers[$name] ?? null)) return null;
        }
        if (! hash_equals('SELCOM '.base64_encode($key), $headers['authorization']) || $headers['digest-method'] !== 'HS256') return null;
        $timestamp = $headers['timestamp'];
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $timestamp)) return null;
        try { $time = new DateTimeImmutable($timestamp); } catch (\Exception) { return null; }
        // Compare instants; timestamp text itself remains unchanged in the signed message.
        if (abs($now->getTimestamp() - $time->getTimestamp()) > 300) return null;
        $fields = explode(',', $headers['signed-fields']);
        $required = ['transid','order_id','reference','result','resultcode','payment_status'];
        if (count($fields) !== count(array_unique($fields)) || array_diff($required, $fields)
            || array_diff($fields, [...$required,'channel','amount','phone'])) return null;
        try { $expected = $this->digest($payload, $fields, $secret, $timestamp); }
        catch (InvalidArgumentException) { return null; }
        if (! hash_equals($expected, $headers['digest'])) return null;
        return array_intersect_key($payload, array_flip($required));
    }
}
