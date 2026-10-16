<?php

namespace App\Support;

/** Indian-system amount in words for payslips: 65432.50 => "Sixty-Five Thousand Four Hundred Thirty-Two Rupees and Fifty Paise Only". */
class AmountInWords
{
    private const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    public static function rupees(float $amount): string
    {
        $amount = round(max(0, $amount), 2);
        $rupees = (int) floor($amount);
        $paise = (int) round(($amount - $rupees) * 100);
        $words = $rupees ? self::number($rupees).' Rupees' : 'Zero Rupees';

        return $words.($paise ? ' and '.self::number($paise).' Paise' : '').' Only';
    }

    private static function number(int $n): string
    {
        $parts = [];
        foreach ([10000000 => 'Crore', 100000 => 'Lakh', 1000 => 'Thousand', 100 => 'Hundred'] as $size => $label) {
            if ($n >= $size) {
                $parts[] = self::number(intdiv($n, $size)).' '.$label;
                $n %= $size;
            }
        }
        if ($n > 0) $parts[] = $n < 20 ? self::ONES[$n] : self::TENS[intdiv($n, 10)].($n % 10 ? '-'.self::ONES[$n % 10] : '');

        return implode(' ', $parts);
    }
}
