<?php
session_start();
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Get JSON input
$json = file_get_contents('php://input');
$data = json_decode($json, true);

$action = $data['action'] ?? '';

// 检查用户是否已登录（对于需要权限的操作）
if (in_array($action, ['create', 'update', 'delete'])) {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'User not logged in', 'data' => null]);
        exit;
    }
    
    // 检查C168权限（用于二级密码修改权限判断）
    $user_role = strtolower($_SESSION['role'] ?? '');
    $company_id = $_SESSION['company_id'] ?? null;
    $company_code = strtoupper($_SESSION['company_code'] ?? '');
    
    $isOwnerOrAdmin = in_array($user_role, ['owner', 'admin'], true);
    $isC168ByCode = ($company_code === 'C168');
    $isC168ById = isC168Company($pdo, $company_id);
    $hasC168Context = ($isC168ByCode || $isC168ById);
}

/**
 * 将 ID 数组标准化为唯一的整型列表
 */
function normalizeIds(array $ids): array
{
    $normalized = [];
    foreach ($ids as $id) {
        if ($id === null || $id === '') {
            continue;
        }
        $normalized[] = (int)$id;
    }
    return array_values(array_unique($normalized));
}

/**
 * 根据给定 SQL 查询返回整型 ID 列
 */
function fetchIds(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return normalizeIds($stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * 为 IN 语句生成占位符
 */
function buildInPlaceholders(int $count): string
{
    return implode(',', array_fill(0, $count, '?'));
}

/**
 * 删除指定表中匹配 ID 的记录
 */
function deleteByIds(PDO $pdo, string $table, string $column, array $ids): void
{
    $ids = normalizeIds($ids);
    if (empty($ids)) {
        return;
    }
    
    $placeholders = buildInPlaceholders(count($ids));
    $sql = sprintf("DELETE FROM `%s` WHERE `%s` IN (%s)", $table, $column, $placeholders);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
}

/**
 * 检查公司是否为 C168（用于二级密码等权限判断）
 */
function isC168Company(PDO $pdo, $company_id): bool {
    if (!$company_id) return false;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND UPPER(company_id) = 'C168'");
        $stmt->execute([$company_id]);
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * 根据 owner_id 获取 owner 及其公司列表（含到期日）
 */
function getOwnerWithCompanies(PDO $pdo, $owner_id) {
    $stmt = $pdo->prepare("
        SELECT o.id, o.owner_code, o.name, o.email, o.created_by,
               GROUP_CONCAT(c.company_id ORDER BY c.company_id SEPARATOR ', ') as companies
        FROM owner o
        LEFT JOIN company c ON o.id = c.owner_id
        WHERE o.id = ?
        GROUP BY o.id
    ");
    $stmt->execute([$owner_id]);
    $owner = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$owner) return null;
    $stmt2 = $pdo->prepare("SELECT company_id, expiration_date FROM company WHERE owner_id = ? ORDER BY company_id");
    $stmt2->execute([$owner_id]);
    $owner['companies_full'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    return $owner;
}

/**
 * 标准 JSON 响应：success, message, data
 */
function jsonResponse($success, $message, $data = null, $httpCode = null) {
    if ($httpCode !== null) {
        http_response_code($httpCode);
    }
    echo json_encode([
        'success' => (bool) $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
}

try {
    switch($action) {
        case 'create':
            // Create new owner
            $owner_code = strtoupper(trim($data['owner_code'] ?? ''));
            $name = trim($data['name'] ?? '');
            $email = strtolower(trim($data['email'] ?? ''));
            $password = $data['password'] ?? '';
            $secondary_password = $data['secondary_password'] ?? '';
            $companies = $data['companies'] ?? '';
            
            // Validate required fields
            if (empty($owner_code) || empty($name) || empty($email) || empty($password) || empty($secondary_password)) {
                echo json_encode(['success' => false, 'message' => 'All fields are required', 'data' => null]);
                exit;
            }
            
            // 验证二级密码：必须是6位数字
            if (!preg_match('/^\d{6}$/', $secondary_password)) {
                echo json_encode(['success' => false, 'message' => 'Secondary password must be exactly 6 digits', 'data' => null]);
                exit;
            }
            
            // Hash passwords
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $hashed_secondary_password = password_hash($secondary_password, PASSWORD_DEFAULT);
            
            // Start transaction
            $pdo->beginTransaction();
            
            try {
                // Insert owner
                $stmt = $pdo->prepare("INSERT INTO owner (owner_code, name, email, password, secondary_password, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$owner_code, $name, $email, $hashed_password, $hashed_secondary_password, $_SESSION['login_id'] ?? 'system']);
                
                $owner_id = $pdo->lastInsertId();
                
                // Insert companies if any
                if (!empty($companies)) {
                    // 尝试解析 JSON 格式的 companies 数据
                    $companies_data = json_decode($companies, true);
                    
                    if (json_last_error() === JSON_ERROR_NONE && is_array($companies_data)) {
                        // 新格式：JSON 数组，包含 company_id、expiration_date、permissions
                        $stmt = $pdo->prepare("INSERT INTO company (company_id, owner_id, created_by, expiration_date, permissions) VALUES (?, ?, ?, ?, ?)");
                        
                        foreach ($companies_data as $company) {
                            $company_id = strtoupper(trim($company['company_id'] ?? $company));
                            $expiration_date = !empty($company['expiration_date']) ? $company['expiration_date'] : null;
                            $permissions = (isset($company['permissions']) && is_array($company['permissions'])) ? json_encode($company['permissions']) : null;
                            
                            if (!empty($company_id)) {
                                $stmt->execute([$company_id, $owner_id, $_SESSION['login_id'] ?? 'system', $expiration_date, $permissions]);
                            }
                        }
                    } else {
                        // 旧格式：逗号分隔的字符串（向后兼容）
                        $company_ids = array_map('trim', explode(',', $companies));
                        $stmt = $pdo->prepare("INSERT INTO company (company_id, owner_id, created_by, expiration_date) VALUES (?, ?, ?, ?)");
                        
                        foreach ($company_ids as $company_id) {
                            if (!empty($company_id)) {
                                $stmt->execute([strtoupper($company_id), $owner_id, $_SESSION['login_id'] ?? 'system', null]);
                            }
                        }
                    }
                }
                
                $pdo->commit();
                
                $owner = getOwnerWithCompanies($pdo, $owner_id);
                echo json_encode([
                    'success' => true,
                    'message' => 'Owner created successfully',
                    'data' => $owner
                ]);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
            
        case 'update':
            // Update existing owner
            $id = $data['id'] ?? 0;
            $name = trim($data['name'] ?? '');
            $email = strtolower(trim($data['email'] ?? ''));
            $password = $data['password'] ?? '';
            $secondary_password = $data['secondary_password'] ?? '';
            $companies = $data['companies'] ?? '';
            
            if (empty($id) || empty($name) || empty($email)) {
                echo json_encode(['success' => false, 'message' => 'Required fields are missing', 'data' => null]);
                exit;
            }
            
            // 如果提供了二级密码，验证格式（只有C168的owner/admin可以修改）
            if (!empty($secondary_password)) {
                if (!$hasC168Context || !$isOwnerOrAdmin) {
                    echo json_encode(['success' => false, 'message' => 'Only C168 owner/admin can modify secondary password', 'data' => null]);
                    exit;
                }
                
                // 验证二级密码：必须是6位数字
                if (!preg_match('/^\d{6}$/', $secondary_password)) {
                    echo json_encode(['success' => false, 'message' => 'Secondary password must be exactly 6 digits', 'data' => null]);
                    exit;
                }
            }
            
            // Start transaction
            $pdo->beginTransaction();
            
            try {
                // Update owner - 根据提供的字段构建UPDATE语句
                $updateFields = [];
                $updateValues = [];
                
                $updateFields[] = "name = ?";
                $updateValues[] = $name;
                
                $updateFields[] = "email = ?";
                $updateValues[] = $email;
                
                if (!empty($password)) {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $updateFields[] = "password = ?";
                    $updateValues[] = $hashed_password;
                }
                
                // 只有C168的owner/admin可以修改二级密码
                if (!empty($secondary_password) && $hasC168Context && $isOwnerOrAdmin) {
                    $hashed_secondary_password = password_hash($secondary_password, PASSWORD_DEFAULT);
                    $updateFields[] = "secondary_password = ?";
                    $updateValues[] = $hashed_secondary_password;
                }
                
                $updateValues[] = $id;
                $sql = "UPDATE owner SET " . implode(', ', $updateFields) . " WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($updateValues);
                
                // Get existing companies for this owner
                $stmt = $pdo->prepare("SELECT id, company_id FROM company WHERE owner_id = ?");
                $stmt->execute([$id]);
                $existing_companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $existing_company_ids = array_map(function($c) { return strtoupper($c['company_id']); }, $existing_companies);
                
                // Get new company IDs from input
                $new_companies_data = [];
                if (!empty($companies)) {
                    // 尝试解析 JSON 格式
                    $companies_data = json_decode($companies, true);
                    
                    if (json_last_error() === JSON_ERROR_NONE && is_array($companies_data)) {
                        // 新格式：JSON 数组
                        foreach ($companies_data as $company) {
                            $company_id = strtoupper(trim($company['company_id'] ?? $company));
                            if (!empty($company_id)) {
                                $new_companies_data[] = [
                                    'company_id' => $company_id,
                                    'expiration_date' => !empty($company['expiration_date']) ? $company['expiration_date'] : null,
                                    'permissions' => (isset($company['permissions']) && is_array($company['permissions'])) ? $company['permissions'] : []
                                ];
                            }
                        }
                    } else {
                        // 旧格式：逗号分隔的字符串（向后兼容）
                        $company_ids = array_map(function($c) { return strtoupper(trim($c)); }, explode(',', $companies));
                        $company_ids = array_filter($company_ids, function($c) { return !empty($c); });
                        foreach ($company_ids as $company_id) {
                            $new_companies_data[] = [
                                'company_id' => $company_id,
                                'expiration_date' => null,
                                'permissions' => []
                            ];
                        }
                    }
                }
                $new_company_ids = array_column($new_companies_data, 'company_id');
                
                // Find companies to delete (existing but not in new list)
                $companies_to_delete = [];
                foreach ($existing_companies as $existing) {
                    $company_id_upper = strtoupper($existing['company_id']);
                    if (!in_array($company_id_upper, $new_company_ids)) {
                        $companies_to_delete[] = $existing;
                    }
                }
                
                // 级联删除公司及其相关数据
                if (!empty($companies_to_delete)) {
                    $delete_db_ids = normalizeIds(array_column($companies_to_delete, 'id'));
                    
                    if (!empty($delete_db_ids)) {
                        $companyPlaceholders = buildInPlaceholders(count($delete_db_ids));
                        
                        // 1. account 及其关联的 transactions
                        // account 表已不再直接持有 company_id，通过 account_company 关系表获取账户
                        $accountStmt = $pdo->prepare("
                            SELECT DISTINCT ac.account_id 
                            FROM account_company ac
                            WHERE ac.company_id IN ($companyPlaceholders)
                        ");
                        $accountStmt->execute($delete_db_ids);
                        $accountIds = normalizeIds($accountStmt->fetchAll(PDO::FETCH_COLUMN));
                        
                        if (!empty($accountIds)) {
                            // 先删除与这些账户相关的交易
                            deleteByIds($pdo, 'transactions', 'account_id', $accountIds);
                            deleteByIds($pdo, 'transactions', 'from_account_id', $accountIds);
                        }
                        
                        // 2. process 相关
                        $processStmt = $pdo->prepare("SELECT id FROM process WHERE company_id IN ($companyPlaceholders)");
                        $processStmt->execute($delete_db_ids);
                        $processIds = normalizeIds($processStmt->fetchAll(PDO::FETCH_COLUMN));
                        
                        if (!empty($processIds)) {
                            deleteByIds($pdo, 'process_day', 'process_id', $processIds);
                            deleteByIds($pdo, 'submitted_processes', 'process_id', $processIds);
                            
                            // data_capture -> details
                            $processPlaceholders = buildInPlaceholders(count($processIds));
                            $captureStmt = $pdo->prepare("SELECT id FROM data_captures WHERE process_id IN ($processPlaceholders)");
                            $captureStmt->execute($processIds);
                            $captureIds = normalizeIds($captureStmt->fetchAll(PDO::FETCH_COLUMN));
                            
                            if (!empty($captureIds)) {
                                deleteByIds($pdo, 'data_capture_details', 'capture_id', $captureIds);
                                deleteByIds($pdo, 'data_captures', 'id', $captureIds);
                            }
                            
                            deleteByIds($pdo, 'process', 'id', $processIds);
                        }
                        
                        // 3. 其他含 company_id 的表
                        // data_captures 和 data_capture_details（直接包含 company_id 的情况）
                        $directCaptureStmt = $pdo->prepare("SELECT id FROM data_captures WHERE company_id IN ($companyPlaceholders)");
                        $directCaptureStmt->execute($delete_db_ids);
                        $directCaptureIds = normalizeIds($directCaptureStmt->fetchAll(PDO::FETCH_COLUMN));
                        
                        if (!empty($directCaptureIds)) {
                            deleteByIds($pdo, 'data_capture_details', 'capture_id', $directCaptureIds);
                            deleteByIds($pdo, 'data_captures', 'id', $directCaptureIds);
                        }
                        
                        // data_capture_details（直接包含 company_id 的情况）
                        deleteByIds($pdo, 'data_capture_details', 'company_id', $delete_db_ids);
                        
                        // data_capture_templates
                        deleteByIds($pdo, 'data_capture_templates', 'company_id', $delete_db_ids);
                        
                        // submitted_processes（直接包含 company_id 的情况）
                        deleteByIds($pdo, 'submitted_processes', 'company_id', $delete_db_ids);
                        
                        // 4. 其他含 company / user 关系的表
                        // 由于 user 不再直接持有 company_id（改为 user_company_map 关系表），
                        // 这里通过 user_company_map 找到与这些 company 关联的用户，仅清理其相关数据，用户本身暂不删除。
                        $userStmt = $pdo->prepare("
                            SELECT DISTINCT u.id
                            FROM user u
                            INNER JOIN user_company_map ucm ON u.id = ucm.user_id
                            WHERE ucm.company_id IN ($companyPlaceholders)
                        ");
                        $userStmt->execute($delete_db_ids);
                        $userIds = normalizeIds($userStmt->fetchAll(PDO::FETCH_COLUMN));
                        
                        if (!empty($userIds)) {
                            deleteByIds($pdo, 'submitted_processes', 'user_id', $userIds);
                            deleteByIds($pdo, 'transactions', 'created_by', $userIds);
                            
                            $userPlaceholder = buildInPlaceholders(count($userIds));
                            $captureByUserStmt = $pdo->prepare("SELECT id FROM data_captures WHERE created_by IN ($userPlaceholder)");
                            $captureByUserStmt->execute($userIds);
                            $userCaptureIds = normalizeIds($captureByUserStmt->fetchAll(PDO::FETCH_COLUMN));
                            
                            if (!empty($userCaptureIds)) {
                                deleteByIds($pdo, 'data_capture_details', 'capture_id', $userCaptureIds);
                                deleteByIds($pdo, 'data_captures', 'id', $userCaptureIds);
                            }
                        }
                        
                        // 5. 删除其他直接包含 company_id 的表
                        deleteByIds($pdo, 'description', 'company_id', $delete_db_ids);
                        deleteByIds($pdo, 'currency', 'company_id', $delete_db_ids);
                        
                        // 6. 删除 account_company 中与这些 company 关联的记录
                        deleteByIds($pdo, 'account_company', 'company_id', $delete_db_ids);
                        
                        // 7. 删除不再关联任何公司的账户本身
                        if (!empty($accountIds)) {
                            $accountPlaceholder = buildInPlaceholders(count($accountIds));
                            $orphanStmt = $pdo->prepare("
                                SELECT id 
                                FROM account 
                                WHERE id IN ($accountPlaceholder)
                                  AND NOT EXISTS (
                                      SELECT 1 FROM account_company ac 
                                      WHERE ac.account_id = account.id
                                  )
                            ");
                            $orphanStmt->execute($accountIds);
                            $orphanAccountIds = normalizeIds($orphanStmt->fetchAll(PDO::FETCH_COLUMN));
                            
                            if (!empty($orphanAccountIds)) {
                                deleteByIds($pdo, 'account', 'id', $orphanAccountIds);
                            }
                        }
                        
                        // 8. 删除 user 与这些 company 的映射关系
                        deleteByIds($pdo, 'user_company_map', 'company_id', $delete_db_ids);
                        
                        // 9. 最后删除公司本身
                        deleteByIds($pdo, 'company', 'id', $delete_db_ids);
                    }
                }
                
                // Find companies to add (in new list but not existing)
                $companies_to_add = [];
                foreach ($new_companies_data as $new_company) {
                    if (!in_array($new_company['company_id'], $existing_company_ids)) {
                        $companies_to_add[] = $new_company;
                    }
                }
                
                // Insert new companies
                if (!empty($companies_to_add)) {
                    $stmt = $pdo->prepare("INSERT INTO company (company_id, owner_id, created_by, expiration_date, permissions) VALUES (?, ?, ?, ?, ?)");
                    
                    foreach ($companies_to_add as $company_data) {
                        $permissions_json = !empty($company_data['permissions']) && is_array($company_data['permissions']) ? json_encode($company_data['permissions']) : null;
                        $stmt->execute([
                            $company_data['company_id'], 
                            $id, 
                            $_SESSION['login_id'] ?? 'system',
                            $company_data['expiration_date'],
                            $permissions_json
                        ]);
                    }
                }
                
                // Update existing companies' expiration dates and permissions if changed
                foreach ($new_companies_data as $new_company) {
                    if (in_array($new_company['company_id'], $existing_company_ids)) {
                        foreach ($existing_companies as $existing) {
                            if (strtoupper($existing['company_id']) === $new_company['company_id']) {
                                $permissions_json = !empty($new_company['permissions']) && is_array($new_company['permissions']) ? json_encode($new_company['permissions']) : null;
                                $updateStmt = $pdo->prepare("UPDATE company SET expiration_date = ?, permissions = ? WHERE id = ?");
                                $updateStmt->execute([$new_company['expiration_date'], $permissions_json, $existing['id']]);
                                break;
                            }
                        }
                    }
                }
                
                $pdo->commit();
                
                $owner = getOwnerWithCompanies($pdo, $id);
                echo json_encode([
                    'success' => true,
                    'message' => 'Owner updated successfully',
                    'data' => $owner
                ]);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
            
        case 'delete':
            // Delete owner and cascade delete all related data手動
            $id = $data['id'] ?? 0;
            
            if (empty($id)) {
                echo json_encode(['success' => false, 'message' => 'Invalid ID', 'data' => null]);
                exit;
            }
            
            // Start transaction
            $pdo->beginTransaction();
            
            try {
                // 获取 owner 旗下的所有公司
                $stmt = $pdo->prepare("SELECT id FROM company WHERE owner_id = ?");
                $stmt->execute([$id]);
                $companyIds = normalizeIds($stmt->fetchAll(PDO::FETCH_COLUMN));
                
                if (!empty($companyIds)) {
                    $companyPlaceholders = buildInPlaceholders(count($companyIds));
                    
                    // 1. account 及其关联的 transactions
                    // account 表已不再直接持有 company_id，通过 account_company 关系表获取账户
                    $accountStmt = $pdo->prepare("
                        SELECT DISTINCT ac.account_id 
                        FROM account_company ac
                        WHERE ac.company_id IN ($companyPlaceholders)
                    ");
                    $accountStmt->execute($companyIds);
                    $accountIds = normalizeIds($accountStmt->fetchAll(PDO::FETCH_COLUMN));
                    
                    if (!empty($accountIds)) {
                        // 先删除与这些账户相关的交易
                        deleteByIds($pdo, 'transactions', 'account_id', $accountIds);
                        deleteByIds($pdo, 'transactions', 'from_account_id', $accountIds);
                    }
                    
                    // 2. process 相关
                    $processStmt = $pdo->prepare("SELECT id FROM process WHERE company_id IN ($companyPlaceholders)");
                    $processStmt->execute($companyIds);
                    $processIds = normalizeIds($processStmt->fetchAll(PDO::FETCH_COLUMN));
                    
                    if (!empty($processIds)) {
                        deleteByIds($pdo, 'process_day', 'process_id', $processIds);
                        deleteByIds($pdo, 'submitted_processes', 'process_id', $processIds);
                        
                        // data_capture -> details
                        $processPlaceholders = buildInPlaceholders(count($processIds));
                        $captureStmt = $pdo->prepare("SELECT id FROM data_captures WHERE process_id IN ($processPlaceholders)");
                        $captureStmt->execute($processIds);
                        $captureIds = normalizeIds($captureStmt->fetchAll(PDO::FETCH_COLUMN));
                        
                        if (!empty($captureIds)) {
                            deleteByIds($pdo, 'data_capture_details', 'capture_id', $captureIds);
                            deleteByIds($pdo, 'data_captures', 'id', $captureIds);
                        }
                        
                        deleteByIds($pdo, 'process', 'id', $processIds);
                    }
                    
                    // 3. 其他含 company / user 关系的表
                    // 由于 user 不再直接持有 company_id（改为 user_company_map 关系表），
                    // 这里通过 user_company_map 找到与这些 company 关联的用户，仅清理其相关数据，用户本身暂不删除。
                    $userStmt = $pdo->prepare("
                        SELECT DISTINCT u.id
                        FROM user u
                        INNER JOIN user_company_map ucm ON u.id = ucm.user_id
                        WHERE ucm.company_id IN ($companyPlaceholders)
                    ");
                    $userStmt->execute($companyIds);
                    $userIds = normalizeIds($userStmt->fetchAll(PDO::FETCH_COLUMN));
                    
                    if (!empty($userIds)) {
                        deleteByIds($pdo, 'submitted_processes', 'user_id', $userIds);
                        deleteByIds($pdo, 'transactions', 'created_by', $userIds);
                        
                        $userPlaceholder = buildInPlaceholders(count($userIds));
                        $captureByUserStmt = $pdo->prepare("SELECT id FROM data_captures WHERE created_by IN ($userPlaceholder)");
                        $captureByUserStmt->execute($userIds);
                        $userCaptureIds = normalizeIds($captureByUserStmt->fetchAll(PDO::FETCH_COLUMN));
                        
                        if (!empty($userCaptureIds)) {
                            deleteByIds($pdo, 'data_capture_details', 'capture_id', $userCaptureIds);
                            deleteByIds($pdo, 'data_captures', 'id', $userCaptureIds);
                        }
                    }
                    
                    deleteByIds($pdo, 'description', 'company_id', $companyIds);
                    deleteByIds($pdo, 'currency', 'company_id', $companyIds);
                    
                    // 删除 account_company 中与这些 company 关联的记录
                    deleteByIds($pdo, 'account_company', 'company_id', $companyIds);
                    
                    // 删除不再关联任何公司的账户本身
                    if (!empty($accountIds)) {
                        $accountPlaceholder = buildInPlaceholders(count($accountIds));
                        $orphanStmt = $pdo->prepare("
                            SELECT id 
                            FROM account 
                            WHERE id IN ($accountPlaceholder)
                              AND NOT EXISTS (
                                  SELECT 1 FROM account_company ac 
                                  WHERE ac.account_id = account.id
                              )
                        ");
                        $orphanStmt->execute($accountIds);
                        $orphanAccountIds = normalizeIds($orphanStmt->fetchAll(PDO::FETCH_COLUMN));
                        
                        if (!empty($orphanAccountIds)) {
                            deleteByIds($pdo, 'account', 'id', $orphanAccountIds);
                        }
                    }
                    
                    // 删除 user 与这些 company 的映射关系
                    deleteByIds($pdo, 'user_company_map', 'company_id', $companyIds);
                }
                
                // 删除 owner 直接创建的数据 (data_captures / transactions)
                $ownerCaptureStmt = $pdo->prepare("SELECT id FROM data_captures WHERE user_type = 'owner' AND created_by = ?");
                $ownerCaptureStmt->execute([$id]);
                $ownerCaptureIds = normalizeIds($ownerCaptureStmt->fetchAll(PDO::FETCH_COLUMN));
                
                if (!empty($ownerCaptureIds)) {
                    deleteByIds($pdo, 'data_capture_details', 'capture_id', $ownerCaptureIds);
                    deleteByIds($pdo, 'data_captures', 'id', $ownerCaptureIds);
                }
                
                deleteByIds($pdo, 'transactions', 'created_by', [$id]);
                
                // 删除 company -> owner
                deleteByIds($pdo, 'company', 'owner_id', [$id]);
                deleteByIds($pdo, 'owner', 'id', [$id]);
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Owner and all related data deleted successfully',
                    'data' => null
                ]);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
            
        case 'get_companies':
            // Get companies for a specific owner with expiration dates
            $owner_id = $data['owner_id'] ?? ($_GET['owner_id'] ?? 0);
            
            if (empty($owner_id)) {
                echo json_encode(['success' => false, 'message' => 'Invalid owner ID', 'data' => null]);
                exit;
            }
            
            try {
                $stmt = $pdo->prepare("SELECT company_id, expiration_date, permissions FROM company WHERE owner_id = ? ORDER BY company_id");
                $stmt->execute([$owner_id]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $companies = [];
                foreach ($rows as $row) {
                    $perms = $row['permissions'];
                    if ($perms !== null && $perms !== '') {
                        $decoded = json_decode($perms, true);
                        $row['permissions'] = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
                    } else {
                        $row['permissions'] = [];
                    }
                    $companies[] = $row;
                }
                echo json_encode([
                    'success' => true,
                    'message' => 'OK',
                    'data' => ['companies' => $companies]
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error: ' . $e->getMessage(),
                    'data' => null
                ]);
            }
            break;
            
        case 'get_company_permissions':
            // Get permissions for a specific company
            $company_id = $data['company_id'] ?? '';
            
            if (empty($company_id)) {
                echo json_encode(['success' => false, 'message' => 'Invalid company ID', 'data' => null]);
                exit;
            }
            
            try {
                // 通过 company_id (字符串) 查找公司
                $stmt = $pdo->prepare("SELECT permissions FROM company WHERE company_id = ?");
                $stmt->execute([strtoupper($company_id)]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result && $result['permissions'] !== null && $result['permissions'] !== '') {
                    $permissions = json_decode($result['permissions'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($permissions)) {
                        echo json_encode([
                            'success' => true,
                            'message' => 'OK',
                            'data' => ['permissions' => $permissions]
                        ]);
                    } else {
                        echo json_encode([
                            'success' => true,
                            'message' => 'OK',
                            'data' => ['permissions' => []]
                        ]);
                    }
                } else {
                    // 无权限设置或公司不存在：返回空数组，不再默认全选
                    echo json_encode([
                        'success' => true,
                        'message' => 'OK',
                        'data' => ['permissions' => []]
                    ]);
                }
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error: ' . $e->getMessage(),
                    'data' => null
                ]);
            }
            break;
            
        case 'update_company_permissions':
            // Update permissions for a specific company
            $company_id = $data['company_id'] ?? '';
            $permissions = $data['permissions'] ?? [];
            
            if (empty($company_id)) {
                echo json_encode(['success' => false, 'message' => 'Invalid company ID', 'data' => null]);
                exit;
            }
            
            if (!is_array($permissions)) {
                echo json_encode(['success' => false, 'message' => 'Invalid permissions format', 'data' => null]);
                exit;
            }
            
            try {
                // 验证权限值
                $valid_permissions = ['Games', 'Bank', 'Loan', 'Rate', 'Money'];
                $filtered_permissions = array_intersect($permissions, $valid_permissions);
                
                // 转换为 JSON
                $permissions_json = json_encode(array_values($filtered_permissions));
                
                // 更新数据库
                $stmt = $pdo->prepare("UPDATE company SET permissions = ? WHERE company_id = ?");
                $stmt->execute([$permissions_json, strtoupper($company_id)]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Permissions updated successfully',
                    'data' => null
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error: ' . $e->getMessage(),
                    'data' => null
                ]);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action', 'data' => null]);
            break;
    }
    
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
        'data' => null
    ]);
} catch(Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'data' => null
    ]);
}
?>