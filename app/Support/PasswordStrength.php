<?php

namespace App\Support;

/**
 * Password strength scoring, mirroring the prototype's pwStrength (spec §5.2):
 * +1 length ≥ 8, +1 has BOTH lower and upper, +1 has a digit, +1 has a symbol.
 * Reset is rejected when score < 3.
 */
final class PasswordStrength
{
    public static function score(string $pw): int
    {
        $score = 0;
        if (strlen($pw) >= 8) {
            $score++;
        }
        if (preg_match('/[a-z]/', $pw) && preg_match('/[A-Z]/', $pw)) {
            $score++;
        }
        if (preg_match('/\d/', $pw)) {
            $score++;
        }
        if (preg_match('/[^A-Za-z0-9]/', $pw)) {
            $score++;
        }

        return $score;
    }

    /** @return array{label:string,color:string} */
    public static function meta(int $score): array
    {
        return [
            'label' => ['Too weak', 'Weak', 'Fair', 'Good', 'Strong'][$score] ?? 'Too weak',
            'color' => ['var(--over)', 'var(--over)', 'var(--due)', 'var(--info)', 'var(--paid)'][$score] ?? 'var(--over)',
        ];
    }
}
