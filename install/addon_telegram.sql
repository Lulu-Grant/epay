CREATE TABLE IF NOT EXISTS `pre_telegram_bind` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `chat_id` varchar(64) NOT NULL,
  `uid` int(11) unsigned NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `bindtime` datetime DEFAULT NULL,
  `notify_order` tinyint(1) NOT NULL DEFAULT '1',
  `notify_settle` tinyint(1) NOT NULL DEFAULT '1',
  `notify_login` tinyint(1) NOT NULL DEFAULT '1',
  `notify_complain` tinyint(1) NOT NULL DEFAULT '1',
  `notify_mchrisk` tinyint(1) NOT NULL DEFAULT '1',
  `notify_balance` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `chat_id` (`chat_id`),
  KEY `uid` (`uid`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `pre_telegram_update` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `update_id` bigint(20) NOT NULL,
  `content` text DEFAULT NULL,
  `addtime` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `update_id` (`update_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `pre_telegram_notify_queue` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL DEFAULT '0',
  `scene` varchar(50) NOT NULL,
  `param` text NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `retry_count` tinyint(1) NOT NULL DEFAULT '0',
  `error_msg` varchar(500) DEFAULT NULL,
  `addtime` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sendtime` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_uid_status` (`uid`,`status`),
  KEY `idx_status_addtime` (`status`,`addtime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `pre_telegram_admin_settings` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `chat_id` varchar(64) NOT NULL,
  `notify_order` tinyint(1) NOT NULL DEFAULT '1',
  `notify_settle` tinyint(1) NOT NULL DEFAULT '1',
  `notify_login` tinyint(1) NOT NULL DEFAULT '1',
  `notify_complain` tinyint(1) NOT NULL DEFAULT '1',
  `notify_mchrisk` tinyint(1) NOT NULL DEFAULT '1',
  `notify_balance` tinyint(1) NOT NULL DEFAULT '1',
  `updatetime` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chat_id` (`chat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `pre_telegram_bind_code` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(11) unsigned NOT NULL,
  `code` varchar(32) NOT NULL,
  `chat_id` varchar(64) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `addtime` datetime DEFAULT NULL,
  `expiretime` datetime DEFAULT NULL,
  `usetime` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `uid` (`uid`),
  KEY `status_expiretime` (`status`,`expiretime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
