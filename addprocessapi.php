<?php
header('Content-Type: application/json');
require_once 'config.php';
require_once 'permissions.php';

// 开启 session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 检查用户是否登录
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => '用户未登录'
    ]);
    exit;
}

// 优先使用请求中的 company_id（如果提供了），否则使用 session 中的
$companyId = null;
if (isset($_POST['company_id']) && !empty($_POST['company_id'])) {
    $companyId = (int)$_POST['company_id'];
} elseif (isset($_SESSION['company_id'])) {
    $companyId = $_SESSION['company_id'];
}

if (!$companyId) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => '缺少公司信息'
    ]);
    exit;
}

// 验证 company_id 是否属于当前用户
$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['role'] ?? '';

// 如果是 owner，验证 company 是否属于该 owner
if ($current_user_role === 'owner') {
    $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND owner_id = ?");
    $stmt->execute([$companyId, $owner_id]);
    if ($stmt->fetchColumn() == 0) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => '无权限访问该公司'
        ]);
        exit;
    }
} else {
    // 普通用户，验证是否通过 user_company_map 关联到该 company
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM user_company_map 
        WHERE user_id = ? AND company_id = ?
    ");
    $stmt->execute([$current_user_id, $companyId]);
    if ($stmt->fetchColumn() == 0) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => '无权限访问该公司'
        ]);
        exit;
    }
}

// 获取当前登录用户的数值 ID
function getCurrentUserId(PDO $pdo) {
    // 首先尝试从 session 获取 user_id
    if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
        $userId = (int)$_SESSION['user_id'];
        // 验证用户 ID 是否存在于数据库中
        $stmt = $pdo->prepare("SELECT id FROM user WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn()) {
            return $userId;
        }
    }
    
    // 如果 session 中有 login_id，尝试通过 login_id 查找
    if (!empty($_SESSION['login_id'])) {
        $stmt = $pdo->prepare("SELECT id FROM user WHERE login_id = ? LIMIT 1");
        $stmt->execute([$_SESSION['login_id']]);
        $userId = $stmt->fetchColumn();
        if ($userId) {
            return (int)$userId;
        }
    }
    
    // 如果都找不到，尝试获取数据库中的第一个有效用户
    try {
        $stmt = $pdo->query("SELECT id FROM user WHERE status = 'active' ORDER BY id ASC LIMIT 1");
        $fallbackId = $stmt->fetchColumn();
        if ($fallbackId) {
            return (int)$fallbackId;
        }
        
        // 如果连 active 用户都没有，尝试获取任何用户
        $stmt = $pdo->query("SELECT id FROM user ORDER BY id ASC LIMIT 1");
        $fallbackId = $stmt->fetchColumn();
        if ($fallbackId) {
            return (int)$fallbackId;
        }
    } catch (Exception $e) {
        error_log("getCurrentUserId error: " . $e->getMessage());
    }
    
    // 如果所有方法都失败，抛出异常而不是返回可能不存在的 ID
    throw new Exception("无法获取有效的用户 ID。请确保已登录并且 user 表中有有效的用户记录。");
}

/**
 * 将新创建的流程自动分配给已配置 process 权限的用户，保证新流程默认可见。
 */
function assignNewProcessesToRestrictedUsers(PDO $pdo, int $companyId, array $createdProcesses): void {
    if (empty($createdProcesses)) {
        return;
    }

    $processIds = array_column($createdProcesses, 'id');
    $processIds = array_unique(array_map('intval', $processIds));
    if (empty($processIds)) {
        return;
    }

    $placeholders = str_repeat('?,', count($processIds) - 1) . '?';
    $stmt = $pdo->prepare("
        SELECT 
            p.id,
            p.process_id,
            d.name AS description_name
        FROM process p
        LEFT JOIN description d ON p.description_id = d.id
        WHERE p.id IN ($placeholders) AND p.company_id = ?
    ");
    $params = array_merge($processIds, [$companyId]);
    $stmt->execute($params);
    $processDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($processDetails)) {
        return;
    }

    // 使用 user_company_map 来获取属于该公司的用户
    $usersStmt = $pdo->prepare("
        SELECT u.id
        FROM user u
        INNER JOIN user_company_map ucm ON u.id = ucm.user_id
        WHERE ucm.company_id = ?
    ");
    $usersStmt->execute([$companyId]);
    $users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

    // 从 user_company_permissions 表读取和更新权限
    $selectPermStmt = $pdo->prepare("SELECT process_permissions FROM user_company_permissions WHERE user_id = ? AND company_id = ?");
    $updatePermStmt = $pdo->prepare("
        INSERT INTO user_company_permissions (user_id, company_id, process_permissions) 
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE process_permissions = VALUES(process_permissions)
    ");

    foreach ($users as $user) {
        $userId = $user['id'];
        
        // 从 user_company_permissions 表读取权限
        $selectPermStmt->execute([$userId, $companyId]);
        $permissionRow = $selectPermStmt->fetch(PDO::FETCH_ASSOC);
        
        // 如果用户没有设置权限（NULL），默认可见全部，跳过
        if (!$permissionRow || $permissionRow['process_permissions'] === null) {
            continue;
        }

        $permissions = json_decode($permissionRow['process_permissions'], true);
        if (!is_array($permissions)) {
            $permissions = [];
        }

        if (empty($permissions)) {
            // 即使字段存在但为空数组，仍视为没有限制
            continue;
        }

        $existingIds = [];
        foreach ($permissions as $permission) {
            if (isset($permission['id'])) {
                $existingIds[(int)$permission['id']] = true;
            }
        }

        $added = false;
        foreach ($processDetails as $process) {
            $pid = (int)$process['id'];
            if (isset($existingIds[$pid])) {
                continue;
            }

            $permissions[] = [
                'id' => $pid,
                'process_id' => $process['process_id'],
                'process_description' => $process['description_name'] ?? ''
            ];
            $added = true;
        }

        if ($added) {
            $updatePermStmt->execute([$userId, $companyId, json_encode($permissions, JSON_UNESCAPED_UNICODE)]);
        }
    }
}

// 处理 copy-from 请求
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'copy_from') {
    try {
        // 使用全局的 $companyId（已经过验证）
        if (!$companyId) {
            echo json_encode([
                'success' => false,
                'error' => '用户未登录或缺少公司信息'
            ]);
            exit;
        }
        $currentCompanyId = $companyId;
        
        $processIdParam = isset($_GET['process_id']) ? trim($_GET['process_id']) : '';
        if (empty($processIdParam)) {
            throw new Exception('process_id is required');
        }
        
        // process_id 可能是 process.id (整数) 或 process.process_id (字符串，如 'KKKAB')
        // 先尝试作为整数处理，如果不是，则作为 process.process_id 字符串处理
        $isNumeric = is_numeric($processIdParam);
        $whereClause = $isNumeric ? 'p.id = ?' : 'p.process_id = ?';
        
        $sql = "SELECT 
                    p.id,
                    p.currency_id,
                    c.code AS currency_code,
                    c.company_id AS currency_company_id,
                    p.description_id,
                    d.name AS description_name,
                    p.remove_word,
                    p.replace_word_from,
                    p.replace_word_to,
                    p.remark,
                    p.process_id,
                    GROUP_CONCAT(pd.day_id ORDER BY pd.day_id SEPARATOR ',') as day_ids
                FROM process p
                LEFT JOIN currency c ON p.currency_id = c.id
                LEFT JOIN description d ON p.description_id = d.id
                LEFT JOIN process_day pd ON p.id = pd.process_id
                WHERE $whereClause AND p.company_id = ?
                GROUP BY p.id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$processIdParam, $currentCompanyId]);
        $process = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($process) {
            // 检查 currency 是否属于当前公司
            $currencyId = null;
            if ($process['currency_id'] && $process['currency_company_id'] == $currentCompanyId) {
                $currencyId = $process['currency_id'];
            }
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'currency_id' => $currencyId, // 只有属于当前公司的 currency 才返回 ID
                    'currency_code' => $process['currency_code'],
                    'currency_warning' => $process['currency_id'] && $process['currency_company_id'] != $currentCompanyId ? 'Currency does not belong to current company' : null,
                    'description_id' => $process['description_id'],
                    'description_name' => $process['description_name'],
                    'remove_word' => $process['remove_word'],
                    'replace_word_from' => $process['replace_word_from'],
                    'replace_word_to' => $process['replace_word_to'],
                    'replace_word' => $process['replace_word_from'] . ' == ' . $process['replace_word_to'],
                    'remark' => $process['remark'],
                    'day_use' => $process['day_ids'],
                    'source_process_id' => $process['process_id'] // 返回 source process_id 用于复制 templates
                ]
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Process not found'
            ]);
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// 处理添加描述请求（不允许同一公司内重名）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_description') {
    try {
        $descriptionName = trim($_POST['description_name'] ?? '');
        if (empty($descriptionName)) {
            throw new Exception('Description name is required');
        }
        
        // 使用全局的 $companyId（已经过验证）
        if (!$companyId) {
            throw new Exception('User company_id not found');
        }

        // 检查当前 company 是否已经存在同名 description（同一个公司内禁止重复）
        // 使用表的默认排序规则（utf8mb4_unicode_ci），已经是不区分大小写比较
        $checkStmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM description 
            WHERE company_id = ? AND name = ?
        ");
        $checkStmt->execute([$companyId, $descriptionName]);
        $existsCount = (int)$checkStmt->fetchColumn();

        if ($existsCount > 0) {
            echo json_encode([
                'success' => false,
                'error' => 'Description name already exists for this company',
                'duplicate' => true
            ]);
            exit;
        }
        
        // 插入新描述，包含 company_id
        $stmt = $pdo->prepare("INSERT INTO description (name, company_id) VALUES (?, ?)");
        $stmt->execute([$descriptionName, $companyId]);
        $descriptionId = $pdo->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'description_id' => $descriptionId,
            'message' => 'Description added successfully'
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// 删除描述
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_description') {
    try {
        $descriptionId = isset($_POST['description_id']) ? (int)$_POST['description_id'] : 0;
        if (!$descriptionId) {
            throw new Exception('Description ID is required');
        }

        // 确认描述存在且属于当前公司
        $stmt = $pdo->prepare("SELECT id, name, company_id FROM description WHERE id = ? LIMIT 1");
        $stmt->execute([$descriptionId]);
        $description = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$description) {
            throw new Exception('Description not found');
        }
        if ((int)$description['company_id'] !== (int)$companyId) {
            throw new Exception('无权限删除该描述');
        }

        // 检查是否被流程使用
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM process WHERE description_id = ? AND company_id = ?");
        $stmt->execute([$descriptionId, $companyId]);
        $usageCount = (int)$stmt->fetchColumn();
        if ($usageCount > 0) {
            throw new Exception('该描述正在被流程使用，无法删除');
        }

        // 删除描述
        $stmt = $pdo->prepare("DELETE FROM description WHERE id = ? AND company_id = ?");
        $stmt->execute([$descriptionId, $companyId]);

        echo json_encode([
            'success' => true,
            'message' => 'Description deleted successfully'
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

        // 处理添加process请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    global $companyId; // 确保可以访问全局的 company_id
    try {
        $processIds = [];
        $descriptionIds = [];
        $currencyId = $_POST['currency_id'] ?? '';
        $removeWord = $_POST['remove_word'] ?? '';
        $replaceWordFrom = $_POST['replace_word_from'] ?? '';
        $replaceWordTo = $_POST['replace_word_to'] ?? '';
        $remark = $_POST['remark'] ?? '';
        $dayUse = $_POST['day_use'] ?? '';
        $copyFromProcessId = $_POST['copy_from'] ?? ''; // 获取 copy_from 的 process_id（字符串）
        error_log("Received POST copy_from: " . ($copyFromProcessId ?: 'empty') . ", all POST keys: " . implode(', ', array_keys($_POST)));
        
        // 处理选中的process IDs
        if (!empty($_POST['selected_processes'])) {
            $selectedProcesses = json_decode($_POST['selected_processes'], true);
            if (is_array($selectedProcesses)) {
                $processIds = $selectedProcesses;
            }
        } elseif (!empty($_POST['process_id'])) {
            $processIds = [$_POST['process_id']];
        }
        
        // 处理选中的描述
        if (!empty($_POST['selected_descriptions'])) {
            $selectedDescriptions = json_decode($_POST['selected_descriptions'], true);
            if (is_array($selectedDescriptions) && !empty($selectedDescriptions)) {
                // 获取描述的ID - 添加 company_id 过滤以确保选择正确的描述
                $placeholders = str_repeat('?,', count($selectedDescriptions) - 1) . '?';
                $stmt = $pdo->prepare("SELECT id FROM description WHERE name IN ($placeholders) AND company_id = ?");
                $params = $selectedDescriptions;
                $params[] = $companyId;
                $stmt->execute($params);
                $descriptionIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
        } elseif (!empty($_POST['description_id'])) {
            $descriptionIds = [$_POST['description_id']];
        }
        
        if (empty($processIds)) {
            throw new Exception('At least one process ID must be selected');
        }
        
        if (empty($descriptionIds)) {
            throw new Exception('At least one description must be selected');
        }
        
        if (empty($currencyId)) {
            throw new Exception('Currency must be selected');
        }
        
        // replace_word_from 和 replace_word_to 已经直接从 POST 获取
        
        // 解析day_use
        $dayIds = [];
        if (!empty($dayUse)) {
            $dayIds = array_filter(array_map('trim', explode(',', $dayUse)));
        }
        
        // 如果提供了 copy_from，获取原 process 的所有 data_capture_templates
        // copy_from 提供的是 process.process_id（字符串，如 'KKKAB'）
        // 注意：data_capture_templates.process_id 可能是 VARCHAR（存储 process.process_id 字符串）或 INT（存储 process.id 整数）
        $sourceTemplates = [];
        $copyFromProcessDbId = null;
        if (!empty($copyFromProcessId)) {
            try {
                // 先根据 process.process_id（字符串）找到对应的 process.id（整数），用于后续复制
                $stmt = $pdo->prepare("SELECT id FROM process WHERE process_id = ? AND company_id = ? LIMIT 1");
                $stmt->execute([$copyFromProcessId, $companyId]);
                $copyFromProcessDbId = $stmt->fetchColumn();
                
                error_log("Copy from: process_id='$copyFromProcessId', found process.id=$copyFromProcessDbId, company_id=$companyId");
                
                // 如果没有找到 process，检查是否存在该 process_id 但 company_id 不同（用于调试）
                if (!$copyFromProcessDbId) {
                    $debugStmt = $pdo->prepare("SELECT id, company_id FROM process WHERE process_id = ? LIMIT 5");
                    $debugStmt->execute([$copyFromProcessId]);
                    $debugProcesses = $debugStmt->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($debugProcesses)) {
                        error_log("Debug: Found process '$copyFromProcessId' in other companies: " . json_encode($debugProcesses));
                    } else {
                        error_log("Debug: Process '$copyFromProcessId' not found in any company");
                    }
                }
                
                // 首先尝试使用 process.process_id（字符串）查询 templates（因为表中可能存储的是字符串）
                $stmt = $pdo->prepare("
                    SELECT * FROM data_capture_templates 
                    WHERE process_id = ? AND company_id = ?
                ");
                $stmt->execute([$copyFromProcessId, $companyId]);
                $sourceTemplates = $stmt->fetchAll(PDO::FETCH_ASSOC);
                error_log("Query: SELECT * FROM data_capture_templates WHERE process_id='$copyFromProcessId' AND company_id=$companyId");
                error_log("Found " . count($sourceTemplates) . " source templates using process.process_id='$copyFromProcessId'");
                
                // 如果没有找到，检查是否存在该 process_id 的其他 company_id 的数据（用于调试）
                if (empty($sourceTemplates)) {
                    $debugStmt = $pdo->prepare("
                        SELECT company_id, COUNT(*) as cnt 
                        FROM data_capture_templates 
                        WHERE process_id = ? 
                        GROUP BY company_id
                    ");
                    $debugStmt->execute([$copyFromProcessId]);
                    $debugResults = $debugStmt->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($debugResults)) {
                        error_log("Debug: Found templates with process_id='$copyFromProcessId' in other companies: " . json_encode($debugResults));
                    } else {
                        error_log("Debug: No templates found with process_id='$copyFromProcessId' in any company");
                    }
                }
                
                // 如果没有找到，且 process.id 存在，尝试使用 process.id（整数）查询（如果表已经迁移到 INT 类型）
                if (empty($sourceTemplates) && $copyFromProcessDbId) {
                    error_log("No templates found with process.process_id, trying with process.id integer...");
                    $stmt = $pdo->prepare("
                        SELECT * FROM data_capture_templates 
                        WHERE process_id = ? AND company_id = ?
                    ");
                    $stmt->execute([$copyFromProcessDbId, $companyId]);
                    $sourceTemplates = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    error_log("Found " . count($sourceTemplates) . " source templates using process.id=$copyFromProcessDbId");
                }
                
                if (empty($sourceTemplates)) {
                    error_log("Copy from: No templates found for process_id=$copyFromProcessId (company_id=$companyId)");
                }
            } catch (Exception $e) {
                // 如果查询失败，记录错误但不阻止后续操作
                error_log("Failed to fetch source templates for copy_from ($copyFromProcessId): " . $e->getMessage());
                $sourceTemplates = []; // 确保是空数组，不会复制 templates
            }
        } else {
            error_log("Copy from: copy_from parameter is empty");
        }
        
        $currentUserId = null;
        $createdByType = 'user';
        $createdByOwnerId = null;
        if (!empty($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner') {
            $createdByType = 'owner';
            $createdByOwnerId = $_SESSION['owner_id'] ?? null;
        } else {
            $currentUserId = getCurrentUserId($pdo);
        }
        $createdProcesses = [];
        $errors = [];
        $copiedTemplatesCount = 0;
        
        // 开始事务
        $pdo->beginTransaction();
        
        try {
            // 为每个process ID和描述的组合创建process
            foreach ($processIds as $processId) {
                foreach ($descriptionIds as $descriptionId) {
                    // 检查是否已存在相同的process
                    $stmt = $pdo->prepare("SELECT id FROM process WHERE process_id = ? AND description_id = ?");
                    $stmt->execute([$processId, $descriptionId]);
                    if ($stmt->fetch()) {
                        $errors[] = "Process already exists for process_id $processId and description $descriptionId";
                        continue;
                    }
                    
                    // 插入process，包含company_id与创建者主体
                    $stmt = $pdo->prepare("INSERT INTO process (
                        process_id, description_id, currency_id, remove_word, 
                        replace_word_from, replace_word_to, remark, created_by, created_by_type, created_by_owner_id, dts_created, company_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    
                    $stmt->execute([
                        $processId,
                        $descriptionId,
                        $currencyId,
                        $removeWord,
                        $replaceWordFrom,
                        $replaceWordTo,
                        $remark,
                        $currentUserId,
                        $createdByType,
                        $createdByOwnerId,
                        date('Y-m-d H:i:s'),
                        $companyId
                    ]);
                    
                    $newProcessId = $pdo->lastInsertId();
                    
                    // 插入day关联
                    if (!empty($dayIds)) {
                        $stmt = $pdo->prepare("INSERT INTO process_day (process_id, day_id) VALUES (?, ?)");
                        foreach ($dayIds as $dayId) {
                            $stmt->execute([$newProcessId, $dayId]);
                        }
                    }
                    
                    $createdProcesses[] = [
                        'id' => $newProcessId,
                        'process_id' => $processId,
                        'description_id' => $descriptionId
                    ];
                    
                    // 如果提供了 copy_from，复制 data_capture_templates 到新 process
                    // 使用新创建的 process.id（整数）而不是 process.process_id（字符串）
                    error_log("Checking copy: copyFromProcessId=" . ($copyFromProcessId ?? 'empty') . ", sourceTemplates count=" . count($sourceTemplates));
                    if (!empty($copyFromProcessId) && !empty($sourceTemplates)) {
                        error_log("Starting to copy " . count($sourceTemplates) . " templates to new process.id=$newProcessId");
                        foreach ($sourceTemplates as $template) {
                            try {
                                // 准备插入新 template 的数据
                                $insertStmt = $pdo->prepare("
                                    INSERT INTO data_capture_templates (
                                        company_id, process_id, data_capture_id, row_index,
                                        id_product, product_type, formula_variant, parent_id_product,
                                        template_key, description, account_id, account_display,
                                        currency_id, currency_display, source_columns, formula_operators,
                                        input_method,
                                        batch_selection, columns_display, formula_display,
                                        last_source_value, last_processed_amount, updated_at, created_at
                                    ) VALUES (
                                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
                                    )
                                ");
                                
                                $insertStmt->execute([
                                    $companyId,
                                    $newProcessId, // 使用新 process 的 id（整数），而不是 process_id（字符串）
                                    $template['data_capture_id'],
                                    $template['row_index'],
                                    $template['id_product'],
                                    $template['product_type'],
                                    $template['formula_variant'],
                                    $template['parent_id_product'],
                                    $template['template_key'],
                                    $template['description'],
                                    $template['account_id'],
                                    $template['account_display'],
                                    $template['currency_id'],
                                    $template['currency_display'],
                                    $template['source_columns'],
                                    $template['formula_operators'],
                                    $template['input_method'],
                                    $template['batch_selection'],
                                    $template['columns_display'],
                                    $template['formula_display'],
                                    $template['last_source_value'],
                                    $template['last_processed_amount']
                                ]);
                                
                                $copiedTemplatesCount++;
                                error_log("Successfully copied template id=" . ($template['id'] ?? 'unknown') . " to new process.id=$newProcessId");
                            } catch (Exception $e) {
                                // 如果插入失败（例如唯一约束冲突），记录错误但继续
                                error_log("Failed to copy template for process.id $newProcessId: " . $e->getMessage());
                                error_log("Template data: " . json_encode($template));
                            }
                        }
                        error_log("Finished copying templates. Total copied: $copiedTemplatesCount");
                    }
                }
            }
            
            // 对已有 process 权限配置的用户自动追加新建的流程，保证默认可见
            assignNewProcessesToRestrictedUsers($pdo, $companyId, $createdProcesses);
            
            // 提交事务
            $pdo->commit();
            
            $message = "Successfully created " . count($createdProcesses) . " process(es)";
            if ($copiedTemplatesCount > 0) {
                $message .= " and copied " . $copiedTemplatesCount . " template(s)";
            } else if (!empty($copyFromProcessId)) {
                // 如果指定了 copy_from 但没有复制任何 templates，添加提示
                $message .= ". Note: No templates were copied from source process.";
            }
            if (!empty($errors)) {
                $message .= ". " . count($errors) . " process(es) were skipped due to conflicts.";
            }
            
            echo json_encode([
                'success' => true,
                'message' => $message,
                'created_processes' => $createdProcesses,
                'copied_templates_count' => $copiedTemplatesCount,
                'copy_from_used' => !empty($copyFromProcessId),
                'source_templates_found' => count($sourceTemplates),
                'errors' => $errors
            ]);
            
        } catch (Exception $e) {
            $pdo->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// 处理GET请求 - 返回表单数据
try {
    // 获取货币列表 - 根据 company_id 过滤
    $stmt = $pdo->prepare("SELECT id, code FROM currency WHERE company_id = ? ORDER BY code");
    $stmt->execute([$companyId]);
    $currencies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 获取process列表（用于datacapture form）- 根据 company_id 过滤
    $stmt = $pdo->prepare("
        SELECT 
            p.id as process_id,
            p.process_id as process_name,
            d.name as description_name
        FROM process p
        LEFT JOIN description d ON p.description_id = d.id
        WHERE p.status = 'active' AND p.company_id = ?
        ORDER BY p.process_id
    ");
    $stmt->execute([$companyId]);
    $processes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 获取描述列表 - 根据 company_id 过滤
    $stmt = $pdo->prepare("SELECT id, name FROM description WHERE company_id = ? ORDER BY name");
    $stmt->execute([$companyId]);
    $descriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 获取日期列表
    $stmt = $pdo->query("SELECT id, day_name FROM day ORDER BY id");
    $days = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 获取现有process列表（用于copy-from）- 根据 company_id 过滤
    $stmt = $pdo->prepare("
        SELECT 
            p.id as process_id,
            p.process_id as process_name,
            d.name as description_name
        FROM process p
        LEFT JOIN description d ON p.description_id = d.id
        WHERE p.company_id = ?
        ORDER BY p.dts_created DESC
        LIMIT 50
    ");
    $stmt->execute([$companyId]);
    $existingProcesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'currencies' => $currencies,
        'processes' => $processes,
        'descriptions' => $descriptions,
        'days' => $days,
        'existingProcesses' => $existingProcesses
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>