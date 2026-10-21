-- Review the actual table prefix and take the normal schema backup. Apply once.
-- Read-only preflight: SHOW COLUMNS FROM `pre_order` LIKE 'payer_ip%';
-- On a supported MariaDB release, operators may explicitly require ALGORITHM=INSTANT, LOCK=NONE.
-- Existing ip is merchant-reported/legacy data and MUST NOT be copied into payer_ip.
ALTER TABLE `pre_order`
  ADD COLUMN `payer_ip` varchar(45) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  ADD COLUMN `payer_ip_at` datetime DEFAULT NULL,
  ADD COLUMN `payer_ip_peer` varchar(45) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  ADD COLUMN `payer_ip_source` varchar(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL;

-- No IP ranges are guessed. Import the site's official ESA origin list and verify the header first.
INSERT IGNORE INTO `pre_config` (k,v) VALUES
  ('payer_ip_enabled','0'),('payer_ip_esa_verified','0'),
  ('payer_ip_esa_cidrs',''),('payer_ip_header','Ali-Cdn-Real-Ip');
-- Marker is last: application capture remains off until the operator explicitly enables it.
INSERT IGNORE INTO `pre_config` (k,v) VALUES ('payer_ip_version','1');
-- Rollback disables collection and preserves evidence. Do not drop columns or rewrite legacy orders.
