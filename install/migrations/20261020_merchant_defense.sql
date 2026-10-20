-- PHP 8.4 merchant defense. Review table prefix and take the normal schema backup first.
-- Preflight (read-only): inspect engine/columns and outstanding payment links before enabling any merchant.
-- SHOW TABLE STATUS LIKE 'pre_order'; SHOW COLUMNS FROM pre_user LIKE 'defense_%';
-- SELECT uid,COUNT(*) FROM pre_order WHERE status IN (0,4) GROUP BY uid;
-- Apply this migration once. It never enables merchants or changes payment/channel records.
-- Strict activation also requires the existing 20261010_order_uid_outtradeno_unique.sql migration.
-- That order-index migration remains separate and requires duplicate review before execution.
ALTER TABLE pre_user
  ADD COLUMN defense_mode tinyint unsigned NOT NULL DEFAULT 0,
  ADD COLUMN defense_enabled_at bigint unsigned DEFAULT NULL,
  ADD COLUMN defense_revision int unsigned NOT NULL DEFAULT 0;

-- Capture first local receipt automatically, including interrupted adapter saves.
SET @defense_complaint_ddl = IF(
  EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pre_complain')
  AND NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pre_complain' AND COLUMN_NAME='defense_received_at'),
  'ALTER TABLE pre_complain ADD COLUMN defense_received_at timestamp NULL DEFAULT CURRENT_TIMESTAMP',
  'SELECT 1');
PREPARE defense_complaint_statement FROM @defense_complaint_ddl;
EXECUTE defense_complaint_statement;
DEALLOCATE PREPARE defense_complaint_statement;

CREATE TABLE IF NOT EXISTS pre_merchant_defense_entry (
  digest char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  trade_no varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  requested_type int unsigned NOT NULL DEFAULT 0,
  reported_ip varchar(45) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  created_at bigint unsigned NOT NULL,
  expires_at bigint unsigned NOT NULL,
  PRIMARY KEY (digest), KEY order_entries (trade_no,expires_at), KEY expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pre_merchant_defense_context (
  trade_no varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  uid int unsigned NOT NULL,
  ip varchar(45) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  reported_ip varchar(45) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  session_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  browser_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  params_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  revision int unsigned NOT NULL,
  verified_at bigint unsigned NOT NULL,
  valid_until bigint unsigned NOT NULL,
  PRIMARY KEY (trade_no), KEY merchant_ip (uid,ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pre_merchant_defense_challenge (
  digest char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  trade_no varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  uid int unsigned NOT NULL,
  ip varchar(45) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  session_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  browser_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  params_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  revision int unsigned NOT NULL,
  created_at bigint unsigned NOT NULL,
  expires_at bigint unsigned NOT NULL,
  consumed_at bigint unsigned DEFAULT NULL,
  PRIMARY KEY (digest), KEY expiry (expires_at), KEY order_challenges (trade_no,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pre_merchant_defense_quota (
  uid int unsigned NOT NULL,
  ip varchar(45) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  quota_day date NOT NULL,
  success_count int unsigned NOT NULL DEFAULT 0,
  faulted tinyint unsigned NOT NULL DEFAULT 0,
  updated_at bigint unsigned NOT NULL,
  PRIMARY KEY (uid,ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pre_merchant_defense_order (
  trade_no varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  uid int unsigned NOT NULL,
  ip varchar(45) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  state varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  channel int unsigned NOT NULL,
  account_scope varchar(150) NOT NULL DEFAULT '',
  amount_cents bigint unsigned NOT NULL,
  unsafe_exposure tinyint unsigned NOT NULL DEFAULT 0,
  revision int unsigned NOT NULL,
  created_at bigint unsigned NOT NULL,
  attempted_at bigint unsigned DEFAULT NULL,
  success_at bigint unsigned DEFAULT NULL,
  success_day date DEFAULT NULL,
  checked_at bigint unsigned DEFAULT NULL,
  pay_result mediumtext DEFAULT NULL,
  PRIMARY KEY (trade_no), KEY active_orders (uid,ip,state),
  KEY successes (uid,ip,success_day), KEY reconcile (state,checked_at,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pre_merchant_defense_complaint (
  event_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  complaint_id varchar(150) NOT NULL,
  trade_no varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  uid int unsigned NOT NULL,
  ip varchar(45) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  observed_at bigint unsigned NOT NULL,
  expires_at bigint unsigned NOT NULL,
  released_at bigint unsigned DEFAULT NULL,
  released_by varchar(80) DEFAULT NULL,
  release_reason varchar(255) DEFAULT NULL,
  PRIMARY KEY (event_key), KEY active_bans (uid,ip,expires_at), KEY expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pre_merchant_defense_audit (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  uid int unsigned NOT NULL,
  trade_no varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  action varchar(40) NOT NULL,
  reason varchar(255) NOT NULL,
  actor varchar(80) NOT NULL,
  created_at bigint unsigned NOT NULL,
  PRIMARY KEY (id), KEY merchant_time (uid,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @defense_cursor_ddl = IF(
  EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pre_complain'),
  'INSERT IGNORE INTO pre_config (k,v) SELECT ''merchant_defense_cursor'',COALESCE(MAX(id),0) FROM pre_complain',
  'INSERT IGNORE INTO pre_config (k,v) VALUES (''merchant_defense_cursor'',''0'')');
PREPARE defense_cursor_statement FROM @defense_cursor_ddl;
EXECUTE defense_cursor_statement;
DEALLOCATE PREPARE defense_cursor_statement;
INSERT IGNORE INTO pre_config (k,v) VALUES ('merchant_defense_version','1');
INSERT IGNORE INTO pre_config (k,v) VALUES ('merchant_defense_ip_mode',''),('merchant_defense_net_verified','0'),('merchant_defense_trusted_proxies','');
-- Network readiness is intentionally unset. Configure/verify direct or trusted_proxy mode before enabling.
-- No destructive down migration: preserve evidence, reservations and payment confirmation records on rollback.
