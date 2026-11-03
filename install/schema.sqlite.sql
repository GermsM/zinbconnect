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
  data_limit_bytes INTEGER NULL,          -- plafond par ticket (NULL = illimité)
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

-- ============================================================
-- Phase 3 — tickets imprimables, plafonds, réglages, consommation
-- ============================================================
CREATE TABLE IF NOT EXISTS batches (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  reference TEXT NOT NULL UNIQUE,
  subscription_id INTEGER NULL,
  quantity INTEGER NOT NULL DEFAULT 0,
  unit_price REAL NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by TEXT NULL
);

CREATE TABLE IF NOT EXISTS tickets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  batch_id INTEGER NULL,
  subscription_id INTEGER NULL,
  code_hash TEXT NOT NULL UNIQUE,
  code_enc TEXT NULL,
  status TEXT NOT NULL DEFAULT 'available',
  price REAL NOT NULL DEFAULT 0,
  data_limit_bytes INTEGER NULL,
  bytes_used INTEGER NOT NULL DEFAULT 0,
  contact TEXT NULL,
  contact_type TEXT NULL,
  created_at TEXT NOT NULL,
  activated_at TEXT NULL,
  expires_at TEXT NULL,
  revoked_at TEXT NULL,
  last_sync_at TEXT NULL,
  reseller_id INTEGER NULL,
  note TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_ticket_status ON tickets(status);
CREATE INDEX IF NOT EXISTS idx_ticket_expires ON tickets(expires_at);
CREATE INDEX IF NOT EXISTS idx_ticket_contact ON tickets(contact);
CREATE INDEX IF NOT EXISTS idx_ticket_batch ON tickets(batch_id);

CREATE TABLE IF NOT EXISTS settings (
  k TEXT PRIMARY KEY,
  v TEXT NULL
);

CREATE TABLE IF NOT EXISTS usage_samples (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  sample_date TEXT NOT NULL,
  bytes_down INTEGER NOT NULL DEFAULT 0,
  bytes_up INTEGER NOT NULL DEFAULT 0,
  source TEXT NOT NULL DEFAULT 'router',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (sample_date, source)
);
