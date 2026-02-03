<?php
require_once 'config.php';
require_once 'permissions.php';

// 开启 session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// 获取当前登录用户的数值 ID
function getCurrentUserId(PDO $pdo) {
    // 检查是否是 owner 登录
    $isOwner = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner';
    $currentCompanyId = $_SESSION['company_id'] ?? null;
    
    // 如果不是 owner，尝试从 session 获取 user_id
    if (!$isOwner && isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
        $userId = (int)$_SESSION['user_id'];
        // 验证用户 ID 是否存在于数据库中
        $stmt = $pdo->prepare("SELECT id FROM user WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn()) {
            return $userId;
        }
    }
    
    // 如果 session 中有 login_id，尝试通过 login_id 查找（仅当不是 owner 时）
    if (!$isOwner && !empty($_SESSION['login_id'])) {
        $stmt = $pdo->prepare("SELECT id FROM user WHERE login_id = ? LIMIT 1");
        $stmt->execute([$_SESSION['login_id']]);
        $userId = $stmt->fetchColumn();
        if ($userId) {
            return (int)$userId;
        }
    }
    
    // 如果是 owner 或者找不到用户，尝试获取该公司下的第一个有效用户
    if ($currentCompanyId) {
        try {
            // 使用 user_company_map 来查找属于该公司的用户
            $stmt = $pdo->prepare("
                SELECT u.id 
                FROM user u
                INNER JOIN user_company_map ucm ON u.id = ucm.user_id
                WHERE ucm.company_id = ? AND u.status = 'active' 
                ORDER BY u.id ASC 
                LIMIT 1
            ");
            $stmt->execute([$currentCompanyId]);
            $fallbackId = $stmt->fetchColumn();
            if ($fallbackId) {
                return (int)$fallbackId;
            }
            
            // 如果该公司没有 active 用户，尝试获取该公司的任何用户
            $stmt = $pdo->prepare("
                SELECT u.id 
                FROM user u
                INNER JOIN user_company_map ucm ON u.id = ucm.user_id
                WHERE ucm.company_id = ? 
                ORDER BY u.id ASC 
                LIMIT 1
            ");
            $stmt->execute([$currentCompanyId]);
            $fallbackId = $stmt->fetchColumn();
            if ($fallbackId) {
                return (int)$fallbackId;
            }
        } catch (Exception $e) {
            error_log("getCurrentUserId error (company-specific): " . $e->getMessage());
        }
    }
    
    // 如果都找不到，尝试获取数据库中的第一个有效用户（全局）
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

// Handle different actions
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_process':
        getProcess();
        break;
    case 'update_process':
        updateProcess();
        break;
    case 'get_banks_by_country':
        getBanksByCountry();
        break;
    case 'save_country_banks':
        saveCountryBanks();
        break;
    default:
        getProcesses();
        break;
}

function getProcesses() {
    global $pdo;
    
    try {
        // Bank 类别：从 bank_process 表获取数据，不影响 Gambling 的 process 表
        if (isset($_GET['permission']) && $_GET['permission'] === 'Bank') {
            getBankProcesses();
            return;
        }

        // 获取 company_id，优先从 URL 参数获取，否则从 session 获取
        $requested_company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : ($_SESSION['company_id'] ?? null);

        if (!$requested_company_id) {
            echo json_encode([
                'success' => false,
                'error' => '缺少公司信息'
            ]);
            return;
        }

        // 验证当前用户是否有权限访问此 company_id
        $current_user_id = $_SESSION['user_id'] ?? null;
        $current_user_role = $_SESSION['role'] ?? '';

        $has_permission = false;
        if ($current_user_role === 'owner') {
            // Owner 可以访问自己拥有的所有公司
            $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND owner_id = ?");
            $stmt->execute([$requested_company_id, $owner_id]);
            if ($stmt->fetchColumn() > 0) {
                $has_permission = true;
            }
        } else {
            // 普通用户只能访问其关联的公司
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_company_map WHERE user_id = ? AND company_id = ?");
            $stmt->execute([$current_user_id, $requested_company_id]);
            if ($stmt->fetchColumn() > 0) {
                $has_permission = true;
            }
        }

        if (!$has_permission) {
            echo json_encode([
                'success' => false,
                'error' => '您没有权限访问此公司的数据'
            ]);
            return;
        }
        
        $targetCompanyId = $requested_company_id; // 使用验证后的 company_id
        
        $searchTerm = $_GET['search'] ?? '';
        $showInactive = isset($_GET['showInactive']) && $_GET['showInactive'] == '1';
        $showAll = isset($_GET['showAll']) && $_GET['showAll'] == '1';
        
        $sql = "SELECT 
                    p.id,
                    p.process_id,
                    d.name as description_name,
                    c.code as currency_code,
                    p.remove_word,
                    GROUP_CONCAT(day.day_name ORDER BY day.id SEPARATOR ',') as day_names,
                    p.replace_word_from,
                    p.replace_word_to,
                    p.remark,
                    p.dts_modified,
                    COALESCE(u_modified.login_id, o_modified.owner_code) as modified_by_login,
                    p.dts_created,
                    COALESCE(u_created.login_id, o_created.owner_code) as created_by_login,
                    p.status
                FROM process p
                LEFT JOIN description d ON p.description_id = d.id
                LEFT JOIN currency c ON p.currency_id = c.id
                LEFT JOIN process_day pd ON p.id = pd.process_id
                LEFT JOIN day ON pd.day_id = day.id
                LEFT JOIN user u_modified ON p.modified_by = u_modified.id AND (p.modified_by_type IS NULL OR p.modified_by_type = 'user')
                LEFT JOIN owner o_modified ON p.modified_by_owner_id = o_modified.id AND p.modified_by_type = 'owner'
                LEFT JOIN user u_created ON p.created_by = u_created.id
                LEFT JOIN owner o_created ON p.created_by_owner_id = o_created.id
                WHERE 1=1";
        
        $conditions = [];
        $params = [];
        
        // 添加 company_id 过滤
        $conditions[] = "p.company_id = ?";
        $params[] = $targetCompanyId;
        
        if (!empty($searchTerm)) {
            $conditions[] = "(p.process_id LIKE ? OR d.name LIKE ?)";
            $params[] = "%$searchTerm%";
            $params[] = "%$searchTerm%";
        }
        
        // 根据 showAll 和 showInactive 参数过滤状态
        if ($showAll) {
            // Show All：显示所有 active 流程（不包含 inactive），但前端不分页
            $conditions[] = "p.status = 'active'";
        } elseif ($showInactive) {
            // 勾选 showInactive 时，只显示 inactive 流程
            $conditions[] = "p.status = 'inactive'";
        } else {
            // 未勾选时，只显示 active 流程（分页）
            $conditions[] = "p.status = 'active'";
        }
        
        if (!empty($conditions)) {
            $baseSql = $sql . ' AND ' . implode(' AND ', $conditions);
        } else {
            $baseSql = $sql;
        }
        
        // 权限过滤 - 在添加 GROUP BY 之前
        list($baseSql, $params) = filterProcessesByPermissions($pdo, $baseSql, $params);
        
        // 添加 GROUP BY 和 ORDER BY
        $baseSql .= " GROUP BY p.id ORDER BY p.dts_created DESC";
        $sql = $baseSql;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $processes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 处理数据格式以匹配前端期望
        $formattedProcesses = [];
        foreach ($processes as $process) {
            $formattedProcesses[] = [
                'id' => $process['id'],
                'process_name' => $process['process_id'],
                'description' => $process['description_name'],
                'status' => $process['status'],
                'currency' => $process['currency_code'],
                'day_use' => $process['day_names'],
                'dts_modified' => $process['dts_modified'],
                'modified_by' => $process['modified_by_login'],
                'dts_created' => $process['dts_created'],
                'created_by' => $process['created_by_login'],
                'remove_word' => $process['remove_word'],
                'replace_word' => $process['replace_word_from'] . ' == ' . $process['replace_word_to'],
                'remarks' => $process['remark']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'data' => $formattedProcesses
        ]);
        
    } catch (PDOException $e) {
        error_log("Error fetching processes: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => 'Failed to fetch processes: ' . $e->getMessage()
        ]);
    }
}

function getProcess() {
    global $pdo;
    
    try {
        // Bank 类别：从 bank_process 表获取单条记录
        if (isset($_GET['permission']) && $_GET['permission'] === 'Bank') {
            getBankProcess();
            return;
        }

        // 获取当前用户的 company_id
        $currentCompanyId = $_SESSION['company_id'] ?? null;
        
        if (!$currentCompanyId) {
            echo json_encode([
                'success' => false,
                'error' => 'User company_id not found in session'
            ]);
            return;
        }
        
        $processId = $_GET['id'] ?? '';
        
        if (empty($processId)) {
            echo json_encode([
                'success' => false,
                'error' => 'Process ID is required'
            ]);
            return;
        }
        
        $base = "SELECT 
                    p.id,
                    p.process_id,
                    p.description_id,
                    p.currency_id,
                    c.company_id AS currency_company_id,
                    p.remove_word,
                    p.replace_word_from,
                    p.replace_word_to,
                    p.remark,
                    p.status,
                    p.dts_modified,
                    p.dts_created,
                    d.name as description_name,
                    c.code as currency_code,
                    GROUP_CONCAT(pd.day_id ORDER BY pd.day_id SEPARATOR ',') as day_ids,
                    GROUP_CONCAT(day.day_name ORDER BY day.id SEPARATOR ',') as day_names,
                    COALESCE(u_modified.login_id, o_modified.owner_code) as modified_by_login,
                    COALESCE(u_created.login_id, o_created.owner_code) as created_by_login
                FROM process p
                LEFT JOIN description d ON p.description_id = d.id
                LEFT JOIN currency c ON p.currency_id = c.id
                LEFT JOIN process_day pd ON p.id = pd.process_id
                LEFT JOIN day ON pd.day_id = day.id
                LEFT JOIN user u_modified ON p.modified_by = u_modified.id AND (p.modified_by_type IS NULL OR p.modified_by_type = 'user')
                LEFT JOIN owner o_modified ON p.modified_by_owner_id = o_modified.id AND p.modified_by_type = 'owner'
                LEFT JOIN user u_created ON p.created_by = u_created.id
                LEFT JOIN owner o_created ON p.created_by_owner_id = o_created.id
                WHERE p.id = ? AND p.company_id = ?
                GROUP BY p.id";

        // 权限过滤
        list($sql, $params) = filterProcessesByPermissions($pdo, $base, [$processId, $currentCompanyId]);
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $process = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($process) {
            // 检查 currency 是否属于当前公司
            $currencyId = null;
            if ($process['currency_id'] && $process['currency_company_id'] == $currentCompanyId) {
                $currencyId = $process['currency_id'];
            }
            
            // 格式化数据以匹配前端期望
            $formattedProcess = [
                'id' => $process['id'],
                'process_name' => $process['process_id'],
                'process_id' => $process['process_id'],
                'description_id' => $process['description_id'],
                'description_names' => $process['description_name'] ? [$process['description_name']] : [],
                'currency_id' => $currencyId, // 只有属于当前公司的 currency 才返回 ID
                'currency_code' => $process['currency_code'], // 返回货币代码用于自动匹配
                'currency_warning' => $process['currency_id'] && $process['currency_company_id'] != $currentCompanyId ? 'Currency does not belong to current company' : null,
                'status' => $process['status'],
                'remove_word' => $process['remove_word'],
                'replace_word_from' => $process['replace_word_from'],
                'replace_word_to' => $process['replace_word_to'],
                'replace_word' => $process['replace_word_from'] . ' == ' . $process['replace_word_to'],
                'remarks' => $process['remark'],
                'day_use' => $process['day_ids'],
                'day_names' => $process['day_names'],
                'dts_modified' => $process['dts_modified'],
                'modified_by' => $process['modified_by_login'],
                'dts_created' => $process['dts_created'],
                'created_by' => $process['created_by_login']
            ];
            
            echo json_encode([
                'success' => true,
                'data' => $formattedProcess
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Process not found'
            ]);
        }
        
    } catch (PDOException $e) {
        error_log("Error fetching process: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => 'Failed to fetch process: ' . $e->getMessage()
        ]);
    }
}

function updateProcess() {
    global $pdo;
    
    try {
        // Bank 类别：更新 bank_process 表
        if (isset($_POST['permission']) && $_POST['permission'] === 'Bank') {
            updateBankProcess();
            return;
        }

        // 获取当前用户的 company_id
        $currentCompanyId = $_SESSION['company_id'] ?? null;
        
        if (!$currentCompanyId) {
            echo json_encode([
                'success' => false,
                'error' => 'User company_id not found in session'
            ]);
            return;
        }
        
        $id = $_POST['id'] ?? '';
        $processId = $_POST['process_name'] ?? '';  // 前端发送的是 process_name，但数据库字段是 process_id
        $description = $_POST['description'] ?? '';
        $currencyId = $_POST['currency_id'] ?? '';
        $removeWord = $_POST['remove_word'] ?? '';
        $replaceWordFrom = $_POST['replace_word_from'] ?? '';
        $replaceWordTo = $_POST['replace_word_to'] ?? '';
        $remark = $_POST['remark'] ?? '';
        $status = $_POST['status'] ?? 'active';
        $dayUse = $_POST['day_use'] ?? '';
        $selectedDescriptions = $_POST['selected_descriptions'] ?? '';
        
        if (empty($id)) {
            echo json_encode([
                'success' => false,
                'error' => 'Process ID is required'
            ]);
            return;
        }
        
        // 验证 process 是否属于当前用户的 company_id
        $checkStmt = $pdo->prepare("SELECT id, company_id FROM process WHERE id = ?");
        $checkStmt->execute([$id]);
        $process = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$process) {
            echo json_encode([
                'success' => false,
                'error' => 'Process not found'
            ]);
            return;
        }
        
        if ($process['company_id'] != $currentCompanyId) {
            echo json_encode([
                'success' => false,
                'error' => 'You do not have permission to update this process'
            ]);
            return;
        }
        
        // 对于编辑操作，只验证process_name和currency_id，description是只读的
        if (empty($processId) || empty($currencyId)) {
            echo json_encode([
                'success' => false,
                'error' => 'Process Name and Currency are required'
            ]);
            return;
        }
        
        // 开始事务
        $pdo->beginTransaction();
        
        try {
            // 检查是否是 owner 登录
            $isOwner = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner';
            $modifiedByType = 'user';
            $modifiedByOwnerId = null;
            $currentUserId = null;
            
            if ($isOwner) {
                // 如果是 owner，设置 owner 相关信息
                $modifiedByType = 'owner';
                $modifiedByOwnerId = $_SESSION['owner_id'] ?? null;
            } else {
                // 如果是普通用户，获取用户 ID
                $currentUserId = getCurrentUserId($pdo);
            }
            
            // 更新process基本信息
            $updateSql = "UPDATE process SET 
                            process_id = ?,
                            currency_id = ?,
                            remove_word = ?,
                            replace_word_from = ?,
                            replace_word_to = ?,
                            remark = ?,
                            status = ?,
                            dts_modified = NOW(),
                            modified_by = ?,
                            modified_by_type = ?,
                            modified_by_owner_id = ?
                          WHERE id = ? AND company_id = ?";
            
            $stmt = $pdo->prepare($updateSql);
            $stmt->execute([
                $processId,
                $currencyId,
                $removeWord,
                $replaceWordFrom,
                $replaceWordTo,
                $remark,
                $status,
                $currentUserId,
                $modifiedByType,
                $modifiedByOwnerId,
                $id,
                $currentCompanyId
            ]);
            
            // 处理选中的描述 - 只取第一个描述
            if (!empty($selectedDescriptions)) {
                $selectedDescriptionsArray = json_decode($selectedDescriptions, true);
                if (is_array($selectedDescriptionsArray) && !empty($selectedDescriptionsArray)) {
                    // 只取第一个描述
                    $firstDescription = $selectedDescriptionsArray[0];
                    
                    // 获取描述的ID - 添加 company_id 过滤以确保选择正确的描述
                    $stmt = $pdo->prepare("SELECT id FROM description WHERE name = ? AND company_id = ? LIMIT 1");
                    $stmt->execute([$firstDescription, $currentCompanyId]);
                    $descriptionId = $stmt->fetchColumn();
                    
                    // 更新process表的description_id字段
                    if ($descriptionId) {
                        $updateDescSql = "UPDATE process SET description_id = ? WHERE id = ?";
                        $stmt = $pdo->prepare($updateDescSql);
                        $stmt->execute([$descriptionId, $id]);
                    }
                }
            }
            
            // 更新day关联
            // 先删除现有的day关联
            $deleteDaySql = "DELETE FROM process_day WHERE process_id = ?";
            $stmt = $pdo->prepare($deleteDaySql);
            $stmt->execute([$id]);
            
            // 添加新的day关联
            if (!empty($dayUse)) {
                $dayIds = explode(',', $dayUse);
                $insertDaySql = "INSERT INTO process_day (process_id, day_id) VALUES (?, ?)";
                $stmt = $pdo->prepare($insertDaySql);
                
                foreach ($dayIds as $dayId) {
                    $dayId = trim($dayId);
                    if (!empty($dayId)) {
                        $stmt->execute([$id, $dayId]);
                    }
                }
            }
            
            // 提交事务
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Process updated successfully!'
            ]);
            
        } catch (Exception $e) {
            // 回滚事务
            $pdo->rollback();
            throw $e;
        }
        
    } catch (PDOException $e) {
        error_log("Error updating process: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => 'Failed to update process: ' . $e->getMessage()
        ]);
    } catch (Exception $e) {
        error_log("Error updating process: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => 'Failed to update process: ' . $e->getMessage()
        ]);
    }
}

/**
 * Bank 类别：从 bank_process 表获取列表，不影响 Gambling 的 process 表
 */
function getBankProcesses() {
    global $pdo;
    try {
        $requested_company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : ($_SESSION['company_id'] ?? null);
        if (!$requested_company_id) {
            echo json_encode(['success' => false, 'error' => '缺少公司信息']);
            return;
        }
        $current_user_id = $_SESSION['user_id'] ?? null;
        $current_user_role = $_SESSION['role'] ?? '';
        $has_permission = false;
        if ($current_user_role === 'owner') {
            $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND owner_id = ?");
            $stmt->execute([$requested_company_id, $owner_id]);
            if ($stmt->fetchColumn() > 0) $has_permission = true;
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_company_map WHERE user_id = ? AND company_id = ?");
            $stmt->execute([$current_user_id, $requested_company_id]);
            if ($stmt->fetchColumn() > 0) $has_permission = true;
        }
        if (!$has_permission) {
            echo json_encode(['success' => false, 'error' => '您没有权限访问此公司的数据']);
            return;
        }
        $targetCompanyId = $requested_company_id;
        $searchTerm = $_GET['search'] ?? '';
        $showInactive = isset($_GET['showInactive']) && $_GET['showInactive'] == '1';
        $showAll = isset($_GET['showAll']) && $_GET['showAll'] == '1';
        $waiting = isset($_GET['waiting']) && $_GET['waiting'] == '1';

        $sql = "SELECT 
                    bp.id,
                    bp.country,
                    bp.bank,
                    bp.type,
                    bp.name,
                    bp.card_merchant_id,
                    bp.customer_id,
                    bp.contract,
                    bp.insurance,
                    bp.cost,
                    bp.price,
                    bp.profit,
                    bp.profit_sharing,
                    bp.day_start,
                    bp.day_end,
                    bp.status,
                    bp.dts_modified,
                    a_cm.name as card_merchant_name,
                    a_cm.account_id as card_merchant_account_id,
                    a_cust.account_id as customer_account
                FROM bank_process bp
                LEFT JOIN account a_cm ON bp.card_merchant_id = a_cm.id
                LEFT JOIN account a_cust ON bp.customer_id = a_cust.id
                WHERE bp.company_id = ?";
        $params = [$targetCompanyId];
        if (!empty($searchTerm)) {
            $sql .= " AND (bp.country LIKE ? OR bp.bank LIKE ? OR bp.type LIKE ? OR bp.name LIKE ?)";
            $term = "%$searchTerm%";
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }
        if ($waiting) {
            $sql .= " AND bp.status = 'waiting'";
        } elseif ($showAll) {
            $sql .= " AND bp.status = 'active'";
        } elseif ($showInactive) {
            $sql .= " AND bp.status = 'inactive'";
        } else {
            $sql .= " AND bp.status = 'active'";
        }
        $sql .= " ORDER BY bp.dts_created DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formattedProcesses = [];
        foreach ($rows as $r) {
            $formattedProcesses[] = [
                'id' => $r['id'],
                'supplier' => $r['name'] ?? '',
                'country' => $r['country'] ?? '',
                'bank' => $r['bank'] ?? '',
                'types' => $r['type'] ?? '',
                'card_lower' => $r['card_merchant_account_id'] ?? '',
                'contract' => $r['contract'] ?? '',
                'insurance' => $r['insurance'] ?? '',
                'customer' => $r['customer_account'] ?? '',
                'cost' => $r['cost'],
                'price' => $r['price'],
                'profit' => $r['profit'],
                'status' => $r['status'],
                'date' => $r['day_start'] ?? '',
                'day_start' => $r['day_start'] ?? null,
                'day_end' => $r['day_end'] ?? null,
            ];
        }
        echo json_encode(['success' => true, 'data' => $formattedProcesses]);
    } catch (PDOException $e) {
        error_log("getBankProcesses: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Failed to fetch bank processes: ' . $e->getMessage()]);
    }
}

/**
 * Bank 类别：从 bank_process 表获取单条记录（编辑用）
 */
function getBankProcess() {
    global $pdo;
    try {
        $currentCompanyId = $_SESSION['company_id'] ?? null;
        if (!$currentCompanyId) {
            echo json_encode(['success' => false, 'error' => 'User company_id not found in session']);
            return;
        }
        $processId = $_GET['id'] ?? '';
        if (empty($processId)) {
            echo json_encode(['success' => false, 'error' => 'Process ID is required']);
            return;
        }
        $stmt = $pdo->prepare("SELECT 
                bp.id, bp.country, bp.bank, bp.type, bp.name,
                bp.card_merchant_id, bp.customer_id, bp.contract, bp.insurance,
                bp.cost, bp.price, bp.profit, bp.profit_sharing, bp.day_start, bp.day_end, bp.status,
                bp.dts_modified, bp.dts_created,
                a_cm.name as card_merchant_name, a_cust.account_id as customer_account, a_cust.name as customer_name
            FROM bank_process bp
            LEFT JOIN account a_cm ON bp.card_merchant_id = a_cm.id
            LEFT JOIN account a_cust ON bp.customer_id = a_cust.id
            WHERE bp.id = ? AND bp.company_id = ?");
        $stmt->execute([$processId, $currentCompanyId]);
        $process = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$process) {
            echo json_encode(['success' => false, 'error' => 'Process not found']);
            return;
        }
        $formatted = [
            'id' => $process['id'],
            'process_name' => $process['name'] ?: $process['bank'],
            'country' => $process['country'],
            'bank' => $process['bank'],
            'type' => $process['type'],
            'name' => $process['name'],
            'card_merchant_id' => $process['card_merchant_id'],
            'customer_id' => $process['customer_id'],
            'card_merchant_name' => $process['card_merchant_name'],
            'customer_name' => $process['customer_name'],
            'customer_account' => $process['customer_account'] ?? '',
            'contract' => $process['contract'],
            'insurance' => $process['insurance'],
            'cost' => $process['cost'],
            'price' => $process['price'],
            'profit' => $process['profit'],
            'profit_sharing' => $process['profit_sharing'],
            'day_start' => $process['day_start'],
            'day_end' => $process['day_end'] ?? null,
            'status' => $process['status'],
            'dts_modified' => $process['dts_modified'],
            'dts_created' => $process['dts_created'],
        ];
        echo json_encode(['success' => true, 'data' => $formatted]);
    } catch (PDOException $e) {
        error_log("getBankProcess: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Failed to fetch bank process: ' . $e->getMessage()]);
    }
}

/**
 * Bank 类别：更新 bank_process 表
 */
function updateBankProcess() {
    global $pdo;
    try {
        $currentCompanyId = $_SESSION['company_id'] ?? null;
        if (!$currentCompanyId) {
            echo json_encode(['success' => false, 'error' => 'User company_id not found in session']);
            return;
        }
        $id = $_POST['id'] ?? '';
        if (empty($id)) {
            echo json_encode(['success' => false, 'error' => 'Process ID is required']);
            return;
        }
        $checkStmt = $pdo->prepare("SELECT id FROM bank_process WHERE id = ? AND company_id = ?");
        $checkStmt->execute([$id, $currentCompanyId]);
        if (!$checkStmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Process not found or no permission']);
            return;
        }
        $country = $_POST['country'] ?? null;
        $bank = $_POST['bank'] ?? null;
        $type = $_POST['type'] ?? null;
        $name = $_POST['name'] ?? null;
        $card_merchant_id = !empty($_POST['card_merchant_id']) ? (int)$_POST['card_merchant_id'] : null;
        $customer_id = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
        $contract = $_POST['contract'] ?? null;
        $insurance = isset($_POST['insurance']) && $_POST['insurance'] !== '' ? (float)$_POST['insurance'] : null;
        $cost = isset($_POST['cost']) && $_POST['cost'] !== '' ? (float)$_POST['cost'] : null;
        $price = isset($_POST['price']) && $_POST['price'] !== '' ? (float)$_POST['price'] : null;
        $profit = isset($_POST['profit']) && $_POST['profit'] !== '' ? (float)$_POST['profit'] : null;
        $profit_sharing = $_POST['profit_sharing'] ?? null;
        $day_start = $_POST['day_start'] ?? null;
        $day_end = $_POST['day_end'] ?? null;
        $day_end = ($day_end !== null && $day_end !== '') ? $day_end : null;
        $status = $_POST['status'] ?? 'active';
        if (!in_array($status, ['active', 'inactive', 'waiting'], true)) {
            $status = 'active';
        }
        $isOwner = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner';
        $modifiedByType = $isOwner ? 'owner' : 'user';
        $modifiedByOwnerId = $isOwner ? ($_SESSION['owner_id'] ?? null) : null;
        $currentUserId = $isOwner ? null : getCurrentUserId($pdo);
        $stmt = $pdo->prepare("UPDATE bank_process SET 
            country=?, bank=?, type=?, name=?, card_merchant_id=?, customer_id=?,
            contract=?, insurance=?, cost=?, price=?, profit=?, profit_sharing=?, day_start=?, day_end=?, status=?,
            dts_modified=NOW(), modified_by=?, modified_by_type=?, modified_by_owner_id=?
            WHERE id=? AND company_id=?");
        $stmt->execute([
            $country, $bank, $type, $name, $card_merchant_id, $customer_id,
            $contract, $insurance, $cost, $price, $profit, $profit_sharing, $day_start, $day_end, $status,
            $currentUserId, $modifiedByType, $modifiedByOwnerId, $id, $currentCompanyId
        ]);
        if ($country !== '' && $bank !== '') {
            try {
                $ins = $pdo->prepare("INSERT IGNORE INTO country_bank (company_id, country, bank) VALUES (?, ?, ?)");
                $ins->execute([$currentCompanyId, $country, $bank]);
            } catch (Exception $e) { /* ignore */ }
        }
        echo json_encode(['success' => true, 'message' => 'Process updated successfully!']);
    } catch (Exception $e) {
        error_log("updateBankProcess: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Failed to update process: ' . $e->getMessage()]);
    }
}

/**
 * 按 Country 获取该 Country 下的 Bank 列表（用于 Bank 下拉联动）
 */
function getBanksByCountry() {
    global $pdo;
    try {
        $companyId = $_SESSION['company_id'] ?? null;
        if (!$companyId) {
            echo json_encode(['success' => false, 'error' => 'Company not found']);
            return;
        }
        $country = isset($_GET['country']) ? trim((string)$_GET['country']) : '';
        if ($country === '') {
            echo json_encode(['success' => true, 'data' => []]);
            return;
        }
        $stmt = $pdo->prepare("SELECT bank FROM country_bank WHERE company_id = ? AND country = ? ORDER BY bank ASC");
        $stmt->execute([$companyId, $country]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['success' => true, 'data' => array_values($rows)]);
    } catch (Exception $e) {
        error_log("getBanksByCountry: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage(), 'data' => []]);
    }
}

/**
 * 保存 Country-Bank 关联（确保这些 bank 都 under 当前 country）
 */
function saveCountryBanks() {
    global $pdo;
    try {
        $companyId = $_SESSION['company_id'] ?? null;
        if (!$companyId) {
            echo json_encode(['success' => false, 'error' => 'Company not found']);
            return;
        }
        $country = isset($_POST['country']) ? trim((string)$_POST['country']) : '';
        $banks = isset($_POST['banks']) ? $_POST['banks'] : [];
        if (!is_array($banks)) $banks = [];
        if ($country === '') {
            echo json_encode(['success' => true, 'message' => 'No country']);
            return;
        }
        foreach ($banks as $bank) {
            $bank = trim((string)$bank);
            if ($bank === '') continue;
            $stmt = $pdo->prepare("INSERT IGNORE INTO country_bank (company_id, country, bank) VALUES (?, ?, ?)");
            $stmt->execute([$companyId, $country, $bank]);
        }
        echo json_encode(['success' => true, 'message' => 'Saved']);
    } catch (Exception $e) {
        error_log("saveCountryBanks: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
?>