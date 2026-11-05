<?php

namespace App\Support;

/**
 * The training certificate as a one-page A4 PDF, laid out like the company's Word certificate: a double border, the
 * logo and company name, "Certificate of Training", the course, a boxed "This is to certify that …" paragraph, the
 * authorised signature (image) with the company seal, the date, and the company's address at the foot. Built with
 * the standard Times fonts, so nothing has to be installed on the server.
 */
class CertificatePdf
{
    private const W = 595.28;
    private const H = 841.89;
    private const NAVY = '0.13 0.27 0.50';
    private const INK = '0.1 0.1 0.1';

    /** Advance widths (1/1000 em) of characters 32–126: Times Roman, Bold, Italic, Bold Italic. */
    private const WIDTHS = [
        'R' => [250, 333, 408, 500, 500, 833, 778, 180, 333, 333, 500, 564, 250, 333, 250, 278, 500, 500, 500, 500, 500, 500, 500, 500, 500, 500, 278, 278, 564, 564, 564, 444, 921, 722, 667, 667, 722, 611, 556, 722, 722, 333, 389, 722, 611, 889, 722, 722, 556, 722, 667, 556, 611, 722, 722, 944, 722, 722, 611, 333, 278, 333, 469, 500, 333, 444, 500, 444, 500, 444, 333, 500, 500, 278, 278, 500, 278, 778, 500, 500, 500, 500, 333, 389, 278, 500, 500, 722, 500, 500, 444, 480, 200, 480, 541],
        'B' => [250, 333, 555, 500, 500, 1000, 833, 278, 333, 333, 500, 570, 250, 333, 250, 278, 500, 500, 500, 500, 500, 500, 500, 500, 500, 500, 333, 333, 570, 570, 570, 500, 930, 722, 667, 722, 722, 667, 611, 778, 778, 389, 500, 778, 667, 944, 722, 778, 611, 778, 722, 556, 667, 722, 722, 1000, 722, 722, 667, 333, 278, 333, 581, 500, 333, 500, 556, 444, 556, 444, 333, 500, 556, 278, 333, 556, 278, 833, 556, 500, 556, 556, 444, 389, 333, 556, 500, 722, 500, 500, 444, 394, 220, 394, 520],
        'I' => [250, 333, 420, 500, 500, 833, 778, 214, 333, 333, 500, 675, 250, 333, 250, 278, 500, 500, 500, 500, 500, 500, 500, 500, 500, 500, 333, 333, 675, 675, 675, 500, 920, 611, 611, 667, 722, 611, 611, 722, 722, 333, 444, 667, 556, 833, 667, 722, 611, 722, 611, 500, 556, 722, 611, 833, 611, 556, 556, 389, 278, 389, 422, 500, 333, 500, 500, 444, 500, 444, 278, 500, 500, 278, 278, 444, 278, 722, 500, 500, 500, 500, 389, 389, 278, 500, 444, 667, 444, 444, 389, 400, 275, 400, 541],
        'BI' => [250, 389, 555, 500, 500, 833, 778, 278, 333, 333, 500, 570, 250, 333, 250, 278, 500, 500, 500, 500, 500, 500, 500, 500, 500, 500, 333, 333, 570, 570, 570, 500, 832, 667, 667, 667, 722, 667, 667, 722, 778, 389, 500, 667, 611, 889, 722, 722, 611, 722, 667, 556, 611, 722, 667, 889, 667, 611, 611, 333, 278, 333, 570, 500, 333, 500, 500, 444, 500, 444, 333, 500, 500, 278, 278, 500, 278, 778, 556, 500, 500, 500, 389, 389, 278, 556, 444, 667, 500, 444, 389, 348, 220, 348, 570],
    ];

    private const FONTS = ['R' => 'F1', 'B' => 'F2', 'I' => 'F3', 'BI' => 'F4'];

    private string $ops = '';
    private ?array $logo;
    private ?array $signature;
    private ?array $seal;

    public function __construct(?string $logoPath = null, ?string $signaturePath = null, ?string $sealPath = null)
    {
        $this->logo = $logoPath ? LetterPdf::loadImage($logoPath) : null;
        $this->signature = $signaturePath ? LetterPdf::loadImage($signaturePath) : null;
        $this->seal = $sealPath ? LetterPdf::loadImage($sealPath) : null;
    }

    /**
     * @param  array{salutation: string, name: string, relation: string, parent: string, course: string, from: string, to: string, date: string,
     *               company: string, tagline: string, address_lines: list<string>, phone: string, email: string, website: string}  $c
     */
    public function render(array $c): string
    {
        $this->ops = '';
        $cx = self::W / 2;
        // Double border.
        $this->rect(18, 18, self::W - 36, self::H - 36, 3.2);
        $this->rect(25, 25, self::W - 50, self::H - 50, 0.8);

        // Logo (left) and company name with its line (right).
        if ($this->logo) $this->image('Im1', $this->logo, 62, 718, 120, 84, 'left');
        $this->center(400, 770, mb_strtoupper($c['company']), 'B', 19, self::NAVY);
        if (trim($c['tagline']) !== '') $this->center(400, 745, $c['tagline'], 'BI', 12.5, self::NAVY);

        // Title and course.
        $this->center($cx, 636, 'Certificate of Training', 'I', 42);
        $this->center($cx, 598, '('.$c['course'].' Training)', 'BI', 24);
        $this->center($cx, 576, '('.$c['course'].')', 'R', 15);
        $this->center($cx, 558, '('.$c['company'].')', 'R', 15);

        // The boxed statement.
        $this->rect(70, 340, self::W - 140, 150, 1.4);
        $this->rect(74, 344, self::W - 148, 142, 0.6);
        $this->center($cx, 462, 'This is to certify that', 'R', 13);
        $him = $c['salutation'] === 'Mr.' ? ['him', 'his'] : ($c['salutation'] === '' ? ['him/her', 'his/her'] : ['her', 'her']);
        $runs = [
            [$c['salutation'] !== '' ? $c['salutation'].' ' : '', 'R'], [$c['name'], 'BI'],
            [' '.$c['relation'].' ', 'R'], [$c['parent'], 'BI'],
            [' has successfully completed ', 'R'], [$c['course'], 'BI'],
            [' training at ', 'R'], [$c['company'], 'BI'],
            [' between ', 'R'], [$c['from'], 'BI'], [' and ', 'R'], [$c['to'], 'BI'], ['.', 'R'],
        ];
        if (trim($c['parent']) === '') $runs = [$runs[0], $runs[1], ...array_slice($runs, 4)];
        $y = 436;
        foreach ($this->wrap($runs, 12.5, self::W - 172) as $line) {
            $x = 86;
            foreach ($line as [$text, $style]) {
                $this->text($x, $y, $text, $style, 12.5);
                $x += $this->width($text, $style, 12.5);
            }
            $y -= 17;
        }
        $this->center($cx, min($y - 12, 372), "We wish {$him[0]} all the best for {$him[1]} better future.", 'R', 12.5);

        // Authorised signature (image on the dotted line), the seal beside it, and the date.
        $label = 'Authorized Signed ';
        $dots = str_repeat('.', 60);
        $lineW = $this->width($label.$dots, 'I', 13);
        $x0 = $cx - $lineW / 2;
        $this->text($x0, 290, $label, 'I', 13);
        $dotsX = $x0 + $this->width($label, 'I', 13);
        $this->text($dotsX, 290, $dots, 'R', 13);
        if ($this->signature) $this->image('Im2', $this->signature, $dotsX + 20, 293, 170, 46, 'center');
        if ($this->seal) $this->image('Im3', $this->seal, self::W - 160, 222, 88, 88, 'center');
        $this->center($cx, 260, 'Date: '.$c['date'], 'I', 13);

        // Company and address at the foot.
        $this->center($cx, 176, $c['company'], 'R', 22);
        $y = 156;
        $address = $c['address_lines'];
        foreach ($address as $i => $line) {
            $i === 0 ? $this->centerRuns($cx, $y, [['Address: ', 'B'], [$line, 'R']], 10) : $this->center($cx, $y, $line, 'R', 10);
            $y -= 12.5;
        }
        if ($c['phone'] !== '') { $this->center($cx, $y, $c['phone'], 'R', 10); $y -= 12.5; }
        if ($c['email'] !== '') { $this->centerRuns($cx, $y, [['Email: ', 'B'], [$c['email'], 'R']], 10); $y -= 12.5; }
        if ($c['website'] !== '') $this->centerRuns($cx, $y, [["Website \u{2013} ", 'B'], [$c['website'], 'R']], 10);

        return $this->document('Certificate of Training - '.$c['name']);
    }

    // ---- drawing ------------------------------------------------------------------------------

    private function rect(float $x, float $y, float $w, float $h, float $width): void
    {
        $this->ops .= sprintf("q %s RG %.2F w %.2F %.2F %.2F %.2F re S Q\n", self::INK, $width, $x, $y, $w, $h);
    }

    /** An image inside a box keeping its shape; $align left / center within the box. */
    private function image(string $name, array $img, float $x, float $y, float $maxW, float $maxH, string $align): void
    {
        $scale = min($maxW / max(1, $img['w']), $maxH / max(1, $img['h']));
        $w = $img['w'] * $scale;
        $h = $img['h'] * $scale;
        if ($align === 'center') $x += ($maxW - $w) / 2;
        $this->ops .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $w, $h, $x, $y + ($maxH - $h) / 2, $name);
    }

    private function text(float $x, float $y, string $text, string $style, float $size, string $color = self::INK): void
    {
        if ($text === '') return;
        $this->ops .= sprintf("BT %s rg /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $color, self::FONTS[$style], $size, $x, $y, self::escape(self::cp1252($text)));
    }

    private function center(float $cx, float $y, string $text, string $style, float $size, string $color = self::INK): void
    {
        $this->text($cx - $this->width($text, $style, $size) / 2, $y, $text, $style, $size, $color);
    }

    private function centerRuns(float $cx, float $y, array $runs, float $size): void
    {
        $x = $cx - array_sum(array_map(fn ($r) => $this->width($r[0], $r[1], $size), $runs)) / 2;
        foreach ($runs as [$text, $style]) {
            $this->text($x, $y, $text, $style, $size);
            $x += $this->width($text, $style, $size);
        }
    }

    private function width(string $text, string $style, float $size): float
    {
        $w = 0;
        foreach (str_split(self::cp1252($text)) as $ch) {
            $o = ord($ch);
            $w += ($o >= 32 && $o <= 126) ? self::WIDTHS[$style][$o - 32] : 500;
        }

        return $w * $size / 1000;
    }

    /** Runs of [text, style] broken into lines no wider than $max (at spaces). */
    private function wrap(array $runs, float $size, float $max): array
    {
        $words = [];
        foreach ($runs as [$text, $style]) {
            foreach (preg_split('/(\s+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) $words[] = [$part, $style];
        }
        $lines = [];
        $line = [];
        $width = 0;
        foreach ($words as [$word, $style]) {
            $w = $this->width($word, $style, $size);
            $space = trim($word) === '';
            if (! $space && $width + $w > $max && $line) {
                while ($line && trim(end($line)[0]) === '') array_pop($line);
                $lines[] = $line;
                $line = [];
                $width = 0;
            }
            if ($space && ! $line) continue;
            $line[] = [$space ? ' ' : $word, $style];
            $width += $space ? $this->width(' ', $style, $size) : $w;
        }
        if ($line) $lines[] = $line;

        // Words next to each other in the same style become one piece of text (so it copies as written).
        return array_map(function (array $line) {
            $merged = [];
            foreach ($line as [$text, $style]) {
                if ($merged && end($merged)[1] === $style) $merged[array_key_last($merged)][0] .= $text;
                else $merged[] = [$text, $style];
            }

            return $merged;
        }, $lines);
    }

    private static function cp1252(string $text): string
    {
        $text = str_replace(['₹', "\u{00A0}"], ['Rs.', ' '], $text);
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);

        return $converted !== false ? $converted : mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $text);
    }

    // ---- file -----------------------------------------------------------------------------------

    private function document(string $title): string
    {
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', 3 => null];
        $fonts = [];
        foreach (['F1' => 'Times-Roman', 'F2' => 'Times-Bold', 'F3' => 'Times-Italic', 'F4' => 'Times-BoldItalic'] as $key => $base) {
            $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /{$base} /Encoding /WinAnsiEncoding >>";
            $fonts[] = "/{$key} ".array_key_last($objects).' 0 R';
        }
        $images = [];
        foreach (['Im1' => $this->logo, 'Im2' => $this->signature, 'Im3' => $this->seal] as $name => $img) {
            if (! $img) continue;
            $objects[] = sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent 8 /Filter /%s /Length %d >>\nstream\n%s\nendstream",
                $img['w'], $img['h'], $img['cs'], $img['filter'], strlen($img['data']), $img['data']);
            $images[] = "/{$name} ".array_key_last($objects).' 0 R';
        }
        $stream = gzcompress($this->ops);
        $objects[] = sprintf("<< /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream", strlen($stream), $stream);
        $content = array_key_last($objects);
        $resources = '<< /Font << '.implode(' ', $fonts).' >>'.($images ? ' /XObject << '.implode(' ', $images).' >>' : '').' >>';
        $objects[3] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources %s /Contents %d 0 R >>', self::W, self::H, $resources, $content);
        $objects[] = '<< /Title ('.self::escape(self::cp1252($title)).') /Producer (SWS HR) /CreationDate (D:'.now()->format('YmdHis').') >>';
        $info = array_key_last($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $n => $body) {
            $offsets[$n] = strlen($pdf);
            $pdf .= "{$n} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) $pdf .= sprintf("%010d 00000 n \n", $offset);

        return $pdf."trailer\n<< /Size ".(count($objects) + 1).' /Root 1 0 R /Info '.$info." 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }
}
