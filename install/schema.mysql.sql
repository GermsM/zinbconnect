-- ============================================================
-- Zinbconnect — schéma MySQL (MySQL 5.7+/8.x / MariaDB)
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
  -- Plafond de données par ticket de ce forfait (limit-bytes-total côté MikroTik).
  -- NULL = illimité. Valeurs par défaut : 1 h = 100 Mo, 12 h = 300 Mo,
  -- 24 h = 500 Mo, 3 jours = 1 Go.
  data_limit_bytes BIGINT NULL,
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

-- ============================================================
-- Phase 3 — tickets imprimables, plafonds de données, réglages,
-- consommation Starlink
-- ============================================================

-- Un lot = un paquet de tickets imprimés en une fois (même forfait, même prix).
CREATE TABLE IF NOT EXISTS batches (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(32) NOT NULL UNIQUE,     -- ex. LOT-20261103-A1B2
  subscription_id INT NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 0,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by VARCHAR(80) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Un ticket = un code vendu, avec son cycle de vie complet.
-- status : available (non utilisé) | active (en cours) | used (quota épuisé) | revoked
-- L'expiration est calculée à partir de expires_at (pas de statut stocké à mettre à jour).
CREATE TABLE IF NOT EXISTS tickets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_id INT NULL,
  subscription_id INT NULL,
  code_hash CHAR(64) NOT NULL UNIQUE,        -- empreinte : recherche et unicité
  code_enc TEXT NULL,                        -- code chiffré (réimpression possible, jamais en clair)
  status VARCHAR(20) NOT NULL DEFAULT 'available',
  price DECIMAL(12,2) NOT NULL DEFAULT 0,
  data_limit_bytes BIGINT NULL,              -- plafond copié du forfait à la génération
  bytes_used BIGINT NOT NULL DEFAULT 0,
  contact VARCHAR(120) NULL,
  contact_type VARCHAR(10) NULL,
  created_at DATETIME NOT NULL,
  activated_at DATETIME NULL,
  expires_at DATETIME NULL,
  revoked_at DATETIME NULL,
  last_sync_at DATETIME NULL,
  reseller_id INT NULL,
  note VARCHAR(200) NULL,
  KEY idx_ticket_status (status),
  KEY idx_ticket_expires (expires_at),
  KEY idx_ticket_contact (contact),
  KEY idx_ticket_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Réglages modifiables depuis la console (clé/valeur, texte).
CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(64) NOT NULL PRIMARY KEY,
  v TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Consommation Internet mesurée sur le routeur (une ligne par jour et par source).
-- Alimentée en Phase 4 par la synchronisation MikroTik ; sert au suivi des 500 Go.
CREATE TABLE IF NOT EXISTS usage_samples (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sample_date DATE NOT NULL,
  bytes_down BIGINT NOT NULL DEFAULT 0,
  bytes_up BIGINT NOT NULL DEFAULT 0,
  source VARCHAR(20) NOT NULL DEFAULT 'router',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY idx_usage_day (sample_date, source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
