<?php
namespace App\Filament\Auth;
use Filament\Schemas\Components\Component;
class AdminLogin extends \Filament\Auth\Pages\Login {
    protected function getRememberFormComponent():Component {
        // Each expired admin session requires password and mandatory MFA again.
        return parent::getRememberFormComponent()->hidden()->dehydrated(false);
    }
}
