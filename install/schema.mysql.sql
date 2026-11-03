-- ============================================================
-- Zinbconnect — schéma MySQL (InfinityFree, MySQL 5.7+/8.x)
-- Création possible à chaud : CREATE TABLE IF NOT EXISTS.
-- Les colonnes ajoutées sur une base existante sont gérées par
-- install/upgrade_existing_db.php (avec sauvegarde préalable).
-- ============================================================

CREATE TABLE IF NOT EXISTS access_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  -- code en clair : uniquement pour les lots historiques (transition, retiré en Phase 3)
  code VARCHAR(32) NULL,
  -- empreinte du code : nouveau standard (le code en clair n'a plus besoin d'être stocké)
  code_hash CHAR(64) NOT NULL UNIQUE,
  status VARCHAR(20) NOT NULL DEFAULT 'available',
  subscription_id INT NULL,
  claimed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS current_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(32) NULL,
  code_hash CHAR(64) NULL,
  contact VARCHAR(120) NULL,
  contact_type VARCHAR(10) NULL,
  subscription_id INT NULL,
  created_at DATETIME NULL,
  expiration_date DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  KEY idx_current_hash (code_hash),
  KEY idx_current_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subscriptions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  rate DECIMAL(12,2) NOT NULL DEFAULT 0,
  duration_days INT NULL,
  duration_minutes INT NULL,          -- précision horaire (1 h = 60)
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS transactions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reseller_id INT NULL,
  subscription_id INT NULL,
  buyer_contact VARCHAR(120) NULL,
  access_code VARCHAR(32) NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  commission DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS resellers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  email VARCHAR(120) NULL,
  address VARCHAR(200) NULL,
  password VARCHAR(255) NOT NULL,     -- haché Argon2id/bcrypt, jamais en clair
  status TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY idx_resellers_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admins (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(30) NULL,
  email VARCHAR(120) NULL,
  -- password : haché Argon2id après migration (install/upgrade_existing_db.php).
  -- Les lignes encore "en clair" sont converties automatiquement à la première
  -- connexion réussie, ou tout de suite par le script de migration.
  password VARCHAR(255) NOT NULL,
  totp_secret VARCHAR(64) NULL,       -- 2FA optionnelle
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Anti-brute-force : k = SHA-256 de "bucket|clé" (IP ou identifiant)
CREATE TABLE IF NOT EXISTS rate_limits (
  k CHAR(64) NOT NULL PRIMARY KEY,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  first_hit INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Journal d'audit : lectures recommandées (connexions, ventes, révocations…)
CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL,
  event VARCHAR(64) NOT NULL,
  actor VARCHAR(80) NULL,
  ip VARCHAR(45) NULL,
  details VARCHAR(500) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
