-- ============================================================
-- Zinbconnect — schéma SQLite (tests locaux, petit déploiement tout-en-un)
-- ============================================================

CREATE TABLE IF NOT EXISTS access_codes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NULL,                       -- lots historiques (transition, retiré en Phase 3)
  code_hash TEXT NOT NULL UNIQUE,       -- empreinte du code (nouveau standard)
  status TEXT NOT NULL DEFAULT 'available',
  subscription_id INTEGER NULL,
  claimed_at TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS current_codes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NULL,
  code_hash TEXT NULL,
  contact TEXT NULL,
  contact_type TEXT NULL,
  subscription_id INTEGER NULL,
  created_at TEXT NULL,
  expiration_date TEXT NOT NULL,
  revoked_at TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_current_hash ON current_codes(code_hash);
CREATE INDEX IF NOT EXISTS idx_current_code ON current_codes(code);

CREATE TABLE IF NOT EXISTS subscriptions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  rate REAL NOT NULL DEFAULT 0,
  duration_days INTEGER NULL,
  duration_minutes INTEGER NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS transactions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  reseller_id INTEGER NULL,
  subscription_id INTEGER NULL,
  buyer_contact TEXT NULL,
  access_code TEXT NULL,
  amount REAL NOT NULL DEFAULT 0,
  commission REAL NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS resellers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  phone TEXT NOT NULL UNIQUE,
  email TEXT NULL,
  address TEXT NULL,
  password TEXT NOT NULL,               -- haché Argon2id/bcrypt, jamais en clair
  status INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS admins (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  phone TEXT NULL,
  email TEXT NULL,
  password TEXT NOT NULL,               -- haché Argon2id après migration
  totp_secret TEXT NULL,                -- 2FA optionnelle
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS rate_limits (
  k TEXT PRIMARY KEY,
  hits INTEGER NOT NULL DEFAULT 0,
  first_hit INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS audit_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  created_at TEXT NOT NULL,
  event TEXT NOT NULL,
  actor TEXT NULL,
  ip TEXT NULL,
  details TEXT NULL
);
