<?php

declare(strict_types=1);

namespace App\Validation;

final class DocumentValidator
{
    public static function digitsOnly(string $val): string
    {
        return preg_replace('/\D+/', '', $val) ?? '';
    }

    public static function isValidCpf(string $cpf): bool
    {
        $cpf = self::digitsOnly($cpf);

        if (strlen($cpf) !== 11) {
            return false;
        }

        if (preg_match('/^(\d)\1{10}$/', $cpf) === 1) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($c = 0; $c < $t; $c++) {
                $sum += ((int) $cpf[$c]) * (($t + 1) - $c);
            }
            $digit = ((10 * $sum) % 11) % 10;
            if ((int) $cpf[$t] !== $digit) {
                return false;
            }
        }

        return true;
    }

    public static function isValidCnpj(string $cnpj): bool
    {
        $cnpj = self::digitsOnly($cnpj);

        if (strlen($cnpj) !== 14) {
            return false;
        }

        if (preg_match('/^(\d)\1{13}$/', $cnpj) === 1) {
            return false;
        }

        $weights1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $weights2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        $sum1 = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum1 += ((int) $cnpj[$i]) * $weights1[$i];
        }
        $rem1 = $sum1 % 11;
        $d1 = $rem1 < 2 ? 0 : 11 - $rem1;
        if ((int) $cnpj[12] !== $d1) {
            return false;
        }

        $sum2 = 0;
        for ($i = 0; $i < 13; $i++) {
            $sum2 += ((int) $cnpj[$i]) * $weights2[$i];
        }
        $rem2 = $sum2 % 11;
        $d2 = $rem2 < 2 ? 0 : 11 - $rem2;

        return (int) $cnpj[13] === $d2;
    }

    public static function normalizePlate(string $plate): string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $plate) ?? '');
        if (strlen($clean) === 7) {
            return substr($clean, 0, 3) . '-' . substr($clean, 3, 4);
        }
        return $clean;
    }

    public static function isValidPlate(string $plate): bool
    {
        $normalized = self::normalizePlate($plate);
        return preg_match('/^[A-Z]{3}-[0-9][A-Z0-9][0-9]{2}$/', $normalized) === 1;
    }

    public static function formatCpf(string $cpf): string
    {
        $d = self::digitsOnly($cpf);
        if (strlen($d) !== 11) {
            return $cpf;
        }
        return sprintf('%s.%s.%s-%s', substr($d, 0, 3), substr($d, 3, 3), substr($d, 6, 3), substr($d, 9, 2));
    }

    public static function formatPhone(string $phone): string
    {
        $d = self::digitsOnly($phone);
        if (strlen($d) === 11) {
            return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7, 4));
        }
        if (strlen($d) === 10) {
            return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6, 4));
        }
        return $phone;
    }

    public static function formatCnpj(?string $cnpj): ?string
    {
        if ($cnpj === null || $cnpj === '') {
            return null;
        }
        $d = self::digitsOnly($cnpj);
        if (strlen($d) !== 14) {
            return $cnpj;
        }
        return sprintf(
            '%s.%s.%s/%s-%s',
            substr($d, 0, 2),
            substr($d, 2, 3),
            substr($d, 5, 3),
            substr($d, 8, 4),
            substr($d, 12, 2)
        );
    }
}

