<?php

namespace App\Support;

/**
 * A small built-in PDF writer for HR letters (no PDF package is needed on the shared hosting):
 * the company letterhead on every page (logo, name, address, contact line), the letter body from simple
 * HTML (paragraphs, headings, bold / italic / underline, lists, line breaks, centred or right-aligned text),
 * the signature block (where the body says {signature}, else at the end) and "Page x of y" in the footer.
 * Uses the standard PDF Helvetica fonts, so text is real, selectable text.
 */
class LetterPdf
{
    public const SIGNATURE = '{signature}';

    private const W = 595.28;
    private const H = 841.89;
    private const LEFT = 56;
    private const RIGHT = 56;
    private const TOP = 132;
    private const BOTTOM = 70;
    private const SIZE = 10.5;
    private const LEADING = 15.5;

    private const INK = '0.12 0.16 0.22';
    private const NAVY = '0.075 0.145 0.31';
    private const MUTED = '0.38 0.44 0.53';
    private const ACCENT = '0.17 0.36 0.84';

    /** Helvetica / Helvetica-Bold widths (1/1000 em) for characters 32–126. */
    private const WIDTHS = [
        'R' => [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015,
            667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333,
            556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584],
        'B' => [278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611, 975,
            722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 333,
            556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584],
    ];

    /** A few Windows-1252 characters above 126 (curly quotes, dashes, bullet …); others use 556. */
    private const EXTRA_WIDTHS = [128 => 556, 145 => 222, 146 => 222, 147 => 333, 148 => 333, 149 => 350, 150 => 556, 151 => 1000, 160 => 278, 169 => 737, 174 => 737, 183 => 278];

    private const FONTS = ['R' => 'F1', 'B' => 'F2', 'I' => 'F3', 'BI' => 'F4'];

    /** @var list<string> one content stream per page */
    private array $pages = [];
    private string $ops = '';
    private float $y = 0;
    private ?array $logo = null;
    private array $blocks = [];
    private array $runs = [];

    /**
     * @param  array{name?: string, address_lines?: list<string>, contact?: string, footer?: string}  $company
     * @param  array{company?: string, name?: string, designation?: string}  $signatory
     */
    public function __construct(private readonly array $company, private readonly array $signatory, ?string $logoPath = null)
    {
        $this->logo = $logoPath ? self::loadImage($logoPath) : null;
    }

    /** The PDF file contents for the letter body (sanitised HTML). */
    public function render(string $html, string $title = 'Letter'): string
    {
        $this->pages = [];
        $this->newPage();
        $signed = false;
        foreach ($this->parse($html) as $block) {
            if ($block['type'] === 'signature') {
                $this->signatureBlock();
                $signed = true;
            } else {
                $this->paragraph($block);
            }
        }
        if (! $signed) $this->signatureBlock();
        $this->pages[] = $this->ops;

        return $this->document($title);
    }

    // ---- HTML → blocks -------------------------------------------------------------------------

    /** @return list<array> blocks: paragraphs (runs of [text, style]) and the signature marker */
    private function parse(string $html): array
    {
        $this->blocks = [];
        $this->runs = [];
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>'.$html.'</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $body = $dom->getElementsByTagName('body')->item(0);
        $base = ['type' => 'p', 'size' => self::SIZE, 'align' => 'left', 'indent' => 0, 'prefix' => null, 'bold' => false];
        if ($body) $this->walk($body, '', $base);
        $this->flush($base);

        return $this->blocks;
    }

    private function walk(\DOMNode $node, string $style, array $block): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $this->runs[] = [preg_replace('/[ \t\r\n]+/', ' ', $child->nodeValue), $style];
                continue;
            }
            if (! $child instanceof \DOMElement) continue;
            $tag = strtolower($child->tagName);
            if (in_array($tag, ['script', 'style', 'head', 'title'], true)) continue;
            if ($tag === 'br') {
                $this->runs[] = ["\n", $style];
            } elseif (in_array($tag, ['b', 'strong'], true)) {
                $this->walk($child, $this->addStyle($style, 'B'), $block);
            } elseif (in_array($tag, ['i', 'em'], true)) {
                $this->walk($child, $this->addStyle($style, 'I'), $block);
            } elseif ($tag === 'u') {
                $this->walk($child, $this->addStyle($style, 'U'), $block);
            } elseif (in_array($tag, ['ul', 'ol'], true)) {
                $this->flush($block);
                $n = 0;
                foreach ($child->childNodes as $li) {
                    if (! $li instanceof \DOMElement || strtolower($li->tagName) !== 'li') continue;
                    $n++;
                    $item = ['type' => 'p', 'indent' => $block['indent'] + 20, 'prefix' => $tag === 'ol' ? $n.'.' : "\x95", 'align' => 'left', 'gap' => 0.25] + $block;
                    $this->walk($li, $style, $item);
                    $this->flush($item);
                }
            } elseif (in_array($tag, ['p', 'div', 'li', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'table', 'tr'], true)) {
                $this->flush($block);
                $inner = ['prefix' => null, 'align' => $this->align($child) ?? $block['align'], 'gap' => null] + $block;
                if (preg_match('/^h([1-6])$/', $tag, $m)) $inner = ['size' => [1 => 16, 2 => 14, 3 => 12.5, 4 => 11.5, 5 => 11, 6 => 10.5][(int) $m[1]], 'bold' => true, 'gap' => 0.4] + $inner;
                $this->walk($child, $inner['bold'] ? $this->addStyle($style, 'B') : $style, $inner);
                $this->flush($inner);
            } else {
                $this->walk($child, $style, $block); // span, a, td … : just their text
            }
        }
    }

    private function addStyle(string $style, string $flag): string
    {
        return str_contains($style, $flag) ? $style : $style.$flag;
    }

    private function align(\DOMElement $el): ?string
    {
        return preg_match('/text-align\s*:\s*(left|center|right|justify)/i', (string) $el->getAttribute('style'), $m) ? strtolower($m[1]) : null;
    }

    /** Ends the paragraph being collected (empty ones are skipped; "{signature}" on its own is the signature marker). */
    private function flush(array $block): void
    {
        $runs = $this->runs;
        $this->runs = [];
        $text = trim(implode('', array_column($runs, 0)));
        if ($text === '') {
            // An empty paragraph (<p><br></p>) is a blank line, as in the editor.
            if ($runs && in_array("\n", array_column($runs, 0), true)) $this->blocks[] = ['type' => 'space'] + $block;
            return;
        }
        if ($text === self::SIGNATURE) {
            $this->blocks[] = ['type' => 'signature'];
            return;
        }
        $this->blocks[] = ['type' => 'p', 'runs' => $runs] + $block;
    }

    // ---- layout ---------------------------------------------------------------------------------

    private function paragraph(array $block): void
    {
        $size = (float) $block['size'];
        $leading = $size * (self::LEADING / self::SIZE);
        if ($block['type'] === 'space') {
            $this->y -= $leading * 0.6;
            return;
        }
        $indent = (float) $block['indent'];
        $max = self::W - self::LEFT - self::RIGHT - $indent;
        $lines = $this->lines($block['runs'], $size, $max);
        foreach ($lines as $i => [$segments, $width]) {
            if ($this->y - $leading < self::BOTTOM) {
                $this->pages[] = $this->ops;
                $this->newPage();
            }
            $this->y -= $leading;
            $x = self::LEFT + $indent + match ($block['align']) { 'center' => ($max - $width) / 2, 'right' => $max - $width, default => 0 };
            if ($i === 0 && $block['prefix'] !== null) $this->text(self::LEFT + $indent - 16, $this->y, $block['prefix'], 'R', $size, self::INK); // already Windows-1252
            foreach ($segments as [$text, $style, $offset]) {
                $font = (str_contains($style, 'B') ? 'B' : '').(str_contains($style, 'I') ? 'I' : '');
                $this->text($x + $offset, $this->y, $text, $font ?: 'R', $size, self::INK);
                if (str_contains($style, 'U')) $this->line($x + $offset, $this->y - 1.6, $x + $offset + $this->width($text, $style, $size), $this->y - 1.6, 0.5, self::INK);
            }
        }
        $this->y -= $leading * ($block['gap'] ?? 0.55);
    }

    /**
     * Breaks the runs into lines that fit the width; each line is [segments, width],
     * a segment being [text, style, x offset] (neighbouring words in the same style are joined).
     */
    private function lines(array $runs, float $size, float $max): array
    {
        $tokens = [];
        $space = false;
        foreach ($runs as [$text, $style]) {
            if ($text === "\n") {
                $tokens[] = null;
                $space = false;
                continue;
            }
            foreach (preg_split('/( )/', $this->cp1252($text), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
                if ($part === ' ') { $space = true; continue; }
                $tokens[] = [$part, $style, $space];
                $space = false;
            }
        }

        $lines = [];
        $segments = [];
        $width = 0.0;
        $push = function () use (&$lines, &$segments, &$width) {
            $lines[] = [$segments, $width];
            $segments = [];
            $width = 0.0;
        };
        foreach ($tokens as $token) {
            if ($token === null) { $push(); continue; }
            [$word, $style, $space] = $token;
            // A word longer than the line (a long link) is cut into pieces that fit.
            foreach ($this->pieces($word, $style, $size, $max) as $p => $piece) {
                $gap = $segments && $space && $p === 0 ? $this->width(' ', $style, $size) : 0.0;
                $w = $this->width($piece, $style, $size);
                if ($segments && $width + $gap + $w > $max) {
                    $push();
                    $gap = 0.0;
                }
                $last = array_key_last($segments);
                if ($last !== null && $segments[$last][1] === $style) {
                    $segments[$last][0] .= ($gap > 0 ? ' ' : '').$piece;
                } else {
                    $segments[] = [$piece, $style, $width + $gap];
                }
                $width += $gap + $w;
            }
        }
        if ($segments || ! $lines) $push();

        return $lines;
    }

    private function pieces(string $word, string $style, float $size, float $max): array
    {
        if ($this->width($word, $style, $size) <= $max) return [$word];
        $pieces = [];
        $current = '';
        foreach (str_split($word) as $char) {
            if ($current !== '' && $this->width($current.$char, $style, $size) > $max) {
                $pieces[] = $current;
                $current = '';
            }
            $current .= $char;
        }

        return [...$pieces, $current];
    }

    private function width(string $text, string $style, float $size): float
    {
        $table = self::WIDTHS[str_contains($style, 'B') ? 'B' : 'R'];
        $sum = 0;
        foreach (str_split($text) as $char) {
            $code = ord($char);
            $sum += $code >= 32 && $code <= 126 ? $table[$code - 32] : (self::EXTRA_WIDTHS[$code] ?? 556);
        }

        return $sum * $size / 1000;
    }

    /** Plain UTF-8 text wrapped to a width (letterhead address); the lines come back in Windows-1252. */
    private function wrapPlain(string $text, string $style, float $size, float $max): array
    {
        return array_map(fn ($line) => implode('', array_column($line[0], 0)), $this->lines([[$text, $style]], $size, $max));
    }

    private function signatureBlock(): void
    {
        if ($this->y - 96 < self::BOTTOM) {
            $this->pages[] = $this->ops;
            $this->newPage();
        }
        $this->y -= self::LEADING;
        $this->text(self::LEFT, $this->y, $this->cp1252('For '.($this->signatory['company'] ?? '')), 'B', self::SIZE, self::INK);
        $this->y -= 46;
        $this->line(self::LEFT, $this->y, self::LEFT + 170, $this->y, 0.6, self::MUTED);
        $this->y -= 13;
        $this->text(self::LEFT, $this->y, $this->cp1252(($this->signatory['name'] ?? '') ?: 'Authorised Signatory'), 'B', self::SIZE, self::INK);
        if ($designation = trim((string) ($this->signatory['designation'] ?? ''))) {
            $this->y -= 13;
            $this->text(self::LEFT, $this->y, $this->cp1252($designation), 'R', 9.5, self::MUTED);
        }
        $this->y -= self::LEADING;
    }

    private function newPage(): void
    {
        $this->ops = '';
        $top = self::H - 40;
        $x = self::LEFT;
        if ($this->logo) {
            $h = 50;
            $w = $h * $this->logo['w'] / max(1, $this->logo['h']);
            $this->ops .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im1 Do Q\n", $w, $h, $x, $top - $h);
            $x += $w + 14;
        }
        $width = self::W - self::RIGHT - $x;
        $this->text($x, $top - 17, $this->cp1252((string) ($this->company['name'] ?? '')), 'B', 17, self::NAVY);
        $y = $top - 31;
        foreach (array_slice($this->wrapPlain(implode(', ', $this->company['address_lines'] ?? []), 'R', 8.5, $width), 0, 2) as $line) {
            $this->text($x, $y, $line, 'R', 8.5, self::MUTED);
            $y -= 11;
        }
        if ($contact = (string) ($this->company['contact'] ?? '')) {
            $this->text($x, $y, $this->cp1252($contact), 'R', 8.5, self::MUTED);
        }
        $rule = self::H - self::TOP + 22;
        $this->line(self::LEFT, $rule, self::W - self::RIGHT, $rule, 1.4, self::ACCENT);
        $this->y = self::H - self::TOP;
    }

    // ---- drawing ---------------------------------------------------------------------------------

    private function text(float $x, float $y, string $text, string $font, float $size, string $color): void
    {
        if ($text === '') return;
        $this->ops .= sprintf("BT %s rg /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $color, self::FONTS[$font], $size, $x, $y, self::escape($text));
    }

    private function line(float $x1, float $y1, float $x2, float $y2, float $width, string $color): void
    {
        $this->ops .= sprintf("%s RG %.2F w %.2F %.2F m %.2F %.2F l S\n", $color, $width, $x1, $y1, $x2, $y2);
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ''], $text);
    }

    /** UTF-8 → Windows-1252 (the encoding of the standard fonts); ₹ is written "Rs.". */
    private function cp1252(string $text): string
    {
        if (! preg_match('/[^\x00-\x7F]/', $text)) return $text;
        $text = str_replace(['₹', "\u{00A0}"], ['Rs.', ' '], $text);
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);

        return $converted !== false ? $converted : mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    }

    // ---- file -----------------------------------------------------------------------------------

    private function document(string $title): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = null; // pages, written once the page objects are numbered
        foreach (['F1' => 'Helvetica', 'F2' => 'Helvetica-Bold', 'F3' => 'Helvetica-Oblique', 'F4' => 'Helvetica-BoldOblique'] as $i => $name) {
            $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /{$name} /Encoding /WinAnsiEncoding >>";
        }
        $image = null;
        if ($this->logo) {
            $objects[] = sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent 8 /Filter /%s /Length %d >>\nstream\n%s\nendstream",
                $this->logo['w'], $this->logo['h'], $this->logo['cs'], $this->logo['filter'], strlen($this->logo['data']), $this->logo['data']);
            $image = array_key_last($objects);
        }
        $resources = '<< /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R /F4 6 0 R >>'.($image ? " /XObject << /Im1 {$image} 0 R >>" : '').' >>';
        $count = count($this->pages);
        $kids = [];
        foreach ($this->pages as $n => $ops) {
            $ops .= $this->footer($n + 1, $count);
            $stream = gzcompress($ops);
            $objects[] = sprintf("<< /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream", strlen($stream), $stream);
            $content = array_key_last($objects);
            $objects[] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources %s /Contents %d 0 R >>', self::W, self::H, $resources, $content);
            $kids[] = array_key_last($objects).' 0 R';
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$count.' >>';
        $objects[] = '<< /Title ('.self::escape($this->cp1252($title)).') /Producer (SWS HR) /CreationDate (D:'.now()->format('YmdHis').') >>';
        $info = array_key_last($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $n => $body) {
            $offsets[$n] = strlen($pdf);
            $pdf .= "{$n} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n".'0 '.(count($objects) + 1)."\n".'0000000000 65535 f '."\n";
        foreach ($offsets as $offset) $pdf .= sprintf("%010d 00000 n \n", $offset);

        return $pdf.'trailer'."\n".'<< /Size '.(count($objects) + 1).' /Root 1 0 R /Info '.$info.' 0 R >>'."\n".'startxref'."\n".$xref."\n".'%%EOF';
    }

    private function footer(int $page, int $count): string
    {
        $saved = $this->ops;
        $this->ops = '';
        $this->line(self::LEFT, 52, self::W - self::RIGHT, 52, 0.5, '0.85 0.87 0.9');
        $this->text(self::LEFT, 38, $this->cp1252((string) ($this->company['footer'] ?? '')), 'R', 8, self::MUTED);
        $label = "Page {$page} of {$count}";
        $this->text(self::W - self::RIGHT - $this->width($label, 'R', 8), 38, $label, 'R', 8, self::MUTED);
        $footer = $this->ops;
        $this->ops = $saved;

        return $footer;
    }

    // ---- logo -----------------------------------------------------------------------------------

    /**
     * A JPEG as it is, or a PNG decoded to RGB on a white background (transparency flattened).
     *
     * @return array{w: int, h: int, cs: string, filter: string, data: string}|null
     */
    public static function loadImage(string $path): ?array
    {
        try {
            if (! is_file($path)) return null;
            $data = (string) file_get_contents($path);
            if (str_starts_with($data, "\xFF\xD8")) {
                $info = getimagesize($path);

                return $info ? ['w' => $info[0], 'h' => $info[1], 'cs' => ($info['channels'] ?? 3) === 1 ? 'DeviceGray' : 'DeviceRGB', 'filter' => 'DCTDecode', 'data' => $data] : null;
            }

            return str_starts_with($data, "\x89PNG\r\n\x1A\n") ? self::png($data) : null;
        } catch (\Throwable) {
            return null; // no logo rather than no letter
        }
    }

    private static function png(string $data): ?array
    {
        $pos = 8;
        $idat = '';
        $palette = '';
        $alphaTable = '';
        $header = null;
        while ($pos + 8 <= strlen($data)) {
            $length = unpack('N', substr($data, $pos, 4))[1];
            $type = substr($data, $pos + 4, 4);
            $chunk = substr($data, $pos + 8, $length);
            $pos += 12 + $length;
            if ($type === 'IHDR') $header = unpack('Nw/Nh/Cbits/Ccolor/Ccomp/Cfilter/Cinterlace', $chunk);
            elseif ($type === 'PLTE') $palette = $chunk;
            elseif ($type === 'tRNS') $alphaTable = $chunk;
            elseif ($type === 'IDAT') $idat .= $chunk;
            elseif ($type === 'IEND') break;
        }
        if (! $header || $header['bits'] !== 8 || $header['interlace'] !== 0) return null;
        $channels = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$header['color']] ?? null;
        $raw = $channels ? @gzuncompress($idat) : false;
        if ($raw === false) return null;

        $w = $header['w'];
        $h = $header['h'];
        $stride = $w * $channels;
        $previous = array_fill(0, $stride, 0);
        $rgb = '';
        for ($row = 0, $offset = 0; $row < $h; $row++, $offset += $stride + 1) {
            $filter = ord($raw[$offset] ?? "\0");
            $line = array_values(unpack('C*', substr($raw, $offset + 1, $stride)) ?: []);
            if (count($line) < $stride) return null;
            for ($i = 0; $i < $stride; $i++) {
                $left = $i >= $channels ? $line[$i - $channels] : 0;
                $up = $previous[$i];
                $upLeft = $i >= $channels ? $previous[$i - $channels] : 0;
                $line[$i] = ($line[$i] + match ($filter) {
                    1 => $left,
                    2 => $up,
                    3 => intdiv($left + $up, 2),
                    4 => self::paeth($left, $up, $upLeft),
                    default => 0,
                }) & 0xFF;
            }
            $previous = $line;
            $out = [];
            for ($i = 0; $i < $w; $i++) {
                [$r, $g, $b, $a] = match ($header['color']) {
                    0 => [$line[$i], $line[$i], $line[$i], 255],
                    2 => [$line[$i * 3], $line[$i * 3 + 1], $line[$i * 3 + 2], 255],
                    3 => [ord($palette[$line[$i] * 3] ?? "\0"), ord($palette[$line[$i] * 3 + 1] ?? "\0"), ord($palette[$line[$i] * 3 + 2] ?? "\0"), isset($alphaTable[$line[$i]]) ? ord($alphaTable[$line[$i]]) : 255],
                    4 => [$line[$i * 2], $line[$i * 2], $line[$i * 2], $line[$i * 2 + 1]],
                    6 => [$line[$i * 4], $line[$i * 4 + 1], $line[$i * 4 + 2], $line[$i * 4 + 3]],
                };
                // Flatten onto white paper.
                $out[] = intdiv($r * $a + 255 * (255 - $a), 255);
                $out[] = intdiv($g * $a + 255 * (255 - $a), 255);
                $out[] = intdiv($b * $a + 255 * (255 - $a), 255);
            }
            $rgb .= pack('C*', ...$out);
        }

        return ['w' => $w, 'h' => $h, 'cs' => 'DeviceRGB', 'filter' => 'FlateDecode', 'data' => gzcompress($rgb)];
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
    }
}
