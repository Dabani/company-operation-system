<?php

namespace App\Support;

use Closure;
use Illuminate\Validation\Rules\Password;

/** One password policy for activation, reset and profile change (12+ characters, letters, numbers, symbols, not derived from the e-mail). */
class PasswordRules
{
    public const MIN = 12;

    public static function for(?string $email = null): array
    {
        $rules = [Password::min(self::MIN)->letters()->numbers()->symbols()];
        $local = $email ? mb_strtolower(explode('@', $email)[0]) : '';
        if (mb_strlen($local) >= 4) {
            $rules[] = function (string $attribute, mixed $value, Closure $fail) use ($local) {
                if (str_contains(mb_strtolower((string) $value), $local)) $fail(__('users.password_contains_email'));
            };
        }
        return $rules;
    }
}
