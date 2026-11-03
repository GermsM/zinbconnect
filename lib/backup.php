<?php
/**
 * Zinbconnect — sauvegarde et restauration simples.
 *
 * Format : un fichier texte SQL lisible (structure + données), portable entre
 * SQLite et MySQL. Chaque instruction est suivie d'un séparateur explicite
 * "-- @@" pour ne pas casser les valeurs contenant des espaces ou des retours
 * à la ligne.
 *
 * La restauration REMPLACE le contenu des tables présentes dans la sauvegarde.
 * L'appelant doit faire une sauvegarde de sécurité avant (voir admin.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const BACKUP_MAGIC  = '-- ZINBCONNECT-BACKUP 1';
const BACKUP_SEP    = "\n-- @@\n";

function db_tables(): array
{
    $db = db();
    if (env('DB_DRIVER', 'mysql') === 'sqlite') {
        $rows = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll();
        return array_map(static fn($r) => (string)$r['name'], $rows);
    }
    $rows = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
    return array_map(static fn($r) => (string)$r[0], $rows);
}

function db_table_ddl(string $table): string
{
    $db = db();
    if (env('DB_DRIVER', 'mysql') === 'sqlite') {
        $stmt = $db->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);
        $ddl = (string)$stmt->fetchColumn();
        return $ddl !== '' ? preg_replace('/^CREATE TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', $ddl) : '';
    }
    $row = $db->query('SHOW CREATE TABLE `' . str_replace('`', '', $table) . '`')->fetch(PDO::FETCH_NUM);
    $ddl = (string)($row[1] ?? '');
    return $ddl !== '' ? preg_replace('/^CREATE TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', $ddl) : '';
}

/** Génère la sauvegarde complète (chaîne SQL). */
function db_dump_sql(): string
{
    $db = db();
    $tables = db_tables();
    $driver = env('DB_DRIVER', 'mysql');
    $q = $driver === 'sqlite' ? '"' : '`';

    $out  = BACKUP_MAGIC . "\n";
    $out .= '-- créé le ' . date('Y-m-d H:i:s') . ' (driver=' . $driver . ")\n";
    $out .= '-- tables : ' . implode(', ', $tables) . "\n";
    $out .= "\n-- ==== STRUCTURE\n\n";

    foreach ($tables as $t) {
        $ddl = db_table_ddl($t);
        if ($ddl !== '') {
            $out .= $ddl . ";\n" . BACKUP_SEP;
        }
    }

    $out .= "\n-- ==== DONNÉES\n\n";
    foreach ($tables as $t) {
        $out .= "-- table: {$t}\n";
        $rows = $db->query('SELECT * FROM ' . $q . $t . $q)->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            continue;
        }
        $cols = array_keys($rows[0]);
        $prefix = 'INSERT INTO ' . $q . $t . $q . ' (' . implode(',', array_map(static fn($c) => $q . $c . $q, $cols)) . ') VALUES ';
        foreach ($rows as $row) {
            $vals = [];
            foreach ($cols as $c) {
                $v = $row[$c];
                $vals[] = $v === null ? 'NULL' : $db->quote((string)$v);
            }
            $out .= $prefix . '(' . implode(',', $vals) . ");\n" . BACKUP_SEP;
        }
    }

    $out .= "\n-- ==== FIN\n";
    return $out;
}

/**
 * Restaure une sauvegarde produite par db_dump_sql().
 * Retourne ['ok'=>bool, 'error'=>?string, 'statements'=>int].
 */
function db_restore_sql(string $sql): array
{
    if (!str_starts_with(ltrim($sql), BACKUP_MAGIC)) {
        return ['ok' => false, 'error' => 'badFormat', 'statements' => 0];
    }

    $parts = explode(BACKUP_SEP, $sql);
    $db = db();
    $count = 0;

    try {
        $db->beginTransaction();
        foreach ($parts as $part) {
            $stmt = trim($part);
            // Ignore les commentaires de section et les entêtes
            if ($stmt === '' || str_starts_with($stmt, '--') || str_starts_with($stmt, BACKUP_MAGIC)) {
                // Un bloc peut contenir "-- table: x" suivi d'un INSERT : on garde les lignes SQL utiles
                $lines = array_filter(
                    explode("\n", $stmt),
                    static fn($l) => trim($l) !== '' && !str_starts_with(ltrim($l), '--')
                );
                $stmt = trim(implode("\n", $lines));
                if ($stmt === '') {
                    continue;
                }
            }
            $stmt = rtrim($stmt, "; \n\r\t") . ';';
            if (preg_match('/^\s*(BEGIN|COMMIT|ROLLBACK|PRAGMA)\b/i', $stmt)) {
                continue; // jamais de contrôle de transaction depuis un fichier importé
            }
            // Seules les tables de l'application sont autorisées (pas de DROP arbitraire).
            if (preg_match('/^\s*DROP\s/i', $stmt)) {
                return ['ok' => false, 'error' => 'badFormat', 'statements' => $count];
            }
            $db->exec($stmt);
            $count++;
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        audit('restore_error', 'admin', $e->getMessage());
        return ['ok' => false, 'error' => 'restoreFailed', 'statements' => $count];
    }

    audit('backup_restored', 'admin', $count . ' instructions');
    return ['ok' => true, 'error' => null, 'statements' => $count];
}

/**
 * Restauration "remplacement" : vide les tables présentes dans la sauvegarde
 * avant de réinsérer. À utiliser depuis l'écran Sauvegarde de l'admin.
 */
function db_restore_replace(string $sql): array
{
    if (!str_starts_with(ltrim($sql), BACKUP_MAGIC)) {
        return ['ok' => false, 'error' => 'badFormat', 'statements' => 0];
    }

    $db = db();
    $driver = env('DB_DRIVER', 'mysql');
    $q = $driver === 'sqlite' ? '"' : '`';

    // Tables citées dans la sauvegarde (ligne "-- tables : ...")
    $tables = [];
    if (preg_match('/^-- tables : (.+)$/m', $sql, $m)) {
        $tables = array_filter(array_map('trim', explode(',', $m[1])));
    }
    $known = db_tables();
    $tables = array_values(array_intersect($tables, $known));

    try {
        $db->beginTransaction();
        // Désactive les clés étrangères le temps du remplacement
        if ($driver === 'sqlite') {
            $db->exec('PRAGMA foreign_keys = OFF');
        } else {
            $db->exec('SET FOREIGN_KEY_CHECKS=0');
        }
        foreach ($tables as $t) {
            $db->exec('DELETE FROM ' . $q . $t . $q);
        }
        if ($driver === 'sqlite') {
            $db->exec('PRAGMA foreign_keys = ON');
        } else {
            $db->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollback(); }
        audit('restore_error', 'admin', $e->getMessage());
        return ['ok' => false, 'error' => 'restoreFailed', 'statements' => 0];
    }

    return db_restore_sql($sql);
}

/** Sauvegarde immédiate dans backups/, retourne le chemin absolu. */
function db_backup_to_file(string $prefix = 'auto'): ?string
{
    $dir = app_root() . '/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $file = $dir . '/' . $prefix . '_' . date('Ymd-His') . '.sql';
    return file_put_contents($file, db_dump_sql()) === false ? null : $file;
}
