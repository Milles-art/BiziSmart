<?php

namespace App\Filament\Auth;

use Filament\Schemas\Components\Component;

class AdminProfile extends \Filament\Auth\Pages\EditProfile
{
    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()->minLength(12)->maxLength(72)->rule(new \App\Rules\AdminPasswordLength);
    }
}
