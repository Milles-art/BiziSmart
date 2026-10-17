<?php
namespace App\Filament\Auth;
use Filament\Schemas\Components\Component;
class AdminLogin extends \Filament\Auth\Pages\Login {
    protected function getPasswordFormComponent():Component {
        return parent::getPasswordFormComponent()->minLength(12)->maxLength(72)->rule(new \App\Rules\AdminPasswordLength);
    }
    protected function getRememberFormComponent():Component {
        // Each expired admin session requires the password again. No remembered recall.
        return parent::getRememberFormComponent()->hidden()->dehydrated(false);
    }
}
