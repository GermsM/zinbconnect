<?php
/**
 * Zinbconnect — générateur de QR code autonome (aucune extension, aucune
 * dépendance) : mode octet, correction d'erreur M, versions 1 à 10.
 *
 * Sortie : une matrice de modules (1 = noir). Le PDF (lib/pdf.php) dessine
 * directement les carrés, sans passer par une image.
 *
 * Implémentation ISO/IEC 18004. Validée matrice par matrice contre segno
 * (voir tools/qr_check.py) pour chaque masque.
 */
declare(strict_types=1);

/**
 * Table des capacités (correction M) :
 * version => [nombre total de mots de code, mots de correction par bloc,
 *             [[nombre de blocs, mots de données par bloc], …]]
 */
const QR_M_TABLE = [
    1  => [26,  10, [[1, 16]]],
    2  => [44,  16, [[1, 28]]],
    3  => [70,  26, [[1, 44]]],
    4  => [100, 18, [[2, 32]]],
    5  => [134, 24, [[2, 43]]],
    6  => [172, 16, [[4, 27]]],
    7  => [196, 18, [[4, 31]]],
    8  => [242, 22, [[2, 38], [2, 39]]],
    9  => [292, 22, [[3, 36], [2, 37]]],
    10 => [346, 26, [[4, 43], [1, 44]]],
];

const QR_ALIGN = [
    1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
    6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
];

function qr_data_codewords(int $version): int
{
    $n = 0;
    foreach (QR_M_TABLE[$version][2] as [$blocks, $data]) {
        $n += $blocks * $data;
    }
    return $n;
}

/** Multiplication dans GF(256), polynôme 0x11D. */
function qr_gf_mul(int $a, int $b): int
{
    $r = 0;
    while ($b > 0) {
        if ($b & 1) {
            $r ^= $a;
        }
        $b >>= 1;
        $a <<= 1;
        if ($a & 0x100) {
            $a ^= 0x11D;
        }
    }
    return $r & 0xFF;
}

function qr_gf_pow(int $a, int $n): int
{
    $r = 1;
    for ($i = 0; $i < $n; $i++) {
        $r = qr_gf_mul($r, $a);
    }
    return $r;
}

/** Polynôme générateur de degré $degree (coefficients du plus haut au plus bas). */
function qr_generator(int $degree): array
{
    $g = [1];
    for ($i = 0; $i < $degree; $i++) {
        $a = qr_gf_pow(2, $i);
        $new = array_fill(0, count($g) + 1, 0);
        for ($j = 0, $n = count($g); $j < $n; $j++) {
            $new[$j]     ^= $g[$j];
            $new[$j + 1] ^= qr_gf_mul($g[$j], $a);
        }
        $g = $new;
    }
    return $g;
}

/** Correction Reed-Solomon d'un bloc de données. */
function qr_rs_ecc(array $data, int $degree): array
{
    $gen = qr_generator($degree);
    $rem = array_fill(0, $degree, 0);
    foreach ($data as $byte) {
        $factor = $byte ^ $rem[0];
        array_shift($rem);
        $rem[] = 0;
        if ($factor !== 0) {
            for ($i = 0; $i < $degree; $i++) {
                $rem[$i] ^= qr_gf_mul($gen[$i + 1], $factor);
            }
        }
    }
    return $rem;
}

/** Construit la séquence finale de mots de code (données + correction entrelacées). */
function qr_codewords(string $data, int $version): array
{
    [$total, $ecPerBlock, $blocksSpec] = QR_M_TABLE[$version];
    $capacity = qr_data_codewords($version);

    // 1) Flux binaire : mode octet (0100) + longueur + données
    $bits = '0100';
    $bits .= str_pad(decbin(strlen($data)), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
    foreach (str_split($data) as $ch) {
        $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
    }
    // 2) Terminateur et bourrage
    $maxBits = $capacity * 8;
    $bits .= str_repeat('0', min(4, $maxBits - strlen($bits)));
    if (strlen($bits) % 8 !== 0) {
        $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
    }
    $dataCw = [];
    foreach (str_split($bits, 8) as $byte) {
        $dataCw[] = bindec($byte);
    }
    $pad = [0xEC, 0x11];
    $i = 0;
    while (count($dataCw) < $capacity) {
        $dataCw[] = $pad[$i++ % 2];
    }

    // 3) Découpage en blocs
    $dataBlocks = [];
    $ecBlocks   = [];
    $pos = 0;
    foreach ($blocksSpec as [$count, $size]) {
        for ($b = 0; $b < $count; $b++) {
            $block = array_slice($dataCw, $pos, $size);
            $pos += $size;
            $dataBlocks[] = $block;
            $ecBlocks[]   = qr_rs_ecc($block, $ecPerBlock);
        }
    }

    // 4) Entrelacement
    $out = [];
    $maxData = max(array_map('count', $dataBlocks));
    for ($i = 0; $i < $maxData; $i++) {
        foreach ($dataBlocks as $block) {
            if (isset($block[$i])) {
                $out[] = $block[$i];
            }
        }
    }
    for ($i = 0; $i < $ecPerBlock; $i++) {
        foreach ($ecBlocks as $block) {
            $out[] = $block[$i];
        }
    }

    if (count($out) !== $total) {
        throw new RuntimeException("QR interne : {$total} mots attendus, " . count($out) . ' produits');
    }
    return $out;
}

/**
 * Construit la matrice du QR.
 * $mask : 0-7 pour forcer un masque (tests), null pour choisir automatiquement.
 * Retour : ['version'=>int, 'size'=>int, 'mask'=>int, 'matrix'=>[[0|1]]]
 */
function qr_encode(string $data, ?int $mask = null): array
{
    $length = strlen($data);
    $version = null;
    foreach (array_keys(QR_M_TABLE) as $v) {
        $needBits = 4 + ($v < 10 ? 8 : 16) + 8 * $length;
        if ($needBits <= qr_data_codewords($v) * 8) {
            $version = $v;
            break;
        }
    }
    if ($version === null) {
        throw new InvalidArgumentException('Données trop longues pour un QR version 10-M (' . $length . ' octets)');
    }

    $size = 4 * $version + 17;
    $mod  = array_fill(0, $size, array_fill(0, $size, 0));
    $fn   = array_fill(0, $size, array_fill(0, $size, false));

    $set = static function (int $r, int $c, int $v) use (&$mod, &$fn, $size): void {
        if ($r >= 0 && $r < $size && $c >= 0 && $c < $size) {
            $mod[$r][$c] = $v ? 1 : 0;
            $fn[$r][$c]  = true;
        }
    };

    // --- Motifs de synchronisation ---
    $finder = [
        [1, 1, 1, 1, 1, 1, 1],
        [1, 0, 0, 0, 0, 0, 1],
        [1, 0, 1, 1, 1, 0, 1],
        [1, 0, 1, 1, 1, 0, 1],
        [1, 0, 1, 1, 1, 0, 1],
        [1, 0, 0, 0, 0, 0, 1],
        [1, 1, 1, 1, 1, 1, 1],
    ];
    foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$br, $bc]) {
        for ($r = 0; $r < 7; $r++) {
            for ($c = 0; $c < 7; $c++) {
                $set($br + $r, $bc + $c, $finder[$r][$c]);
            }
        }
        // Séparateurs (blancs)
        for ($i = -1; $i <= 7; $i++) {
            $set($br + $i, $bc - 1, 0);
            $set($br + $i, $bc + 7, 0);
            $set($br - 1, $bc + $i, 0);
            $set($br + 7, $bc + $i, 0);
        }
    }

    // --- Motifs de synchronisation (timing) ---
    for ($i = 8; $i < $size - 8; $i++) {
        $v = ($i % 2 === 0) ? 1 : 0;
        $set(6, $i, $v);
        $set($i, 6, $v);
    }

    // --- Motifs d'alignement ---
    $align = QR_ALIGN[$version];
    foreach ($align as $ar) {
        foreach ($align as $ac) {
            $overlapsFinder = ($ar <= 8 && $ac <= 8)
                || ($ar <= 8 && $ac >= $size - 9)
                || ($ar >= $size - 9 && $ac <= 8);
            if ($overlapsFinder) {
                continue;
            }
            for ($dr = -2; $dr <= 2; $dr++) {
                for ($dc = -2; $dc <= 2; $dc++) {
                    $v = (max(abs($dr), abs($dc)) !== 1) ? 1 : 0;
                    $set($ar + $dr, $ac + $dc, $v);
                }
            }
        }
    }

    // --- Module noir fixe ---
    $set(4 * $version + 9, 8, 1);

    // --- Réserve les zones d'information de format (sans toucher au timing) ---
    for ($i = 0; $i <= 8; $i++) {
        if ($i !== 6) {
            $set(8, $i, 0);
            $set($i, 8, 0);
        }
    }
    for ($i = 0; $i < 8; $i++) {
        $set($size - 1 - $i, 8, 0);
        $set(8, $size - 1 - $i, 0);
    }

    // --- Information de version (v7+) ---
    if ($version >= 7) {
        $vbits = ($version << 12) | qr_bch($version, 0x1F25, 12);
        for ($i = 0; $i < 18; $i++) {
            $bit = ($vbits >> $i) & 1;
            $set(intdiv($i, 3), $size - 11 + $i % 3, $bit);
            $set($size - 11 + $i % 3, intdiv($i, 3), $bit);
        }
    }

    // --- Placement des données (zigzag) ---
    $cw = qr_codewords($data, $version);
    $bitIndex = 0;
    $totalBits = count($cw) * 8;
    $upward = true;
    for ($col = $size - 1; $col > 0; $col -= 2) {
        if ($col === 6) {
            $col--; // saute la colonne de synchronisation
        }
        for ($i = 0; $i < $size; $i++) {
            $row = $upward ? $size - 1 - $i : $i;
            for ($c = 0; $c < 2; $c++) {
                $cc = $col - $c;
                if ($fn[$row][$cc]) {
                    continue;
                }
                $bit = 0;
                if ($bitIndex < $totalBits) {
                    $byte = $cw[intdiv($bitIndex, 8)];
                    $bit  = ($byte >> (7 - ($bitIndex % 8))) & 1;
                }
                $mod[$row][$cc] = $bit;
                $bitIndex++;
            }
        }
        $upward = !$upward;
    }

    // --- Choix du masque ---
    $masks = $mask !== null ? [$mask] : [0, 1, 2, 3, 4, 5, 6, 7];
    $best = null;
    $bestScore = PHP_INT_MAX;
    $bestMatrix = null;

    foreach ($masks as $m) {
        $candidate = $mod;
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if (!$fn[$r][$c] && qr_mask_condition($m, $r, $c)) {
                    $candidate[$r][$c] ^= 1;
                }
            }
        }
        qr_place_format($candidate, $fn, $version, $m, $size);
        qr_place_version($candidate, $version, $size);
        $score = qr_penalty($candidate, $size);
        if ($score < $bestScore) {
            $bestScore = $score;
            $best = $m;
            $bestMatrix = $candidate;
        }
    }

    return ['version' => $version, 'size' => $size, 'mask' => $best, 'matrix' => $bestMatrix];
}

function qr_bch(int $data, int $poly, int $bits): int
{
    $d = $data << $bits;
    $polyBits = (int)floor(log($poly, 2)) + 1;
    while (($len = (int)floor(log(max(1, $d), 2)) + 1) >= $polyBits) {
        $d ^= $poly << ($len - $polyBits);
    }
    return $d;
}

function qr_mask_condition(int $mask, int $r, int $c): bool
{
    return match ($mask) {
        0 => ($r + $c) % 2 === 0,
        1 => $r % 2 === 0,
        2 => $c % 3 === 0,
        3 => ($r + $c) % 3 === 0,
        4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
        5 => ($r * $c) % 2 + ($r * $c) % 3 === 0,
        6 => (($r * $c) % 2 + ($r * $c) % 3) % 2 === 0,
        7 => (($r + $c) % 2 + ($r * $c) % 3) % 2 === 0,
        default => false,
    };
}

/** Écrit les 15 bits d'information de format (deux copies). */
function qr_place_format(array &$matrix, array $fn, int $version, int $mask, int $size): void
{
    // Niveau M = 00
    $data = (0b00 << 3) | $mask;
    $bits = ($data << 10) | qr_bch($data, 0x537, 10);
    $bits ^= 0x5412;

    $bit = static fn(int $i): int => ($bits >> $i) & 1;

    // Copie 1 : colonne 8 en haut, puis ligne 8 à gauche
    for ($i = 0; $i <= 5; $i++) {
        $matrix[$i][8] = $bit($i);
    }
    $matrix[7][8] = $bit(6);
    $matrix[8][8] = $bit(7);
    $matrix[8][7] = $bit(8);
    for ($i = 9; $i < 15; $i++) {
        $matrix[8][14 - $i] = $bit($i);
    }

    // Copie 2 : ligne 8 à droite, colonne 8 en bas
    for ($i = 0; $i < 8; $i++) {
        $matrix[8][$size - 1 - $i] = $bit($i);
    }
    for ($i = 8; $i < 15; $i++) {
        $matrix[$size - 15 + $i][8] = $bit($i);
    }
    // Module toujours noir, au-dessus du motif de synchronisation bas-gauche
    $matrix[$size - 8][8] = 1;
}

/** Écrit l'information de version (v7+). */
function qr_place_version(array &$matrix, int $version, int $size): void
{
    if ($version < 7) {
        return;
    }
    $bits = ($version << 12) | qr_bch($version, 0x1F25, 12);
    for ($i = 0; $i < 18; $i++) {
        $b = ($bits >> $i) & 1;
        $matrix[intdiv($i, 3)][$size - 11 + $i % 3] = $b;
        $matrix[$size - 11 + $i % 3][intdiv($i, 3)] = $b;
    }
}

/** Score de pénalité ISO (règles 1 à 4), pour choisir le meilleur masque. */
function qr_penalty(array $m, int $size): int
{
    $score = 0;

    // Règle 1 : séries de 5 modules ou plus de même couleur
    for ($i = 0; $i < $size; $i++) {
        $runRow = 1;
        $runCol = 1;
        for ($j = 1; $j < $size; $j++) {
            $runRow = ($m[$i][$j] === $m[$i][$j - 1]) ? $runRow + 1 : 1;
            if ($runRow === 5) { $score += 3; } elseif ($runRow > 5) { $score += 1; }
            $runCol = ($m[$j][$i] === $m[$j - 1][$i]) ? $runCol + 1 : 1;
            if ($runCol === 5) { $score += 3; } elseif ($runCol > 5) { $score += 1; }
        }
    }

    // Règle 2 : blocs 2x2 de même couleur
    for ($r = 0; $r < $size - 1; $r++) {
        for ($c = 0; $c < $size - 1; $c++) {
            $v = $m[$r][$c];
            if ($v === $m[$r][$c + 1] && $v === $m[$r + 1][$c] && $v === $m[$r + 1][$c + 1]) {
                $score += 3;
            }
        }
    }

    // Règle 3 : motif 1:1:3:1:1 (niveaux clair/sombre) entouré de 4 modules clairs
    $patA = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
    $patB = [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1];
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c < $size - 10; $c++) {
            $okA = true; $okB = true;
            for ($k = 0; $k < 11; $k++) {
                if ($m[$r][$c + $k] !== $patA[$k]) { $okA = false; }
                if ($m[$r][$c + $k] !== $patB[$k]) { $okB = false; }
            }
            if ($okA) { $score += 40; }
            if ($okB) { $score += 40; }
        }
    }
    for ($c = 0; $c < $size; $c++) {
        for ($r = 0; $r < $size - 10; $r++) {
            $okA = true; $okB = true;
            for ($k = 0; $k < 11; $k++) {
                if ($m[$r + $k][$c] !== $patA[$k]) { $okA = false; }
                if ($m[$r + $k][$c] !== $patB[$k]) { $okB = false; }
            }
            if ($okA) { $score += 40; }
            if ($okB) { $score += 40; }
        }
    }

    // Règle 4 : équilibre noir / blanc
    $dark = 0;
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c < $size; $c++) {
            $dark += $m[$r][$c];
        }
    }
    $ratio = $dark * 100 / ($size * $size);
    $score += (int)(floor(abs($ratio - 50) / 5) * 10);

    return $score;
}
