-- 在 EXPENSES 下面添加 COMPANY 角色
-- 由于 EXPENSES 的 id 是 5，我们需要找到下一个可用的 id
-- 根据现有数据，最大 id 是 9 (STAFF)，所以 COMPANY 可以使用 id 10

INSERT INTO `role` (`id`, `code`) VALUES (10, 'COMPANY');

-- 如果 id 10 已被占用，可以使用以下语句自动分配 id：
-- INSERT INTO `role` (`code`) VALUES ('COMPANY');
