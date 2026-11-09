<?php
/**
 * Zinbconnect — génération de planches de tickets en PDF, sans dépendance.
 *
 * Un PDF A4 avec 10 tickets par page (2 colonnes × 5 lignes) : QR code
 * vectoriel, code en gros caractères, forfait, prix et durée. Aucun volume
 * de données n'est imprimé : pour le client, l'offre est illimitée pendant
 * la durée du forfait (les plafonds techniques restent côté administration).
 *
 * Encodage : le PDF utilise WinAnsiEncoding (CP1252). La conversion est faite
 * par une table explicite (pas d'iconv) pour que « é », « « » », « € »…
 * sortent correctement sur toutes les imprimantes.
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

    /**
     * Texte à position verticale depuis le haut de la page.
     * La coupe éventuelle se fait AVANT la conversion d'encodage.
     */
    public function text(string $font, float $size, float $x, float $yTop, string $text, ?float $maxWidth = null): void
    {
        if ($maxWidth !== null) {
            $text = self::clip($text, $font, $size, $maxWidth);
        }
        $encoded = self::encode($text);
        $y = $this->pageH - $yTop - $size * 0.78;
        $this->current .= sprintf(
            "BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            $font, $size, $x, $y, self::escape($encoded)
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

    /** Largeur moyenne d'un caractère, en fraction de la taille de police. */
    private static function charRatio(string $font): float
    {
        return str_starts_with($font, 'F3') || str_starts_with($font, 'F4') ? 0.60 : 0.52;
    }

    /** Coupe proprement un texte trop long (jamais au milieu d'un accent). */
    private static function clip(string $text, string $font, float $size, float $maxWidth): string
    {
        $maxChars = max(3, (int)floor($maxWidth / ($size * self::charRatio($font))));
        if (mb_strlen($text, 'UTF-8') <= $maxChars) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $maxChars - 1, 'UTF-8')) . '…';
    }

    /**
     * UTF-8 -> WinAnsi (CP1252) sans iconv : table pour les caractères
     * typographiques, Latin-1 direct pour le reste, « ? » en dernier recours.
     */
    private static function encode(string $s): string
    {
        static $special = [
            '€' => "\x80", '‚' => "\x82", 'ƒ' => "\x83", '„' => "\x84", '…' => "\x85",
            '†' => "\x86", '‡' => "\x87", 'ˆ' => "\x88", '‰' => "\x89", 'Š' => "\x8A",
            '‹' => "\x8B", 'Œ' => "\x8C", 'Ž' => "\x8E", '‘' => "\x91", '’' => "\x92",
            '“' => "\x93", '”' => "\x94", '•' => "\x95", '–' => "\x96", '—' => "\x97",
            '˜' => "\x98", '™' => "\x99", 'š' => "\x9A", '›' => "\x9B", 'œ' => "\x9C",
            'ž' => "\x9E", 'Ÿ' => "\x9F", ' ' => ' ', "\u{00A0}" => ' ',
        ];
        if (preg_match('//u', $s) !== 1) {
            return '?';
        }

        $out = '';
        $len = mb_strlen($s, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($s, $i, 1, 'UTF-8');
            if (isset($special[$ch])) {
                $out .= $special[$ch];
                continue;
            }
            $cp = mb_ord($ch, 'UTF-8');
            if ($cp === false) {
                $out .= '?';
            } elseif ($cp < 128) {
                $out .= $ch;
            } elseif ($cp >= 0xA0 && $cp <= 0xFF) {
                $out .= chr($cp); // Latin-1 = CP1252 sur cette plage
            } else {
                $out .= '?';
            }
        }
        return $out;
    }
}

/**
 * Construit la planche PDF d'un lot.
 *
 * $tickets : [['code'=>string, 'plan'=>string, 'price'=>float,
 *              'duration'=>string], …]  (aucun volume de données : illimité)
 * $meta    : ['portal_name'=>string, 'portal_url'=>?string, 'batch'=>string,
 *             'date'=>string]
 */
function pdf_ticket_sheet(array $tickets, array $meta): string
{
    $portalName = $meta['portal_name'] ?? (setting('portal_name') ?? 'Zinbconnect');
    $portalUrl  = trim((string)($meta['portal_url'] ?? ''));
    if ($portalUrl === '') {
        $portalUrl = 'http://10.20.30.1/login.html';
    }
    $batch      = (string)($meta['batch'] ?? '');
    $date       = (string)($meta['date'] ?? date('d/m/Y'));

    $pdf = new PdfDoc();
    $perPage = 10;
    $marginX = 26.0;
    $gapX    = 10.0;
    $cellW   = ($pdf->width() - 2 * $marginX - $gapX) / 2;
    $cellH   = 150.0;
    $gapY    = 4.0;
    $startY  = 38.0;

    $pages = array_chunk($tickets, $perPage);
    $pagesCount = max(1, count($pages));

    foreach ($pages as $pageIndex => $pageTickets) {
        $pdf->newPage();

        // En-tête discret
        $pdf->text('F2', 10, $marginX, 14, $portalName . ' — tickets Wi-Fi', $pdf->width() - 2 * $marginX);
        $pdf->text('F1', 8, $marginX, 26, 'Lot ' . $batch . ' · ' . count($tickets) . ' ticket(s) · ' . $date
            . ' · page ' . ($pageIndex + 1) . '/' . $pagesCount, $pdf->width() - 2 * $marginX);

        foreach ($pageTickets as $i => $t) {
            $col = $i % 2;
            $row = intdiv($i, 2);
            $x = $marginX + $col * ($cellW + $gapX);
            $y = $startY + $row * ($cellH + $gapY);

            // Découpe
            $pdf->dashedRect($x, $y, $cellW, $cellH);

            // QR code à gauche
            $qrSize = 92.0;
            $qrX = $x + 8;
            $qrY = $y + 12;

            $content = $t['code'];
            if ($portalUrl !== '') {
                $sep = str_contains($portalUrl, '?') ? '&' : '?';
                $content = $portalUrl . $sep . 'c=' . $t['code'];
            }
            try {
                try {
                    $qr = qr_encode($content);
                } catch (Throwable $e) {
                    // URL trop longue pour un QR : on imprime au moins le code.
                    $qr = qr_encode($t['code']);
                }
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

            // Textes à droite du QR
            $tx = $qrX + $qrSize + 9;
            $tw = $x + $cellW - $tx - 7;
            $duration = trim((string)($t['duration'] ?? ''));

            $priceVal = (float)($t['price'] ?? 0);
            $priceStr = $priceVal > 0.0 ? format_money($priceVal) : 'ESSAI GRATUIT (TEST)';
            $pdf->text('F2', 12, $tx, $y + 12, (string)$t['plan'], $tw);
            $pdf->text('F2', 13, $tx, $y + 29, $priceStr, $tw);
            $pdf->text('F1', 9, $tx, $y + 47, $duration !== '' ? 'Illimité pendant ' . $duration : 'Internet illimité', $tw);
            $pdf->text('F4', 15.5, $tx, $y + 65, (string)$t['code'], $tw);
            $pdf->line($tx, $y + 84, $tx + $tw, $y + 84);
            $pdf->text('F1', 8, $tx, $y + 88, 'Code à usage unique', $tw);

            // Instructions sur toute la largeur
            $ix = $x + 8;
            $iw = $cellW - 16;
            $pdf->text('F1', 7.6, $ix, $y + 108, 'Réseau « ' . $portalName . ' » : ouvrez la page de connexion', $iw);
            $pdf->text('F1', 7.6, $ix, $y + 119, 'du Wi-Fi, puis saisissez ce code.', $iw);
            $pdf->text('F1', 7, $ix, $y + 132, 'Valable une seule fois, dès la première connexion.', $iw);
            $pdf->text('F1', 6.2, $ix, $y + 143, 'Lot ' . $batch . ' · réf. ' . substr((string)$t['code'], -4), $iw);
        }
    }

    return $pdf->output('Tickets ' . $batch);
}
