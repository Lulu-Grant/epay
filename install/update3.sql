ALTER TABLE `pre_user`
MODIFY COLUMN `email` varchar(254) DEFAULT NULL;

ALTER TABLE `pre_regcode`
MODIFY COLUMN `to` varchar(254) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `pre_registration_completion` (
  `trade_no` char(19) NOT NULL,
  `uid` int(11) unsigned DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`trade_no`),
  KEY `uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT INTO `pre_config` (`k`,`v`) VALUES
('admin_sso_enabled','0'),
('admin_sso_admin_origin',''),
('admin_sso_merchant_origin',''),
('admin_sso_admin_path','/admin/sso.php'),
('admin_sso_merchant_path','/user/sso.php')
ON DUPLICATE KEY UPDATE `v`=`v`;

CREATE TABLE IF NOT EXISTS `pre_admin_sso_ticket` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_hash` char(64) NOT NULL,
  `correlation_id` char(16) NOT NULL,
  `target_uid` int(11) unsigned NOT NULL,
  `issuer_hash` char(64) NOT NULL,
  `issuer_version` char(64) NOT NULL,
  `flow_hash` char(64) NOT NULL,
  `audience` varchar(255) NOT NULL,
  `nonce_hash` char(64) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  `bound_at` datetime DEFAULT NULL,
  `consumed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ticket_hash` (`ticket_hash`),
  KEY `target_status` (`target_uid`,`status`),
  KEY `issuer_status` (`issuer_hash`,`status`),
  KEY `expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `pre_admin_sso_session` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_hash` char(64) NOT NULL,
  `correlation_id` char(16) NOT NULL,
  `uid` int(11) unsigned NOT NULL,
  `issuer_hash` char(64) NOT NULL,
  `issuer_version` char(64) NOT NULL,
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_hash` (`session_hash`),
  KEY `uid_active` (`uid`,`revoked_at`,`expires_at`),
  KEY `issuer_active` (`issuer_hash`,`revoked_at`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `pre_admin_sso_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `correlation_id` char(16) DEFAULT NULL,
  `issuer_hash` char(64) DEFAULT NULL,
  `uid` int(11) unsigned DEFAULT NULL,
  `event` varchar(32) NOT NULL,
  `result` varchar(32) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `correlation_id` (`correlation_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
