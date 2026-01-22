<?php
/**
 * Transaction Submit API
 * 用于提交交易数据
 * 
 * 支持的交易类型：
 * - WIN: 赢钱
 * - LOSE: 输钱
 * - PAYMENT: 付款
 * - RECEIVE: 收款
 * - CONTRA: 对冲/转账
 * - CLAIM: 索赔（算法与 RECEIVE 相同）
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';

try {
    // 检查用户登录
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('请先登录');
    }
    
    // 确定要操作的 company_id（支持 owner 切换公司）
    $company_id = null;
    $requested_company_id = isset($_POST['company_id']) ? trim($_POST['company_id']) : '';
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
    
    // 获取表单数据
    $transaction_type = trim($_POST['transaction_type'] ?? '');
    $account_id = (int)($_POST['account_id'] ?? 0);
    $from_account_id = !empty($_POST['from_account_id']) ? (int)$_POST['from_account_id'] : null;
    $amount = (float)($_POST['amount'] ?? 0);
    $transaction_date = trim($_POST['transaction_date'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $sms = trim($_POST['sms'] ?? '');
    $currency = trim($_POST['currency'] ?? ''); // 获取当前选择的 currency
    $user_type = $_SESSION['user_type'] ?? 'user';
    $created_by_user = null;
    $created_by_owner = null;
    
    if ($user_type === 'owner') {
        $created_by_owner = (int)($_SESSION['owner_id'] ?? $_SESSION['user_id'] ?? 0);
        if ($created_by_owner <= 0) {
            throw new Exception('无法识别当前 owner，提交被拒绝');
        }
    } else {
        $created_by_user = (int)($_SESSION['user_id'] ?? 0);
        if ($created_by_user <= 0) {
            throw new Exception('无法识别当前用户，提交被拒绝');
        }
    }
    
    // 验证必填字段
    if (empty($transaction_type)) {
        throw new Exception('请选择交易类型');
    }
    
    if (!in_array($transaction_type, ['WIN', 'LOSE', 'PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM', 'RATE'])) {
        throw new Exception('无效的交易类型');
    }
    
    // RATE 类型有特殊的验证逻辑
    $is_rate = ($transaction_type === 'RATE');
    
    if (!$is_rate) {
    if ($account_id <= 0) {
        throw new Exception('请选择 To Account');
    }
    
    if ($amount <= 0) {
        throw new Exception('金额必须大于 0');
        }
    }
    
    if (empty($transaction_date)) {
        throw new Exception('请选择交易日期');
    }
    
    // 转换日期格式 (dd/mm/yyyy 转为 yyyy-mm-dd)
    $transaction_date_db = date('Y-m-d', strtotime(str_replace('/', '-', $transaction_date)));
    
    // 检查 CONTRA 交易的日期权限限制
    // Manager 以下的角色（包括 admin）只能 Contra 当天的账目，昨天的账目需要 Manager 以上的职位批准
    if ($transaction_type === 'CONTRA') {
        // 定义角色层级（数字越小，层级越高）
        $role_hierarchy = [
            'owner' => 0,
            'admin' => 1,
            'manager' => 2,
            'supervisor' => 3,
            'accountant' => 4,
            'audit' => 5,
            'customer service' => 6
        ];
        
        $user_role_lower = strtolower($userRole);
        $user_level = $role_hierarchy[$user_role_lower] ?? 999;
        
        // Manager 以下的角色（level > 2，即 supervisor, accountant, audit, customer service）
        // 以及 admin（根据用户要求，admin 也被视为 Manager 以下的角色，level = 1）
        // 限制条件：admin（level = 1）或 Manager 以下的角色（level > 2）
        $is_restricted_role = ($user_level === 1) || ($user_level > 2); // admin 或 Manager 以下的角色
        
        if ($is_restricted_role) {
            // 获取今天的日期
            $today = date('Y-m-d');
            // 获取昨天的日期
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            
            // 检查交易日期
            if ($transaction_date_db < $yesterday) {
                // 超过昨天的账目，Manager 以下角色不能操作
                throw new Exception('Manager 以下的职位只能 Contra 当天的账目。此交易日期为 ' . $transaction_date . '，需要 Manager 以上的职位批准');
            } elseif ($transaction_date_db === $yesterday) {
                // 昨天的账目，需要 Manager 以上批准
                throw new Exception('昨天的账目需要 Manager 以上的职位批准。当前角色：' . ucfirst($user_role_lower));
            }
            // 如果是今天的账目，允许继续
        }
    }
    
    // 验证 From Account（PAYMENT/RECEIVE/CONTRA/CLAIM 需要，RATE 有特殊处理）
    if (in_array($transaction_type, ['PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM'])) {
        if (!$from_account_id || $from_account_id <= 0) {
            throw new Exception('PAYMENT/RECEIVE/CONTRA/CLAIM 交易必须选择 From Account');
        }
        
        if ($from_account_id == $account_id) {
            throw new Exception('From Account 和 To Account 不能相同');
        }
    }
    
    // 验证账户是否存在且属于当前公司（非 RATE 类型）
    // 支持 account 通过 company_id 或 account_company 表关联到公司
    if (!$is_rate) {
        // 验证 To Account（只使用 account_company 表）
        $stmt = $pdo->prepare("
            SELECT a.id, a.account_id, a.name 
            FROM account a
            INNER JOIN account_company ac ON a.id = ac.account_id
            WHERE a.id = ? AND ac.company_id = ?
        ");
        $stmt->execute([$account_id, $company_id]);
        $to_account = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$to_account) {
            throw new Exception('To Account 不存在或不属于当前公司');
        }
        
        // 验证 From Account（只使用 account_company 表）
        if ($from_account_id) {
            $stmt = $pdo->prepare("
                SELECT a.id, a.account_id, a.name 
                FROM account a
                INNER JOIN account_company ac ON a.id = ac.account_id
                WHERE a.id = ? AND ac.company_id = ?
            ");
            $stmt->execute([$from_account_id, $company_id]);
            $from_account = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$from_account) {
                throw new Exception('From Account 不存在或不属于当前公司');
            }
        }
    }
    
    // 验证 currency 并获取 currency_id，如果不存在则自动创建
    $currency_id = null;
    if (!empty($currency)) {
        $stmt = $pdo->prepare("SELECT id FROM currency WHERE code = ? AND company_id = ?");
        $stmt->execute([$currency, $company_id]);
        $currency_id = $stmt->fetchColumn();
        
        // 如果 currency 不存在于该公司，自动创建
        if (!$currency_id) {
            $currencyCode = strtoupper(trim($currency));
            if (strlen($currencyCode) > 10) {
                throw new Exception('Currency code 长度不能超过 10 个字符');
            }
            
            // 检查该 currency code 是否在其他公司存在（用于验证格式）
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM currency WHERE code = ?");
            $stmt->execute([$currencyCode]);
            $existsElsewhere = $stmt->fetchColumn() > 0;
            
            // 自动创建 currency 到当前公司
            $stmt = $pdo->prepare("INSERT INTO currency (code, company_id) VALUES (?, ?)");
            $stmt->execute([$currencyCode, $company_id]);
            $currency_id = $pdo->lastInsertId();
        }
        
        // 注意：不再检查账户是否在 data_capture_details 中有记录
        // 允许即使没有 data_capture 记录也可以提交交易
    }
    
    // 自动生成 description（如果为空）
    if (empty($description) && in_array($transaction_type, ['PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM'])) {
        // 从 To Account 的视角生成描述
        $description = $transaction_type . ' FROM ' . $from_account['account_id'];
    }
    
    // 开始事务
    $pdo->beginTransaction();
    
    try {
        // 检查 transactions 表是否有 currency_id 字段
        $stmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'currency_id'");
        $has_currency_id = $stmt->rowCount() > 0;
        
        // 处理 RATE 类型
        if ($is_rate) {
            // 获取 RATE 相关参数
            $rate_from_account_id = !empty($_POST['rate_from_account_id']) ? (int)$_POST['rate_from_account_id'] : null;
            $rate_from_currency = trim($_POST['rate_from_currency'] ?? '');
            $rate_from_amount = (float)($_POST['rate_from_amount'] ?? 0);
            $rate_from_description = trim($_POST['rate_from_description'] ?? '');
            
            $rate_to_account_id = !empty($_POST['rate_to_account_id']) ? (int)$_POST['rate_to_account_id'] : null;
            $rate_to_currency = trim($_POST['rate_to_currency'] ?? '');
            $rate_to_amount = (float)($_POST['rate_to_amount'] ?? 0);
            $rate_to_description = trim($_POST['rate_to_description'] ?? '');
            
            // 验证第一个 Account 和 Currency 的记录
            if (!$rate_from_account_id || !$rate_to_account_id) {
                throw new Exception('RATE 交易必须填写第一个 Account 和 Currency');
            }
            
            if ($rate_from_amount <= 0 || $rate_to_amount <= 0) {
                throw new Exception('RATE 交易的金额必须大于 0');
            }
            
            // 验证账户（支持 account_company 表）
            // 检查 account_company 表是否存在
            $has_account_company_table = false;
            try {
                $check_stmt = $pdo->query("SHOW TABLES LIKE 'account_company'");
                $has_account_company_table = $check_stmt->rowCount() > 0;
            } catch (PDOException $e) {
                $has_account_company_table = false;
            }
            
            // 验证 Rate From Account（只使用 account_company 表）
            $stmt = $pdo->prepare("
                SELECT a.id, a.account_id, a.name 
                FROM account a
                INNER JOIN account_company ac ON a.id = ac.account_id
                WHERE a.id = ? AND ac.company_id = ?
            ");
            $stmt->execute([$rate_from_account_id, $company_id]);
            $rate_from_account = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$rate_from_account) {
                throw new Exception('Rate From Account 不存在或不属于当前公司');
            }
            
            // 验证 Rate To Account（只使用 account_company 表）
            $stmt = $pdo->prepare("
                SELECT a.id, a.account_id, a.name 
                FROM account a
                INNER JOIN account_company ac ON a.id = ac.account_id
                WHERE a.id = ? AND ac.company_id = ?
            ");
            $stmt->execute([$rate_to_account_id, $company_id]);
            $rate_to_account = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$rate_to_account) {
                throw new Exception('Rate To Account 不存在或不属于当前公司');
            }
            
            // 验证 currency 并获取 currency_id，如果不存在则自动创建
            $stmt = $pdo->prepare("SELECT id FROM currency WHERE code = ? AND company_id = ?");
            $stmt->execute([$rate_from_currency, $company_id]);
            $rate_from_currency_id = $stmt->fetchColumn();
            if (!$rate_from_currency_id) {
                // 自动创建 currency 到当前公司
                $currencyCode = strtoupper(trim($rate_from_currency));
                if (strlen($currencyCode) > 10) {
                    throw new Exception('Rate From Currency code 长度不能超过 10 个字符');
                }
                $stmt = $pdo->prepare("INSERT INTO currency (code, company_id) VALUES (?, ?)");
                $stmt->execute([$currencyCode, $company_id]);
                $rate_from_currency_id = $pdo->lastInsertId();
            }
            
            $stmt = $pdo->prepare("SELECT id FROM currency WHERE code = ? AND company_id = ?");
            $stmt->execute([$rate_to_currency, $company_id]);
            $rate_to_currency_id = $stmt->fetchColumn();
            if (!$rate_to_currency_id) {
                // 自动创建 currency 到当前公司
                $currencyCode = strtoupper(trim($rate_to_currency));
                if (strlen($currencyCode) > 10) {
                    throw new Exception('Rate To Currency code 长度不能超过 10 个字符');
                }
                $stmt = $pdo->prepare("INSERT INTO currency (code, company_id) VALUES (?, ?)");
                $stmt->execute([$currencyCode, $company_id]);
                $rate_to_currency_id = $pdo->lastInsertId();
            }
            
            $transaction_ids = [];
            
            $rate_exchange_rate = (float)($_POST['rate_exchange_rate'] ?? 0);
            if ($rate_exchange_rate <= 0) {
                throw new Exception('Exchange Rate 必须大于 0');
            }
            
            $rate_transfer_from_account_id = !empty($_POST['rate_transfer_from_account_id']) ? (int)$_POST['rate_transfer_from_account_id'] : null;
            $rate_transfer_to_account_id = !empty($_POST['rate_transfer_to_account_id']) ? (int)$_POST['rate_transfer_to_account_id'] : null;
            $rate_transfer_from_amount = !empty($_POST['rate_transfer_from_amount']) ? (float)$_POST['rate_transfer_from_amount'] : null;
            $rate_transfer_to_amount = !empty($_POST['rate_transfer_to_amount']) ? (float)$_POST['rate_transfer_to_amount'] : null;
            $rate_transfer_from_description = trim($_POST['rate_transfer_from_description'] ?? '');
            $rate_transfer_to_description = trim($_POST['rate_transfer_to_description'] ?? '');
            $rate_transfer_from_currency = trim($_POST['rate_transfer_from_currency'] ?? '');
            $rate_transfer_to_currency = trim($_POST['rate_transfer_to_currency'] ?? '');
            
            $rate_middleman_account_id = !empty($_POST['rate_middleman_account_id']) ? (int)$_POST['rate_middleman_account_id'] : null;
            $rate_middleman_amount = !empty($_POST['rate_middleman_amount']) ? (float)$_POST['rate_middleman_amount'] : null;
            $rate_middleman_description = trim($_POST['rate_middleman_description'] ?? '');
            $rate_middleman_rate = !empty($_POST['rate_middleman_rate']) ? (float)$_POST['rate_middleman_rate'] : null;
            $rate_middleman_currency = trim($_POST['rate_middleman_currency'] ?? $rate_transfer_to_currency ?: $rate_to_currency ?: $rate_from_currency);
            
            if (!$rate_from_account_id || $rate_from_account_id <= 0) {
                throw new Exception('Rate From Account ID 无效');
            }
            if (!$rate_to_account_id || $rate_to_account_id <= 0) {
                throw new Exception('Rate To Account ID 无效');
            }
            
            $rate_group_id = 'RATE_' . time() . '_' . mt_rand(1000, 9999);
            
            if ($has_currency_id) {
                $sql = "INSERT INTO transactions (
                            company_id,
                            transaction_type,
                            account_id,
                            from_account_id,
                            amount,
                            transaction_date,
                            description,
                            sms,
                            currency_id,
                            created_by,
                            created_by_owner
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            } else {
                $sql = "INSERT INTO transactions (
                            company_id,
                            transaction_type,
                            account_id,
                            from_account_id,
                            amount,
                            transaction_date,
                            description,
                            sms,
                            created_by,
                            created_by_owner
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            }
            
            $stmt = $pdo->prepare($sql);
            if ($has_currency_id) {
                $stmt->execute([
                    $company_id,
                    'RATE',
                    $rate_to_account_id,
                    $rate_from_account_id,
                    $rate_from_amount,
                    $transaction_date_db,
                    $rate_from_description,
                    $sms,
                    $rate_from_currency_id,
                    $created_by_user,
                    $created_by_owner
                ]);
            } else {
                $stmt->execute([
                    $company_id,
                    'RATE',
                    $rate_to_account_id,
                    $rate_from_account_id,
                    $rate_from_amount,
                    $transaction_date_db,
                    $rate_from_description,
                    $sms,
                    $created_by_user,
                    $created_by_owner
                ]);
            }
            $main_transaction_id = $pdo->lastInsertId();
            $transaction_ids[] = $main_transaction_id;
            
            $rate_transfer_currency = $rate_transfer_to_currency ?: $rate_to_currency;
            if ($rate_transfer_currency) {
                $stmt = $pdo->prepare("SELECT id FROM currency WHERE code = ? AND company_id = ?");
                $stmt->execute([$rate_transfer_currency, $company_id]);
                $rate_transfer_currency_id = $stmt->fetchColumn();
                if (!$rate_transfer_currency_id) {
                    // 自动创建 currency 到当前公司
                    $currencyCode = strtoupper(trim($rate_transfer_currency));
                    if (strlen($currencyCode) > 10) {
                        throw new Exception('Rate Transfer Currency code 长度不能超过 10 个字符');
                    }
                    $stmt = $pdo->prepare("INSERT INTO currency (code, company_id) VALUES (?, ?)");
                    $stmt->execute([$currencyCode, $company_id]);
                    $rate_transfer_currency_id = $pdo->lastInsertId();
                }
            } else {
                $rate_transfer_currency_id = $rate_to_currency_id;
            }
            
            if ($rate_middleman_account_id) {
                if ($rate_middleman_currency) {
                    $stmt = $pdo->prepare("SELECT id FROM currency WHERE code = ? AND company_id = ?");
                    $stmt->execute([$rate_middleman_currency, $company_id]);
                    $rate_middleman_currency_id = $stmt->fetchColumn();
                    if (!$rate_middleman_currency_id) {
                        // 自动创建 currency 到当前公司
                        $currencyCode = strtoupper(trim($rate_middleman_currency));
                        if (strlen($currencyCode) > 10) {
                            throw new Exception('Rate Middleman Currency code 长度不能超过 10 个字符');
                        }
                        $stmt = $pdo->prepare("INSERT INTO currency (code, company_id) VALUES (?, ?)");
                        $stmt->execute([$currencyCode, $company_id]);
                        $rate_middleman_currency_id = $pdo->lastInsertId();
                    }
                } else {
                    $rate_middleman_currency_id = $rate_transfer_currency_id;
                }
            } else {
                $rate_middleman_currency_id = null;
            }
            
            $stmt = $pdo->prepare("INSERT INTO transactions_rate (
                transaction_id, company_id, rate_group_id,
                rate_from_account_id, rate_to_account_id,
                rate_from_currency_id, rate_from_amount,
                rate_to_currency_id, rate_to_amount, exchange_rate,
                rate_transfer_from_account_id, rate_transfer_to_account_id,
                rate_transfer_from_amount, rate_transfer_to_amount,
                rate_middleman_account_id, rate_middleman_rate, rate_middleman_amount
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->execute([
                $main_transaction_id,
                $company_id,
                $rate_group_id,
                $rate_from_account_id,
                $rate_to_account_id,
                $rate_from_currency_id,
                $rate_from_amount,
                $rate_to_currency_id,
                $rate_to_amount,
                $rate_exchange_rate,
                $rate_transfer_from_account_id,
                $rate_transfer_to_account_id,
                $rate_transfer_from_amount,
                $rate_transfer_to_amount,
                $rate_middleman_account_id,
                $rate_middleman_rate,
                $rate_middleman_amount
            ]);
            
            $stmt = $pdo->prepare("INSERT INTO transactions_rate_details (
                rate_group_id, transaction_id, company_id, record_type,
                account_id, from_account_id, amount, currency_id, description
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $details_stmt = $pdo->prepare("INSERT INTO transactions_rate_details (
                rate_group_id, transaction_id, company_id, record_type,
                account_id, from_account_id, amount, currency_id, description
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $details_stmt->execute([
                $rate_group_id, $main_transaction_id, $company_id, 'first_from',
                $rate_from_account_id, null, $rate_from_amount, $rate_from_currency_id,
                $rate_from_description
            ]);
            
            $details_stmt->execute([
                $rate_group_id, $main_transaction_id, $company_id, 'first_to',
                // 第一行两个 Account 都跟随第一个币种（例如 SGD），金额都是 rate_from_amount（例如 100）
                $rate_to_account_id, null, $rate_from_amount, $rate_from_currency_id,
                $rate_to_description
            ]);
            
            if ($rate_transfer_from_account_id && $rate_transfer_to_account_id) {
                if (!$rate_transfer_from_amount || !$rate_transfer_to_amount) {
                    throw new Exception('Transfer Account 必须填写金额');
                }
                
                // 验证 Transfer 账户（只使用 account_company 表）
                $stmt = $pdo->prepare("
                    SELECT a.id, a.account_id, a.name 
                    FROM account a
                    INNER JOIN account_company ac ON a.id = ac.account_id
                    WHERE a.id = ? AND ac.company_id = ?
                ");
                $stmt->execute([$rate_transfer_from_account_id, $company_id]);
                if (!$stmt->fetchColumn()) {
                    throw new Exception('Rate Transfer From Account 不存在或不属于当前公司');
                }
                
                $stmt = $pdo->prepare("
                    SELECT a.id, a.account_id, a.name 
                    FROM account a
                    INNER JOIN account_company ac ON a.id = ac.account_id
                    WHERE a.id = ? AND ac.company_id = ?
                ");
                $stmt->execute([$rate_transfer_to_account_id, $company_id]);
                if (!$stmt->fetchColumn()) {
                    throw new Exception('Rate Transfer To Account 不存在或不属于当前公司');
                }
                
                $stmt = $pdo->prepare($sql);
                if ($has_currency_id) {
                    $stmt->execute([
                        $company_id,
                        'RATE',
                        $rate_transfer_to_account_id,
                        $rate_transfer_from_account_id,
                        $rate_transfer_to_amount,
                        $transaction_date_db,
                        $rate_transfer_from_description,
                        $sms,
                        $rate_transfer_currency_id,
                        $created_by_user,
                        $created_by_owner
                    ]);
                } else {
                    $stmt->execute([
                        $company_id,
                        'RATE',
                        $rate_transfer_to_account_id,
                        $rate_transfer_from_account_id,
                        $rate_transfer_to_amount,
                        $transaction_date_db,
                        $rate_transfer_from_description,
                        $sms,
                        $created_by_user,
                        $created_by_owner
                    ]);
                }
                $transfer_transaction_id = $pdo->lastInsertId();
                $transaction_ids[] = $transfer_transaction_id;
                
                $details_stmt->execute([
                $rate_group_id, $transfer_transaction_id, $company_id, 'transfer_from',
                    $rate_transfer_from_account_id, $rate_transfer_from_account_id,
                    $rate_transfer_from_amount, $rate_transfer_currency_id,
                    $rate_transfer_from_description
                ]);
                
                $details_stmt->execute([
                $rate_group_id, $transfer_transaction_id, $company_id, 'transfer_to',
                    $rate_transfer_to_account_id, null,
                    $rate_transfer_to_amount, $rate_transfer_currency_id,
                    $rate_transfer_to_description
                ]);
                
                if ($rate_middleman_account_id && $rate_middleman_amount > 0) {
                    // 验证 Middleman 账户（只使用 account_company 表）
                    $stmt = $pdo->prepare("
                        SELECT a.id, a.account_id, a.name 
                        FROM account a
                        INNER JOIN account_company ac ON a.id = ac.account_id
                        WHERE a.id = ? AND ac.company_id = ?
                    ");
                    $stmt->execute([$rate_middleman_account_id, $company_id]);
                    if (!$stmt->fetchColumn()) {
                        throw new Exception('Rate Middleman Account 不存在或不属于当前公司');
                    }
                    
                    $stmt = $pdo->prepare($sql);
                    if ($has_currency_id) {
                        $stmt->execute([
                            $company_id,
                            'RATE',
                            $rate_middleman_account_id,
                            null,
                            $rate_middleman_amount,
                            $transaction_date_db,
                            $rate_middleman_description,
                            $sms,
                            $rate_middleman_currency_id,
                            $created_by_user,
                            $created_by_owner
                        ]);
                    } else {
                        $stmt->execute([
                            $company_id,
                            'RATE',
                            $rate_middleman_account_id,
                            null,
                            $rate_middleman_amount,
                            $transaction_date_db,
                            $rate_middleman_description,
                            $sms,
                            $created_by_user,
                            $created_by_owner
                        ]);
                    }
                    $middleman_transaction_id = $pdo->lastInsertId();
                    $transaction_ids[] = $middleman_transaction_id;
                    
                    $details_stmt->execute([
                    $rate_group_id, $middleman_transaction_id, $company_id, 'middleman',
                        $rate_middleman_account_id, null,
                        $rate_middleman_amount, $rate_middleman_currency_id,
                        $rate_middleman_description
                    ]);
                    
                    $middleman_deduction = $rate_transfer_from_amount - $rate_transfer_to_amount;
                    if (abs($middleman_deduction) > 0.01) {
                        $stmt = $pdo->prepare($sql);
                        if ($has_currency_id) {
                            $stmt->execute([
                                $company_id,
                                'RATE',
                                $rate_transfer_from_account_id,
                                $rate_transfer_from_account_id,
                                $middleman_deduction,
                                $transaction_date_db,
                                $rate_middleman_description,
                                $sms,
                                $rate_transfer_currency_id,
                                $created_by_user,
                                $created_by_owner
                            ]);
                        } else {
                            $stmt->execute([
                                $company_id,
                                'RATE',
                                $rate_transfer_from_account_id,
                                $rate_transfer_from_account_id,
                                $middleman_deduction,
                                $transaction_date_db,
                                $rate_middleman_description,
                                $sms,
                                $created_by_user,
                                $created_by_owner
                            ]);
                        }
                        $middleman_deduction_transaction_id = $pdo->lastInsertId();
                        $transaction_ids[] = $middleman_deduction_transaction_id;
                        
                        $details_stmt->execute([
                        $rate_group_id, $middleman_deduction_transaction_id, $company_id, 'transfer_from',
                            $rate_transfer_from_account_id, $rate_transfer_from_account_id,
                            $middleman_deduction, $rate_transfer_currency_id,
                            $rate_middleman_description
                        ]);
                    }
                }
            }

            // ==================== 写入统一分录表 transaction_entry（仅针对 RATE） ====================
            try {
                $entrySql = "INSERT INTO transaction_entry
                    (header_id, company_id, account_id, currency_id, amount, entry_type, description)
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
                $entryStmt = $pdo->prepare($entrySql);

                // 1) 第一行：全部跟随第一个币种（例如 SGD），金额 = rate_from_amount（例如 100）
                $sgdAmount      = (float)$rate_from_amount;
                $sgdCurrencyId  = (int)$rate_from_currency_id;

                // From account：减
                $entryStmt->execute([
                    $main_transaction_id,
                    $company_id,
                    $rate_from_account_id,
                    $sgdCurrencyId,
                    -$sgdAmount,
                    'RATE_FIRST_FROM',
                    $rate_from_description
                ]);

                // To account：加
                $entryStmt->execute([
                    $main_transaction_id,
                    $company_id,
                    $rate_to_account_id,
                    $sgdCurrencyId,
                    $sgdAmount,
                    'RATE_FIRST_TO',
                    $rate_to_description
                ]);

                // 2) 第二行：全部跟随第二个币种（例如 MYR）
                if ($rate_transfer_from_account_id && $rate_transfer_to_account_id && $rate_transfer_currency_id) {
                    $myrFromAmount = (float)$rate_transfer_from_amount; // 例如 330
                    $myrToAmount   = (float)$rate_transfer_to_amount;   // 例如 320
                    $myrCurrencyId = (int)$rate_transfer_currency_id;

                    // account3：MYR 减 330
                    $entryStmt->execute([
                        $main_transaction_id,
                        $company_id,
                        $rate_transfer_from_account_id,
                        $myrCurrencyId,
                        -$myrFromAmount,
                        'RATE_TRANSFER_FROM',
                        $rate_transfer_from_description
                    ]);

                    // account4：MYR 加 320
                    $entryStmt->execute([
                        $main_transaction_id,
                        $company_id,
                        $rate_transfer_to_account_id,
                        $myrCurrencyId,
                        $myrToAmount,
                        'RATE_TRANSFER_TO',
                        $rate_transfer_to_description
                    ]);

                    // Middle-man：MYR 加手续费（如果存在）
                    if ($rate_middleman_account_id && $rate_middleman_amount > 0) {
                        $middleAmount = (float)$rate_middleman_amount;
                        $middleCurrencyId = (int)$rate_middleman_currency_id ?: $myrCurrencyId;

                        $entryStmt->execute([
                            $main_transaction_id,
                            $company_id,
                            $rate_middleman_account_id,
                            $middleCurrencyId,
                            $middleAmount,
                            'RATE_MIDDLEMAN',
                            $rate_middleman_description
                        ]);
                    }
                }
            } catch (Exception $e) {
                // 为了兼容旧数据，如果分录表写入失败，不阻止主交易提交，只记录日志
                error_log('Failed to insert RATE entries into transaction_entry: ' . $e->getMessage());
            }

            // 提交事务
            $pdo->commit();
            
            // 返回成功响应
            echo json_encode([
                'success' => true,
                'message' => 'RATE 交易提交成功，共创建 ' . count($transaction_ids) . ' 条记录',
                'data' => [
                    'transaction_ids' => $transaction_ids,
                    'transaction_type' => $transaction_type,
                    'transaction_date' => $transaction_date
                ]
            ]);
            
        } else {
            // 非 RATE 类型的原有逻辑
            // 确保金额是正数（对于所有交易类型）
            $amount = abs($amount);
            
            // 插入交易记录（只创建一条记录，余额计算逻辑会自动处理 From Account 和 To Account）
            if ($has_currency_id) {
                // 如果表有 currency_id 字段，使用它
                $sql = "INSERT INTO transactions (
                            company_id,
                            transaction_type,
                            account_id,
                            from_account_id,
                            amount,
                            transaction_date,
                            description,
                            sms,
                            currency_id,
                            created_by,
                            created_by_owner
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $company_id,
                    $transaction_type,
                    $account_id,
                    $from_account_id,
                    $amount,
                    $transaction_date_db,
                    $description,
                    $sms,
                    $currency_id,
                    $created_by_user,
                    $created_by_owner
                ]);
            } else {
                // 如果表没有 currency_id 字段，使用旧的方式
                $sql = "INSERT INTO transactions (
                            company_id,
                            transaction_type,
                            account_id,
                            from_account_id,
                            amount,
                            transaction_date,
                            description,
                            sms,
                            created_by,
                            created_by_owner
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $company_id,
                    $transaction_type,
                    $account_id,
                    $from_account_id,
                    $amount,
                    $transaction_date_db,
                    $description,
                    $sms,
                    $created_by_user,
                    $created_by_owner
                ]);
            }
            
            $transaction_id = $pdo->lastInsertId();
        
        // 提交事务
        $pdo->commit();
        
        // 返回成功响应
        echo json_encode([
            'success' => true,
            'message' => '交易提交成功',
            'data' => [
                'transaction_id' => $transaction_id,
                'transaction_type' => $transaction_type,
                'to_account' => $to_account['account_id'] . ' - ' . $to_account['name'],
                'from_account' => $from_account ? $from_account['account_id'] . ' - ' . $from_account['name'] : null,
                'amount' => number_format($amount, 2),
                'transaction_date' => $transaction_date
            ]
        ]);
        }
        
    } catch (Exception $e) {
        // 回滚事务
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

