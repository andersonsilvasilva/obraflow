<?php

namespace App\Modules\Auth;

use Illuminate\Validation\Rules\Password;

/** Regra única de senha forte (sem consulta externa a bases de vazamento). */
final class PasswordRules
{
    public static function strong(): Password
    {
        return Password::min(12)->letters()->mixedCase()->numbers();
    }
}
