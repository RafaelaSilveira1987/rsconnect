<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Pequeno gerador PDF sem dependências externas.
 *
 * Foi desenhado para relatórios executivos com texto, cartões, linhas e tabelas.
 * Usa Helvetica/WinAnsi e converte UTF-8 para Windows-1252.
 */
final class SimplePdfDocument
{
    public const PAGE_WIDTH = 595.28;
    public const PAGE_HEIGHT = 841.89;

    /** @var list<string> */
    private array $pages = [];
    private int $currentPage = -1;
    /** @var array<string,array{bytes:string,width:int,height:int}> */
    private array $images = [];

    public function addPage(): int
    {
        $this->pages[] = '';
        $this->currentPage = count($this->pages) - 1;
        return $this->currentPage;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function text(
        float $x,
        float $top,
        string $text,
        float $size = 10,
        bool $bold = false,
        string $color = '#172033'
    ): void {
        $this->ensurePage();
        [$r, $g, $b] = $this->rgb($color);
        $font = $bold ? 'F2' : 'F1';
        $encoded = $this->escape($text);
        $y = self::PAGE_HEIGHT - $top - $size;
        $this->append(sprintf(
            "BT /%s %.2F Tf %.4F %.4F %.4F rg %.2F %.2F Td (%s) Tj ET\n",
            $font,
            $size,
            $r,
            $g,
            $b,
            $x,
            $y,
            $encoded
        ));
    }

    /**
     * @return float altura usada
     */
    public function paragraph(
        float $x,
        float $top,
        float $width,
        string $text,
        float $size = 9,
        float $lineHeight = 13,
        bool $bold = false,
        string $color = '#445066',
        int $maxLines = 0
    ): float {
        $lines = $this->wrap($text, $width, $size, $bold);
        if ($maxLines > 0 && count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $last = array_pop($lines) ?? '';
            $lines[] = rtrim($this->substring($last, 0, max(1, $this->length($last) - 1))) . '…';
        }
        foreach ($lines as $index => $line) {
            $this->text($x, $top + ($index * $lineHeight), $line, $size, $bold, $color);
        }
        return max($lineHeight, count($lines) * $lineHeight);
    }

    /** @return list<string> */
    public function wrap(string $text, float $width, float $size = 9, bool $bold = false): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return [''];
        }

        // Aproximação conservadora para Helvetica.
        $factor = $bold ? 0.56 : 0.52;
        $maxChars = max(5, (int) floor($width / max(1.0, $size * $factor)));
        $words = preg_split('/\s+/u', $text) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            if ($line === '') {
                $line = $word;
                continue;
            }
            if ($this->length($line . ' ' . $word) <= $maxChars) {
                $line .= ' ' . $word;
                continue;
            }
            $lines[] = $line;
            $line = $word;
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        return $lines ?: [''];
    }

    public function rect(
        float $x,
        float $top,
        float $width,
        float $height,
        string $fill = '#ffffff',
        ?string $stroke = '#dfe6ef',
        float $lineWidth = 0.6
    ): void {
        $this->ensurePage();
        $y = self::PAGE_HEIGHT - $top - $height;
        [$fr, $fg, $fb] = $this->rgb($fill);
        $command = sprintf("q %.4F %.4F %.4F rg ", $fr, $fg, $fb);
        if ($stroke !== null) {
            [$sr, $sg, $sb] = $this->rgb($stroke);
            $command .= sprintf("%.4F %.4F %.4F RG %.2F w ", $sr, $sg, $sb, $lineWidth);
        }
        $command .= sprintf("%.2F %.2F %.2F %.2F re %s Q\n", $x, $y, $width, $height, $stroke === null ? 'f' : 'B');
        $this->append($command);
    }

    public function line(float $x1, float $top1, float $x2, float $top2, string $color = '#dfe6ef', float $width = 0.7): void
    {
        $this->ensurePage();
        [$r, $g, $b] = $this->rgb($color);
        $y1 = self::PAGE_HEIGHT - $top1;
        $y2 = self::PAGE_HEIGHT - $top2;
        $this->append(sprintf(
            "q %.4F %.4F %.4F RG %.2F w %.2F %.2F m %.2F %.2F l S Q\n",
            $r,
            $g,
            $b,
            $width,
            $x1,
            $y1,
            $x2,
            $y2
        ));
    }

    public function jpeg(string $path, float $x, float $top, float $width, float $height): void
    {
        $this->ensurePage();
        if (!isset($this->images[$path])) {
            $info = @getimagesize($path);
            $bytes = @file_get_contents($path);
            if ($info === false || $bytes === false || ($info['mime'] ?? '') !== 'image/jpeg') {
                return;
            }
            $this->images[$path] = [
                'bytes' => $bytes,
                'width' => (int) $info[0],
                'height' => (int) $info[1],
            ];
        }
        $index = array_search($path, array_keys($this->images), true);
        $resource = 'Im' . ((int) $index + 1);
        $y = self::PAGE_HEIGHT - $top - $height;
        $this->append(sprintf(
            "q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n",
            $width,
            $height,
            $x,
            $y,
            $resource
        ));
    }

    public function output(): string
    {
        if ($this->pages === []) {
            $this->addPage();
        }

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

        $imageCount = count($this->images);
        $firstPageObjectId = 5 + $imageCount;
        $pageObjectIds = [];
        foreach ($this->pages as $index => $_content) {
            $pageObjectIds[] = $firstPageObjectId + ($index * 2);
        }
        $kids = implode(' ', array_map(static fn (int $id): string => $id . ' 0 R', $pageObjectIds));
        $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($this->pages) . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        $xObjects = [];
        foreach (array_values($this->images) as $index => $image) {
            $imageId = 5 + $index;
            $resource = 'Im' . ($index + 1);
            $xObjects[] = '/' . $resource . ' ' . $imageId . ' 0 R';
            $objects[$imageId] = sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
                $image['width'],
                $image['height'],
                strlen($image['bytes']),
                $image['bytes']
            );
        }
        $xObjectResources = $xObjects !== [] ? ' /XObject << ' . implode(' ', $xObjects) . ' >>' : '';

        foreach ($this->pages as $index => $content) {
            $pageId = $firstPageObjectId + ($index * 2);
            $contentId = $pageId + 1;
            $footer = $this->footerCommands($index + 1, count($this->pages));
            $stream = $content . $footer;
            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>%s >> /Contents %d 0 R >>',
                self::PAGE_WIDTH,
                self::PAGE_HEIGHT,
                $xObjectResources,
                $contentId
            );
            $objects[$contentId] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        }

        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $maxId = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxId + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $offset = $offsets[$id] ?? 0;
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";
        return $pdf;
    }

    private function footerCommands(int $page, int $total): string
    {
        [$r, $g, $b] = $this->rgb('#7A8496');
        [$br, $bg, $bb] = $this->rgb('#2F80FF');
        [$pr, $pg, $pb] = $this->rgb('#7B3FF2');
        $text = $this->escape('RS CONNECT  |  Página ' . $page . ' de ' . $total);
        return sprintf(
            "q %.4F %.4F %.4F RG 1.4 w 42 31 m 300 31 l S Q\nq %.4F %.4F %.4F RG 1.4 w 300 31 m 553 31 l S Q\nBT /F2 7.2 Tf %.4F %.4F %.4F rg 42 17 Td (%s) Tj ET\n",
            $br, $bg, $bb,
            $pr, $pg, $pb,
            $r,
            $g,
            $b,
            $text
        );
    }

    private function append(string $command): void
    {
        $this->ensurePage();
        $this->pages[$this->currentPage] .= $command;
    }

    private function ensurePage(): void
    {
        if ($this->currentPage < 0) {
            $this->addPage();
        }
    }

    /** @return array{0:float,1:float,2:float} */
    private function rgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = '172033';
        }
        return [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
    }

    private function escape(string $text): string
    {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($encoded === false) {
            $encoded = preg_replace('/[^\x20-\x7E]/', '?', $text) ?: '';
        }
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $encoded);
    }
    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function substring(string $value, int $start, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, $start, $length, 'UTF-8') : substr($value, $start, $length);
    }

}
