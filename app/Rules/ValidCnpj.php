<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ValidCnpj implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $cnpj = preg_replace('/\D/', '', (string) $value);

        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            $fail('Informe um CNPJ válido.');

            return;
        }

        $digits = substr($cnpj, 0, 12);
        $firstVerifier = $this->calculateVerifier($digits);
        $secondVerifier = $this->calculateVerifier($digits.$firstVerifier);

        if ($cnpj !== $digits.$firstVerifier.$secondVerifier) {
            $fail('Informe um CNPJ válido.');
        }
    }

    private function calculateVerifier(string $digits): int
    {
        $weights = strlen($digits) === 12
            ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]
            : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $sum = 0;

        foreach (str_split($digits) as $index => $digit) {
            $sum += (int) $digit * $weights[$index];
        }

        $remainder = $sum % 11;

        return $remainder < 2 ? 0 : 11 - $remainder;
    }
}
