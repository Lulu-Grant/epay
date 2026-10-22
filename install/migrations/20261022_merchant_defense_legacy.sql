-- PHP 8.4 / MySQL 5.7+; substitute pre_ with the installed prefix.
-- Additive only. Does not enable merchants or adopt/delete/update any payment order.
-- Review and install before using the explicit per-order retirement command.
CREATE TABLE IF NOT EXISTS `pre_merchant_defense_legacy` (
  trade_no varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  uid int unsigned NOT NULL,
  channel int unsigned NOT NULL,
  amount_cents bigint unsigned NOT NULL,
  fingerprint char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  state varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at bigint unsigned NOT NULL,
  updated_at bigint unsigned NOT NULL,
  PRIMARY KEY (trade_no), KEY merchant_state (uid,state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO `pre_config` (k,v) VALUES ('merchant_defense_legacy_version','1');
