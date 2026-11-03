<?php
/**
 * Zinbconnect — réglages modifiables (table settings) et formats d'affichage.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** Valeurs par défaut, utilisées si la table est vide. */
const SETTING_DEFAULTS = [
    'starlink_quota_bytes' => '536870912000',   // 500 Gio (forfait Local Priority)
    'currency'             => 'FC',
    'portal_name'          => 'Zinbconnect Kitutu',
    'reseller_commission'  => '25',             // pourcentage reversé au revendeur
    'low_stock_threshold'  => '20',             // alerte stock de tickets
];

function setting(string $key, ?string $default = null): ?string
{
    if (!isset($GLOBALS['__SETTINGS'])) {
        $GLOBALS['__SETTINGS'] = [];
        try {
            foreach (db()->query('SELECT k, v FROM settings')->fetchAll() as $row) {
                $GLOBALS['__SETTINGS'][$row['k']] = (string)$row['v'];
            }
        } catch (Throwable $e) {
            // table absente (base non migrée) : on retombe sur les défauts
        }
    }
    if (array_key_exists($key, $GLOBALS['__SETTINGS'])) {
        return $GLOBALS['__SETTINGS'][$key];
    }
    return $default ?? (SETTING_DEFAULTS[$key] ?? null);
}

function setting_int(string $key, int $default = 0): int
{
    $v = setting($key);
    return $v === null || $v === '' ? $default : (int)$v;
}

function setting_set(string $key, string $value): void
{
    $db = db();
    $exists = $db->prepare('SELECT 1 FROM settings WHERE k = ?');
    $exists->execute([$key]);
    if ($exists->fetchColumn()) {
        $db->prepare('UPDATE settings SET v = ? WHERE k = ?')->execute([$value, $key]);
    } else {
        $db->prepare('INSERT INTO settings (k, v) VALUES (?, ?)')->execute([$key, $value]);
    }
    // Mise à jour du cache en mémoire pour la fin de la requête.
    $GLOBALS['__SETTINGS'][$key] = $value;
}

/** Remplit les réglages absents avec les valeurs par défaut. */
function settings_seed(): void
{
    foreach (SETTING_DEFAULTS as $k => $v) {
        if (setting($k) === null) {
            setting_set($k, $v);
        }
    }
}

/** 1 234 567 octets -> "1,2 Mo" (unités françaises, base 1024). */
function format_bytes(?int $bytes, int $decimals = 1): string
{
    if ($bytes === null) {
        return 'illimité';
    }
    $b = max(0, $bytes);
    $units = ['o', 'Ko', 'Mo', 'Go', 'To'];
    $i = 0;
    $v = (float)$b;
    while ($v >= 1024 && $i < count($units) - 1) {
        $v /= 1024;
        $i++;
    }
    $dec = $i === 0 ? 0 : $decimals;
    return number_format($v, $dec, ',', ' ') . ' ' . $units[$i];
}

/** 2500 -> "2 500 FC". */
function format_money(float $amount): string
{
    return number_format($amount, 0, ',', ' ') . ' ' . (setting('currency') ?? 'FC');
}

/** Durée lisible à partir de secondes : "1 h 05", "3 j 04 h". */
function format_duration(int $seconds): string
{
    if ($seconds <= 0) {
        return '0 min';
    }
    $d = intdiv($seconds, 86400);
    $h = intdiv($seconds % 86400, 3600);
    $m = intdiv($seconds % 3600, 60);
    if ($d > 0) {
        return $d . ' j' . ($h > 0 ? ' ' . str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ' h' : '');
    }
    if ($h > 0) {
        return $h . ' h' . ($m > 0 ? ' ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) : '');
    }
    return max(1, $m) . ' min';
}
