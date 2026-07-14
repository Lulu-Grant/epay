CREATE TABLE IF NOT EXISTS `pre_shop_config` (
  `k` varchar(64) NOT NULL,
  `v` text DEFAULT NULL,
  `remark` varchar(255) DEFAULT NULL,
  `addtime` datetime DEFAULT NULL,
  `updatetime` datetime DEFAULT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商城配置表';

INSERT IGNORE INTO `pre_shop_config` (`k`, `v`, `remark`, `addtime`, `updatetime`) VALUES
('shop_status', '0', '商城开关：0关闭，1开启', NOW(), NOW()),
('shop_flow_mode', 'checkout', '商城流程：checkout确认页，shadow无感影子订单', NOW(), NOW()),
('shop_shadow_started_at', '', '无感影子订单补偿扫描起始时间', NOW(), NOW()),
('shop_name', '商城', '商城名称', NOW(), NOW()),
('shop_desc', '', '商城描述', NOW(), NOW()),
('shop_excluded_uids', '', '不创建商城附属记录的商户UID，逗号分隔', NOW(), NOW()),
('shop_query_verify', '1', '订单查询校验：1启用', NOW(), NOW());

CREATE TABLE IF NOT EXISTS `pre_shop_goods` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `stock` int(11) NOT NULL DEFAULT '-1',
  `image` varchar(500) DEFAULT NULL,
  `sort` int(11) NOT NULL DEFAULT '0',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `deleted` tinyint(1) NOT NULL DEFAULT '0',
  `addtime` datetime DEFAULT NULL,
  `updatetime` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status_sort` (`status`,`deleted`,`sort`,`id`),
  KEY `idx_addtime` (`addtime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商城商品表';

UPDATE `pre_shop_goods` SET `name`='ChatGPT Token 充值',`description`='ChatGPT Token 额度充值服务，充值数量与到账方式以实际订单为准。',`price`=0.00,`stock`=-1,`image`='/assets/shop/products/chatgpt-token.webp',`sort`=60,`status`=1,`deleted`=0,`updatetime`=NOW() WHERE `name` IN ('入门体验包','ChatGPT Token 充值');
UPDATE `pre_shop_goods` SET `name`='OpenAI API 额度充值',`description`='OpenAI API 使用额度充值，适用于接口调用与模型服务消耗。',`price`=0.00,`stock`=-1,`image`='/assets/shop/products/openai-api-credit.webp',`sort`=50,`status`=1,`deleted`=0,`updatetime`=NOW() WHERE `name` IN ('基础服务包','OpenAI API 额度充值');
UPDATE `pre_shop_goods` SET `name`='Claude API 额度充值',`description`='Claude API 使用额度充值，适用于对话、长文本与内容处理。',`price`=0.00,`stock`=-1,`image`='/assets/shop/products/claude-api-credit.webp',`sort`=40,`status`=1,`deleted`=0,`updatetime`=NOW() WHERE `name` IN ('标准服务包','Claude API 额度充值');
UPDATE `pre_shop_goods` SET `name`='Gemini API 额度充值',`description`='Gemini API 使用额度充值，适用于文本、图像与多模态调用。',`price`=0.00,`stock`=-1,`image`='/assets/shop/products/gemini-api-credit.webp',`sort`=30,`status`=1,`deleted`=0,`updatetime`=NOW() WHERE `name` IN ('高级服务包','Gemini API 额度充值');
UPDATE `pre_shop_goods` SET `name`='Qwen API 额度充值',`description`='Qwen API 使用额度充值，适用于通义千问系列模型接口调用。',`price`=0.00,`stock`=-1,`image`='/assets/shop/products/qwen-api-credit.webp',`sort`=20,`status`=1,`deleted`=0,`updatetime`=NOW() WHERE `name` IN ('专属支持服务','Qwen API 额度充值');
UPDATE `pre_shop_goods` SET `name`='DeepSeek API 额度充值',`description`='DeepSeek API 使用额度充值，适用于推理、编程与文本处理。',`price`=0.00,`stock`=-1,`image`='/assets/shop/products/deepseek-api-credit.webp',`sort`=10,`status`=1,`deleted`=0,`updatetime`=NOW() WHERE `name` IN ('企业服务套餐','DeepSeek API 额度充值');

INSERT INTO `pre_shop_goods` (`name`,`description`,`price`,`stock`,`image`,`sort`,`status`,`deleted`,`addtime`,`updatetime`)
SELECT 'ChatGPT Token 充值','ChatGPT Token 额度充值服务，充值数量与到账方式以实际订单为准。',0.00,-1,'/assets/shop/products/chatgpt-token.webp',60,1,0,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM `pre_shop_goods` WHERE `name`='ChatGPT Token 充值');
INSERT INTO `pre_shop_goods` (`name`,`description`,`price`,`stock`,`image`,`sort`,`status`,`deleted`,`addtime`,`updatetime`)
SELECT 'OpenAI API 额度充值','OpenAI API 使用额度充值，适用于接口调用与模型服务消耗。',0.00,-1,'/assets/shop/products/openai-api-credit.webp',50,1,0,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM `pre_shop_goods` WHERE `name`='OpenAI API 额度充值');
INSERT INTO `pre_shop_goods` (`name`,`description`,`price`,`stock`,`image`,`sort`,`status`,`deleted`,`addtime`,`updatetime`)
SELECT 'Claude API 额度充值','Claude API 使用额度充值，适用于对话、长文本与内容处理。',0.00,-1,'/assets/shop/products/claude-api-credit.webp',40,1,0,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM `pre_shop_goods` WHERE `name`='Claude API 额度充值');
INSERT INTO `pre_shop_goods` (`name`,`description`,`price`,`stock`,`image`,`sort`,`status`,`deleted`,`addtime`,`updatetime`)
SELECT 'Gemini API 额度充值','Gemini API 使用额度充值，适用于文本、图像与多模态调用。',0.00,-1,'/assets/shop/products/gemini-api-credit.webp',30,1,0,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM `pre_shop_goods` WHERE `name`='Gemini API 额度充值');
INSERT INTO `pre_shop_goods` (`name`,`description`,`price`,`stock`,`image`,`sort`,`status`,`deleted`,`addtime`,`updatetime`)
SELECT 'Qwen API 额度充值','Qwen API 使用额度充值，适用于通义千问系列模型接口调用。',0.00,-1,'/assets/shop/products/qwen-api-credit.webp',20,1,0,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM `pre_shop_goods` WHERE `name`='Qwen API 额度充值');
INSERT INTO `pre_shop_goods` (`name`,`description`,`price`,`stock`,`image`,`sort`,`status`,`deleted`,`addtime`,`updatetime`)
SELECT 'DeepSeek API 额度充值','DeepSeek API 使用额度充值，适用于推理、编程与文本处理。',0.00,-1,'/assets/shop/products/deepseek-api-credit.webp',10,1,0,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM `pre_shop_goods` WHERE `name`='DeepSeek API 额度充值');

CREATE TABLE IF NOT EXISTS `pre_shop_orders` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `shop_trade_no` char(22) NOT NULL,
  `pay_trade_no` char(19) CHARACTER SET utf8 NOT NULL,
  `out_trade_no` varchar(150) NOT NULL,
  `goods_id` int(11) unsigned NOT NULL,
  `goods_name` varchar(120) NOT NULL,
  `goods_price` decimal(10,2) NOT NULL,
  `goods_image` varchar(500) DEFAULT NULL,
  `quantity` int(11) unsigned NOT NULL DEFAULT '1',
  `money` decimal(10,2) NOT NULL,
  `pay_type` int(10) unsigned NOT NULL DEFAULT '0',
  `pay_status` tinyint(1) NOT NULL DEFAULT '0',
  `order_status` tinyint(1) NOT NULL DEFAULT '0',
  `buyer_name` varchar(64) DEFAULT NULL,
  `buyer_contact` varchar(64) DEFAULT NULL,
  `buyer_remark` varchar(500) DEFAULT NULL,
  `query_token` char(32) NOT NULL,
  `pay_api_trade_no` varchar(150) DEFAULT NULL,
  `logistics_company` varchar(80) DEFAULT NULL,
  `tracking_no` varchar(120) DEFAULT NULL,
  `status_times` text DEFAULT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT '0',
  `addtime` datetime DEFAULT NULL,
  `paytime` datetime DEFAULT NULL,
  `updatetime` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_shop_trade_no` (`shop_trade_no`),
  UNIQUE KEY `uk_pay_trade_no` (`pay_trade_no`),
  KEY `idx_goods_id` (`goods_id`),
  KEY `idx_pay_status` (`pay_status`),
  KEY `idx_order_status` (`order_status`),
  KEY `idx_addtime` (`addtime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商城订单表';
