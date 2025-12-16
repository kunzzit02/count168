<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config.php';

session_start();

// 检查用户是否登录
if (!isset($_SESSION['user_id']) || !isset($_SESSION['company_id'])) {
    sendResponse(false, 'Unauthorized access');
}

$current_company_id = $_SESSION['company_id'];

// 获取当前登录用户（你需要根据你的登录系统调整这个逻辑）
function getCurrentUser() {
    // 这里你需要根据你的登录系统来获取当前用户
    // 示例：如果你在 session 中存储了 login_id
    return $_SESSION['login_id'] ?? 'admin001'; // 默认为 admin001
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Response function
function sendResponse($success, $message = '', $data = null) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

// Validate permissions array
function validatePermissions($permissions) {
    if (!is_array($permissions)) {
        return false;
    }
    
    $validPermissions = ['home', 'admin', 'account', 'process', 'datacapture', 'payment', 'report', 'maintenance'];
    
    foreach ($permissions as $permission) {
        if (!in_array($permission, $validPermissions)) {
            return false;
        }
    }
    
    return true;
}

// Log permission changes for audit purposes
function logPermissionChange($pdo, $templateUserId, $affectedUserIds, $permissions) {
    try {
        $currentUser = getCurrentUser();
        $logData = [
            'template_user_id' => $templateUserId, // null for manual mode
            'source_type' => $templateUserId ? 'template' : 'manual',
            'affected_user_ids' => $affectedUserIds,
            'permissions_copied' => $permissions,
            'performed_by' => $currentUser,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // 这里你可以创建一个单独的日志表来记录权限更改
        // 现在先记录到 PHP 错误日志
        error_log("Permission Copy Log: " . json_encode($logData));
        
        return true;
    } catch (Exception $e) {
        error_log("Failed to log permission change: " . $e->getMessage());
        return false;
    }
}

try {
    if (!$input || !isset($input['action'])) {
        sendResponse(false, 'Invalid request');
    }
    
    $action = $input['action'];
    
    switch ($action) {
        case 'copy_permissions':
            // 验证输入数据
            if (!isset($input['affected_user_ids']) || !isset($input['permissions']) || !isset($input['source_type'])) {
                sendResponse(false, 'Missing required parameters');
            }

            // 如果是template模式才需要template_user_id
            if ($input['source_type'] === 'template' && !isset($input['template_user_id'])) {
                sendResponse(false, 'Template user ID is required for template mode');
            }
            
            $templateUserId = $input['template_user_id'] ?? null;
            $affectedUserIds = $input['affected_user_ids'];
            $permissions = $input['permissions'];

            // 只在template模式下验证模板用户ID
            if ($input['source_type'] === 'template') {
                if (!is_numeric($templateUserId) || $templateUserId <= 0) {
                    sendResponse(false, 'Invalid template user ID');
                }
            }
            
            // 验证受影响用户ID数组
            if (!is_array($affectedUserIds) || empty($affectedUserIds)) {
                sendResponse(false, 'No affected users specified');
            }
            
            foreach ($affectedUserIds as $userId) {
                if (!is_numeric($userId) || $userId <= 0) {
                    sendResponse(false, 'Invalid affected user ID: ' . $userId);
                }
            }
            
            // 验证权限数组
            if (!validatePermissions($permissions)) {
                sendResponse(false, 'Invalid permissions data');
            }

            // 验证账户权限数据（在现有验证后添加）
            $accountPermissions = $input['account_permissions'] ?? [];
            if (!is_array($accountPermissions)) {
                sendResponse(false, 'Invalid account permissions data');
            }

            // 验证账户ID的有效性 - 添加 company_id 检查
            global $current_company_id;
            if (!empty($accountPermissions)) {
                // 确保所有 ID 都是整数类型
                foreach ($accountPermissions as &$perm) {
                    if (isset($perm['id'])) {
                        $perm['id'] = (int)$perm['id'];
                    }
                }
                unset($perm); // 解除引用
                
                $accountIds = array_column($accountPermissions, 'id');
                $accountIds = array_unique($accountIds); // 去重
                
                if (!empty($accountIds)) {
                    $placeholders = str_repeat('?,', count($accountIds) - 1) . '?';
                    $checkAccountStmt = $pdo->prepare("SELECT COUNT(*) FROM account WHERE id IN ($placeholders) AND company_id = ?");
                    $params = array_merge($accountIds, [$current_company_id]);
                    $checkAccountStmt->execute($params);
                    $existingAccountsCount = $checkAccountStmt->fetchColumn();
                    
                    if ($existingAccountsCount != count($accountIds)) {
                        sendResponse(false, 'One or more selected accounts not found or access denied');
                    }
                }
            }

            // 验证流程权限数据
            $processPermissions = $input['process_permissions'] ?? [];
            if (!is_array($processPermissions)) {
                sendResponse(false, 'Invalid process permissions data');
            }

            // 验证流程ID的有效性 - 添加 company_id 检查
            if (!empty($processPermissions)) {
                // 确保所有 ID 都是整数类型
                foreach ($processPermissions as &$perm) {
                    if (isset($perm['id'])) {
                        $perm['id'] = (int)$perm['id'];
                    }
                }
                unset($perm); // 解除引用
                
                $processIds = array_column($processPermissions, 'id');
                $processIds = array_unique($processIds); // 去重
                
                if (!empty($processIds)) {
                    $placeholders = str_repeat('?,', count($processIds) - 1) . '?';
                    $checkProcessStmt = $pdo->prepare("SELECT COUNT(*) FROM process WHERE id IN ($placeholders) AND company_id = ?");
                    $params = array_merge($processIds, [$current_company_id]);
                    $checkProcessStmt->execute($params);
                    $existingProcessesCount = $checkProcessStmt->fetchColumn();
                    
                    if ($existingProcessesCount != count($processIds)) {
                        sendResponse(false, 'One or more selected processes not found or access denied');
                    }
                }
            }
            
            // 只在template模式下验证模板用户存在 - 添加 company_id 检查
            $templateUser = null;
            if ($input['source_type'] === 'template') {
                $checkStmt = $pdo->prepare("SELECT name, login_id FROM user WHERE id = ? AND company_id = ?");
                $checkStmt->execute([$templateUserId, $current_company_id]);
                $templateUser = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$templateUser) {
                    sendResponse(false, 'Template user not found or access denied');
                }
            }
            
            // 验证所有受影响的用户都存在 - 添加 company_id 检查
            $placeholders = str_repeat('?,', count($affectedUserIds) - 1) . '?';
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM user WHERE id IN ($placeholders) AND company_id = ?");
            $params = array_merge($affectedUserIds, [$current_company_id]);
            $checkStmt->execute($params);
            $existingUsersCount = $checkStmt->fetchColumn();
            
            if ($existingUsersCount != count($affectedUserIds)) {
                sendResponse(false, 'One or more affected users not found or access denied');
            }
            
            // 开始事务
            $pdo->beginTransaction();
            
            try {
                $permissionsJson = json_encode($permissions);
                $successCount = 0;
                $failedUsers = [];
                
                // 更新每个受影响用户的权限（替换现有的更新逻辑）- 添加 company_id 检查
                $updateStmt = $pdo->prepare("UPDATE user SET permissions = ?, account_permissions = ?, process_permissions = ? WHERE id = ? AND company_id = ?");
                $currentUser = getCurrentUser();
                $accountPermissionsJson = json_encode($accountPermissions);
                $processPermissionsJson = json_encode($processPermissions);

                foreach ($affectedUserIds as $userId) {
                    $result = $updateStmt->execute([$permissionsJson, $accountPermissionsJson, $processPermissionsJson, $userId, $current_company_id]);
                    if ($result) {
                        $successCount++;
                    } else {
                        // 获取用户信息用于错误报告
                        $userStmt = $pdo->prepare("SELECT name, login_id FROM user WHERE id = ? AND company_id = ?");
                        $userStmt->execute([$userId, $current_company_id]);
                        $userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);
                        $failedUsers[] = $userInfo ? $userInfo['name'] . ' (' . $userInfo['login_id'] . ')' : "User ID: $userId";
                    }
                }
                
                // 记录权限更改日志
                logPermissionChange($pdo, $templateUserId, $affectedUserIds, $permissions);
                
                // 提交事务
                $pdo->commit();
                
                // 准备响应消息
                if ($successCount === count($affectedUserIds)) {
                    if ($input['source_type'] === 'template') {
                        $message = "Successfully updated permissions for $successCount user(s) based on template: {$templateUser['name']} ({$templateUser['login_id']})";
                    } else {
                        $message = "Successfully updated permissions for $successCount user(s) with manually selected permissions";
                    }
                    sendResponse(true, $message, [
                        'success_count' => $successCount,
                        'total_count' => count($affectedUserIds),
                        'template_user' => $templateUser
                    ]);
                } else {
                    $failCount = count($affectedUserIds) - $successCount;
                    $message = "Partially completed: $successCount succeeded, $failCount failed.";
                    if (!empty($failedUsers)) {
                        $message .= " Failed users: " . implode(', ', $failedUsers);
                    }
                    sendResponse(false, $message, [
                        'success_count' => $successCount,
                        'failed_count' => $failCount,
                        'failed_users' => $failedUsers
                    ]);
                }
                
            } catch (Exception $e) {
                // 回滚事务
                $pdo->rollback();
                throw $e;
            }
            
            break;
            
        case 'get_user_permissions':
            // 获取特定用户的权限信息 - 添加 company_id 检查
            if (!isset($input['user_id'])) {
                sendResponse(false, 'User ID is required');
            }
            
            $userId = $input['user_id'];
            
            if (!is_numeric($userId) || $userId <= 0) {
                sendResponse(false, 'Invalid user ID');
            }
            
            global $current_company_id;
            $stmt = $pdo->prepare("SELECT id, login_id, name, email, role, permissions, account_permissions, process_permissions FROM user WHERE id = ? AND company_id = ?");
            $stmt->execute([$userId, $current_company_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                sendResponse(false, 'User not found or access denied');
            }
            
            // 解析权限JSON
            $permissions = [];
            if ($user['permissions']) {
                $permissions = json_decode($user['permissions'], true) ?? [];
            }
            
            $user['permissions'] = $permissions;
            
            sendResponse(true, 'User permissions retrieved successfully', $user);
            break;
            
        case 'get_all_users':
            // 获取所有用户的基本信息（用于界面显示）- 添加 company_id 过滤
            global $current_company_id;
            $stmt = $pdo->prepare("SELECT id, login_id, name, email, role, permissions, account_permissions, process_permissions FROM user WHERE company_id = ? ORDER BY name ASC");
            $stmt->execute([$current_company_id]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 为每个用户解析权限
            foreach ($users as &$user) {
                if ($user['permissions']) {
                    $user['permissions'] = json_decode($user['permissions'], true) ?? [];
                } else {
                    $user['permissions'] = [];
                }
            }
            
            sendResponse(true, 'Users retrieved successfully', $users);
            break;
            
        case 'validate_permissions':
            // 验证权限数据的有效性
            if (!isset($input['permissions'])) {
                sendResponse(false, 'Permissions data is required');
            }
            
            $permissions = $input['permissions'];
            
            if (validatePermissions($permissions)) {
                sendResponse(true, 'Permissions are valid', [
                    'permissions' => $permissions,
                    'count' => count($permissions)
                ]);
            } else {
                sendResponse(false, 'Invalid permissions data');
            }
            break;
            
        default:
            sendResponse(false, 'Invalid action');
            break;
    }
    
} catch (PDOException $e) {
    // 如果事务正在进行，回滚它
    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }
    
    error_log("Database error in useraccessapi.php: " . $e->getMessage());
    sendResponse(false, 'Database error occurred. Please try again later.');
    
} catch (Exception $e) {
    // 如果事务正在进行，回滚它
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollback();
    }
    
    error_log("General error in useraccessapi.php: " . $e->getMessage());
    sendResponse(false, 'An unexpected error occurred. Please try again later.');
}

ob_clean(); // 清理任何之前的输出
?>