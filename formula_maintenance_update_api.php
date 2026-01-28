<?php
/**
 * Formula Maintenance Update API
 * 用于更新 data_capture_templates 的内容
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';

try {
    // 检查用户是否登录
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }
    
    // 获取POST数据
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('无效的请求数据');
    }
    
    // 确定要操作的 company_id（支持 owner 切换公司）
    $company_id = null;
    $requested_company_id = isset($input['company_id']) ? trim($input['company_id']) : '';
    $userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';

    if ($requested_company_id !== '') {
        $requested_company_id = (int)$requested_company_id;
        if ($userRole === 'owner') {
            $owner_id = $_SESSION['owner_id'] ?? $_SESSION['user_id'];
            $stmt = $pdo->prepare("SELECT id FROM company WHERE id = ? AND owner_id = ?");
            $stmt->execute([$requested_company_id, $owner_id]);
            if ($stmt->fetchColumn()) {
                $company_id = $requested_company_id;
            } else {
                throw new Exception('无权访问该公司');
            }
        } else {
            if (!isset($_SESSION['company_id']) || (int)$_SESSION['company_id'] !== $requested_company_id) {
                throw new Exception('无权访问该公司');
            }
            $company_id = (int)$_SESSION['company_id'];
        }
    } else {
        if (!isset($_SESSION['company_id'])) {
            throw new Exception('用户未登录或缺少公司信息');
        }
        $company_id = (int)$_SESSION['company_id'];
    }
    
    // 检查请求方法
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('只支持 POST 请求');
    }
    
    $template_id = isset($input['template_id']) ? (int)$input['template_id'] : 0;
    $account_id = isset($input['account_id']) ? (int)$input['account_id'] : 0;
    $source_columns = isset($input['source_columns']) ? trim($input['source_columns']) : '';
    $source_display = isset($input['source_display']) ? trim($input['source_display']) : $source_columns;
    $input_method = isset($input['input_method']) ? trim($input['input_method']) : '';
    $formula = isset($input['formula']) ? trim($input['formula']) : '';
    $description = isset($input['description']) ? trim($input['description']) : '';
    
    if ($template_id <= 0) {
        throw new Exception('Template ID 是必填项');
    }
    
    if ($account_id <= 0) {
        throw new Exception('Account 是必填项');
    }
    
    // 验证模板是否属于当前公司
    $stmt = $pdo->prepare("
        SELECT dct.id
        FROM data_capture_templates dct
        INNER JOIN process p ON dct.process_id = p.id
        WHERE dct.id = ? AND p.company_id = ?
    ");
    $stmt->execute([$template_id, $company_id]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) {
        throw new Exception('模板不存在或不属于当前公司');
    }
    
    // 获取账户显示文本（只使用 account_company 表）
    $accountStmt = $pdo->prepare("
        SELECT a.account_id, a.name 
        FROM account a
        INNER JOIN account_company ac ON a.id = ac.account_id
        WHERE a.id = ? AND ac.company_id = ?
    ");
    $accountStmt->execute([$account_id, $company_id]);
    $account = $accountStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$account) {
        throw new Exception('Account 不存在或不属于当前公司');
    }
    
    $account_display = $account['account_id'];
    
    // 获取当前 template 的 process_id，用于同步
    $getProcessStmt = $pdo->prepare("
        SELECT process_id, id_product, product_type, formula_variant, 
               source_percent, enable_source_percent, enable_input_method,
               currency_id, currency_display
        FROM data_capture_templates 
        WHERE id = ?
    ");
    $getProcessStmt->execute([$template_id]);
    $templateInfo = $getProcessStmt->fetch(PDO::FETCH_ASSOC);
    $sourceProcessId = $templateInfo ? (int)$templateInfo['process_id'] : null;
    
    // 开始事务
    $pdo->beginTransaction();
    
    try {
        $updateSql = "UPDATE data_capture_templates 
                      SET account_id = :account_id,
                          account_display = :account_display,
                          source_columns = :source_columns,
                          columns_display = :columns_display,
                          input_method = :input_method,
                          formula_display = :formula_display,
                          formula_operators = :formula_operators,
                          description = :description,
                          updated_at = NOW()
                      WHERE id = :id";
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute([
            ':account_id' => $account_id,
            ':account_display' => $account_display,
            ':source_columns' => $source_columns,
            ':columns_display' => $source_display,
            ':input_method' => $input_method ?: null,
            ':formula_display' => $formula,
            ':formula_operators' => $formula,
            ':description' => $description,
            ':id' => $template_id
        ]);
        
        // 如果当前 Process 是源 Process，同步 Formula 到所有关联的 Multi-use Processes
        if ($sourceProcessId) {
            // 查找所有 sync_source_process_id 指向当前源 Process 的 Processes
            $findSyncedStmt = $pdo->prepare("
                SELECT id, process_id 
                FROM process 
                WHERE sync_source_process_id = ? AND company_id = ?
            ");
            $findSyncedStmt->execute([$sourceProcessId, $company_id]);
            $syncedProcesses = $findSyncedStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($syncedProcesses)) {
                // 为每个关联的 Process 同步 Formula
                foreach ($syncedProcesses as $syncedProcess) {
                    $targetProcessId = $syncedProcess['id'];
                    
                    // 查找目标 Process 中对应的 template
                    $findTargetTemplateStmt = $pdo->prepare("
                        SELECT id FROM data_capture_templates 
                        WHERE process_id = ? 
                          AND company_id = ?
                          AND id_product = ?
                          AND account_id = ?
                          AND product_type = ?
                          AND formula_variant = ?
                        LIMIT 1
                    ");
                    $findTargetTemplateStmt->execute([
                        $targetProcessId,
                        $company_id,
                        $templateInfo['id_product'],
                        $account_id,
                        $templateInfo['product_type'],
                        $templateInfo['formula_variant']
                    ]);
                    $targetTemplate = $findTargetTemplateStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($targetTemplate) {
                        // 更新目标 template
                        $syncUpdateStmt = $pdo->prepare("
                            UPDATE data_capture_templates SET
                                account_id = ?,
                                account_display = ?,
                                source_columns = ?,
                                columns_display = ?,
                                input_method = ?,
                                formula_display = ?,
                                formula_operators = ?,
                                description = ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $syncUpdateStmt->execute([
                            $account_id,
                            $account_display,
                            $source_columns,
                            $source_display,
                            $input_method ?: null,
                            $formula,
                            $formula,
                            $description,
                            $targetTemplate['id']
                        ]);
                    }
                }
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => '更新成功'
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => '数据库错误: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>

