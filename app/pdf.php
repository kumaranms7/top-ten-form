<?php
/** Tiny PDF table writer (built-in Helvetica, landscape A4, auto page breaks). Text is converted to Windows-1252; other characters become "?". */
class SimplePdf {
    private array $pages = []; private string $cur = ''; private float $y; private int $n = 0;
    private const W = 842, H = 595, M = 36;
    private array $widths = [];

    private function esc(string $s): string {
        $s = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s) ?: preg_replace('/[^\x20-\x7E]/', '?', $s);
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $s);
    }
    private function textWidth(string $s, float $size): float { return strlen($s) * $size * 0.5; } // good-enough average for Helvetica

    private function fit(string $s, float $maxW, float $size): string {
        $s = preg_replace('/\s+/', ' ', $s);
        $max = max(1, (int)floor($maxW / ($size * 0.5)));
        return mb_strlen($s) > $max ? mb_substr($s, 0, max(1, $max - 1)) . '...' : $s;
    }
    private function newPage(string $title, string $sub): void {
        if ($this->cur !== '') $this->pages[] = $this->cur;
        $this->cur = ''; $this->n++;
        $this->cur .= "0.388 0.4 0.945 rg\n" . self::M . ' ' . (self::H - 44) . ' ' . (self::W - 2 * self::M) . " 22 re f\n";
        $this->cur .= "BT /F2 12 Tf 1 1 1 rg " . (self::M + 8) . ' ' . (self::H - 38) . ' Td (' . $this->esc($title) . ") Tj ET\n";
        $this->cur .= "BT /F1 8 Tf 0.4 0.42 0.5 rg " . self::M . ' ' . (self::H - 58) . ' Td (' . $this->esc($sub) . ") Tj ET\n";
        $this->y = self::H - 76;
    }

    /** @param string[] $headers  @param array<int,string[]> $rows */
    public function table(string $title, string $sub, array $headers, array $rows): void {
        $cols = max(1, count($headers)); $avail = self::W - 2 * self::M;
        // column widths proportional to content length (clamped)
        $len = array_fill(0, $cols, 6);
        foreach (array_merge([$headers], array_slice($rows, 0, 200)) as $r) foreach ($r as $i => $v) $len[$i] = max($len[$i], min(40, mb_strlen((string)$v)));
        $tot = array_sum($len); $this->widths = array_map(fn($l) => $avail * $l / $tot, $len);
        $size = $cols > 9 ? 6.5 : ($cols > 6 ? 7.5 : 8.5); $rowH = $size + 8;
        $this->newPage($title, $sub);
        $head = function () use ($headers, $size, $rowH) {
            $x = self::M;
            $this->cur .= "0.93 0.94 1 rg " . self::M . ' ' . ($this->y - 4) . ' ' . (self::W - 2 * self::M) . ' ' . $rowH . " re f\n";
            foreach ($headers as $i => $h) { $this->cur .= 'BT /F2 ' . $size . ' Tf 0.2 0.2 0.5 rg ' . ($x + 3) . ' ' . $this->y . ' Td (' . $this->esc($this->fit((string)$h, $this->widths[$i] - 6, $size)) . ") Tj ET\n"; $x += $this->widths[$i]; }
            $this->y -= $rowH;
        };
        $head();
        foreach ($rows as $ri => $r) {
            if ($this->y < self::M + 20) { $this->newPage($title, $sub); $head(); }
            if ($ri % 2) $this->cur .= "0.975 0.977 0.99 rg " . self::M . ' ' . ($this->y - 4) . ' ' . (self::W - 2 * self::M) . ' ' . $rowH . " re f\n";
            $x = self::M;
            foreach ($headers as $i => $_) { $this->cur .= 'BT /F1 ' . $size . ' Tf 0.1 0.1 0.15 rg ' . ($x + 3) . ' ' . $this->y . ' Td (' . $this->esc($this->fit((string)($r[$i] ?? ''), $this->widths[$i] - 6, $size)) . ") Tj ET\n"; $x += $this->widths[$i]; }
            $this->y -= $rowH;
        }
    }

    public function output(): string {
        if ($this->cur !== '') $this->pages[] = $this->cur;
        $objs = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>', 4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];
        $kids = []; $id = 5; $total = count($this->pages);
        foreach ($this->pages as $i => $content) {
            $content .= "BT /F1 7 Tf 0.5 0.5 0.55 rg " . (self::W - self::M - 60) . ' 18 Td (Page ' . ($i + 1) . ' of ' . $total . ") Tj ET\n";
            $objs[$id] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream"; $cid = $id++;
            $objs[$id] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . self::W . ' ' . self::H . "] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents $cid 0 R >>"; $kids[] = "$id 0 R"; $id++;
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);
        $out = "%PDF-1.4\n"; $off = [];
        foreach ($objs as $n => $body) { $off[$n] = strlen($out); $out .= "$n 0 obj\n$body\nendobj\n"; }
        $xref = strlen($out); $max = max(array_keys($objs));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($n = 1; $n <= $max; $n++) $out .= sprintf("%010d 00000 n \n", $off[$n] ?? 0);
        return $out . "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    }
}
