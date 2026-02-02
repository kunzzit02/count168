-- 为 bank_process 表添加合同结束时间列
-- 开始时间：已有 day_start（Add Process 时的 Day start）
-- 结束时间：新增 day_end，用于判断 Contract 列显示颜色（未开始/执行中/已过期）

ALTER TABLE `bank_process`
  ADD COLUMN `day_end` date DEFAULT NULL COMMENT '合同结束日期（Contract 到期日）' AFTER `day_start`;
