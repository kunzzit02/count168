<?php
// 确保 Session Cookie 在同站 POST（如 fetch 提交）时会被发送，避免无痕/部分环境下 403
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';

// Helper function to convert PHP ini size values to bytes
function return_bytes($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    $val = (int)$val;
    switch($last) {
        case 'g':
            $val *= 1024;
        case 'm':
            $val *= 1024;
        case 'k':
            $val *= 1024;
    }
    return $val;
}

/**
 * 根据 company_id 校验/解析 currency_id，必要时根据 currency_code 匹配。
 */
function resolveCompanyCurrencyId(PDO $pdo, int $companyId, $currencyId = null, ?string $currencyCode = null) {
    static $cacheById = [];
    static $cacheByCode = [];

    if ($currencyId !== null && $currencyId !== '') {
        $currencyId = (int)$currencyId;
        $cacheKey = $companyId . ':' . $currencyId;
        if (array_key_exists($cacheKey, $cacheById)) {
            return $cacheById[$cacheKey];
        }
        $stmt = $pdo->prepare("SELECT id, UPPER(code) AS code FROM currency WHERE company_id = ? AND id = ? LIMIT 1");
        $stmt->execute([$companyId, $currencyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $cacheById[$cacheKey] = (int)$row['id'];
            $cacheByCode[$companyId . ':' . $row['code']] = (int)$row['id'];
            return $cacheById[$cacheKey];
        }
        $cacheById[$cacheKey] = null;
    }

    if ($currencyCode) {
        $currencyCode = strtoupper(trim($currencyCode));
        $cacheCodeKey = $companyId . ':' . $currencyCode;
        if (array_key_exists($cacheCodeKey, $cacheByCode)) {
            return $cacheByCode[$cacheCodeKey];
        }
        $stmt = $pdo->prepare("SELECT id FROM currency WHERE company_id = ? AND UPPER(code) = ? LIMIT 1");
        $stmt->execute([$companyId, $currencyCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $cacheByCode[$cacheCodeKey] = (int)$row['id'];
            $cacheById[$companyId . ':' . (int)$row['id']] = (int)$row['id'];
            return (int)$row['id'];
        }
        $cacheByCode[$cacheCodeKey] = null;
    }

    return null;
}

function ensureTemplateSchema(PDO $pdo) {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $columnStmt = $pdo->query("SHOW COLUMNS FROM data_capture_templates LIKE 'product_type'");
        $hasProductType = $columnStmt && $columnStmt->fetch(PDO::FETCH_ASSOC);

        if (!$hasProductType) {
            $pdo->exec("
                ALTER TABLE data_capture_templates
                ADD COLUMN product_type ENUM('main','sub') NOT NULL DEFAULT 'main' AFTER id_product,
                ADD COLUMN parent_id_product VARCHAR(255) NULL AFTER product_type,
                ADD COLUMN template_key VARCHAR(255) NOT NULL DEFAULT '' AFTER parent_id_product
            ");

            try {
                $pdo->exec("ALTER TABLE data_capture_templates DROP INDEX id_product");
            } catch (Exception $e) {
                error_log('Template schema drop index warning: ' . $e->getMessage());
            }

            // Drop old unique index if exists
            try {
                $pdo->exec("ALTER TABLE data_capture_templates DROP INDEX template_unique");
            } catch (Exception $e) {
                error_log('Template schema drop old unique index warning: ' . $e->getMessage());
            }

            // Add new unique index that includes process_id to prevent duplicates within same process
            // For templates (data_capture_id IS NULL), uniqueness is based on (process_id, product_type, template_key)
            // For capture-specific templates (data_capture_id IS NOT NULL), they can coexist with general templates
            try {
                $pdo->exec("ALTER TABLE data_capture_templates ADD UNIQUE KEY template_unique (process_id, product_type, template_key, data_capture_id)");
            } catch (Exception $e) {
                error_log('Template schema add index warning: ' . $e->getMessage());
            }

            $pdo->exec("
                UPDATE data_capture_templates
                SET product_type = 'main',
                    template_key = CASE WHEN template_key = '' THEN id_product ELSE template_key END
            ");
        } else {
            $indexStmt = $pdo->query("SHOW INDEX FROM data_capture_templates WHERE Key_name = 'template_unique'");
            $hasTemplateIndex = $indexStmt && $indexStmt->fetch(PDO::FETCH_ASSOC);
            if (!$hasTemplateIndex) {
                // Drop old unique index if exists (in case it has different columns)
                try {
                    $pdo->exec("ALTER TABLE data_capture_templates DROP INDEX template_unique");
                } catch (Exception $e) {
                    error_log('Template schema drop old unique index warning: ' . $e->getMessage());
                }
                
                // Add new unique index that includes process_id to prevent duplicates within same process
                try {
                    $pdo->exec("ALTER TABLE data_capture_templates ADD UNIQUE KEY template_unique (process_id, product_type, template_key, data_capture_id)");
                } catch (Exception $e) {
                    error_log('Template schema add index warning: ' . $e->getMessage());
                }
            } else {
                // Check if the index has the correct columns
                $indexStmt = $pdo->query("SHOW INDEX FROM data_capture_templates WHERE Key_name = 'template_unique'");
                $indexColumns = [];
                while ($row = $indexStmt->fetch(PDO::FETCH_ASSOC)) {
                    $indexColumns[] = $row['Column_name'];
                }
                
                // If index doesn't include process_id or data_capture_id, recreate it
                if (!in_array('process_id', $indexColumns) || !in_array('data_capture_id', $indexColumns)) {
                    try {
                        $pdo->exec("ALTER TABLE data_capture_templates DROP INDEX template_unique");
                        $pdo->exec("ALTER TABLE data_capture_templates ADD UNIQUE KEY template_unique (process_id, product_type, template_key, data_capture_id)");
                        error_log('Template schema: Recreated unique index with process_id and data_capture_id');
                    } catch (Exception $e) {
                        error_log('Template schema recreate index warning: ' . $e->getMessage());
                    }
                }
            }
        }
        
        // Ensure process_id column is INT(11) to store process.id (not process.process_id)
        try {
            $processIdColumnStmt = $pdo->query("SHOW COLUMNS FROM data_capture_templates LIKE 'process_id'");
            $processIdColumn = $processIdColumnStmt ? $processIdColumnStmt->fetch(PDO::FETCH_ASSOC) : null;
            if ($processIdColumn && stripos($processIdColumn['Type'] ?? '', 'int') === false) {
                // If column exists but is not INT, we need to migrate it
                // This should be done via the migration script first
                error_log('Template schema: process_id column should be INT(11), but found: ' . ($processIdColumn['Type'] ?? 'unknown'));
                error_log('Please run migrate_data_capture_templates_process_id_to_int.sql migration script first');
            }
        } catch (Exception $columnException) {
            error_log('Template schema process_id check warning: ' . $columnException->getMessage());
        }

        // Ensure row_index column exists to preserve row ordering in summary table
        try {
            $rowIndexColumnStmt = $pdo->query("SHOW COLUMNS FROM data_capture_templates LIKE 'row_index'");
            $hasRowIndex = $rowIndexColumnStmt && $rowIndexColumnStmt->fetch(PDO::FETCH_ASSOC);
            if (!$hasRowIndex) {
                $pdo->exec("ALTER TABLE data_capture_templates ADD COLUMN row_index INT NULL AFTER data_capture_id");
                error_log('Template schema: Added row_index column to data_capture_templates');
            }
        } catch (Exception $columnException) {
            error_log('Template schema row_index alteration warning: ' . $columnException->getMessage());
        }
    } catch (Exception $e) {
        error_log('Template schema ensure error: ' . $e->getMessage());
    }
}

function computeTemplateKey(array $row): string {
    $productType = $row['product_type'] ?? 'main';

    if ($productType === 'sub') {
        $parent = trim((string)($row['parent_id_product'] ?? $row['id_product_main'] ?? ''));
        $subId = trim((string)($row['id_product_sub'] ?? $row['id_product'] ?? ''));
        $description = trim((string)($row['description_sub'] ?? $row['description'] ?? ''));
        $accountId = trim((string)($row['account_id'] ?? ''));
        $subOrder = isset($row['sub_order']) && $row['sub_order'] !== null && $row['sub_order'] !== '' ? (string)$row['sub_order'] : '';

        if ($subId === '' && $parent === '') {
            $parent = 'sub';
        }

        // 与 main 一致：sub 的 template_key 使用 parent_id_product，并加上 account_id 区分同 parent 下多 account（避免 2 条 sub 共用一个 key 互相覆盖或产生重复）
        $baseKey = $parent !== '' ? $parent : ($subId !== '' ? $subId : '');
        $accountId = trim((string)($row['account_id'] ?? ''));
        if ($baseKey !== '') {
            $key = $accountId !== '' ? $baseKey . '_' . $accountId : $baseKey;
            return substr($key, 0, 250);
        }

        // 无 parent/sub 时用长格式保证唯一
        $keyParts = [$parent, $subId !== '' ? $subId : $parent, $description, $accountId, $subOrder];
        $key = implode('::', array_map(static function ($part) {
            return trim((string)$part);
        }, $keyParts));
        if ($key === '::::' || $key === ':::::') {
            $key = 'sub-' . md5(json_encode($row));
        }
        return substr($key, 0, 250);
    }

    $idProduct = trim((string)($row['id_product'] ?? $row['id_product_main'] ?? ''));
    if ($idProduct === '') {
        $idProduct = 'main-' . md5(json_encode($row));
    }

    return substr($idProduct, 0, 250);
}

ensureTemplateSchema($pdo);

/**
 * 同步 Formula 到所有关联的 Multi-use Processes
 * 当源 Process 的 Formula 更新时，自动同步到所有 sync_source_process_id 指向该源 Process 的 Processes
 */
function syncFormulaToMultiUseProcesses(PDO $pdo, int $sourceProcessId, array $templateData, int $companyId) {
    try {
        // 查找所有 sync_source_process_id 指向当前源 Process 的 Processes
        $stmt = $pdo->prepare("
            SELECT id, process_id 
            FROM process 
            WHERE sync_source_process_id = ? AND company_id = ?
        ");
        $stmt->execute([$sourceProcessId, $companyId]);
        $syncedProcesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($syncedProcesses)) {
            return; // 没有需要同步的 Processes
        }
        
        error_log("Syncing formula to " . count($syncedProcesses) . " multi-use processes for source process ID: $sourceProcessId");
        
        // 为每个关联的 Process 同步 Formula
        foreach ($syncedProcesses as $syncedProcess) {
            $targetProcessId = $syncedProcess['id'];
            $targetProcessCode = $syncedProcess['process_id'];
            
            try {
                // 查找目标 Process 中对应的 template（基于 id_product, account_id, product_type, formula_variant；sub 行另加 sub_order）
                $productType = $templateData['product_type'] ?? 'main';
                $subOrder = isset($templateData['sub_order']) && $templateData['sub_order'] !== null && $templateData['sub_order'] !== '' ? (float)$templateData['sub_order'] : null;
                $hasSubOrder = $productType === 'sub' && $subOrder !== null;
                $sql = "
                    SELECT id FROM data_capture_templates 
                    WHERE process_id = ? 
                      AND company_id = ?
                      AND id_product = ?
                      AND account_id = ?
                      AND product_type = ?
                      AND formula_variant = ?
                " . ($hasSubOrder ? " AND (COALESCE(sub_order, 0) = COALESCE(?, 0))" : "") . "
                    LIMIT 1
                ";
                $findTemplateStmt = $pdo->prepare($sql);
                $params = [
                    $targetProcessId,
                    $companyId,
                    $templateData['id_product'],
                    $templateData['account_id'],
                    $productType,
                    $templateData['formula_variant']
                ];
                if ($hasSubOrder) {
                    $params[] = $subOrder;
                }
                $findTemplateStmt->execute($params);
                $targetTemplate = $findTemplateStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($targetTemplate) {
                    // 更新已存在的 template（Source、Rate、Formula 等全部覆盖）
                    $updateStmt = $pdo->prepare("
                        UPDATE data_capture_templates SET
                            source_columns = ?,
                            formula_operators = ?,
                            source_percent = ?,
                            enable_source_percent = ?,
                            input_method = ?,
                            enable_input_method = ?,
                            batch_selection = COALESCE(?, batch_selection),
                            columns_display = ?,
                            formula_display = ?,
                            description = ?,
                            account_display = ?,
                            currency_id = ?,
                            currency_display = ?,
                            last_source_value = COALESCE(?, last_source_value),
                            last_processed_amount = COALESCE(?, last_processed_amount),
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $updateStmt->execute([
                        $templateData['source_columns'],
                        $templateData['formula_operators'],
                        $templateData['source_percent'],
                        $templateData['enable_source_percent'],
                        $templateData['input_method'],
                        $templateData['enable_input_method'],
                        isset($templateData['batch_selection']) ? (int)$templateData['batch_selection'] : null,
                        $templateData['columns_display'],
                        $templateData['formula_display'],
                        $templateData['description'],
                        $templateData['account_display'],
                        $templateData['currency_id'],
                        $templateData['currency_display'],
                        isset($templateData['last_source_value']) ? $templateData['last_source_value'] : null,
                        isset($templateData['last_processed_amount']) ? $templateData['last_processed_amount'] : null,
                        $targetTemplate['id']
                    ]);
                    error_log("Updated template ID {$targetTemplate['id']} for process $targetProcessCode (ID: $targetProcessId)");
                } else {
                    // 新增同步：目标无该 Id_Product 行则插入对应 template
                    $insStmt = $pdo->prepare("
                        INSERT INTO data_capture_templates (
                            company_id, process_id, id_product, product_type, parent_id_product,
                            template_key, description, account_id, account_display,
                            currency_id, currency_display, source_columns, formula_operators,
                            source_percent, enable_source_percent, input_method, enable_input_method,
                            batch_selection, columns_display, formula_display,
                            last_source_value, last_processed_amount, row_index, sub_order, formula_variant, data_capture_id
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $templateKey = isset($templateData['template_key']) && $templateData['template_key'] !== '' ? $templateData['template_key'] : null;
                    if ($templateKey === null && !empty($templateData['id_product'])) {
                        $templateKey = $templateData['id_product'] . '_' . ($templateData['account_id'] ?? '') . '_' . ($templateData['formula_variant'] ?? 0);
                    }
                    $insStmt->execute([
                        $companyId,
                        $targetProcessId,
                        $templateData['id_product'],
                        $productType,
                        isset($templateData['parent_id_product']) ? $templateData['parent_id_product'] : null,
                        $templateKey,
                        isset($templateData['description']) ? $templateData['description'] : null,
                        $templateData['account_id'],
                        isset($templateData['account_display']) ? $templateData['account_display'] : null,
                        isset($templateData['currency_id']) ? $templateData['currency_id'] : null,
                        isset($templateData['currency_display']) ? $templateData['currency_display'] : null,
                        $templateData['source_columns'],
                        $templateData['formula_operators'],
                        isset($templateData['source_percent']) ? $templateData['source_percent'] : '1',
                        isset($templateData['enable_source_percent']) ? (int)$templateData['enable_source_percent'] : 1,
                        isset($templateData['input_method']) ? $templateData['input_method'] : null,
                        isset($templateData['enable_input_method']) ? (int)$templateData['enable_input_method'] : 0,
                        isset($templateData['batch_selection']) ? (int)$templateData['batch_selection'] : 0,
                        isset($templateData['columns_display']) ? $templateData['columns_display'] : null,
                        isset($templateData['formula_display']) ? $templateData['formula_display'] : null,
                        isset($templateData['last_source_value']) ? $templateData['last_source_value'] : null,
                        isset($templateData['last_processed_amount']) ? $templateData['last_processed_amount'] : 0,
                        isset($templateData['row_index']) ? (int)$templateData['row_index'] : null,
                        $subOrder,
                        $templateData['formula_variant'],
                        isset($templateData['data_capture_id']) ? (int)$templateData['data_capture_id'] : null
                    ]);
                    error_log("Inserted new template for process $targetProcessCode (ID: $targetProcessId) - id_product={$templateData['id_product']}");
                }
            } catch (Exception $e) {
                error_log("Error syncing formula to process $targetProcessCode (ID: $targetProcessId): " . $e->getMessage());
                // 继续同步其他 Processes，不中断
            }
        }
    } catch (Exception $e) {
        error_log("Error in syncFormulaToMultiUseProcesses: " . $e->getMessage());
        // 不抛出异常，避免影响主流程
    }
}

/**
 * A_ID 删除某行时，同步删除所有 sync_source_process_id = A_ID 的 process 中对应行（按 id_product/account_id/product_type/formula_variant/sub_order 匹配）
 */
function syncDeleteTemplateToMultiUseProcesses(PDO $pdo, int $sourceProcessId, string $idProduct, $accountId, string $productType, $formulaVariant, $subOrder, int $companyId) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, process_id FROM process 
            WHERE sync_source_process_id = ? AND company_id = ?
        ");
        $stmt->execute([$sourceProcessId, $companyId]);
        $syncedProcesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($syncedProcesses)) {
            return;
        }
        $hasSubOrder = $productType === 'sub' && $subOrder !== null && $subOrder !== '';
        $sql = "
            DELETE FROM data_capture_templates 
            WHERE process_id = ? AND company_id = ?
              AND id_product = ? AND account_id = ?
              AND product_type = ? AND formula_variant = ?
        " . ($hasSubOrder ? " AND (COALESCE(sub_order, 0) = COALESCE(?, 0))" : "");
        $delStmt = $pdo->prepare($sql);
        foreach ($syncedProcesses as $synced) {
            $targetProcessId = $synced['id'];
            $params = [$targetProcessId, $companyId, $idProduct, $accountId, $productType, $formulaVariant];
            if ($hasSubOrder) {
                $params[] = $subOrder;
            }
            $delStmt->execute($params);
            $n = $delStmt->rowCount();
            if ($n > 0) {
                error_log("Sync delete: removed template for process {$synced['process_id']} (ID: $targetProcessId)");
            }
        }
    } catch (Exception $e) {
        error_log("Error in syncDeleteTemplateToMultiUseProcesses: " . $e->getMessage());
    }
}

function saveTemplateRow(PDO $pdo, array $row, int $companyId) {
    // Ensure required keys exist
    if (empty($row['id_product']) || empty($row['account_id'])) {
        return null;
    }

    $productType = $row['product_type'] ?? 'main';
    $parentIdProduct = $row['parent_id_product'] ?? null;

    if ($productType === 'sub' && !$parentIdProduct) {
        $parentIdProduct = $row['id_product_main'] ?? null;
    }

    $templateKey = $row['template_key'] ?? computeTemplateKey(array_merge($row, [
        'product_type' => $productType,
        'parent_id_product' => $parentIdProduct,
    ]));
    
    // process_id should be process.id (int), not process.process_id (varchar string)
    $processId = null;
    if (isset($row['process_id'])) {
        $processIdValue = $row['process_id'];
        // Convert to integer (process.id)
        if (is_numeric($processIdValue)) {
            $processId = (int)$processIdValue;
        } elseif (is_string($processIdValue) && trim($processIdValue) !== '') {
            // If it's a string (process.process_id like 'KKKAB'), try to find process.id
            // This is for backward compatibility during migration
            try {
                $stmt = $pdo->prepare("SELECT id FROM process WHERE process_id = ? AND company_id = ? LIMIT 1");
                $stmt->execute([trim($processIdValue), $companyId]);
                $processRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($processRow) {
                    $processId = (int)$processRow['id'];
                    error_log("Converted process_id from string '{$processIdValue}' to int {$processId}");
                } else {
                    error_log("Warning: Could not find process.id for process_id '{$processIdValue}'");
                }
            } catch (Exception $e) {
                error_log("Error converting process_id '{$processIdValue}': " . $e->getMessage());
            }
        }
    }
    $hasProcessId = $processId !== null && $processId > 0;
    $dataCaptureId = isset($row['data_capture_id']) && !empty($row['data_capture_id']) ? (int)$row['data_capture_id'] : null;
    
    // Get formula_display to determine formula_variant
    $formulaDisplay = $row['formula_display'] ?? '';
    $batchSelection = isset($row['batch_selection']) ? (int)$row['batch_selection'] : 0;
    
    // Get sub_order for sub rows (used to distinguish multiple sub rows with same account)
    $subOrder = isset($row['sub_order']) && $row['sub_order'] !== null && $row['sub_order'] !== '' ? (float)$row['sub_order'] : null;
    
    // 如果提供了 template_id，优先使用它来查找现有模板（编辑模式）
    $templateId = isset($row['template_id']) && !empty($row['template_id']) ? (int)$row['template_id'] : null;
    
    // Determine formula_variant: if provided, use it; otherwise find the next available variant
    $formulaVariant = isset($row['formula_variant']) && $row['formula_variant'] !== null && $row['formula_variant'] !== '' ? (int)$row['formula_variant'] : null;
    
    // 如果提供了 template_id，直接使用它来查找现有模板并获取 formula_variant
    if ($templateId !== null) {
        $existingTemplateStmt = $pdo->prepare("
            SELECT formula_variant FROM data_capture_templates 
            WHERE id = :template_id
              AND company_id = :company_id
            LIMIT 1
        ");
        $existingTemplateStmt->execute([
            ':template_id' => $templateId,
            ':company_id' => $companyId
        ]);
        $existingTemplate = $existingTemplateStmt->fetch();
        if ($existingTemplate) {
            // 使用现有模板的 formula_variant
            $formulaVariant = (int)$existingTemplate['formula_variant'];
        }
    }
    
    // If formula_variant not provided, check if a record with same id_product, account_id, batch_selection, AND formula_display exists
    // If exists, use its formula_variant (update existing record)
    // If not exists, find the next available formula_variant (create new record)
    // This allows multiple rows with same id_product and account_id but different formulas (different formula_variant)
    if ($formulaVariant === null) {
        // First, try to find existing template with same id_product, account_id, batch_selection, AND formula_display
        // This handles the case where the same formula is being updated
        // For sub rows, also check sub_order to distinguish multiple sub rows with same account
        if ($productType === 'sub') {
            $existingTemplateStmt = $pdo->prepare("
                SELECT formula_variant FROM data_capture_templates 
                WHERE company_id = :company_id
                  AND process_id " . ($hasProcessId ? "= :process_id" : "IS NULL") . "
                  AND product_type = 'sub'
                  AND COALESCE(parent_id_product, '') = COALESCE(:parent_id_product, '')
                  AND COALESCE(id_product, '') = COALESCE(:id_product, '')
                  AND account_id = :account_id
                  AND batch_selection = :batch_selection
                  AND COALESCE(formula_display, '') = COALESCE(:formula_display, '')
                  AND (COALESCE(sub_order, 0) = COALESCE(:sub_order, 0))
                  AND data_capture_id " . ($dataCaptureId ? "= :data_capture_id" : "IS NULL") . "
                ORDER BY updated_at DESC
                LIMIT 1
            ");
            
            $existingTemplateParams = [
                ':company_id' => $companyId,
                ':parent_id_product' => $parentIdProduct,
                ':id_product' => $row['id_product'],
                ':account_id' => $row['account_id'],
                ':batch_selection' => $batchSelection,
                ':formula_display' => $formulaDisplay,
                ':sub_order' => $subOrder
            ];
            
            if ($hasProcessId) {
                $existingTemplateParams[':process_id'] = $processId;
            }
            if ($dataCaptureId) {
                $existingTemplateParams[':data_capture_id'] = $dataCaptureId;
            }
        } else {
            $existingTemplateStmt = $pdo->prepare("
                SELECT formula_variant FROM data_capture_templates 
                WHERE company_id = :company_id
                  AND process_id " . ($hasProcessId ? "= :process_id" : "IS NULL") . "
                  AND product_type = 'main'
                  AND COALESCE(id_product, '') = COALESCE(:id_product, '')
                  AND account_id = :account_id
                  AND batch_selection = :batch_selection
                  AND COALESCE(formula_display, '') = COALESCE(:formula_display, '')
                  AND data_capture_id " . ($dataCaptureId ? "= :data_capture_id" : "IS NULL") . "
                ORDER BY updated_at DESC
                LIMIT 1
            ");
            
            $existingTemplateParams = [
                ':company_id' => $companyId,
                ':id_product' => $row['id_product'],
                ':account_id' => $row['account_id'],
                ':batch_selection' => $batchSelection,
                ':formula_display' => $formulaDisplay
            ];
            
            if ($hasProcessId) {
                $existingTemplateParams[':process_id'] = $processId;
            }
            if ($dataCaptureId) {
                $existingTemplateParams[':data_capture_id'] = $dataCaptureId;
            }
        }
        
        $existingTemplateStmt->execute($existingTemplateParams);
        $existingTemplate = $existingTemplateStmt->fetch();
        
        if ($existingTemplate) {
            // Use existing formula_variant for the same batch_selection state AND formula_display
            // This means it's the same template, just being updated
            $formulaVariant = (int)$existingTemplate['formula_variant'];
        } else {
            // No existing template with same formula_display found
            // Find the next available formula_variant for this id_product and account_id
            // This allows multiple rows with same id_product and account_id but different formulas
            // For sub rows, also consider sub_order to distinguish multiple sub rows with same account
            if ($productType === 'sub') {
                $maxVariantStmt = $pdo->prepare("
                    SELECT MAX(formula_variant) as max_variant FROM data_capture_templates 
                    WHERE company_id = :company_id
                      AND process_id " . ($hasProcessId ? "= :process_id" : "IS NULL") . "
                      AND product_type = 'sub'
                      AND COALESCE(parent_id_product, '') = COALESCE(:parent_id_product, '')
                      AND COALESCE(id_product, '') = COALESCE(:id_product, '')
                      AND account_id = :account_id
                      AND (COALESCE(sub_order, 0) = COALESCE(:sub_order, 0))
                      AND data_capture_id " . ($dataCaptureId ? "= :data_capture_id" : "IS NULL") . "
                ");
                
                $maxVariantParams = [
                    ':company_id' => $companyId,
                    ':parent_id_product' => $parentIdProduct,
                    ':id_product' => $row['id_product'],
                    ':account_id' => $row['account_id'],
                    ':sub_order' => $subOrder
                ];
                
                if ($hasProcessId) {
                    $maxVariantParams[':process_id'] = $processId;
                }
                if ($dataCaptureId) {
                    $maxVariantParams[':data_capture_id'] = $dataCaptureId;
                }
            } else {
                $maxVariantStmt = $pdo->prepare("
                    SELECT MAX(formula_variant) as max_variant FROM data_capture_templates 
                    WHERE company_id = :company_id
                      AND process_id " . ($hasProcessId ? "= :process_id" : "IS NULL") . "
                      AND product_type = 'main'
                      AND COALESCE(id_product, '') = COALESCE(:id_product, '')
                      AND account_id = :account_id
                      AND data_capture_id " . ($dataCaptureId ? "= :data_capture_id" : "IS NULL") . "
                ");
                
                $maxVariantParams = [
                    ':company_id' => $companyId,
                    ':id_product' => $row['id_product'],
                    ':account_id' => $row['account_id']
                ];
                
                if ($hasProcessId) {
                    $maxVariantParams[':process_id'] = $processId;
                }
                if ($dataCaptureId) {
                    $maxVariantParams[':data_capture_id'] = $dataCaptureId;
                }
            }
            
            $maxVariantStmt->execute($maxVariantParams);
            $maxVariantResult = $maxVariantStmt->fetch();
            $maxVariant = $maxVariantResult && $maxVariantResult['max_variant'] !== null ? (int)$maxVariantResult['max_variant'] : 0;
            $formulaVariant = $maxVariant + 1;
        }
    }
    
    // Check for duplicate before inserting/updating
    // Now includes formula_variant in the check
    // 如果提供了 template_id，优先使用它来查找现有记录（编辑模式）
    $existingRecord = null;
    if ($templateId !== null) {
        // 直接使用 template_id 查找现有记录
        $checkStmt = $pdo->prepare("
            SELECT id FROM data_capture_templates 
            WHERE id = :template_id
              AND company_id = :company_id
            LIMIT 1
        ");
        $checkStmt->execute([
            ':template_id' => $templateId,
            ':company_id' => $companyId
        ]);
        $existingRecord = $checkStmt->fetch();
    }
    
    // 同 (process, type, product, account) 只保留一条：先按 account 找任意一条（不要求 formula_variant），避免因 input_method 不同多出 2 条
    if (!$existingRecord && $dataCaptureId === null) {
        if ($productType === 'sub') {
            $anyStmt = $pdo->prepare("
                SELECT id, formula_variant FROM data_capture_templates 
                WHERE company_id = ? AND process_id " . ($hasProcessId ? "= ?" : "IS NULL") . "
                  AND product_type = 'sub' AND COALESCE(TRIM(parent_id_product), '') = COALESCE(TRIM(?), '')
                  AND COALESCE(TRIM(id_product), '') = COALESCE(TRIM(?), '') AND account_id = ?
                  AND (data_capture_id IS NULL OR data_capture_id = 0)
                ORDER BY updated_at DESC LIMIT 1
            ");
            $anyParams = [$companyId, $parentIdProduct, $row['id_product'], $row['account_id']];
            if ($hasProcessId) {
                array_splice($anyParams, 1, 0, [$processId]);
            }
            $anyStmt->execute($anyParams);
            $anyRow = $anyStmt->fetch(PDO::FETCH_ASSOC);
            if ($anyRow) {
                $existingRecord = ['id' => $anyRow['id']];
                $formulaVariant = (int)$anyRow['formula_variant'];
            }
        } else {
            $anyStmt = $pdo->prepare("
                SELECT id, formula_variant FROM data_capture_templates 
                WHERE company_id = ? AND process_id " . ($hasProcessId ? "= ?" : "IS NULL") . "
                  AND product_type = 'main' AND COALESCE(TRIM(id_product), '') = COALESCE(TRIM(?), '')
                  AND account_id = ? AND (data_capture_id IS NULL OR data_capture_id = 0)
                ORDER BY updated_at DESC LIMIT 1
            ");
            $anyParams = [$companyId, $row['id_product'], $row['account_id']];
            if ($hasProcessId) {
                array_splice($anyParams, 1, 0, [$processId]);
            }
            $anyStmt->execute($anyParams);
            $anyRow = $anyStmt->fetch(PDO::FETCH_ASSOC);
            if ($anyRow) {
                $existingRecord = ['id' => $anyRow['id']];
                $formulaVariant = (int)$anyRow['formula_variant'];
            }
        }
    }
    
    // 如果没有通过 template_id 找到记录，使用原来的逻辑查找（按 formula_variant 精确匹配）
    if (!$existingRecord) {
        if ($productType === 'sub') {
            // For sub type, check by parent_id_product, id_product, account_id, formula_variant, sub_order, process_id, data_capture_id
            $checkStmt = $pdo->prepare("
                SELECT id FROM data_capture_templates 
                WHERE company_id = :company_id
                  AND process_id " . ($hasProcessId ? "= :process_id" : "IS NULL") . "
                  AND product_type = 'sub'
                  AND COALESCE(parent_id_product, '') = COALESCE(:parent_id_product, '')
                  AND COALESCE(id_product, '') = COALESCE(:id_product, '')
                  AND account_id = :account_id
                  AND formula_variant = :formula_variant
                  AND (COALESCE(sub_order, 0) = COALESCE(:sub_order, 0))
                  AND data_capture_id " . ($dataCaptureId ? "= :data_capture_id" : "IS NULL") . "
                LIMIT 1
            ");
            
            $checkParams = [
                ':company_id' => $companyId,
                ':parent_id_product' => $parentIdProduct,
                ':id_product' => $row['id_product'],
                ':account_id' => $row['account_id'],
                ':formula_variant' => $formulaVariant,
                ':sub_order' => $subOrder
            ];
            
            if ($hasProcessId) {
                $checkParams[':process_id'] = $processId;
            }
            if ($dataCaptureId) {
                $checkParams[':data_capture_id'] = $dataCaptureId;
            }
        } else {
            // For main type, check by id_product, account_id, formula_variant, process_id, data_capture_id
            $checkStmt = $pdo->prepare("
                SELECT id FROM data_capture_templates 
                WHERE company_id = :company_id
                  AND process_id " . ($hasProcessId ? "= :process_id" : "IS NULL") . "
                  AND product_type = 'main'
                  AND COALESCE(id_product, '') = COALESCE(:id_product, '')
                  AND account_id = :account_id
                  AND formula_variant = :formula_variant
                  AND data_capture_id " . ($dataCaptureId ? "= :data_capture_id" : "IS NULL") . "
                LIMIT 1
            ");
            
            $checkParams = [
                ':company_id' => $companyId,
                ':id_product' => $row['id_product'],
                ':account_id' => $row['account_id'],
                ':formula_variant' => $formulaVariant
            ];
            
            if ($hasProcessId) {
                $checkParams[':process_id'] = $processId;
            }
            if ($dataCaptureId) {
                $checkParams[':data_capture_id'] = $dataCaptureId;
            }
        }
        
        $checkStmt->execute($checkParams);
        $existingRecord = $checkStmt->fetch();
    }
    
    // If record exists, use UPDATE instead of INSERT to avoid duplicates
    if ($existingRecord) {
        $existingId = $existingRecord['id'];
        error_log("Found duplicate template record (ID: $existingId) - product_type=$productType, id_product=" . ($row['id_product'] ?? 'NULL') . ", account_id=" . ($row['account_id'] ?? 'NULL') . ", formula_variant=$formulaVariant, process_id=" . ($processId ?? 'NULL') . ", data_capture_id=" . ($dataCaptureId ?? 'NULL') . " - Updating instead of inserting");
        
        $stmt = $pdo->prepare("
            UPDATE data_capture_templates SET
                id_product = :id_product,
                parent_id_product = :parent_id_product,
                template_key = :template_key,
                description = :description,
                account_id = :account_id,
                account_display = :account_display,
                currency_id = :currency_id,
                currency_display = :currency_display,
                source_columns = :source_columns,
                formula_operators = :formula_operators,
                source_percent = :source_percent,
                enable_source_percent = :enable_source_percent,
                input_method = :input_method,
                enable_input_method = :enable_input_method,
                batch_selection = :batch_selection,
                columns_display = :columns_display,
                formula_display = :formula_display,
                last_source_value = :last_source_value,
                last_processed_amount = :last_processed_amount,
                process_id = :process_id,
                data_capture_id = :data_capture_id,
                row_index = :row_index,
                sub_order = :sub_order,
                formula_variant = :formula_variant,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        
        $stmt->execute([
            ':id' => $existingId,
            ':id_product' => $row['id_product'],
            ':parent_id_product' => $parentIdProduct,
            ':template_key' => $templateKey, // Update template_key to keep it consistent
            ':description' => $row['description'] ?? null,
            ':account_id' => $row['account_id'],
            ':account_display' => $row['account_display'] ?? null,
            ':currency_id' => $row['currency_id'] ?? null,
            ':currency_display' => $row['currency_display'] ?? null,
            ':source_columns' => $row['source_columns'] ?? '',
            ':formula_operators' => $row['formula_operators'] ?? '',
            // source_percent: default to '1' (multiplier, 1 = multiply by 1), auto-enable if has value
            ':source_percent' => isset($row['source_percent']) && $row['source_percent'] !== '' ? (string)$row['source_percent'] : '1',
            ':enable_source_percent' => (isset($row['source_percent']) && $row['source_percent'] !== '' && $row['source_percent'] !== '0') ? 1 : 0,
            ':input_method' => $row['input_method'] ?? null,
            ':enable_input_method' => isset($row['enable_input_method']) ? (int)$row['enable_input_method'] : 0,
            ':batch_selection' => isset($row['batch_selection']) ? (int)$row['batch_selection'] : 0,
            ':columns_display' => $row['columns_display'] ?? null,
            ':formula_display' => $row['formula_display'] ?? null,
            ':last_source_value' => $row['last_source_value'] ?? null,
            ':last_processed_amount' => isset($row['last_processed_amount']) ? $row['last_processed_amount'] : 0,
            ':process_id' => $processId,
            ':data_capture_id' => $dataCaptureId,
            ':row_index' => isset($row['row_index']) ? (int)$row['row_index'] : null,
            ':sub_order' => isset($row['sub_order']) && $row['sub_order'] !== null && $row['sub_order'] !== '' ? (float)$row['sub_order'] : null,
            ':formula_variant' => $formulaVariant,
        ]);
        
        // 如果当前 Process 是源 Process，同步 Formula 到所有关联的 Multi-use Processes
        if ($hasProcessId && $processId) {
            $syncTemplateData = [
                'id_product' => $row['id_product'],
                'account_id' => $row['account_id'],
                'product_type' => $productType,
                'formula_variant' => $formulaVariant,
                'source_columns' => $row['source_columns'] ?? '',
                'formula_operators' => $row['formula_operators'] ?? '',
                'source_percent' => isset($row['source_percent']) && $row['source_percent'] !== '' ? (string)$row['source_percent'] : '1',
                'enable_source_percent' => (isset($row['source_percent']) && $row['source_percent'] !== '' && $row['source_percent'] !== '0') ? 1 : 0,
                'input_method' => $row['input_method'] ?? null,
                'enable_input_method' => isset($row['enable_input_method']) ? (int)$row['enable_input_method'] : 0,
                'columns_display' => $row['columns_display'] ?? null,
                'formula_display' => $row['formula_display'] ?? null,
                'description' => $row['description'] ?? null,
                'account_display' => $row['account_display'] ?? null,
                'currency_id' => $row['currency_id'] ?? null,
                'currency_display' => $row['currency_display'] ?? null,
            ];
            syncFormulaToMultiUseProcesses($pdo, $processId, $syncTemplateData, $companyId);
        }
        
        return [
            'template_key' => $templateKey,
            'template_id' => $existingId,
            'formula_variant' => $formulaVariant
        ]; // Return template info after update
    }

    $stmt = $pdo->prepare("
        INSERT INTO data_capture_templates (
            company_id,
            id_product,
            product_type,
            parent_id_product,
            template_key,
            description,
            account_id,
            account_display,
            currency_id,
            currency_display,
            source_columns,
            formula_operators,
            source_percent,
            enable_source_percent,
            input_method,
            enable_input_method,
            batch_selection,
            columns_display,
            formula_display,
            last_source_value,
            last_processed_amount,
            process_id,
            data_capture_id,
            row_index,
            sub_order,
            formula_variant
        ) VALUES (
            :company_id,
            :id_product,
            :product_type,
            :parent_id_product,
            :template_key,
            :description,
            :account_id,
            :account_display,
            :currency_id,
            :currency_display,
            :source_columns,
            :formula_operators,
            :source_percent,
            :enable_source_percent,
            :input_method,
            :enable_input_method,
            :batch_selection,
            :columns_display,
            :formula_display,
            :last_source_value,
            :last_processed_amount,
            :process_id,
            :data_capture_id,
            :row_index,
            :sub_order,
            :formula_variant
        )
        ON DUPLICATE KEY UPDATE
            description = VALUES(description),
            account_id = VALUES(account_id),
            account_display = VALUES(account_display),
            currency_id = VALUES(currency_id),
            currency_display = VALUES(currency_display),
            source_columns = VALUES(source_columns),
            formula_operators = VALUES(formula_operators),
            source_percent = VALUES(source_percent),
            enable_source_percent = VALUES(enable_source_percent),
            input_method = VALUES(input_method),
            enable_input_method = VALUES(enable_input_method),
            batch_selection = VALUES(batch_selection),
            columns_display = VALUES(columns_display),
            formula_display = VALUES(formula_display),
            last_source_value = VALUES(last_source_value),
            last_processed_amount = VALUES(last_processed_amount),
            parent_id_product = VALUES(parent_id_product),
            template_key = VALUES(template_key),
            product_type = VALUES(product_type),
            process_id = VALUES(process_id),
            data_capture_id = VALUES(data_capture_id),
            row_index = VALUES(row_index),
            sub_order = VALUES(sub_order),
            formula_variant = VALUES(formula_variant),
            updated_at = CURRENT_TIMESTAMP
    ");

    $stmt->execute([
        ':company_id' => $companyId,
        ':id_product' => $row['id_product'],
        ':product_type' => $productType,
        ':parent_id_product' => $parentIdProduct,
        ':template_key' => $templateKey,
        ':description' => $row['description'] ?? null,
        ':account_id' => $row['account_id'],
        ':account_display' => $row['account_display'] ?? null,
        ':currency_id' => $row['currency_id'] ?? null,
        ':currency_display' => $row['currency_display'] ?? null,
        ':source_columns' => $row['source_columns'] ?? '',
        ':formula_operators' => $row['formula_operators'] ?? '',
        ':source_percent' => isset($row['source_percent']) && $row['source_percent'] !== '' ? (string)$row['source_percent'] : '1', // Store as string to preserve expressions like "1/2", default to '1' (multiplier)
        ':enable_source_percent' => isset($row['enable_source_percent']) ? (int)$row['enable_source_percent'] : 1,
        ':input_method' => $row['input_method'] ?? null,
        ':enable_input_method' => isset($row['enable_input_method']) ? (int)$row['enable_input_method'] : 0,
        ':batch_selection' => isset($row['batch_selection']) ? (int)$row['batch_selection'] : 0,
        ':columns_display' => $row['columns_display'] ?? null,
        ':formula_display' => $row['formula_display'] ?? null,
        ':last_source_value' => $row['last_source_value'] ?? null,
        ':last_processed_amount' => isset($row['last_processed_amount']) ? $row['last_processed_amount'] : 0,
        ':process_id' => $processId,
        ':data_capture_id' => isset($row['data_capture_id']) && !empty($row['data_capture_id']) ? (int)$row['data_capture_id'] : null,
        ':row_index' => isset($row['row_index']) ? (int)$row['row_index'] : null,
        ':sub_order' => isset($row['sub_order']) && $row['sub_order'] !== null && $row['sub_order'] !== '' ? (float)$row['sub_order'] : null,
        ':formula_variant' => $formulaVariant,
    ]);
    
    $templateId = $pdo->lastInsertId();
    
    // 如果当前 Process 是源 Process，同步 Formula 到所有关联的 Multi-use Processes
    if ($hasProcessId && $processId) {
        $syncTemplateData = [
            'id_product' => $row['id_product'],
            'account_id' => $row['account_id'],
            'product_type' => $productType,
            'formula_variant' => $formulaVariant,
            'source_columns' => $row['source_columns'] ?? '',
            'formula_operators' => $row['formula_operators'] ?? '',
            'source_percent' => isset($row['source_percent']) && $row['source_percent'] !== '' ? (string)$row['source_percent'] : '1',
            'enable_source_percent' => isset($row['enable_source_percent']) ? (int)$row['enable_source_percent'] : 1,
            'input_method' => $row['input_method'] ?? null,
            'enable_input_method' => isset($row['enable_input_method']) ? (int)$row['enable_input_method'] : 0,
            'columns_display' => $row['columns_display'] ?? null,
            'formula_display' => $row['formula_display'] ?? null,
            'description' => $row['description'] ?? null,
            'account_display' => $row['account_display'] ?? null,
            'currency_id' => $row['currency_id'] ?? null,
            'currency_display' => $row['currency_display'] ?? null,
        ];
        syncFormulaToMultiUseProcesses($pdo, $processId, $syncTemplateData, $companyId);
    }
    
    return [
        'template_key' => $templateKey,
        'template_id' => $templateId,
        'formula_variant' => $formulaVariant
    ]; // Return template info after insert
}

/**
 * Normalize id_product for use as template key (strip trailing " (description)").
 * Matches frontend normalizeIdProductText so that templates group under the same key.
 */
function normalizeIdProductForKey($text) {
    if ($text === null || $text === '') {
        return '';
    }
    $trimmed = trim((string)$text);
    if ($trimmed === '') {
        return '';
    }
    // Strip trailing " (anything)" to match frontend normalized key
    $normalized = preg_replace('/\s*\([^)]+\)\s*$/', '', $trimmed);
    return trim($normalized);
}

/**
 * Base part of id_product (before first "(") for grouping.
 * 与前端 normalizeIdProductText 一致，便于 Summary 用 ALLBET95MS 取到 ALLBET95MS(SV)MYR 等模板。
 */
function baseIdProductForKey($text) {
    if ($text === null || $text === '') {
        return '';
    }
    $trimmed = trim((string)$text);
    if ($trimmed === '') {
        return '';
    }
    $pos = strpos($trimmed, '(');
    return $pos > 0 ? trim(substr($trimmed, 0, $pos)) : $trimmed;
}

/**
 * Normalized key for template grouping: base part with trailing " :" removed.
 * 使 "MY EARNINGS : (RINGGIT...)" 与前端传入的 "MY EARNINGS" 一致，刷新后能取回模板。
 */
function baseIdProductForKeyNormalized($text) {
    $base = baseIdProductForKey($text);
    if ($base === '') {
        return '';
    }
    return trim(rtrim($base, ' :'));
}

/**
 * Merge (id_product, account_id) pairs from data_capture_details into templates
 * so that accounts that exist in details but have no template still get a row (synthetic template).
 * 修复：data_capture_details 有该账目但 data_capture_templates 没有时，仍能在 Summary 中显示。
 */
function mergeDetailOnlyTemplates(PDO $pdo, int $companyId, int $captureId, array $ids, array $templates) {
    $hasDisplayOrder = false;
    try {
        $colStmt = $pdo->query("SHOW COLUMNS FROM data_capture_details LIKE 'display_order'");
        $hasDisplayOrder = $colStmt && $colStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* ignore */ }
    $orderBy = $hasDisplayOrder ? "ORDER BY COALESCE(display_order, 999), id" : "ORDER BY id";
    $cols = $hasDisplayOrder ? "id_product_main, id_product_sub, product_type, account_id, display_order" : "id_product_main, id_product_sub, product_type, account_id";
    $detailStmt = $pdo->prepare("
        SELECT $cols
        FROM data_capture_details
        WHERE company_id = ? AND capture_id = ?
        $orderBy
    ");
    $detailStmt->execute([$companyId, $captureId]);
    $details = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

    $pairsByKey = [];
    foreach ($details as $row) {
        $accountId = isset($row['account_id']) ? trim((string)$row['account_id']) : '';
        if ($accountId === '') {
            continue;
        }
        $productType = $row['product_type'] ?? 'main';
        $idProductMain = isset($row['id_product_main']) ? trim((string)$row['id_product_main']) : '';
        $idProductSub  = isset($row['id_product_sub'])  ? trim((string)$row['id_product_sub'])  : '';
        if ($productType === 'main') {
            $idForKey = $idProductMain !== '' ? $idProductMain : $idProductSub;
        } else {
            $idForKey = $idProductMain !== '' ? $idProductMain : $idProductSub;
        }
        if ($idForKey === '') {
            continue;
        }
        $key = baseIdProductForKeyNormalized($idForKey);
        if ($key === '') {
            $key = $idForKey;
        }
        if (!isset($pairsByKey[$key])) {
            $pairsByKey[$key] = [];
        }
        $pairsByKey[$key][$accountId] = [
            'id_product' => $idForKey,
            'account_id' => $accountId,
            'display_order' => $hasDisplayOrder && isset($row['display_order']) ? (int)$row['display_order'] : null,
        ];
    }

    $accountIds = [];
    foreach ($pairsByKey as $pairs) {
        foreach ($pairs as $accId => $_) {
            if (is_numeric($accId)) {
                $accountIds[(int)$accId] = true;
            }
        }
    }
    $accountIds = array_keys($accountIds);
    $accountDisplayMap = [];
    if (!empty($accountIds)) {
        $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
        $accStmt = $pdo->prepare("
            SELECT a.id, a.account_id AS code, a.name
            FROM account a
            INNER JOIN account_company ac ON a.id = ac.account_id
            WHERE ac.company_id = ? AND a.id IN ($placeholders)
        ");
        $accStmt->execute(array_merge([$companyId], $accountIds));
        while ($row = $accStmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (int)$row['id'];
            $code = $row['code'] ?? '';
            $name = $row['name'] ?? '';
            $accountDisplayMap[$id] = $code !== '' && $name !== '' ? ($code . ' [' . $name . ']') : ($code ?: (string)$id);
            $accountDisplayMap[(string)$id] = $accountDisplayMap[$id];
        }
    }

    $requestedKeys = [];
    foreach ($ids as $id) {
        $n = baseIdProductForKeyNormalized(trim((string)$id));
        if ($n !== '') {
            $requestedKeys[$n] = true;
        }
    }
    foreach ($pairsByKey as $key => $pairs) {
        $keyInRequest = isset($templates[$key]) || isset($requestedKeys[$key]);
        if (!$keyInRequest) {
            continue;
        }
        if (!isset($templates[$key])) {
            $templates[$key] = ['main' => null, 'subs' => [], 'allMains' => []];
        }
        $allMains = $templates[$key]['allMains'] ?? [];
        $existingAccountIds = [];
        foreach ($allMains as $m) {
            $aid = isset($m['account_id']) ? (string)$m['account_id'] : '';
            if ($aid !== '') {
                $existingAccountIds[$aid] = true;
            }
        }
        foreach ($pairs as $accId => $info) {
            if (isset($existingAccountIds[(string)$accId])) {
                continue;
            }
            $display = $accountDisplayMap[(int)$accId] ?? $accountDisplayMap[(string)$accId] ?? (string)$accId;
            $synthetic = [
                'id' => null,
                'id_product' => $info['id_product'],
                'product_type' => 'main',
                'parent_id_product' => null,
                'template_key' => $info['id_product'],
                'description' => '',
                'account_id' => $accId,
                'account_display' => $display,
                'currency_id' => null,
                'currency_display' => null,
                'source_columns' => '',
                'formula_operators' => '',
                'source_percent' => '1',
                'enable_source_percent' => 1,
                'input_method' => null,
                'enable_input_method' => 0,
                'batch_selection' => 0,
                'columns_display' => null,
                'formula_display' => '',
                'last_source_value' => null,
                'last_processed_amount' => 0,
                'process_id' => null,
                'data_capture_id' => null,
                'row_index' => $info['display_order'],
                'sub_order' => null,
                'formula_variant' => 1,
                'updated_at' => null,
            ];
            $allMains[] = $synthetic;
            $existingAccountIds[(string)$accId] = true;
        }
        $templates[$key]['allMains'] = $allMains;
        if ($templates[$key]['main'] === null && !empty($allMains)) {
            $templates[$key]['main'] = $allMains[0];
        }
    }
    return $templates;
}

function fetchTemplates(PDO $pdo, array $ids, ?int $processId = null) {
    if (empty($ids) || $processId === null || $processId <= 0) {
        return [];
    }

    // Build case-insensitive query to match all case variants
    // Use LOWER() for comparison but return original case from database
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $lowerIds = array_map('strtolower', $ids);

    // 获取 company_id（从全局变量或函数参数）
    $companyId = $company_id ?? null;
    if (!$companyId) {
        // 如果函数被调用时没有传入 company_id，尝试从 session 获取
        if (isset($_SESSION['company_id'])) {
            $companyId = $_SESSION['company_id'];
        } else {
            throw new Exception('缺少公司信息');
        }
    }
    
    // 前端传的是 normalize 后的 id（如 ALLBET95MS、MY EARNINGS），库里有完整 id（如 ALLBET95MS(SV)MYR、MY EARNINGS : (RINGGIT...)），
    // 需同时按「前缀」匹配；括号前带 " : " 的 id 再按「去掉尾部空格和冒号」匹配，与前端一致。
    $stmt = $pdo->prepare("
        SELECT
            id,
            id_product,
            product_type,
            parent_id_product,
            template_key,
            description,
            account_id,
            account_display,
            currency_id,
            currency_display,
            source_columns,
            formula_operators,
            source_percent,
            enable_source_percent,
            input_method,
            enable_input_method,
            batch_selection,
            columns_display,
            formula_display,
            last_source_value,
            last_processed_amount,
            process_id,
            data_capture_id,
            row_index,
            sub_order,
            formula_variant,
            updated_at
        FROM data_capture_templates
        WHERE company_id = ?
          AND process_id = ?
          AND (
            (product_type = 'main' AND (
                LOWER(id_product) IN ($placeholders)
                OR LOWER(TRIM(SUBSTRING(id_product, 1, IF(LOCATE('(', id_product) > 0, LOCATE('(', id_product) - 1, LENGTH(id_product))))) IN ($placeholders)
                OR LOWER(TRIM(TRIM(TRAILING ':' FROM TRIM(SUBSTRING(id_product, 1, IF(LOCATE('(', id_product) > 0, LOCATE('(', id_product) - 1, LENGTH(id_product))))))) IN ($placeholders)
            ))
            OR (product_type = 'sub' AND (
                LOWER(parent_id_product) IN ($placeholders)
                OR LOWER(TRIM(SUBSTRING(parent_id_product, 1, IF(LOCATE('(', parent_id_product) > 0, LOCATE('(', parent_id_product) - 1, LENGTH(parent_id_product))))) IN ($placeholders)
                OR LOWER(TRIM(TRIM(TRAILING ':' FROM TRIM(SUBSTRING(parent_id_product, 1, IF(LOCATE('(', parent_id_product) > 0, LOCATE('(', parent_id_product) - 1, LENGTH(parent_id_product))))))) IN ($placeholders)
            ))
          )
        ORDER BY CASE WHEN row_index IS NULL THEN 1 ELSE 0 END,
                 row_index ASC,
                 process_id DESC,
                 CASE 
                     WHEN product_type = 'main' THEN COALESCE(id_product, '')
                     WHEN product_type = 'sub' THEN COALESCE(parent_id_product, '')
                     ELSE COALESCE(id_product, '')
                 END ASC,
                 product_type ASC,
                 CASE WHEN sub_order IS NULL THEN 1 ELSE 0 END,
                 sub_order ASC,
                 formula_variant ASC,
                 id ASC
    ");

    $params = array_merge([$companyId, $processId], $lowerIds, $lowerIds, $lowerIds, $lowerIds, $lowerIds, $lowerIds);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $templates = [];
    foreach ($results as $row) {
        // Formula 只绑定当前 process：不再 claim process_id IS NULL 的模板，避免在其他 process 呈现

        // Ensure source_percent is always a string to preserve decimal values and expressions
        // This is important because decimal fields might be returned as numbers, losing precision
        if (isset($row['source_percent'])) {
            $row['source_percent'] = (string)$row['source_percent'];
        }
        
        $productType = $row['product_type'] ?? 'main';

        if ($productType === 'sub') {
            $parentId = $row['parent_id_product'] ?? $row['id_product'];
            // 与 main 一致：用基名（第一个括号前）作 key，前端用 ALLBET95MS 才能取到 parent 为 ALLBET95MS(KM)MYR 的 sub
            // 使用 baseIdProductForKeyNormalized 使 "XXX : (YYY)" 的 key 为 "XXX"，与前端一致
            $parentKey = baseIdProductForKeyNormalized($parentId);
            if ($parentKey === '') {
                $parentKey = baseIdProductForKey($parentId);
            }
            if ($parentKey === '') {
                $parentKey = normalizeIdProductForKey($parentId);
            }
            if ($parentKey === '') {
                $parentKey = $parentId;
            }
            if (!isset($templates[$parentKey])) {
                $templates[$parentKey] = [
                    'main' => null,
                    'subs' => [],
                    'allMains' => [] // Store all main templates for this parent
                ];
            }
            // Check for duplicate sub templates (same id_product, account_id, batch_selection, formula_variant, AND sub_order)
            // Only remove duplicates if ALL these fields match, including formula_variant and sub_order
            // This allows multiple sub rows with same account but different sub_order or different formulas
            $isDuplicate = false;
            $currentSubOrder = isset($row['sub_order']) && $row['sub_order'] !== null ? (float)$row['sub_order'] : null;
            foreach ($templates[$parentKey]['subs'] as $index => $existingSub) {
                $existingSubOrder = isset($existingSub['sub_order']) && $existingSub['sub_order'] !== null ? (float)$existingSub['sub_order'] : null;
                if ($existingSub['id_product'] === $row['id_product'] 
                    && $existingSub['account_id'] === $row['account_id']
                    && (int)$existingSub['batch_selection'] === (int)$row['batch_selection']
                    && (int)($existingSub['formula_variant'] ?? 1) === (int)($row['formula_variant'] ?? 1)
                    && (($existingSubOrder === null && $currentSubOrder === null) || ($existingSubOrder !== null && $currentSubOrder !== null && abs($existingSubOrder - $currentSubOrder) < 0.0001))) {
                    // Found duplicate (same id_product, account_id, batch_selection, formula_variant, AND sub_order)
                    // Keep the one with latest updated_at
                    $existingUpdated = $existingSub['updated_at'] ?? '';
                    $currentUpdated = $row['updated_at'] ?? '';
                    if ($currentUpdated > $existingUpdated) {
                        // Replace with newer one
                        $templates[$parentKey]['subs'][$index] = $row;
                    }
                    $isDuplicate = true;
                    break;
                }
            }
            if (!$isDuplicate) {
                // Add sub templates for this process only (formula 仅绑定当前 process)
                // This allows multiple sub rows with same account but different formulas
                $templates[$parentKey]['subs'][] = $row;
            }
        } else {
            $idProduct = $row['id_product'];
            // 用「基名」（第一个括号前）作 key，与前端 normalizeIdProductText 一致；
            // 使用 baseIdProductForKeyNormalized 使 "MY EARNINGS : (RINGGIT...)" 的 key 为 "MY EARNINGS"，刷新后能取回
            $mainKey = baseIdProductForKeyNormalized($idProduct);
            if ($mainKey === '') {
                $mainKey = baseIdProductForKey($idProduct);
            }
            if ($mainKey === '') {
                $mainKey = normalizeIdProductForKey($idProduct);
            }
            if ($mainKey === '') {
                $mainKey = $idProduct;
            }
            if (!isset($templates[$mainKey])) {
                $templates[$mainKey] = [
                    'main' => null,
                    'subs' => [],
                    'allMains' => [] // Store all main templates for different process_id
                ];
            }
            
            // Store all main templates for current process only (formula 仅绑定当前 process)
            $templates[$mainKey]['allMains'][] = $row;
            
            // For backward compatibility, still set 'main' to the best default
            // But frontend should use 'allMains' to apply all templates
            // Priority: prefer template with process_id, then most recent
            if ($templates[$mainKey]['main'] === null) {
                $templates[$mainKey]['main'] = $row;
            } else {
                $existing = $templates[$mainKey]['main'];
                $existingProcessId = $existing['process_id'] ?? null;
                $currentProcessId = $row['process_id'] ?? null;
                
                // If existing is generic (NULL) and current is specific, use current
                if ($existingProcessId === null && $currentProcessId !== null) {
                    $templates[$mainKey]['main'] = $row;
                }
                // If both are specific or both are generic, prefer the one with more recent updated_at
                else if (($existingProcessId === null) === ($currentProcessId === null)) {
                    $existingUpdated = $existing['updated_at'] ?? '';
                    $currentUpdated = $row['updated_at'] ?? '';
                    if ($currentUpdated > $existingUpdated) {
                        $templates[$mainKey]['main'] = $row;
                    }
                }
                // Otherwise keep existing (existing is specific, current is generic)
            }
        }
    }

    return $templates;
}

// Check if this is a submit action
// 检查用户是否登录
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '用户未登录', 'data' => null]);
    exit;
}

// 优先使用请求中的 company_id（如果提供了），否则使用 session 中的
$company_id = null;
if (isset($_GET['company_id']) && !empty($_GET['company_id'])) {
    $company_id = (int)$_GET['company_id'];
} elseif (isset($_POST['company_id']) && !empty($_POST['company_id'])) {
    $company_id = (int)$_POST['company_id'];
} elseif (isset($_SESSION['company_id'])) {
    $company_id = $_SESSION['company_id'];
}

if (!$company_id) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '缺少公司信息', 'data' => null]);
    exit;
}

// 验证 company_id 是否属于当前用户（与 submit_api / update_company_session_api 等逻辑一致）
$current_user_id = $_SESSION['user_id'];
$current_user_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
$current_user_type = isset($_SESSION['user_type']) ? strtolower(trim($_SESSION['user_type'])) : '';

// 若本次请求的 company_id 与 session 中一致，说明用户已在选公司时通过校验（如 update_company_session_api），直接放行
$session_company_id = isset($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : 0;
$company_access_ok = false;
if ($session_company_id > 0 && $session_company_id === (int)$company_id) {
    $company_access_ok = true;
}

if (!$company_access_ok) {
// owner：验证 company 是否属于该 owner（role 或 user_type 为 owner 均按 owner 处理）
if ($current_user_role === 'owner' || $current_user_type === 'owner') {
    $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND owner_id = ?");
    $stmt->execute([$company_id, $owner_id]);
    if ($stmt->fetchColumn() == 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无权限访问该公司', 'data' => null]);
        exit;
    }
} else {
    // 普通用户：先查 user_company_map；若无则再允许“该公司 owner 为当前用户”（兜底，避免 session role 未正确设置）
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM user_company_map 
        WHERE user_id = ? AND company_id = ?
    ");
    $stmt->execute([$current_user_id, $company_id]);
    if ($stmt->fetchColumn() > 0) {
        // 已通过 user_company_map 授权
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND owner_id = ?");
        $stmt->execute([$company_id, $current_user_id]);
        if ($stmt->fetchColumn() == 0) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => '无权限访问该公司', 'data' => null]);
            exit;
        }
    }
}
} // end !$company_access_ok

$action = isset($_GET['action']) ? $_GET['action'] : 'load';

if ($action === 'save_template' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle save template action (auto-save when formula is saved)
    try {
        $jsonData = file_get_contents('php://input');
        $row = json_decode($jsonData, true);
        
        if (!$row) {
            throw new Exception('Invalid JSON data');
        }
        
        // Validate required fields
        if (empty($row['id_product']) || empty($row['account_id'])) {
            throw new Exception('Missing required fields: id_product or account_id');
        }
        
        // Prepare template payload
        $templatePayload = [
            'product_type' => $row['product_type'] ?? 'main',
            'id_product' => $row['id_product'],
            'parent_id_product' => $row['parent_id_product'] ?? null,
            'id_product_main' => $row['id_product_main'] ?? null,
            'id_product_sub' => $row['id_product_sub'] ?? null,
            'description' => $row['description'] ?? null,
            'description_sub' => $row['description_sub'] ?? null,
            'account_id' => $row['account_id'],
            'account_display' => $row['account_display'] ?? null,
            'currency_id' => $row['currency_id'] ?? null,
            'currency_display' => $row['currency_display'] ?? null,
            'source_columns' => $row['source_columns'] ?? '',
            'formula_operators' => $row['formula_operators'] ?? '',
            'source_percent' => isset($row['source_percent']) && $row['source_percent'] !== '' ? (string)$row['source_percent'] : '1', // Default to '1' (multiplier)
            'enable_source_percent' => isset($row['enable_source_percent']) ? (int)$row['enable_source_percent'] : 1,
            'input_method' => $row['input_method'] ?? null,
            'enable_input_method' => isset($row['enable_input_method']) ? (int)$row['enable_input_method'] : 0,
            'batch_selection' => isset($row['batch_selection']) ? (int)$row['batch_selection'] : 0,
            'columns_display' => $row['columns_display'] ?? null,
            'formula_display' => $row['formula_display'] ?? null,
            'last_source_value' => $row['last_source_value'] ?? null,
            'last_processed_amount' => isset($row['last_processed_amount']) ? $row['last_processed_amount'] : 0,
            'template_key' => $row['template_key'] ?? null,
            'process_id' => isset($row['process_id']) && is_numeric($row['process_id']) ? (int)$row['process_id'] : null,
            'data_capture_id' => isset($row['data_capture_id']) && !empty($row['data_capture_id']) ? (int)$row['data_capture_id'] : null,
            // Preserve row position in summary table if provided
            'row_index' => isset($row['row_index']) && $row['row_index'] !== null ? (int)$row['row_index'] : null,
            'sub_order' => isset($row['sub_order']) && $row['sub_order'] !== null && $row['sub_order'] !== '' ? (float)$row['sub_order'] : null,
            // Pass template_id and formula_variant for editing existing templates
            'template_id' => isset($row['template_id']) && !empty($row['template_id']) ? (int)$row['template_id'] : null,
            'formula_variant' => isset($row['formula_variant']) && $row['formula_variant'] !== null && $row['formula_variant'] !== '' ? (int)$row['formula_variant'] : null,
        ];
        
        $templateResult = saveTemplateRow($pdo, $templatePayload, $company_id);
        
        // Handle both old format (string) and new format (array) for backward compatibility
        $templateKey = is_array($templateResult) ? $templateResult['template_key'] : $templateResult;
        $templateId = is_array($templateResult) ? $templateResult['template_id'] : null;
        $formulaVariant = is_array($templateResult) ? $templateResult['formula_variant'] : null;
        
        // 显式同步到所有 Multi-Process（Copy From 源账号修改 Formula 后，同步到 sync_source_process_id 指向该源的流程）
        $processIdForSync = isset($templatePayload['process_id']) && $templatePayload['process_id'] > 0 ? (int)$templatePayload['process_id'] : null;
        $formulaVariantForSync = $formulaVariant !== null ? $formulaVariant : (isset($templatePayload['formula_variant']) && $templatePayload['formula_variant'] !== '' ? (int)$templatePayload['formula_variant'] : null);
        if ($processIdForSync && $templateResult !== null && $formulaVariantForSync !== null) {
            $syncTemplateData = [
                'id_product' => $templatePayload['id_product'],
                'account_id' => $templatePayload['account_id'],
                'product_type' => $templatePayload['product_type'] ?? 'main',
                'formula_variant' => $formulaVariantForSync,
                'source_columns' => $templatePayload['source_columns'] ?? '',
                'formula_operators' => $templatePayload['formula_operators'] ?? '',
                'source_percent' => isset($templatePayload['source_percent']) && $templatePayload['source_percent'] !== '' ? (string)$templatePayload['source_percent'] : '1',
                'enable_source_percent' => (isset($templatePayload['source_percent']) && $templatePayload['source_percent'] !== '' && $templatePayload['source_percent'] !== '0') ? 1 : 0,
                'input_method' => $templatePayload['input_method'] ?? null,
                'enable_input_method' => isset($templatePayload['enable_input_method']) ? (int)$templatePayload['enable_input_method'] : 0,
                'columns_display' => $templatePayload['columns_display'] ?? null,
                'formula_display' => $templatePayload['formula_display'] ?? null,
                'description' => $templatePayload['description'] ?? null,
                'account_display' => $templatePayload['account_display'] ?? null,
                'currency_id' => $templatePayload['currency_id'] ?? null,
                'currency_display' => $templatePayload['currency_display'] ?? null,
                'sub_order' => isset($templatePayload['sub_order']) && $templatePayload['sub_order'] !== null && $templatePayload['sub_order'] !== '' ? (float)$templatePayload['sub_order'] : null,
                'template_key' => $templatePayload['template_key'] ?? null,
                'parent_id_product' => $templatePayload['parent_id_product'] ?? null,
                'batch_selection' => isset($templatePayload['batch_selection']) ? (int)$templatePayload['batch_selection'] : 0,
                'last_source_value' => $templatePayload['last_source_value'] ?? null,
                'last_processed_amount' => isset($templatePayload['last_processed_amount']) ? $templatePayload['last_processed_amount'] : 0,
                'row_index' => isset($templatePayload['row_index']) ? (int)$templatePayload['row_index'] : null,
                'data_capture_id' => isset($templatePayload['data_capture_id']) ? (int)$templatePayload['data_capture_id'] : null,
            ];
            syncFormulaToMultiUseProcesses($pdo, $processIdForSync, $syncTemplateData, $company_id);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Template saved successfully',
            'template_key' => $templateKey, // Return the computed template_key so frontend can update DOM
            'template_id' => $templateId, // Return template ID for precise deletion
            'formula_variant' => $formulaVariant // Return formula_variant for precise deletion
        ]);
    } catch (Exception $e) {
        error_log('Template Save Error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'data' => null,
        ]);
    }
    exit;
}

if ($action === 'delete_template' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle delete template action (when row is deleted)
    try {
        $jsonData = file_get_contents('php://input');
        $data = json_decode($jsonData, true);
        
        if (!$data) {
            throw new Exception('Invalid JSON data');
        }
        
        // Validate required fields
        if (empty($data['template_key']) || empty($data['product_type'])) {
            throw new Exception('Missing required fields: template_key or product_type');
        }
        
        $productType = $data['product_type'];
        $templateKey = $data['template_key'];
        $templateId = isset($data['template_id']) && !empty($data['template_id']) ? (int)$data['template_id'] : null;
        $formulaVariant = isset($data['formula_variant']) && $data['formula_variant'] !== null && $data['formula_variant'] !== '' ? (int)$data['formula_variant'] : null;
        $sourceProcessId = isset($data['process_id']) && is_numeric($data['process_id']) ? (int)$data['process_id'] : null;
        
        $companyId = $company_id;
        
        // 删除前先取出行数据，用于同步删除 B_ID/C_ID 的对应行
        $rowForSync = null;
        if ($templateId) {
            $sel = $pdo->prepare("SELECT id_product, account_id, product_type, formula_variant, sub_order, process_id FROM data_capture_templates WHERE id = ? AND company_id = ? LIMIT 1");
            $sel->execute([$templateId, $companyId]);
            $rowForSync = $sel->fetch(PDO::FETCH_ASSOC);
        } elseif ($sourceProcessId && $templateKey && $formulaVariant !== null) {
            $sel = $pdo->prepare("SELECT id_product, account_id, product_type, formula_variant, sub_order, process_id FROM data_capture_templates WHERE company_id = ? AND process_id = ? AND template_key = ? AND product_type = ? AND formula_variant = ? LIMIT 1");
            $sel->execute([$companyId, $sourceProcessId, $templateKey, $productType, $formulaVariant]);
            $rowForSync = $sel->fetch(PDO::FETCH_ASSOC);
        }
        
        if ($templateId) {
            $sql = "
                DELETE FROM data_capture_templates 
                WHERE company_id = :company_id
                  AND id = :template_id
            ";
            $stmt = $pdo->prepare($sql);
            $params = [
                ':company_id' => $companyId,
                ':template_id' => $templateId
            ];
        } else if ($formulaVariant !== null) {
            $sql = "
                DELETE FROM data_capture_templates 
                WHERE company_id = :company_id
                  AND product_type = :product_type 
                  AND template_key = :template_key
                  AND formula_variant = :formula_variant
            ";
            $stmt = $pdo->prepare($sql);
            $params = [
                ':company_id' => $companyId,
                ':product_type' => $productType,
                ':template_key' => $templateKey,
                ':formula_variant' => $formulaVariant
            ];
            if ($sourceProcessId) {
                $sql .= " AND process_id = :process_id";
                $params[':process_id'] = $sourceProcessId;
            }
            $stmt = $pdo->prepare($sql);
        } else {
            $sql = "
                DELETE FROM data_capture_templates 
                WHERE company_id = :company_id
                  AND product_type = :product_type 
                  AND template_key = :template_key
            ";
            $params = [
                ':company_id' => $companyId,
                ':product_type' => $productType,
                ':template_key' => $templateKey
            ];
            if ($sourceProcessId) {
                $sql .= " AND process_id = :process_id";
                $params[':process_id'] = $sourceProcessId;
            }
            $stmt = $pdo->prepare($sql);
        }
        
        $stmt->execute($params);
        
        $deletedCount = $stmt->rowCount();
        
        // 删除同步：A_ID 删除后，同步删除所有 sync_source_process_id = A_ID 的 process 中对应行
        // 优先用请求的 process_id；若未传（如按 template_id 删除），则用 $rowForSync['process_id'] 作为源
        $effectiveSourceProcessId = $sourceProcessId !== null
            ? $sourceProcessId
            : (isset($rowForSync['process_id']) && $rowForSync['process_id'] !== null && $rowForSync['process_id'] !== '' ? (int)$rowForSync['process_id'] : null);
        if ($deletedCount > 0 && $effectiveSourceProcessId !== null && $rowForSync) {
            $subOrder = isset($rowForSync['sub_order']) && $rowForSync['sub_order'] !== null && $rowForSync['sub_order'] !== '' ? (float)$rowForSync['sub_order'] : null;
            syncDeleteTemplateToMultiUseProcesses(
                $pdo,
                $effectiveSourceProcessId,
                $rowForSync['id_product'],
                $rowForSync['account_id'],
                $rowForSync['product_type'],
                (int)$rowForSync['formula_variant'],
                $subOrder,
                $companyId
            );
        }
        
        if ($deletedCount > 0) {
            if ($templateId) {
                error_log("Deleted template by ID: template_id=$templateId");
            } else if ($formulaVariant) {
                error_log("Deleted template by key+variant: product_type=$productType, template_key=$templateKey, formula_variant=$formulaVariant");
            } else {
                error_log("Deleted template by key: product_type=$productType, template_key=$templateKey");
            }
            echo json_encode([
                'success' => true,
                'message' => 'Template deleted successfully',
                'deleted_count' => $deletedCount
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'message' => 'Template not found (may have been already deleted)',
                'deleted_count' => 0
            ]);
        }
    } catch (Exception $e) {
        error_log('Template Delete Error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'data' => null,
        ]);
    }
    exit;
}

if ($action === 'templates') {
    try {
        $ids = [];
        $processId = null;
        $captureId = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $jsonData = file_get_contents('php://input');
            $payload = json_decode($jsonData, true);
            if (isset($payload['idProducts']) && is_array($payload['idProducts'])) {
                $ids = array_values(array_filter(array_map('trim', $payload['idProducts'])));
            }
            if (isset($payload['processId'])) {
                // processId should be process.id (int), not process.process_id (string)
                $processIdValue = $payload['processId'];
                if (is_numeric($processIdValue)) {
                    $processId = (int)$processIdValue;
                } elseif (is_string($processIdValue) && trim($processIdValue) !== '') {
                    $processId = (int)trim($processIdValue);
                }
            }
            if (isset($payload['captureId']) && $payload['captureId'] !== null && $payload['captureId'] !== '') {
                $captureIdVal = $payload['captureId'];
                if (is_numeric($captureIdVal)) {
                    $captureId = (int)$captureIdVal;
                } elseif (is_string($captureIdVal) && trim($captureIdVal) !== '') {
                    $captureId = (int)trim($captureIdVal);
                }
            }
        } elseif (!empty($_GET['ids'])) {
            $ids = array_values(array_filter(array_map('trim', explode(',', $_GET['ids']))));
        }

        if ($processId === null && !empty($_GET['processId'])) {
            // processId should be process.id (int)
            $getProcessId = $_GET['processId'];
            if (is_numeric($getProcessId)) {
                $processId = (int)$getProcessId;
            } elseif (is_string($getProcessId) && trim($getProcessId) !== '') {
                $processId = (int)trim($getProcessId);
            }
        }
        if (!empty($_GET['captureId']) && is_numeric($_GET['captureId'])) {
            $captureId = (int)$_GET['captureId'];
        }

        if (empty($ids)) {
            throw new Exception('No id products provided');
        }

        if ($processId === null) {
            throw new Exception('Process ID is required');
        }

        // 在 Data Capture 选择的 Process 下设置的 formula 只在该 Process 显示；若该 Process 有 sync 到其他 Process 则同步显示
        // Summary 的 formula 仅来自 Maintenance（data_capture_templates）；Process 在 Maintenance 无记录则不显示 formula
        $templates = fetchTemplates($pdo, $ids, $processId);

        if ($captureId !== null && $captureId > 0 && $company_id) {
            $templates = mergeDetailOnlyTemplates($pdo, (int)$company_id, $captureId, $ids, $templates);
        }

        echo json_encode([
            'success' => true,
            'templates' => $templates,
        ]);
    } catch (Exception $e) {
        error_log('Template Fetch Error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'data' => null,
        ]);
    }
    exit;
}

if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle submit action
    try {
        // 使用全局的 $company_id（已经过验证）
        $companyId = $company_id;
        
        // Check PHP configuration limits first
        $postMaxSize = ini_get('post_max_size');
        $postMaxSizeBytes = return_bytes($postMaxSize);
        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
        
        // Get all relevant PHP configuration values for error reporting
        $uploadMaxFilesize = ini_get('upload_max_filesize');
        $maxInputVars = ini_get('max_input_vars');
        $memoryLimit = ini_get('memory_limit');
        
        // Check if Content-Length exceeds post_max_size (before reading data)
        if ($contentLength > 0 && $contentLength > $postMaxSizeBytes) {
            $configInfo = "\n\n当前 PHP 配置：\n";
            $configInfo .= "- post_max_size: $postMaxSize\n";
            $configInfo .= "- upload_max_filesize: $uploadMaxFilesize\n";
            $configInfo .= "- max_input_vars: $maxInputVars\n";
            $configInfo .= "- memory_limit: $memoryLimit\n";
            $configInfo .= "\n实际数据大小: " . round($contentLength / 1024 / 1024, 2) . " MB";
            throw new Exception("数据太大（" . round($contentLength / 1024 / 1024, 2) . " MB），超过了 PHP post_max_size 限制（$postMaxSize）。" . $configInfo);
        }
        
        // IMPORTANT: For JSON requests (application/json), data is NOT in $_POST
        // It's only available via php://input, so we should NOT check $_POST for JSON requests
        // Only check for truncation if Content-Length exceeds post_max_size
        // For JSON requests, empty $_POST is normal and expected
        
        // Check if Content-Length exceeds post_max_size (this is the real check)
        // If it does, PHP will truncate the data before we can read it
        if ($contentLength > 0 && $contentLength > $postMaxSizeBytes) {
            $configInfo = "\n\n当前 PHP 配置：\n";
            $configInfo .= "- post_max_size: $postMaxSize (" . round($postMaxSizeBytes / 1024 / 1024, 2) . " MB)\n";
            $configInfo .= "- upload_max_filesize: $uploadMaxFilesize\n";
            $configInfo .= "- max_input_vars: $maxInputVars\n";
            $configInfo .= "- memory_limit: $memoryLimit\n";
            $configInfo .= "\n数据大小信息：\n";
            $configInfo .= "- Content-Length (请求头): " . round($contentLength / 1024 / 1024, 2) . " MB (" . round($contentLength / 1024, 2) . " KB)\n";
            $configInfo .= "\n⚠️ Content-Length (" . round($contentLength / 1024 / 1024, 2) . " MB) 超过了 post_max_size (" . round($postMaxSizeBytes / 1024 / 1024, 2) . " MB)";
            $configInfo .= "\n\n解决方案：\n";
            $configInfo .= "1. 检查 .htaccess 文件是否在网站根目录，且包含：php_value post_max_size 64M\n";
            $configInfo .= "2. 如果 .htaccess 不生效，通过 php.ini 或控制面板修改配置\n";
            $configInfo .= "3. 访问 check_php_config.php 查看当前配置状态\n";
            $configInfo .= "4. 如果数据确实很大，考虑分批提交";
            
            throw new Exception("数据太大（" . round($contentLength / 1024 / 1024, 2) . " MB），超过了 PHP post_max_size 限制（$postMaxSize）。" . $configInfo);
        }
        
        // Get POST data (php://input can only be read once)
        $jsonData = file_get_contents('php://input');
        $inputSize = strlen($jsonData);
        
        // Log data size for debugging
        error_log("Submit request - Input size: " . round($inputSize / 1024 / 1024, 2) . " MB, Content-Length: " . round($contentLength / 1024 / 1024, 2) . " MB, post_max_size: $postMaxSize");
        
        // Check if data exceeds post_max_size
        if ($inputSize > $postMaxSizeBytes) {
            $configInfo = "\n\n当前 PHP 配置：\n";
            $configInfo .= "- post_max_size: $postMaxSize (" . round($postMaxSizeBytes / 1024 / 1024, 2) . " MB)\n";
            $configInfo .= "- upload_max_filesize: $uploadMaxFilesize\n";
            $configInfo .= "- max_input_vars: $maxInputVars\n";
            $configInfo .= "- memory_limit: $memoryLimit\n";
            $configInfo .= "\n实际数据大小: " . round($inputSize / 1024 / 1024, 2) . " MB (" . round($inputSize / 1024, 2) . " KB)";
            $configInfo .= "\n\n解决方案：\n";
            $configInfo .= "1. 检查网站根目录的 .htaccess 文件是否包含：php_value post_max_size 64M\n";
            $configInfo .= "2. 如果 .htaccess 不生效，联系服务器管理员修改 php.ini\n";
            $configInfo .= "3. 访问 check_php_config.php 查看当前配置状态";
            throw new Exception("数据太大（" . round($inputSize / 1024 / 1024, 2) . " MB），超过了 PHP post_max_size 限制（$postMaxSize）。" . $configInfo);
        }
        
        if (empty($jsonData)) {
            $configInfo = "\n\n当前 PHP 配置：\n";
            $configInfo .= "- post_max_size: $postMaxSize\n";
            $configInfo .= "- Content-Length: " . round($contentLength / 1024 / 1024, 2) . " MB\n";
            $configInfo .= "\n这通常意味着数据在传输过程中被截断了。";
            throw new Exception('没有接收到数据。可能是数据太大超过了 PHP post_max_size 限制（' . $postMaxSize . '）。' . $configInfo);
        }
        
        $data = json_decode($jsonData, true);
        
        if (!$data) {
            $jsonError = json_last_error_msg();
            // Check if JSON was truncated (incomplete JSON usually means data was cut off)
            if (json_last_error() === JSON_ERROR_SYNTAX && $contentLength > $inputSize) {
                $configInfo = "\n\n当前 PHP 配置：\n";
                $configInfo .= "- post_max_size: $postMaxSize (" . round($postMaxSizeBytes / 1024 / 1024, 2) . " MB)\n";
                $configInfo .= "- Content-Length: " . round($contentLength / 1024 / 1024, 2) . " MB\n";
                $configInfo .= "- 实际接收: " . round($inputSize / 1024 / 1024, 2) . " MB\n";
                $configInfo .= "\n数据被截断，说明超过了 post_max_size 限制。";
                throw new Exception("数据太大，超过了 PHP post_max_size 限制（$postMaxSize）。数据被截断导致 JSON 解析失败。" . $configInfo);
            }
            $configInfo = "\n\n当前 PHP 配置：\n";
            $configInfo .= "- post_max_size: $postMaxSize\n";
            $configInfo .= "- Content-Length: " . round($contentLength / 1024 / 1024, 2) . " MB\n";
            throw new Exception('无效的 JSON 数据: ' . $jsonError . '。可能是数据太大导致数据被截断。' . $configInfo);
        }
        
        // Validate required fields
        if (!isset($data['captureDate']) || !isset($data['processId']) || !isset($data['currencyId'])) {
            throw new Exception('Missing required fields: captureDate, processId, or currencyId');
        }
        
        if (!isset($data['summaryRows']) || !is_array($data['summaryRows']) || count($data['summaryRows']) === 0) {
            throw new Exception('No summary rows to submit');
        }
        
        $resolvedCurrencyId = resolveCompanyCurrencyId(
            $pdo,
            $companyId,
            $data['currencyId'] ?? null,
            $data['currencyCode'] ?? ($data['currencyName'] ?? null)
        );
        if ($resolvedCurrencyId === null) {
            throw new Exception('所选币别不属于当前公司，请重新选择正确的币别后再提交');
        }
        $data['currencyId'] = $resolvedCurrencyId;
        
        // Get user ID from session (if available)
        $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
        
        // 检查当前用户是 owner 还是 user
        $user_type = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner' ? 'owner' : 'user';
        
        // Check if this is a batch append (has captureId)
        $captureId = isset($data['captureId']) && !empty($data['captureId']) ? (int)$data['captureId'] : null;
        $isBatchAppend = $captureId !== null;
        
        // Start transaction
        $pdo->beginTransaction();
        
        try {
            if (!$isBatchAppend) {
                // Insert main capture record (first batch)
                $stmt = $pdo->prepare("
                    INSERT INTO data_captures (company_id, capture_date, process_id, currency_id, created_by, user_type, remark) 
                    VALUES (:company_id, :capture_date, :process_id, :currency_id, :created_by, :user_type, :remark)
                ");
                
                $stmt->execute([
                    ':company_id' => $companyId,
                    ':capture_date' => $data['captureDate'],
                    ':process_id' => $data['processId'],
                    ':currency_id' => $data['currencyId'],
                    ':created_by' => $userId,
                    ':user_type' => $user_type,
                    ':remark' => isset($data['remark']) && !empty($data['remark']) ? $data['remark'] : null
                ]);
                
                // Get the inserted capture ID
                $captureId = $pdo->lastInsertId();
            } else {
                // Verify capture exists and belongs to same process/date/currency/company
                $stmt = $pdo->prepare("
                    SELECT id FROM data_captures 
                    WHERE id = :capture_id 
                      AND company_id = :company_id
                      AND capture_date = :capture_date 
                      AND process_id = :process_id 
                      AND currency_id = :currency_id
                ");
                $stmt->execute([
                    ':capture_id' => $captureId,
                    ':company_id' => $companyId,
                    ':capture_date' => $data['captureDate'],
                    ':process_id' => $data['processId'],
                    ':currency_id' => $data['currencyId']
                ]);
                
                if (!$stmt->fetch()) {
                    throw new Exception('Invalid capture ID for batch append');
                }
            }
            
            // Insert detail records
            // Check for duplicates before inserting to prevent duplicate data
            // For 'main' type: check id_product_main, account_id, currency_id, formula_variant (id_product_sub should be NULL or empty)
            // For 'sub' type: check id_product_sub, id_product_main (as parent), account_id, currency_id, formula_variant
            // Use COALESCE to handle NULL values properly in comparison
            $checkStmtMain = $pdo->prepare("
                SELECT id FROM data_capture_details 
                WHERE company_id = :company_id
                  AND capture_id = :capture_id 
                  AND product_type = 'main'
                  AND COALESCE(id_product_main, '') = COALESCE(:id_product_main, '')
                  AND COALESCE(id_product_sub, '') = ''
                  AND account_id = :account_id
                  AND currency_id = :currency_id
                  AND formula_variant = :formula_variant
                LIMIT 1
            ");
            
            $checkStmtSub = $pdo->prepare("
                SELECT id FROM data_capture_details 
                WHERE company_id = :company_id
                  AND capture_id = :capture_id 
                  AND product_type = 'sub'
                  AND COALESCE(id_product_sub, '') = COALESCE(:id_product_sub, '')
                  AND COALESCE(id_product_main, '') = COALESCE(:id_product_main, '')
                  AND account_id = :account_id
                  AND currency_id = :currency_id
                  AND formula_variant = :formula_variant
                LIMIT 1
            ");
            
            // ⚠️ 重要说明（避免误会「数据乱了」）：
            // data_capture_details 表里有一个自增主键列 id_product（AUTO_INCREMENT），
            // 它只是「这一条明细记录本身」的 ID，不是产品编号。
            //
            // 真正的产品相关字段是：
            // - 主产品编号：id_product_main
            // - 主产品描述：description_main
            // - 子产品编号：id_product_sub
            // - 子产品描述：description_sub
            // - 产品类型：product_type（'main' / 'sub'）
            //
            // 也就是说：
            // - 你在界面上看到的「产品代码」会存到 id_product_main / id_product_sub
            // - 数据库里中间那一列递增的 172 / 173 等，是这张表自己的主键，不要拿来当产品号看
            //
            // 如果以后真的需要一个「业务上的产品 ID」列，可以另外加字段，例如：
            //   ALTER TABLE data_capture_details ADD COLUMN business_product_id VARCHAR(255) NULL AFTER product_type;
            // 然后在下面的 INSERT 里一并写入。
            
            // Ensure display_order column exists to preserve row ordering
            try {
                $displayOrderColumnStmt = $pdo->query("SHOW COLUMNS FROM data_capture_details LIKE 'display_order'");
                $hasDisplayOrder = $displayOrderColumnStmt && $displayOrderColumnStmt->fetch(PDO::FETCH_ASSOC);
                if (!$hasDisplayOrder) {
                    $pdo->exec("ALTER TABLE data_capture_details ADD COLUMN display_order INT NULL AFTER rate");
                    error_log('Added display_order column to data_capture_details');
                }
            } catch (Exception $columnException) {
                error_log('display_order column check warning: ' . $columnException->getMessage());
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO data_capture_details 
                (company_id, capture_id, id_product_main, description_main, id_product_sub, description_sub, product_type, formula_variant, account_id, currency_id, columns_value, source_value, source_percent, enable_source_percent, formula, processed_amount, rate, display_order) 
                VALUES 
                (:company_id, :capture_id, :id_product_main, :description_main, :id_product_sub, :description_sub, :product_type, :formula_variant, :account_id, :currency_id, :columns_value, :source_value, :source_percent, :enable_source_percent, :formula, :processed_amount, :rate, :display_order)
            ");
            
            // 同一 capture 下相同 id_product_main 按顺序：第一条为 main，后续均为 sub
            $mainSeenForIdProductMain = [];
            if ($isBatchAppend) {
                $existMainStmt = $pdo->prepare("
                    SELECT DISTINCT COALESCE(TRIM(id_product_main), '') AS id_product_main
                    FROM data_capture_details
                    WHERE capture_id = ? AND company_id = ? AND product_type = 'main' AND COALESCE(id_product_main, '') != ''
                ");
                $existMainStmt->execute([$captureId, $companyId]);
                while ($r = $existMainStmt->fetch(PDO::FETCH_ASSOC)) {
                    $mainSeenForIdProductMain[$r['id_product_main']] = true;
                }
            }
            
            // Track display_order to preserve row order from frontend
            $displayOrder = 0;
            foreach ($data['summaryRows'] as $row) {
                // Validate row data
                if (!isset($row['accountId'])) {
                    throw new Exception('Missing required row data: accountId');
                }
                
                // Validate that at least one of main or sub is provided
                if (empty($row['idProductMain']) && empty($row['idProductSub'])) {
                    throw new Exception('Missing required row data: idProductMain or idProductSub');
                }
                
                // Get display_order from row data, or use auto-incrementing counter
                // This preserves the exact order from the frontend summary table
                $rowDisplayOrder = isset($row['displayOrder']) && $row['displayOrder'] !== null ? (int)$row['displayOrder'] : $displayOrder;
                $displayOrder++;
                
                // Determine product_type: 同一 id_product_main 下第一条为 main，其余为 sub；仅 id_product_sub 有值且 main 空时为 sub
                $productType = 'main';
                if (empty($row['idProductMain']) && !empty($row['idProductSub'])) {
                    $productType = 'sub';
                } elseif (!empty($row['idProductMain'])) {
                    $key = trim((string)$row['idProductMain']);
                    if (isset($mainSeenForIdProductMain[$key])) {
                        $productType = 'sub';
                    } else {
                        $productType = 'main';
                        $mainSeenForIdProductMain[$key] = true;
                    }
                } else {
                    $productType = $row['productType'] ?? 'main';
                }
                
                // Check for duplicate before inserting
                // 注意：
                // - 首次提交（$isBatchAppend === false）时，同一个 capture 还没有明细记录，
                //   此时不需要做「重复检查」，前端 Summary 中的每一行都应当各自插入一条记录。
                // - 只有在追加批次（$isBatchAppend === true，带 captureId 再次提交）时，
                //   才根据 product/account/currency/formula_variant 判断是否更新已有记录，避免重复。
                $existingRecord = false;
                $rowCurrencyId = resolveCompanyCurrencyId(
                    $pdo,
                    $companyId,
                    $row['currencyId'] ?? null,
                    $row['currencyCode'] ?? null
                );
                if ($rowCurrencyId === null) {
                    $rowCurrencyId = $data['currencyId'];
                    error_log('Row currency_id 不属于当前公司，已自动回退为主币别。account_id=' . ($row['accountId'] ?? ''));
                }

                // Get formula_variant from row data
                // If formulaVariant is provided and not null, use it; otherwise generate a new one
                $formulaVariant = null;
                if (isset($row['formulaVariant']) && $row['formulaVariant'] !== null && $row['formulaVariant'] !== '') {
                    $formulaVariant = (int)$row['formulaVariant'];
                }
                
                // If formula_variant not provided or is null, find the next available variant for this id_product and account_id
                if ($formulaVariant === null) {
                    $formula = $row['formula'] ?? '';
                    if ($productType === 'main') {
                        $variantCheckStmt = $pdo->prepare("
                            SELECT formula_variant FROM data_capture_details 
                            WHERE company_id = :company_id
                              AND capture_id = :capture_id 
                              AND product_type = 'main'
                              AND COALESCE(id_product_main, '') = COALESCE(:id_product_main, '')
                              AND COALESCE(id_product_sub, '') = ''
                              AND account_id = :account_id
                              AND COALESCE(formula, '') = COALESCE(:formula, '')
                            LIMIT 1
                        ");
                        $variantCheckStmt->execute([
                            ':company_id' => $companyId,
                            ':capture_id' => $captureId,
                            ':id_product_main' => $row['idProductMain'] ?? null,
                            ':account_id' => $row['accountId'],
                            ':formula' => $formula
                        ]);
                        $existingVariant = $variantCheckStmt->fetch();
                        if ($existingVariant) {
                            $formulaVariant = (int)$existingVariant['formula_variant'];
                        } else {
                            $maxVariantStmt = $pdo->prepare("
                                SELECT MAX(formula_variant) as max_variant FROM data_capture_details 
                                WHERE company_id = :company_id
                                  AND capture_id = :capture_id 
                                  AND product_type = 'main'
                                  AND COALESCE(id_product_main, '') = COALESCE(:id_product_main, '')
                                  AND COALESCE(id_product_sub, '') = ''
                                  AND account_id = :account_id
                            ");
                            $maxVariantStmt->execute([
                                ':company_id' => $companyId,
                                ':capture_id' => $captureId,
                                ':id_product_main' => $row['idProductMain'] ?? null,
                                ':account_id' => $row['accountId']
                            ]);
                            $maxVariantResult = $maxVariantStmt->fetch();
                            $maxVariant = $maxVariantResult && $maxVariantResult['max_variant'] !== null ? (int)$maxVariantResult['max_variant'] : 0;
                            $formulaVariant = $maxVariant + 1;
                        }
                    } else {
                        $variantCheckStmt = $pdo->prepare("
                            SELECT formula_variant FROM data_capture_details 
                            WHERE company_id = :company_id
                              AND capture_id = :capture_id 
                              AND product_type = 'sub'
                              AND COALESCE(id_product_sub, '') = COALESCE(:id_product_sub, '')
                              AND COALESCE(id_product_main, '') = COALESCE(:id_product_main, '')
                              AND account_id = :account_id
                              AND COALESCE(formula, '') = COALESCE(:formula, '')
                            LIMIT 1
                        ");
                        $parentIdProduct = $row['parentIdProduct'] ?? $row['idProductMain'] ?? null;
                        $variantCheckStmt->execute([
                            ':company_id' => $companyId,
                            ':capture_id' => $captureId,
                            ':id_product_sub' => $row['idProductSub'] ?? null,
                            ':id_product_main' => $parentIdProduct,
                            ':account_id' => $row['accountId'],
                            ':formula' => $formula
                        ]);
                        $existingVariant = $variantCheckStmt->fetch();
                        if ($existingVariant) {
                            $formulaVariant = (int)$existingVariant['formula_variant'];
                        } else {
                            $maxVariantStmt = $pdo->prepare("
                                SELECT MAX(formula_variant) as max_variant FROM data_capture_details 
                                WHERE company_id = :company_id
                                  AND capture_id = :capture_id 
                                  AND product_type = 'sub'
                                  AND COALESCE(id_product_sub, '') = COALESCE(:id_product_sub, '')
                                  AND COALESCE(id_product_main, '') = COALESCE(:id_product_main, '')
                                  AND account_id = :account_id
                            ");
                            $maxVariantStmt->execute([
                                ':company_id' => $companyId,
                                ':capture_id' => $captureId,
                                ':id_product_sub' => $row['idProductSub'] ?? null,
                                ':id_product_main' => $parentIdProduct,
                                ':account_id' => $row['accountId']
                            ]);
                            $maxVariantResult = $maxVariantStmt->fetch();
                            $maxVariant = $maxVariantResult && $maxVariantResult['max_variant'] !== null ? (int)$maxVariantResult['max_variant'] : 0;
                            $formulaVariant = $maxVariant + 1;
                        }
                    }
                }

                // 只有在 batch append 模式下才检查并更新已有记录；
                // 首次提交时，一律走 INSERT，让 Summary 里的所有行都各自落一条明细。
                if ($isBatchAppend) {
                    if ($productType === 'main') {
                        $idProductMain = $row['idProductMain'] ?? null;
                        $checkStmtMain->execute([
                            ':company_id' => $companyId,
                            ':capture_id' => $captureId,
                            ':id_product_main' => $idProductMain,
                            ':account_id' => $row['accountId'],
                            ':currency_id' => $rowCurrencyId,
                            ':formula_variant' => $formulaVariant,
                        ]);
                        $existingRecord = $checkStmtMain->fetch();
                    } else {
                        // sub type - use parentIdProduct as id_product_main for checking
                        $idProductSub = $row['idProductSub'] ?? null;
                        $parentIdProduct = $row['parentIdProduct'] ?? $row['idProductMain'] ?? null;
                        
                        // Debug log for sub type duplicate check
                        error_log("Checking duplicate sub: capture_id=$captureId, id_product_sub=" . ($idProductSub ?? 'NULL') . ", parent_id_product=" . ($parentIdProduct ?? 'NULL') . ", account_id=" . $row['accountId'] . ", formula_variant=$formulaVariant");
                        
                        $checkStmtSub->execute([
                            ':company_id' => $companyId,
                            ':capture_id' => $captureId,
                            ':id_product_sub' => $idProductSub,
                            ':id_product_main' => $parentIdProduct,
                            ':account_id' => $row['accountId'],
                            ':currency_id' => $rowCurrencyId,
                            ':formula_variant' => $formulaVariant,
                        ]);
                        $existingRecord = $checkStmtSub->fetch();
                    }
                }
                
                if ($isBatchAppend && $existingRecord) {
                    // Skip duplicate record - update existing record instead of inserting
                    $existingId = $existingRecord['id'];
                    error_log("Found duplicate data_capture_details record (ID: $existingId): capture_id=$captureId, product_type=$productType, id_product_main=" . ($row['idProductMain'] ?? 'NULL') . ", id_product_sub=" . ($row['idProductSub'] ?? 'NULL') . ", account_id=" . $row['accountId'] . " - Updating existing record instead of inserting");
                    
                    // Get rate value: use rateValue if it exists (from Rate Value column or global rateInput)
                    // Priority: Rate Value column > Global rateInput (if checkbox checked)
                    $rateValue = null;
                    if (isset($row['rateValue']) && $row['rateValue'] !== '' && $row['rateValue'] !== null) {
                        // Rate Value column has value, use it
                        $rateValueStr = (string)$row['rateValue'];
                        // Handle formats like "*3", "/2", or plain numbers
                        if (strpos($rateValueStr, '*') === 0) {
                            $rateValue = (float)substr($rateValueStr, 1);
                        } else if (strpos($rateValueStr, '/') === 0) {
                            $rateValue = (float)substr($rateValueStr, 1);
                        } else {
                            $rateValue = (float)$rateValueStr;
                        }
                    } else if (isset($row['rateChecked']) && $row['rateChecked']) {
                        // Fallback: if checkbox checked but no Rate Value, use global rateInput (backward compatibility)
                        $rateValue = isset($row['rateValue']) && $row['rateValue'] !== '' && $row['rateValue'] !== null ? (float)$row['rateValue'] : null;
                    }
                    
                    // Get display_order for update
                    $rowDisplayOrderForUpdate = isset($row['displayOrder']) && $row['displayOrder'] !== null ? (int)$row['displayOrder'] : null;
                    
                    // Update existing record instead of skipping
                    $updateStmt = $pdo->prepare("
                        UPDATE data_capture_details SET
                            description_main = :description_main,
                            description_sub = :description_sub,
                            columns_value = :columns_value,
                            source_value = :source_value,
                            source_percent = :source_percent,
                            enable_source_percent = :enable_source_percent,
                            formula = :formula,
                            processed_amount = :processed_amount,
                            rate = :rate,
                            display_order = :display_order
                        WHERE id = :id
                    ");
                    
                    $updateStmt->execute([
                        ':id' => $existingId,
                        ':description_main' => $row['descriptionMain'] ?? null,
                        ':description_sub' => $row['descriptionSub'] ?? null,
                        ':columns_value' => $row['columns'] ?? '',
                        ':source_value' => $row['source'] ?? '',
                        // source_percent: default to '1' (multiplier, 1 = multiply by 1), auto-enable if has value
                        ':source_percent' => isset($row['sourcePercent']) && $row['sourcePercent'] !== '' ? (string)$row['sourcePercent'] : '1',
                        ':enable_source_percent' => (isset($row['sourcePercent']) && $row['sourcePercent'] !== '' && $row['sourcePercent'] !== '0') ? 1 : 0,
                        ':formula' => $row['formula'] ?? '',
                        ':processed_amount' => $row['processedAmount'] ?? 0,
                        ':rate' => $rateValue,
                        ':display_order' => $rowDisplayOrderForUpdate
                    ]);
                    
                    continue; // Skip insert, already updated
                }
                
                // Get rate value: use rateValue if it exists (from Rate Value column or global rateInput)
                // Priority: Rate Value column > Global rateInput (if checkbox checked)
                $rateValue = null;
                if (isset($row['rateValue']) && $row['rateValue'] !== '' && $row['rateValue'] !== null) {
                    // Rate Value column has value, use it
                    $rateValueStr = (string)$row['rateValue'];
                    // Handle formats like "*3", "/2", or plain numbers
                    if (strpos($rateValueStr, '*') === 0) {
                        $rateValue = (float)substr($rateValueStr, 1);
                    } else if (strpos($rateValueStr, '/') === 0) {
                        $rateValue = (float)substr($rateValueStr, 1);
                    } else {
                        $rateValue = (float)$rateValueStr;
                    }
                } else if (isset($row['rateChecked']) && $row['rateChecked']) {
                    // Fallback: if checkbox checked but no Rate Value, use global rateInput (backward compatibility)
                    $rateValue = isset($row['rateValue']) && $row['rateValue'] !== '' && $row['rateValue'] !== null ? (float)$row['rateValue'] : null;
                }
                
                $stmt->execute([
                    ':company_id' => $companyId,
                    ':capture_id' => $captureId,
                    ':id_product_main' => $row['idProductMain'] ?? null,
                    ':description_main' => $row['descriptionMain'] ?? null,
                    ':id_product_sub' => $row['idProductSub'] ?? null,
                    ':description_sub' => $row['descriptionSub'] ?? null,
                    ':product_type' => $productType,
                    ':formula_variant' => $formulaVariant,
                    ':account_id' => $row['accountId'],
                    ':currency_id' => $rowCurrencyId,
                    ':columns_value' => $row['columns'] ?? '',
                    ':source_value' => $row['source'] ?? '',
                    // source_percent: default to '1' (multiplier, 1 = multiply by 1), auto-enable if has value
                    // Store as string to preserve expressions like "1/2" or "0.5/2"
                    ':source_percent' => isset($row['sourcePercent']) && $row['sourcePercent'] !== '' ? (string)$row['sourcePercent'] : '1',
                    ':enable_source_percent' => (isset($row['sourcePercent']) && $row['sourcePercent'] !== '' && $row['sourcePercent'] !== '0') ? 1 : 0,
                    ':formula' => $row['formula'] ?? '',
                    ':processed_amount' => $row['processedAmount'] ?? 0,
                    ':rate' => $rateValue,
                    ':display_order' => $rowDisplayOrder
                ]);
            }

            // 所有 Data Summary submit 后的 formula 都写入 data_capture_templates，以便在 Maintenance - Formula 中显示
            // 与 data_capture_details 一致：同一 id_product_main 下第一条为 main、其余为 sub，这样 Submit 3 条只产生 3 条模板（1 main + 2 sub）
            $processIdForTemplates = isset($data['processId']) && (is_numeric($data['processId']) || (is_string($data['processId']) && trim($data['processId']) !== '')) ? $data['processId'] : null;
            if ($processIdForTemplates !== null) {
                $templateMainSeen = [];
                foreach ($data['summaryRows'] as $summaryRow) {
                    $idProductMain = $summaryRow['idProductMain'] ?? null;
                    $idProductSub = $summaryRow['idProductSub'] ?? null;
                    if (empty($idProductMain) && !empty($idProductSub)) {
                        $pt = 'sub';
                        $idProduct = $idProductSub;
                    } elseif (!empty($idProductMain)) {
                        $key = trim((string)$idProductMain);
                        if (isset($templateMainSeen[$key])) {
                            $pt = 'sub';
                            $idProduct = $idProductMain;
                        } else {
                            $pt = 'main';
                            $idProduct = $idProductMain;
                            $templateMainSeen[$key] = true;
                        }
                    } else {
                        $pt = $summaryRow['productType'] ?? 'main';
                        $idProduct = $idProductMain ?? $idProductSub;
                    }
                    if ($idProduct === null || $idProduct === '' || !isset($summaryRow['accountId'])) {
                        continue;
                    }
                    $rowCurrId = resolveCompanyCurrencyId($pdo, $companyId, $summaryRow['currencyId'] ?? null, $summaryRow['currencyCode'] ?? null);
                    if ($rowCurrId === null) {
                        $rowCurrId = $resolvedCurrencyId;
                    }
                    // 保存每行的 currency_display，这样再次进入 Data Summary 时 Currency 列能正确显示（否则为 null 会显示为空）
                    $rowCurrencyDisplay = $summaryRow['currencyDisplay'] ?? $summaryRow['currency'] ?? null;
                    if ($rowCurrencyDisplay === null && $rowCurrId !== null) {
                        $codeStmt = $pdo->prepare("SELECT code FROM currency WHERE id = ? AND company_id = ? LIMIT 1");
                        $codeStmt->execute([$rowCurrId, $companyId]);
                        $codeRow = $codeStmt->fetch(PDO::FETCH_ASSOC);
                        $rowCurrencyDisplay = $codeRow['code'] ?? null;
                    }
                    // 优先使用前端传来的 sourceColumns / formulaOperators（保留 $2 / 引用格式），
                    // 仅在缺失时才回退到旧字段，避免在 Submit 时把模板里的符号公式覆盖成代入数值后的公式。
                    $templatePayload = [
                        'product_type' => $pt,
                        'id_product' => $idProduct,
                        'parent_id_product' => ($pt === 'sub') ? ($idProductMain ?? null) : null,
                        'account_id' => $summaryRow['accountId'],
                        'account_display' => $summaryRow['accountDisplay'] ?? null,
                        'currency_id' => $rowCurrId,
                        'currency_display' => $rowCurrencyDisplay,
                        // source_columns：优先使用 summaryRows.sourceColumns，其次回退到 columns
                        'source_columns' => $summaryRow['sourceColumns'] ?? ($summaryRow['columns'] ?? ''),
                        // formula_operators：优先使用 summaryRows.formulaOperators（原始公式，含 $2），
                        // 若不存在再回退到 formula（数值公式）；否则保持为空。
                        'formula_operators' => (
                            isset($summaryRow['formulaOperators']) && $summaryRow['formulaOperators'] !== ''
                                ? $summaryRow['formulaOperators']
                                : ($summaryRow['formula'] ?? '')
                        ),
                        // formula_display 仍使用当前 Summary 提交的公式（通常是代入数值后的表达式）
                        'formula_display' => $summaryRow['formula'] ?? '',
                        'source_percent' => isset($summaryRow['sourcePercent']) && $summaryRow['sourcePercent'] !== '' ? (string)$summaryRow['sourcePercent'] : '1',
                        'enable_source_percent' => (isset($summaryRow['sourcePercent']) && $summaryRow['sourcePercent'] !== '' && $summaryRow['sourcePercent'] !== '0') ? 1 : 0,
                        // 将 Summary 中选好的 Input Method 一并写入模板；字段名兼容驼峰和下划线两种
                        'input_method' => $summaryRow['inputMethod'] ?? ($summaryRow['input_method'] ?? null),
                        'enable_input_method' => isset($summaryRow['enableInputMethod'])
                            ? (int)$summaryRow['enableInputMethod']
                            : ((isset($summaryRow['inputMethod']) && $summaryRow['inputMethod'] !== '') ? 1 : 0),
                        'process_id' => $processIdForTemplates,
                        // 绑定到本次 Data Capture，以避免不同 Account / 行被 (process, product, account) 级别去重掉
                        'data_capture_id' => $captureId,
                        'last_processed_amount' => isset($summaryRow['processedAmount']) ? $summaryRow['processedAmount'] : 0,
                    ];
                    try {
                        // 之前这里在保存 sub 模板时会删除同一 id_product_main + account 的 main 模板，
                        // 会导致「第一次 Submit 有 main，一旦有 sub 再 Submit，main 模板被删，下一次生成 Summary 时 main 行公式消失」。
                        // 现在不再删除 main 模板，保留 main + 多个 sub 同时存在的场景。
                        saveTemplateRow($pdo, $templatePayload, $companyId);
                    } catch (Exception $e) {
                        error_log('Submit: save template for Maintenance - ' . $e->getMessage());
                    }
                }
            }
            
            // Commit transaction
            $pdo->commit();
            
            // Log success
            error_log("Data capture submitted successfully - Capture ID: $captureId, Rows: " . count($data['summaryRows']));
            
            echo json_encode([
                'success' => true,
                'captureId' => $captureId,
                'message' => 'Data submitted successfully',
                'rowsInserted' => count($data['summaryRows'])
            ]);
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $pdo->rollBack();
            throw $e;
        }
        
    } catch (Exception $e) {
        error_log("Submit Error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'data' => null
        ]);
    }
    
} else {
    // Default action: Load currencies and accounts
    try {
        // 使用全局的 $company_id（已经过验证）
        
        // 获取货币列表 - 根据 company_id 过滤
        $stmt = $pdo->prepare("SELECT id, code FROM currency WHERE company_id = ? ORDER BY code");
        $stmt->execute([$company_id]);
        $currencies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 获取账户列表 - 获取 id, account_id, role, name，只包括活跃状态的账户，根据 account_company 表过滤
        $stmt = $pdo->prepare("
            SELECT DISTINCT a.id, a.account_id, a.role, a.name
            FROM account a
            INNER JOIN account_company ac ON a.id = ac.account_id
            WHERE ac.company_id = ? 
            AND a.status = 'active'
            ORDER BY a.account_id
        ");
        $stmt->execute([$company_id]);
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 调试信息
        error_log("API called - Found " . count($accounts) . " accounts and " . count($currencies) . " currencies for company_id: " . $company_id);
        
        echo json_encode([
            'success' => true,
            'currencies' => $currencies,
            'accounts' => $accounts,
            'debug' => [
                'accounts_count' => count($accounts),
                'currencies_count' => count($currencies),
                'company_id' => $company_id
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("API Error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'data' => null
        ]);
    }
}
?>