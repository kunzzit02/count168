# Multi-Process 同步（Main ID → B_ID / C_ID）完成说明

## 功能说明

- **Main ID（A_ID）**：Copy From 选中的源 Process。
- **B_ID、C_ID**：Add Process 时勾选 Multi-Process 并选 Copy From = A_ID 后创建的 Process。
- **同步规则**：在 Data Capture Summary 里对 A_ID 做的**增、删、改**（Source / Rate / Formula 等），会**马上同步**到所有 `sync_source_process_id = A_ID` 的 Process（B_ID、C_ID 等）。

---

## 已实现内容

### 1. 数据库

- **表**：`process` 增加列 `sync_source_process_id`（源 process.id）。
- **索引**：复合索引 `idx_sync_source_company (sync_source_process_id, company_id)`，便于同步查询。
- **脚本**：`add_sync_source_process_id.sql`（可重复执行，列/索引已存在会跳过）。

### 2. Add Process（processlist → Add Process）

- Copy From = A_ID，勾选 Multi-Process，选 B_ID、C_ID 等，Submit。
- 新建的 B_ID、C_ID 的 `sync_source_process_id` 会写入 A_ID 的 `process.id`。
- 同时复制 A_ID 的 Data Capture templates 到 B_ID、C_ID。
- `selected_processes` 支持 JSON 或表单数组，避免漏写 `sync_source_process_id`。

### 3. Data Capture Summary 同步

- **修改**：在 A_ID 下改任意行（Source / Rate / Formula）并保存（含自动保存）→ 按 Id_Product 匹配，更新 B_ID、C_ID 对应行；若目标无该行则**插入**。
- **删除**：在 A_ID 下删除某行 → 同步删除 B_ID、C_ID 的对应行（按 id_product / account_id / product_type / formula_variant / sub_order 匹配）。
- **同步字段**：source_columns、formula_operators、source_percent、columns_display、formula_display、description、account_display、currency、batch_selection、last_source_value、last_processed_amount 等。

---

## 部署与检查

| 步骤 | 操作 |
|-----|------|
| 1 | 执行 `add_sync_source_process_id.sql`（确保列和复合索引存在，可多次执行）。 |
| 2 | 部署 `addprocessapi.php`、`datacapturesummaryapi.php` 到服务器。 |
| 3 | 在 phpMyAdmin 中确认：新建的 Multi-Process 行里 `sync_source_process_id` 不为 NULL。 |

---

## 测试建议

1. **创建**：Copy From = A_ID，Multi-Process 勾选，Description 选好，Process 选 B_ID、C_ID → Submit → 查 DB 中 B_ID、C_ID 的 `sync_source_process_id` = A_ID 的 id。
2. **修改**：Data Capture Summary 选 A_ID，改某行 Source / Rate / Formula 并保存 → 切到 B_ID、C_ID 看对应行是否一致。
3. **删除**：在 A_ID 删除一行 → 看 B_ID、C_ID 对应行是否被删。
4. **新增**：在 A_ID 新增一行并保存 → 看 B_ID、C_ID 是否出现同一行。

---

## 涉及文件

- `addprocessapi.php`：创建时写 `sync_source_process_id`、复制 templates、兼容 `selected_processes`。
- `datacapturesummaryapi.php`：`syncFormulaToMultiUseProcesses`、`syncDeleteTemplateToMultiUseProcesses`，以及 save_template / delete_template 中的同步调用。
- `add_sync_source_process_id.sql`：可重复执行的建列与复合索引脚本。
