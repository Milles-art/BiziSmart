<?php

use App\Support\PasswordResetMail;

uses(Tests\TestCase::class);

it('rejects token-logging mailers defaults and local development smtp', function (array $settings) {
    config($settings);
    expect(PasswordResetMail::configured())->toBeFalse();
})->with([
    [['mail.default'=>'log','mail.from.address'=>'support@bizismart.com']],
    [['mail.default'=>'array','mail.from.address'=>'support@bizismart.com']],
    [['mail.default'=>'failover','mail.from.address'=>'support@bizismart.com']],
    [['mail.default'=>'smtp','mail.from.address'=>'hello@example.com','mail.mailers.smtp.host'=>'smtp.provider.invalid','mail.mailers.smtp.username'=>'user','mail.mailers.smtp.password'=>'secret']],
    [['mail.default'=>'smtp','mail.from.address'=>'support@bizismart.com','mail.mailers.smtp.host'=>'127.0.0.1','mail.mailers.smtp.username'=>'user','mail.mailers.smtp.password'=>'secret']],
    [['mail.default'=>'smtp','mail.from.address'=>'support@bizismart.com','mail.mailers.smtp.host'=>'smtp.provider.invalid','mail.mailers.smtp.username'=>'user','mail.mailers.smtp.password'=>null]],
]);

it('recognizes explicitly configured external smtp without claiming delivery', function () {
    config(['mail.default'=>'smtp','mail.from.address'=>'support@bizismart.com','mail.mailers.smtp.host'=>'smtp.provider.invalid','mail.mailers.smtp.username'=>'user','mail.mailers.smtp.password'=>'secret']);
    expect(PasswordResetMail::configured())->toBeTrue();
});
