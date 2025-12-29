<?php
session_start();
header('Content-Type: application/json');
require_once 'config.php';

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
        
        // Ensure sub_order column exists to preserve sub row ordering within the same parent
        try {
            $subOrderColumnStmt = $pdo->query("SHOW COLUMNS FROM data_capture_templates LIKE 'sub_order'");
            $hasSubOrder = $subOrderColumnStmt && $subOrderColumnStmt->fetch(PDO::FETCH_ASSOC);
            if (!$hasSubOrder) {
                $pdo->exec("ALTER TABLE data_capture_templates ADD COLUMN sub_order DECIMAL(10,2) NULL AFTER row_index");
                error_log('Template schema: Added sub_order column to data_capture_templates');
            }
        } catch (Exception $columnException) {
            error_log('Template schema sub_order alteration warning: ' . $columnException->getMessage());
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

        if ($subId === '' && $parent === '') {
            $parent = 'sub';
        }

        $keyParts = [$parent, $subId !== '' ? $subId : $parent, $description, $accountId];
        $key = implode('::', array_map(static function ($part) {
            return trim((string)$part);
        }, $keyParts));

        if ($key === '::::') {
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
    
    // Calculate sub_order for sub rows
    $subOrder = null;
    if ($productType === 'sub' && $parentIdProduct) {
        // If sub_order is provided, use it
        if (isset($row['sub_order']) && $row['sub_order'] !== null && $row['sub_order'] !== '') {
            $subOrder = is_numeric($row['sub_order']) ? (float)$row['sub_order'] : null;
        } else {
            // Calculate sub_order based on insert position
            // If insert_after_sub_order is provided, insert after that position
            $insertAfterSubOrder = isset($row['insert_after_sub_order']) && $row['insert_after_sub_order'] !== null && $row['insert_after_sub_order'] !== '' 
                ? (float)$row['insert_after_sub_order'] 
                : null;
            
            if ($insertAfterSubOrder !== null) {
                // Find the next sub_order after insert_after_sub_order
                $nextSubOrderStmt = $pdo->prepare("
                    SELECT MIN(sub_order) as next_sub_order FROM data_capture_templates 
                    WHERE company_id = :company_id
                      AND process_id " . ($hasProcessId ? "= :process_id" : "IS NULL") . "
                      AND product_type = 'sub'
                      AND COALESCE(parent_id_product, '') = COALESCE(:parent_id_product, '')
                      AND sub_order > :insert_after_sub_order
                      AND data_capture_id " . ($dataCaptureId ? "= :data_capture_id" : "IS NULL") . "
                ");
                
                $nextSubOrderParams = [
                    ':company_id' => $companyId,
                    ':parent_id_product' => $parentIdProduct,
                    ':insert_after_sub_order' => $insertAfterSubOrder
                ];
                
                if ($hasProcessId) {
                    $nextSubOrderParams[':process_id'] = $processId;
                }
                if ($dataCaptureId) {
                    $nextSubOrderParams[':data_capture_id'] = $dataCaptureId;
                }
                
                $nextSubOrderStmt->execute($nextSubOrderParams);
                $nextSubOrderResult = $nextSubOrderStmt->fetch();
                
                if ($nextSubOrderResult && $nextSubOrderResult['next_sub_order'] !== null) {
                    // Insert between insert_after_sub_order and next_sub_order
                    $subOrder = ($insertAfterSubOrder + (float)$nextSubOrderResult['next_sub_order']) / 2.0;
                } else {
                    // No next sub_order, add 1.0 to insert_after_sub_order
                    $subOrder = $insertAfterSubOrder + 1.0;
                }
            } else {
                // No insert position specified, append to the end
                $maxSubOrderStmt = $pdo->prepare("
                    SELECT MAX(sub_order) as max_sub_order FROM data_capture_templates 
                    WHERE company_id = :company_id
                      AND process_id " . ($hasProcessId ? "= :process_id" : "IS NULL") . "
                      AND product_type = 'sub'
                      AND COALESCE(parent_id_product, '') = COALESCE(:parent_id_product, '')
                      AND data_capture_id " . ($dataCaptureId ? "= :data_capture_id" : "IS NULL") . "
                ");
                
                $maxSubOrderParams = [
                    ':company_id' => $companyId,
                    ':parent_id_product' => $parentIdProduct
                ];
                
                if ($hasProcessId) {
                    $maxSubOrderParams[':process_id'] = $processId;
                }
                if ($dataCaptureId) {
                    $maxSubOrderParams[':data_capture_id'] = $dataCaptureId;
                }
                
                $maxSubOrderStmt->execute($maxSubOrderParams);
                $maxSubOrderResult = $maxSubOrderStmt->fetch();
                
                if ($maxSubOrderResult && $maxSubOrderResult['max_sub_order'] !== null) {
                    $subOrder = (float)$maxSubOrderResult['max_sub_order'] + 1.0;
                } else {
                    // First sub row for this parent
                    $subOrder = 1.0;
                }
            }
        }
    }
    
    // Get formula_display to determine formula_variant
    $formulaDisplay = $row['formula_display'] ?? '';
    $batchSelection = isset($row['batch_selection']) ? (int)$row['batch_selection'] : 0;
    
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
                ':formula_display' => $formulaDisplay
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
            if ($productType === 'sub') {
                $maxVariantStmt = $pdo->prepare("
                    SELECT MAX(formula_variant) as max_variant FROM data_capture_templates 
                    WHERE company_id = :company_id
                      AND process_id " . ($hasProcessId ? "= :process_id" : "IS NULL") . "
                      AND product_type = 'sub'
                      AND COALESCE(parent_id_product, '') = COALESCE(:parent_id_product, '')
                      AND COALESCE(id_product, '') = COALESCE(:id_product, '')
                      AND account_id = :account_id
                      AND data_capture_id " . ($dataCaptureId ? "= :data_capture_id" : "IS NULL") . "
                ");
                
                $maxVariantParams = [
                    ':company_id' => $companyId,
                    ':parent_id_product' => $parentIdProduct,
                    ':id_product' => $row['id_product'],
                    ':account_id' => $row['account_id']
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
    
    // 如果没有通过 template_id 找到记录，使用原来的逻辑查找
    if (!$existingRecord) {
        if ($productType === 'sub') {
            // For sub type, check by parent_id_product, id_product, account_id, formula_variant, process_id, data_capture_id
            $checkStmt = $pdo->prepare("
                SELECT id FROM data_capture_templates 
                WHERE company_id = :company_id
                  AND process_id " . ($hasProcessId ? "= :process_id" : "IS NULL") . "
                  AND product_type = 'sub'
                  AND COALESCE(parent_id_product, '') = COALESCE(:parent_id_product, '')
                  AND COALESCE(id_product, '') = COALESCE(:id_product, '')
                  AND account_id = :account_id
                  AND formula_variant = :formula_variant
                  AND data_capture_id " . ($dataCaptureId ? "= :data_capture_id" : "IS NULL") . "
                LIMIT 1
            ");
            
            $checkParams = [
                ':company_id' => $companyId,
                ':parent_id_product' => $parentIdProduct,
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
            ':sub_order' => $subOrder,
            ':formula_variant' => $formulaVariant,
        ]);
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
        ':sub_order' => $subOrder,
        ':formula_variant' => $formulaVariant,
    ]);
    
    $templateId = $pdo->lastInsertId();
    return [
        'template_key' => $templateKey,
        'template_id' => $templateId,
        'formula_variant' => $formulaVariant
    ]; // Return template info after insert
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
          AND (process_id = ? OR process_id IS NULL)
          AND (
            (product_type = 'main' AND LOWER(id_product) IN ($placeholders))
            OR (product_type = 'sub' AND LOWER(parent_id_product) IN ($placeholders))
          )
        ORDER BY process_id DESC,
                 CASE 
                     WHEN product_type = 'main' THEN COALESCE(id_product, '')
                     WHEN product_type = 'sub' THEN COALESCE(parent_id_product, '')
                     ELSE COALESCE(id_product, '')
                 END ASC,
                 CASE WHEN row_index IS NULL THEN 1 ELSE 0 END,
                 row_index ASC,
                 product_type ASC,
                 CASE WHEN sub_order IS NULL THEN 1 ELSE 0 END,
                 sub_order ASC,
                 formula_variant ASC,
                 id ASC
    ");

    $params = array_merge([$companyId, $processId], $lowerIds, $lowerIds);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $templates = [];
    foreach ($results as $row) {
        // Claim generic templates (process_id IS NULL) to specific process_id
        // Note: process_id is INT(11), storing process.id (int), not process.process_id (string)
        $rowProcessId = isset($row['process_id']) && is_numeric($row['process_id']) ? (int)$row['process_id'] : null;
        if ($rowProcessId === null && $processId !== null && $processId > 0 && isset($row['id'])) {
            try {
                $updateStmt = $pdo->prepare("UPDATE data_capture_templates SET process_id = :process_id WHERE company_id = :company_id AND id = :id");
                $updateStmt->execute([
                    ':company_id' => $companyId,
                    ':process_id' => $processId,
                    ':id' => $row['id']
                ]);
                $row['process_id'] = $processId;
            } catch (Exception $claimException) {
                error_log('Template claim error for ID ' . $row['id'] . ': ' . $claimException->getMessage());
            }
        }

        // Ensure source_percent is always a string to preserve decimal values and expressions
        // This is important because decimal fields might be returned as numbers, losing precision
        if (isset($row['source_percent'])) {
            $row['source_percent'] = (string)$row['source_percent'];
        }
        
        $productType = $row['product_type'] ?? 'main';

        if ($productType === 'sub') {
            $parentId = $row['parent_id_product'] ?? $row['id_product'];
            // Use original case from database as key
            if (!isset($templates[$parentId])) {
                $templates[$parentId] = [
                    'main' => null,
                    'subs' => [],
                    'allMains' => [] // Store all main templates for this parent
                ];
            }
            // Check for duplicate sub templates (same id_product, account_id, batch_selection, AND formula_variant)
            // Only remove duplicates if ALL these fields match, including formula_variant
            // This allows multiple sub rows with same account but different formulas (different formula_variant)
            $isDuplicate = false;
            foreach ($templates[$parentId]['subs'] as $index => $existingSub) {
                if ($existingSub['id_product'] === $row['id_product'] 
                    && $existingSub['account_id'] === $row['account_id']
                    && (int)$existingSub['batch_selection'] === (int)$row['batch_selection']
                    && (int)($existingSub['formula_variant'] ?? 1) === (int)($row['formula_variant'] ?? 1)) {
                    // Found duplicate (same id_product, account_id, batch_selection, AND formula_variant)
                    // Keep the one with latest updated_at
                    $existingUpdated = $existingSub['updated_at'] ?? '';
                    $currentUpdated = $row['updated_at'] ?? '';
                    if ($currentUpdated > $existingUpdated) {
                        // Replace with newer one
                        $templates[$parentId]['subs'][$index] = $row;
                    }
                    $isDuplicate = true;
                    break;
                }
            }
            if (!$isDuplicate) {
                // Add sub templates for this process (legacy NULL templates will be claimed on fetch)
                // This allows multiple sub rows with same account but different formulas
                $templates[$parentId]['subs'][] = $row;
            }
        } else {
            $idProduct = $row['id_product'];
            // Use original case from database as key to preserve case sensitivity
            if (!isset($templates[$idProduct])) {
                $templates[$idProduct] = [
                    'main' => null,
                    'subs' => [],
                    'allMains' => [] // Store all main templates for different process_id
                ];
            }
            
            // Store all main templates (for all process_id and account_id combinations)
            $templates[$idProduct]['allMains'][] = $row;
            
            // For backward compatibility, still set 'main' to the best default
            // But frontend should use 'allMains' to apply all templates
            // Priority: 1) template with process_id (specific), 2) generic template (process_id IS NULL), 3) most recent
            if ($templates[$idProduct]['main'] === null) {
                $templates[$idProduct]['main'] = $row;
            } else {
                $existing = $templates[$idProduct]['main'];
                $existingProcessId = $existing['process_id'] ?? null;
                $currentProcessId = $row['process_id'] ?? null;
                
                // If existing is generic (NULL) and current is specific, use current
                if ($existingProcessId === null && $currentProcessId !== null) {
                    $templates[$idProduct]['main'] = $row;
                }
                // If both are specific or both are generic, prefer the one with more recent updated_at
                else if (($existingProcessId === null) === ($currentProcessId === null)) {
                    $existingUpdated = $existing['updated_at'] ?? '';
                    $currentUpdated = $row['updated_at'] ?? '';
                    if ($currentUpdated > $existingUpdated) {
                        $templates[$idProduct]['main'] = $row;
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
    echo json_encode(['success' => false, 'error' => '用户未登录']);
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
    echo json_encode(['success' => false, 'error' => '缺少公司信息']);
    exit;
}

// 验证 company_id 是否属于当前用户
$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['role'] ?? '';

// 如果是 owner，验证 company 是否属于该 owner
if ($current_user_role === 'owner') {
    $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND owner_id = ?");
    $stmt->execute([$company_id, $owner_id]);
    if ($stmt->fetchColumn() == 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '无权限访问该公司']);
        exit;
    }
} else {
    // 普通用户，验证是否通过 user_company_map 关联到该 company
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM user_company_map 
        WHERE user_id = ? AND company_id = ?
    ");
    $stmt->execute([$current_user_id, $company_id]);
    if ($stmt->fetchColumn() == 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '无权限访问该公司']);
        exit;
    }
}

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
            // Pass template_id and formula_variant for editing existing templates
            'template_id' => isset($row['template_id']) && !empty($row['template_id']) ? (int)$row['template_id'] : null,
            'formula_variant' => isset($row['formula_variant']) && $row['formula_variant'] !== null && $row['formula_variant'] !== '' ? (int)$row['formula_variant'] : null,
        ];
        
        $templateResult = saveTemplateRow($pdo, $templatePayload, $company_id);
        
        // Handle both old format (string) and new format (array) for backward compatibility
        $templateKey = is_array($templateResult) ? $templateResult['template_key'] : $templateResult;
        $templateId = is_array($templateResult) ? $templateResult['template_id'] : null;
        $formulaVariant = is_array($templateResult) ? $templateResult['formula_variant'] : null;
        
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
            'error' => $e->getMessage(),
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
        $formulaVariant = isset($data['formula_variant']) && !empty($data['formula_variant']) ? (int)$data['formula_variant'] : null;
        
        // 优先使用 template_id 进行精确删除（最准确）
        // 如果没有 template_id，则使用 template_key + formula_variant（次准确）
        // 最后回退到只使用 template_key（向后兼容）
        // 使用全局的 $company_id（已经过验证）
        $companyId = $company_id;
        
        if ($templateId) {
            // 使用 template_id 精确删除
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
        } else if ($formulaVariant) {
            // 使用 template_key + formula_variant 删除（更精确）
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
        } else {
            // 回退到只使用 template_key（向后兼容，但可能删除多个相同 template_key 的记录）
            $sql = "
                DELETE FROM data_capture_templates 
                WHERE company_id = :company_id
                  AND product_type = :product_type 
                  AND template_key = :template_key
            ";
            $stmt = $pdo->prepare($sql);
            $params = [
                ':company_id' => $companyId,
                ':product_type' => $productType,
                ':template_key' => $templateKey
            ];
        }
        
        $stmt->execute($params);
        
        $deletedCount = $stmt->rowCount();
        
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
            'error' => $e->getMessage(),
        ]);
    }
    exit;
}

if ($action === 'templates') {
    try {
        $ids = [];
        $processId = null;

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

        if (empty($ids)) {
            throw new Exception('No id products provided');
        }

        if ($processId === null) {
            throw new Exception('Process ID is required');
        }

        $templates = fetchTemplates($pdo, $ids, $processId);

        echo json_encode([
            'success' => true,
            'templates' => $templates,
        ]);
    } catch (Exception $e) {
        error_log('Template Fetch Error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
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
            $stmt = $pdo->prepare("
                INSERT INTO data_capture_details 
                (company_id, capture_id, id_product_main, description_main, id_product_sub, description_sub, product_type, formula_variant, account_id, currency_id, columns_value, source_value, source_percent, enable_source_percent, formula, processed_amount, rate) 
                VALUES 
                (:company_id, :capture_id, :id_product_main, :description_main, :id_product_sub, :description_sub, :product_type, :formula_variant, :account_id, :currency_id, :columns_value, :source_value, :source_percent, :enable_source_percent, :formula, :processed_amount, :rate)
            ");
            
            foreach ($data['summaryRows'] as $row) {
                // Validate row data
                if (!isset($row['accountId'])) {
                    throw new Exception('Missing required row data: accountId');
                }
                
                // Validate that at least one of main or sub is provided
                if (empty($row['idProductMain']) && empty($row['idProductSub'])) {
                    throw new Exception('Missing required row data: idProductMain or idProductSub');
                }
                
                // Determine product_type: if idProductMain exists, it's 'main', otherwise 'sub'
                $productType = 'main';
                if (empty($row['idProductMain']) && !empty($row['idProductSub'])) {
                    $productType = 'sub';
                } elseif (!empty($row['idProductMain'])) {
                    // If idProductMain exists, it's always 'main' regardless of what frontend sent
                    $productType = 'main';
                } else {
                    // Fallback to what frontend sent
                    $productType = $row['productType'] ?? 'main';
                }
                
                // Check for duplicate before inserting
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
                
                if ($existingRecord) {
                    // Skip duplicate record - update existing record instead of inserting
                    $existingId = $existingRecord['id'];
                    error_log("Found duplicate data_capture_details record (ID: $existingId): capture_id=$captureId, product_type=$productType, id_product_main=" . ($row['idProductMain'] ?? 'NULL') . ", id_product_sub=" . ($row['idProductSub'] ?? 'NULL') . ", account_id=" . $row['accountId'] . " - Updating existing record instead of inserting");
                    
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
                            rate = :rate
                        WHERE id = :id
                    ");
                    
                    // Get rate value: if rateChecked is true, use rateValue, otherwise NULL
                    $rateValue = null;
                    if (isset($row['rateChecked']) && $row['rateChecked']) {
                        $rateValue = isset($row['rateValue']) && $row['rateValue'] !== '' && $row['rateValue'] !== null ? (float)$row['rateValue'] : null;
                    }
                    
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
                        ':rate' => $rateValue
                    ]);
                    
                    continue; // Skip insert, already updated
                }
                
                // Get rate value: if rateChecked is true, use rateValue, otherwise NULL
                $rateValue = null;
                if (isset($row['rateChecked']) && $row['rateChecked']) {
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
                    ':rate' => $rateValue
                ]);
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
            'error' => $e->getMessage()
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
            'error' => $e->getMessage()
        ]);
    }
}
?>