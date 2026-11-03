<?php
/**
 * Zinbconnect — chiffrement des codes de tickets.
 *
 * Pourquoi : un ticket doit pouvoir être réimprimé (coupure d'électricité,
 * lot perdu, revendeur qui redemande sa feuille) alors que la base ne doit
 * jamais contenir de code utilisable en clair.
 *
 * Solution : deux colonnes par ticket.
 *   - code_hash : empreinte SHA-256, sert à retrouver/valider un code ;
 *   - code_enc  : code chiffré AES-256-GCM avec APP_KEY (du .env), sert
 *                 uniquement à réimprimer un lot par l'administrateur.
 * Sans APP_KEY, la réimpression est impossible (seul le hash subsiste).
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** Clé de 32 octets issue de APP_KEY, ou null si absente. */
function app_key(): ?string
{
    static $cached = false;
    static $key = null;
    if ($cached) {
        return $key;
    }
    $cached = true;

    $raw = env('APP_KEY');
    if ($raw === null || trim($raw) === '') {
        return null;
    }
    $raw = trim($raw);
    if (preg_match('/^[0-9a-f]{64}$/i', $raw)) {
        $key = hex2bin($raw) ?: null;
    } else {
        // Phrase secrète quelconque : on en dérive une clé de 32 octets.
        $key = hash('sha256', $raw, true);
    }
    return $key;
}

/** Chiffre un code. Retourne null si APP_KEY est absente ou en cas d'erreur. */
function code_encrypt(string $code): ?string
{
    $key = app_key();
    if ($key === null) {
        return null;
    }
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($code, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false) {
        return null;
    }
    return 'v1.' . base64_encode($iv . $tag . $ct);
}

/** Déchiffre un code stocké par code_encrypt(). Null si illisible. */
function code_decrypt(?string $enc): ?string
{
    if ($enc === null || $enc === '') {
        return null;
    }
    if (!str_starts_with($enc, 'v1.')) {
        return null;
    }
    $key = app_key();
    if ($key === null) {
        return null;
    }
    $raw = base64_decode(substr($enc, 3), true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct  = substr($raw, 28);
    $pt  = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $pt === false ? null : $pt;
}
