<?php
/**
 * Zinbconnect — génération de planches de tickets en PDF, sans dépendance.
 *
 * Un PDF A4 avec 10 tickets par page (2 colonnes × 5 lignes) :
 * QR code vectoriel, code en gros caractères, forfait, prix, plafond de
 * données, durée et référence du lot. Les traits de découpe sont en pointillés.
 */
declare(strict_types=1);

require_once __DIR__ . '/qr.php';
require_once __DIR__ . '/settings.php';

final class PdfDoc
{
    /** @var string[] */
    private array $objects = [];
    private float $pageW;
    private float $pageH;
    /** @var string[] */
    private array $pages = [];
    private string $current = '';

    public function __construct(float $w = 595.28, float $h = 841.89)
    {
        $this->pageW = $w;
        $this->pageH = $h;
    }

    public function width(): float { return $this->pageW; }
    public function height(): float { return $this->pageH; }

    public function add(string $ops): void
    {
        $this->current .= $ops;
    }

    /** Texte à position verticale depuis le haut de la page. */
    public function text(string $font, float $size, float $x, float $yTop, string $text, ?float $maxWidth = null): void
    {
        $text = self::encode($text);
        if ($maxWidth !== null) {
            // Approximation : Courier 0,6 em, Helvetica 0,5 em
            $charW = str_starts_with($font, 'F3') || str_starts_with($font, 'F4') ? 0.6 : 0.52;
            $maxChars = max(3, (int)floor($maxWidth / ($size * $charW)));
            if (mb_strlen($text) > $maxChars) {
                $text = mb_substr($text, 0, $maxChars - 1) . '…';
            }
        }
        $y = $this->pageH - $yTop - $size * 0.82;
        $this->current .= sprintf(
            "BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            $font, $size, $x, $y, self::escape($text)
        );
    }

    /** Rectangle plein, coordonnées depuis le haut. */
    public function rect(float $x, float $yTop, float $w, float $h, float $gray = 0.0): void
    {
        $g = $gray > 1 ? 1 : ($gray < 0 ? 0 : $gray);
        $y = $this->pageH - $yTop - $h;
        $this->current .= sprintf("q %.3F g %.2F %.2F %.2F %.2F re f Q\n", $g, $x, $y, $w, $h);
    }

    /** Cadre en pointillés (traits de découpe). */
    public function dashedRect(float $x, float $yTop, float $w, float $h): void
    {
        $y = $this->pageH - $yTop - $h;
        $this->current .= sprintf("q 0.45 G 0.6 w [3 3] 0 d %.2F %.2F %.2F %.2F re S Q\n", $x, $y, $w, $h);
    }

    public function line(float $x1, float $y1Top, float $x2, float $y2Top): void
    {
        $this->current .= sprintf(
            "q 0.6 G 0.6 w %.2F %.2F m %.2F %.2F l S Q\n",
            $x1, $this->pageH - $y1Top, $x2, $this->pageH - $y2Top
        );
    }

    public function newPage(): void
    {
        if (trim($this->current) !== '') {
            $this->pages[] = $this->current;
        }
        $this->current = '';
    }

    /** @return string binaire du PDF */
    public function output(string $title = 'Zinbconnect'): string
    {
        if (trim($this->current) !== '') {
            $this->pages[] = $this->current;
            $this->current = '';
        }
        if (!$this->pages) {
            $this->pages[] = '';
        }

        // Objets : 1 catalogue, 2 pages, 3-6 polices, puis par page (contenu + page)
        $this->objects = [];
        $this->objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $fonts = [
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>',
            6 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding >>',
        ];
        foreach ($fonts as $i => $f) {
            $this->objects[$i] = $f;
        }

        $pageIds = [];
        $next = 7;
        foreach ($this->pages as $content) {
            $contentId = $next++;
            $pageId = $next++;
            $this->objects[$contentId] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
            $this->objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R /F4 6 0 R >> >> /Contents %d 0 R >>',
                $this->pageW, $this->pageH, $contentId
            );
            $pageIds[] = $pageId;
        }

        $kids = implode(' ', array_map(static fn($id) => $id . ' 0 R', $pageIds));
        $this->objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($pageIds) . ' >>';

        ksort($this->objects);

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($this->objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xrefPos = strlen($out);
        $max = max(array_keys($this->objects));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $info = self::escape(self::encode($title));
        $out .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info << /Title (" . $info . ") /Producer (Zinbconnect) >> >>\n";
        $out .= "startxref\n" . $xrefPos . "\n%%EOF";
        return $out;
    }

    private static function escape(string $s): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $s);
    }

    private static function encode(string $s): string
    {
        $converted = @iconv('UTF-8', 'CP1252//TRANSLIT', $s);
        return $converted === false ? $s : $converted;
    }
}

/**
 * Construit la planche PDF d'un lot.
 *
 * $tickets : [['code'=>string, 'plan'=>string, 'price'=>float,
 *              'limit'=>?int, 'duration'=>string], …]
 * $meta    : ['portal_name'=>string, 'portal_url'=>?string, 'batch'=>string,
 *             'plan'=>string, 'price'=>float, 'date'=>string]
 */
function pdf_ticket_sheet(array $tickets, array $meta): string
{
    $portalName = $meta['portal_name'] ?? (setting('portal_name') ?? 'Zinbconnect');
    $portalUrl  = trim((string)($meta['portal_url'] ?? ''));
    $batch      = (string)($meta['batch'] ?? '');
    $date       = (string)($meta['date'] ?? date('d/m/Y'));

    $pdf = new PdfDoc();
    $perPage = 10;
    $marginX = 28.0;
    $marginY = 24.0;
    $cellW = ($pdf->width() - 2 * $marginX - 8) / 2;
    $cellH = 148.0;
    $gapX = 8.0;
    $gapY = 6.0;

    $pages = array_chunk($tickets, $perPage);

    foreach ($pages as $pageIndex => $pageTickets) {
        $pdf->newPage();

        // En-tête discret
        $pdf->text('F2', 9, $marginX, 14, $portalName . ' — tickets Wi-Fi');
        $pdf->text('F1', 8, $marginX, 24, 'Lot ' . $batch . ' · ' . count($tickets) . ' ticket(s) · ' . $date . ' · page ' . ($pageIndex + 1) . '/' . count($pages));

        $startY = 34.0;
        foreach ($pageTickets as $i => $t) {
            $col = $i % 2;
            $row = intdiv($i, 2);
            $x = $marginX + $col * ($cellW + $gapX);
            $y = $startY + $row * ($cellH + $gapY);

            // Découpe
            $pdf->dashedRect($x, $y, $cellW, $cellH);

            // Bandeau gauche pour le QR
            $qrSize = 96.0;
            $qrX = $x + 8;
            $qrY = $y + ($cellH - $qrSize) / 2;

            $content = $t['code'];
            if ($portalUrl !== '') {
                $sep = str_contains($portalUrl, '?') ? '&' : '?';
                $candidate = $portalUrl . $sep . 'c=' . $t['code'];
                try {
                    qr_encode($candidate); // vérifie la capacité
                    $content = $candidate;
                } catch (Throwable $e) {
                    $content = $t['code'];
                }
            }
            try {
                $qr = qr_encode($content);
                $n = $qr['size'];
                $module = $qrSize / $n;
                foreach ($qr['matrix'] as $r => $line) {
                    $c = 0;
                    while ($c < $n) {
                        if ($line[$c] === 1) {
                            $start = $c;
                            while ($c < $n && $line[$c] === 1) {
                                $c++;
                            }
                            $pdf->rect($qrX + $start * $module, $qrY + $r * $module, ($c - $start) * $module, $module, 0.0);
                        } else {
                            $c++;
                        }
                    }
                }
            } catch (Throwable $e) {
                $pdf->text('F1', 7, $qrX, $qrY + 10, 'QR indisponible');
            }

            // Textes à droite
            $tx = $qrX + $qrSize + 10;
            $tw = $x + $cellW - $tx - 6;

            $pdf->text('F2', 11, $tx, $y + 12, (string)$t['plan'], $tw);
            $pdf->text('F2', 15, $tx, $y + 26, format_money((float)$t['price']) . ' / ' . ($t['duration'] ?? ''), $tw);
            $pdf->text('F3', 16, $tx, $y + 52, (string)$t['code'], $tw);
            $pdf->line($tx, $y + 70, $tx + $tw, $y + 70);
            $pdf->text('F1', 8, $tx, $y + 76, 'Internet : ' . ($t['limit'] ?? null ? format_bytes((int)$t['limit']) : 'illimité'), $tw);
            $pdf->text('F1', 7, $tx, $y + 88, 'Connexion : réseau « ' . $portalName . ' »', $tw);
            $pdf->text('F1', 7, $tx, $y + 98, 'puis entrez le code sur la page d\'accueil.', $tw);
            $pdf->text('F1', 6.5, $tx, $y + 112, 'Valable une seule fois, dès la première connexion.', $tw);
            $pdf->text('F1', 6, $tx, $y + 126, 'Lot ' . $batch . ' · ' . substr($t['code'], -4), $tw);
        }
    }

    return $pdf->output('Tickets ' . $batch);
}
