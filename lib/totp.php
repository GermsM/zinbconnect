<?php
/**
 * TOTP RFC 6238, sans dépendance externe (HMAC-SHA1 + Base32).
 * Utilisé pour la 2FA optionnelle de l'administrateur :
 * l'application d'authentification (Aegis, Google Authenticator…) affiche
 * un code à 6 chiffres valid 30 secondes.
 */
declare(strict_types=1);

final class TOTP
{
    private const B32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Génère un secret Base32 aléatoire de $bytes octets (20 = 160 bits). */
    public static function generateSecret(int $bytes = 20): string
    {
        $bin = random_bytes($bytes);
        $out = '';
        $bits = 0;
        $value = 0;
        for ($i = 0; $i < strlen($bin); $i++) {
            $value = ($value << 8) | ord($bin[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $out .= self::B32_ALPHABET[($value >> ($bits - 5)) & 31];
                $bits -= 5;
            }
        }
        if ($bits > 0) {
            $out .= self::B32_ALPHABET[($value << (5 - $bits)) & 31];
        }
        return $out;
    }

    /** Code à 6 chiffres pour un instant ( toujours aligné sur le pas de 30 s). */
    public static function code(string $secretB32, ?int $time = null): string
    {
        $secret = self::base32Decode($secretB32);
        if ($secret === '') {
            throw new InvalidArgumentException('Secret Base32 invalide');
        }
        $time   = $time ?? time();
        $counter = intdiv($time, 30);
        // Compteur en big-endian sur 8 octets :
        $counterBin = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
        $digest = hash_hmac('sha1', $counterBin, $secret, true);
        $offset = ord($digest[strlen($digest) - 1]) & 0x0F;
        $binCode = (
            ((ord($digest[$offset + 0]) & 0x7F) << 24)
            | ((ord($digest[$offset + 1]) & 0xFF) << 16)
            | ((ord($digest[$offset + 2]) & 0xFF) << 8)
            |  (ord($digest[$offset + 3]) & 0xFF)
        );
        return str_pad((string)($binCode % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Valide un code soumis avec une fenêtre de tolérance ($window pas de 30s
     * avant/après, défaut 1) — protection contre le décalage d'horloge.
     */
    public static function verify(string $secretB32, string $token, int $window = 1): bool
    {
        $token = trim($token);
        if (!preg_match('/^\d{6}$/', $token)) {
            return false;
        }
        $now = time();
        for ($i = -$window; $i <= $window; $i++) {
            $candidate = self::code($secretB32, $now + $i * 30);
            if (hash_equals($candidate, $token)) {
                return true;
            }
        }
        return false;
    }

    /** URI otpauth:// à encoder en QR (une application d'auth le scanne). */
    public static function otpauthUri(string $secret, string $label, string $issuer = 'ZinbConnect'): string
    {
        return 'otpauth://totp/' . rawurlencode($label)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }

    private static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(str_replace('=', '', $b32));
        $out = '';
        $bits = 0;
        $value = 0;
        for ($i = 0; $i < strlen($b32); $i++) {
            $pos = strpos(self::B32_ALPHABET, $b32[$i]);
            if ($pos === false) {
                return '';
            }
            $value = ($value << 5) | $pos;
            $bits += 5;
            if ($bits >= 8) {
                $out .= chr(($value >> ($bits - 8)) & 0xFF);
                $bits -= 8;
            }
        }
        return $out;
    }
}
