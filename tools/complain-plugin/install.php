<?php
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

$nosession = true;
if (empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost';
}
if (empty($_SERVER['REQUEST_URI'])) {
    $_SERVER['REQUEST_URI'] = '/';
}

require __DIR__ . '/../../includes/common.php';

$sql = <<<SQL
CREATE TABLE IF NOT EXISTS `pre_complain` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `paytype` int(11) NOT NULL,
  `channel` int(11) NOT NULL,
  `subchannel` int(11) NOT NULL DEFAULT '0',
  `source` tinyint(1) NOT NULL DEFAULT '0',
  `uid` int(11) NOT NULL,
  `trade_no` char(19) NOT NULL,
  `thirdid` varchar(100) NOT NULL,
  `type` varchar(30) NOT NULL,
  `title` varchar(300) DEFAULT NULL,
  `content` text DEFAULT NULL,
  `status` varchar(30) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `addtime` datetime NOT NULL,
  `edittime` datetime DEFAULT NULL,
  `thirdmchid` varchar(30) DEFAULT NULL,
  `info` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`),
  UNIQUE KEY `thirdid` (`thirdid`),
  KEY `addtime` (`addtime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
SQL;

if ($DB->exec($sql) === false) {
    exit('create table failed: '.$DB->error()."\n");
}

if (!$DB->getRow("SHOW COLUMNS FROM pre_complain LIKE 'info'")) {
    if ($DB->exec("ALTER TABLE pre_complain ADD COLUMN `info` text DEFAULT NULL AFTER `thirdmchid`") === false) {
        exit('add info column failed: '.$DB->error()."\n");
    }
}

$defaults = [
    'complain_open' => '0',
    'complain_range' => '0',
    'complain_auto_reply' => '0',
    'complain_auto_reply_con' => '',
    'complain_freeze_order' => '0',
    'complain_auto_black' => '0',
    'complain_auto_refund' => '0',
    'complain_auto_refund_money' => '',
];

foreach ($defaults as $key => $value) {
    $exists = $DB->getColumn("SELECT v FROM pre_config WHERE k=:k LIMIT 1", [':k' => $key]);
    if ($exists === false) {
        saveSetting($key, $value);
    }
}

$CACHE->clear();
echo "complain plugin schema/config ready\n";
