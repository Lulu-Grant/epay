-- 手工迁移，不由安装/升级程序自动执行。
-- 下列 SQL 使用 pay_ 前缀；若实际表前缀不同，执行前人工替换。
-- 必须先人工核对并清理 (uid, out_trade_no) 重复行，再单独执行最后的 ALTER。
-- 不要直接整文件执行：重复订单可能已有支付、退款、商城影子订单或结算关联。
-- 禁止按 MIN(trade_no)、最早时间或 status=0 自动删除；先核对上游交易与关联数据。

-- 1. 只读：找出重复商户订单号（按数据库当前排序规则分组）。
SELECT uid, out_trade_no, COUNT(*) AS duplicate_count
FROM pay_order
GROUP BY uid, out_trade_no
HAVING COUNT(*) > 1;

-- 2. 只读：展开重复行，供人工确定应保留/归档的订单及其关联。
SELECT o.trade_no, o.uid, o.out_trade_no, o.status, o.channel, o.subchannel,
       o.money, o.realmoney, o.api_trade_no, o.addtime, o.endtime
FROM pay_order AS o
JOIN (
    SELECT uid, out_trade_no
    FROM pay_order
    GROUP BY uid, out_trade_no
    HAVING COUNT(*) > 1
) AS d ON o.uid = d.uid AND o.out_trade_no = d.out_trade_no
ORDER BY o.uid, o.out_trade_no, o.addtime, o.trade_no;

-- 3. 只读：确认已有索引；避免重复添加同名/等价唯一索引。
SHOW INDEX FROM pay_order;

-- 4. 必须先人工清理重复行，再执行。
-- 在停止所有下单写入的维护窗口执行，完成后再次确认唯一索引存在。
-- 同一 (uid, out_trade_no) 不再允许十天后复用；商户应使用新的订单号。
ALTER TABLE pay_order ADD UNIQUE KEY uniq_uid_outtradeno (uid, out_trade_no);
