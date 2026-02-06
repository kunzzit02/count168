<?php
/**
 * 调试文件：检查为什么admin用户删除不掉
 * 
 * 这个文件会检查：
 * 1. admin用户的基本信息
 * 2. admin用户是否被其他表引用
 * 3. 是否有其他用户可以作为替换
 * 4. 所有可能阻止删除的原因
 */

header('Content-Type: text/html; charset=utf-8');
require_once 'config.php';

session_start();

// 如果没有登录，可以手动设置要检查的用户ID
$admin_user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;
$admin_login_id = isset($_GET['login_id']) ? trim($_GET['login_id']) : null;

// 如果提供了login_id，先查找对应的用户ID
if ($admin_login_id && !$admin_user_id) {
    try {
        $stmt = $pdo->prepare("SELECT id FROM user WHERE login_id = ?");
        $stmt->execute([$admin_login_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $admin_user_id = $result['id'];
        }
    } catch (PDOException $e) {
        die("查询用户ID失败: " . $e->getMessage());
    }
}

// 如果没有提供任何参数，尝试查找所有admin角色的用户
if (!$admin_user_id && !$admin_login_id) {
    try {
        $stmt = $pdo->prepare("SELECT id, login_id, name, company_id FROM user WHERE role = 'admin' ORDER BY id");
        $stmt->execute();
        $admin_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($admin_users)) {
            die("未找到admin用户。请使用 ?login_id=ADMIN001 或 ?user_id=1 来指定要检查的用户。");
        }
        
        if (count($admin_users) > 1) {
            echo "<h2>找到多个admin用户，请选择：</h2>";
            echo "<ul>";
            foreach ($admin_users as $user) {
                echo "<li><a href='?user_id={$user['id']}'>{$user['login_id']} (ID: {$user['id']}, Name: {$user['name']}, Company: {$user['company_id']})</a></li>";
            }
            echo "</ul>";
            exit;
        } else {
            $admin_user_id = $admin_users[0]['id'];
            $admin_login_id = $admin_users[0]['login_id'];
        }
    } catch (PDOException $e) {
        die("查询admin用户失败: " . $e->getMessage());
    }
}

if (!$admin_user_id) {
    die("请提供user_id或login_id参数。例如：?login_id=ADMIN001 或 ?user_id=1");
}

$debug_info = [];
$errors = [];
$warnings = [];

try {
    // 1. 获取admin用户的基本信息
    $stmt = $pdo->prepare("SELECT * FROM user WHERE id = ?");
    $stmt->execute([$admin_user_id]);
    $admin_user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin_user) {
        die("用户ID {$admin_user_id} 不存在！");
    }
    
    $debug_info['user_info'] = $admin_user;
    $company_id = $admin_user['company_id'];
    
    echo "<!DOCTYPE html>
    <html lang='zh-CN'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Admin删除调试信息</title>
        <link rel="stylesheet" href="css/debug_admin_delete.css">
    </head>
    <body>
    <div class='container'>
        <h1>🔍 Admin用户删除调试信息</h1>";
    
    echo "<div class='info-box'>
            <h3>用户基本信息</h3>
            <p><strong>ID:</strong> {$admin_user['id']}</p>
            <p><strong>Login ID:</strong> {$admin_user['login_id']}</p>
            <p><strong>Name:</strong> {$admin_user['name']}</p>
            <p><strong>Email:</strong> {$admin_user['email']}</p>
            <p><strong>Role:</strong> {$admin_user['role']}</p>
            <p><strong>Status:</strong> {$admin_user['status']}</p>
            <p><strong>Company ID:</strong> {$company_id}</p>
            <p><strong>Created By:</strong> " . ($admin_user['created_by'] ?? 'NULL') . "</p>
            <p><strong>Created At:</strong> {$admin_user['created_at']}</p>
        </div>";
    
    // 2. 检查是否是当前登录用户
    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $admin_user_id) {
        $warnings[] = "⚠️ 这是当前登录的用户！如果删除自己，系统可能会出错。";
    }
    
    // 3. 检查是否是owner影子
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM owner o
        INNER JOIN company c ON c.owner_id = o.id
        WHERE o.id = ? AND c.id = ?
    ");
    $stmt->execute([$admin_user_id, $company_id]);
    $is_owner_shadow = $stmt->fetchColumn() > 0;
    
    if ($is_owner_shadow) {
        $errors[] = "❌ 这是owner影子记录，不能删除！";
    } else {
        $debug_info['is_owner_shadow'] = false;
    }
    
    // 4. 检查其他用户数量（用于替换）
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM user WHERE company_id = ? AND id != ?");
    $stmt->execute([$company_id, $admin_user_id]);
    $other_users_count = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM user WHERE company_id = ? AND id != ? AND status = 'active'");
    $stmt->execute([$company_id, $admin_user_id]);
    $other_active_users_count = $stmt->fetchColumn();
    
    $replacement_users = [];
    if ($other_users_count > 0) {
        $stmt = $pdo->prepare("SELECT id, login_id, name, role, status FROM user WHERE company_id = ? AND id != ? ORDER BY status DESC, id LIMIT 5");
        $stmt->execute([$company_id, $admin_user_id]);
        $replacement_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo "<div class='section'>
            <h2>📊 替换用户检查</h2>
            <p><strong>同公司其他用户总数:</strong> <span class='count-badge " . ($other_users_count > 0 ? 'count-zero' : 'count-critical') . "'>{$other_users_count}</span></p>
            <p><strong>同公司其他活动用户数:</strong> <span class='count-badge " . ($other_active_users_count > 0 ? 'count-zero' : 'count-critical') . "'>{$other_active_users_count}</span></p>";
    
    if ($other_users_count == 0) {
        $errors[] = "❌ 没有其他用户可以作为替换！这是公司唯一用户。";
    } elseif ($other_active_users_count == 0) {
        $warnings[] = "⚠️ 没有其他活动用户，可能影响替换逻辑。";
    }
    
    if (!empty($replacement_users)) {
        echo "<h3>可用替换用户（前5个）：</h3><table>
                <tr><th>ID</th><th>Login ID</th><th>Name</th><th>Role</th><th>Status</th></tr>";
        foreach ($replacement_users as $user) {
            echo "<tr>
                    <td>{$user['id']}</td>
                    <td>{$user['login_id']}</td>
                    <td>{$user['name']}</td>
                    <td>{$user['role']}</td>
                    <td>{$user['status']}</td>
                  </tr>";
        }
        echo "</table>";
    }
    echo "</div>";
    
    // 5. 检查各个表中的引用
    $references = [];
    
    // transactions表 - created_by (NOT NULL)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE created_by = ?");
    $stmt->execute([$admin_user_id]);
    $transactions_count = $stmt->fetchColumn();
    $references['transactions (created_by)'] = [
        'count' => $transactions_count,
        'nullable' => false,
        'description' => '交易记录 - created_by字段（NOT NULL）'
    ];
    
    // submitted_processes表 - user_id (NOT NULL)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM submitted_processes WHERE user_id = ?");
    $stmt->execute([$admin_user_id]);
    $submitted_count = $stmt->fetchColumn();
    $references['submitted_processes (user_id)'] = [
        'count' => $submitted_count,
        'nullable' => false,
        'description' => '提交的处理记录 - user_id字段（NOT NULL）'
    ];
    
    // data_captures表 - created_by (允许NULL)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM data_captures WHERE created_by = ?");
    $stmt->execute([$admin_user_id]);
    $data_captures_count = $stmt->fetchColumn();
    $references['data_captures (created_by)'] = [
        'count' => $data_captures_count,
        'nullable' => true,
        'description' => '数据捕获记录 - created_by字段（允许NULL）'
    ];
    
    // process表 - created_by
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM process WHERE created_by = ?");
    $stmt->execute([$admin_user_id]);
    $process_created_count = $stmt->fetchColumn();
    
    // process表 - modified_by
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM process WHERE modified_by = ?");
    $stmt->execute([$admin_user_id]);
    $process_modified_count = $stmt->fetchColumn();
    $references['process (created_by, modified_by)'] = [
        'count' => $process_created_count + $process_modified_count,
        'nullable' => true,
        'description' => '流程记录 - created_by和modified_by字段'
    ];
    
    // company表 - created_by
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE created_by = ?");
    $stmt->execute([$admin_user_id]);
    $company_count = $stmt->fetchColumn();
    $references['company (created_by)'] = [
        'count' => $company_count,
        'nullable' => true,
        'description' => '公司记录 - created_by字段'
    ];
    
    echo "<div class='section'>
            <h2>🔗 外键引用检查</h2>
            <table>
                <tr>
                    <th>表名和字段</th>
                    <th>引用数量</th>
                    <th>字段是否允许NULL</th>
                    <th>说明</th>
                </tr>";
    
    $total_not_null_references = 0;
    foreach ($references as $key => $ref) {
        $badge_class = $ref['count'] > 0 ? ($ref['nullable'] ? 'count-positive' : 'count-critical') : 'count-zero';
        $nullable_text = $ref['nullable'] ? '是' : '❌ 否（需要替换用户）';
        
        echo "<tr>
                <td><code>{$key}</code></td>
                <td><span class='count-badge {$badge_class}'>{$ref['count']}</span></td>
                <td>{$nullable_text}</td>
                <td>{$ref['description']}</td>
              </tr>";
        
        if (!$ref['nullable'] && $ref['count'] > 0) {
            $total_not_null_references += $ref['count'];
            if ($other_users_count == 0) {
                $errors[] = "❌ {$key} 有 {$ref['count']} 条记录引用此用户，且字段不允许NULL，但没有其他用户可以替换！";
            }
        }
    }
    
    echo "</table></div>";
    
    // 6. 检查是否有外键约束（数据库层面）
    echo "<div class='section'>
            <h2>🔒 数据库约束检查</h2>";
    
    try {
        // 检查user表的外键约束
        $stmt = $pdo->query("
            SELECT 
                CONSTRAINT_NAME,
                TABLE_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM 
                INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE 
                REFERENCED_TABLE_NAME = 'user'
                AND TABLE_SCHEMA = DATABASE()
        ");
        $foreign_keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($foreign_keys)) {
            echo "<p class='info-box'>✅ 没有发现引用user表的外键约束。</p>";
        } else {
            echo "<table>
                    <tr>
                        <th>约束名称</th>
                        <th>引用表</th>
                        <th>引用字段</th>
                        <th>被引用表</th>
                        <th>被引用字段</th>
                    </tr>";
            foreach ($foreign_keys as $fk) {
                echo "<tr>
                        <td>{$fk['CONSTRAINT_NAME']}</td>
                        <td>{$fk['TABLE_NAME']}</td>
                        <td>{$fk['COLUMN_NAME']}</td>
                        <td>{$fk['REFERENCED_TABLE_NAME']}</td>
                        <td>{$fk['REFERENCED_COLUMN_NAME']}</td>
                      </tr>";
            }
            echo "</table>";
        }
    } catch (PDOException $e) {
        $warnings[] = "⚠️ 无法检查外键约束: " . $e->getMessage();
    }
    
    echo "</div>";
    
    // 7. 模拟删除流程检查
    echo "<div class='section'>
            <h2>🧪 删除流程模拟检查</h2>";
    
    if ($is_owner_shadow) {
        echo "<div class='error-box'><strong>❌ 删除会被阻止：</strong>这是owner影子记录，删除代码会直接返回错误。</div>";
    } else if ($other_users_count == 0 && $total_not_null_references > 0) {
        echo "<div class='error-box'><strong>❌ 删除会被阻止：</strong>没有其他用户可以作为替换，但存在NOT NULL字段的引用。</div>";
    } else if ($total_not_null_references > 0 && $other_active_users_count == 0) {
        echo "<div class='warning-box'><strong>⚠️ 删除可能有问题：</strong>存在NOT NULL字段的引用，但没有活动用户作为替换。</div>";
    } else {
        echo "<div class='success-box'><strong>✅ 理论上可以删除：</strong>没有发现阻止删除的硬性条件。</div>";
    }
    
    echo "</div>";
    
    // 8. 总结所有错误和警告
    echo "<div class='section'>
            <h2>📋 问题总结</h2>";
    
    if (!empty($errors)) {
        echo "<div class='error-box'><h3>❌ 错误（会阻止删除）：</h3><ul>";
        foreach ($errors as $error) {
            echo "<li>{$error}</li>";
        }
        echo "</ul></div>";
    }
    
    if (!empty($warnings)) {
        echo "<div class='warning-box'><h3>⚠️ 警告（可能影响删除）：</h3><ul>";
        foreach ($warnings as $warning) {
            echo "<li>{$warning}</li>";
        }
        echo "</ul></div>";
    }
    
    if (empty($errors) && empty($warnings)) {
        echo "<div class='success-box'><h3>✅ 没有发现明显问题</h3><p>如果仍然无法删除，可能是其他原因（如权限问题、会话问题等）。</p></div>";
    }
    
    echo "</div>";
    
    // 9. 建议的解决方案
    echo "<div class='section'>
            <h2>💡 建议的解决方案</h2>
            <div class='info-box'>";
    
    if ($is_owner_shadow) {
        echo "<p><strong>问题：</strong>这是owner影子记录</p>";
        echo "<p><strong>解决方案：</strong>owner记录不能删除，因为它是公司的所有者。如果需要，可以修改owner信息，但不能删除。</p>";
    } elseif ($other_users_count == 0 && $total_not_null_references > 0) {
        echo "<p><strong>问题：</strong>没有其他用户可以作为替换，但存在NOT NULL字段的引用</p>";
        echo "<p><strong>解决方案：</strong></p>";
        echo "<ol>
                <li>创建另一个用户（可以是临时用户）</li>
                <li>或者手动将引用此用户的记录更新为其他值（如果业务允许）</li>
                <li>然后再尝试删除</li>
              </ol>";
    } elseif ($total_not_null_references > 0) {
        echo "<p><strong>问题：</strong>存在NOT NULL字段的引用</p>";
        echo "<p><strong>解决方案：</strong>删除代码会自动将引用更新为其他用户。确保有至少一个其他用户（最好是活动用户）存在。</p>";
    } else {
        echo "<p><strong>状态：</strong>理论上可以删除</p>";
        echo "<p><strong>如果仍然无法删除，请检查：</p>";
        echo "<ol>
                <li>是否是当前登录用户（尝试用其他账户登录后删除）</li>
                <li>是否有其他隐藏的数据库约束</li>
                <li>查看PHP错误日志获取详细错误信息</li>
                <li>检查会话和权限设置</li>
              </ol>";
    }
    
    echo "</div></div>";
    
    // 10. 调试SQL查询
    echo "<div class='section'>
            <h2>🔧 调试SQL查询</h2>
            <div class='info-box'>
                <p>可以手动执行以下SQL来测试删除（<strong>注意：不要在生产环境直接执行</strong>）：</p>
                <pre style='background: #f5f5f5; padding: 15px; border-radius: 4px; overflow-x: auto;'>";
    
    echo "-- 1. 查看用户的详细信息\n";
    echo "SELECT * FROM user WHERE id = {$admin_user_id};\n\n";
    
    echo "-- 2. 查看所有引用此用户的记录数量\n";
    echo "SELECT 'transactions' as table_name, COUNT(*) as count FROM transactions WHERE created_by = {$admin_user_id}\n";
    echo "UNION ALL\n";
    echo "SELECT 'submitted_processes', COUNT(*) FROM submitted_processes WHERE user_id = {$admin_user_id}\n";
    echo "UNION ALL\n";
    echo "SELECT 'data_captures', COUNT(*) FROM data_captures WHERE created_by = {$admin_user_id}\n";
    echo "UNION ALL\n";
    echo "SELECT 'process (created_by)', COUNT(*) FROM process WHERE created_by = {$admin_user_id}\n";
    echo "UNION ALL\n";
    echo "SELECT 'process (modified_by)', COUNT(*) FROM process WHERE modified_by = {$admin_user_id}\n";
    echo "UNION ALL\n";
    echo "SELECT 'company', COUNT(*) FROM company WHERE created_by = {$admin_user_id};\n\n";
    
    if ($other_users_count > 0 && $replacement_users) {
        $replacement_id = $replacement_users[0]['id'];
        echo "-- 3. 手动更新引用（替换为用户ID: {$replacement_id}）\n";
        echo "-- 注意：这只是示例，实际执行前请先备份数据库！\n";
        echo "-- BEGIN;\n";
        echo "-- UPDATE transactions SET created_by = {$replacement_id} WHERE created_by = {$admin_user_id};\n";
        echo "-- UPDATE submitted_processes SET user_id = {$replacement_id} WHERE user_id = {$admin_user_id};\n";
        echo "-- UPDATE data_captures SET created_by = NULL WHERE created_by = {$admin_user_id};\n";
        echo "-- UPDATE process SET created_by = {$replacement_id} WHERE created_by = {$admin_user_id};\n";
        echo "-- UPDATE process SET modified_by = {$replacement_id} WHERE modified_by = {$admin_user_id};\n";
        echo "-- UPDATE company SET created_by = {$replacement_id} WHERE created_by = {$admin_user_id};\n";
        echo "-- DELETE FROM user WHERE id = {$admin_user_id} AND company_id = {$company_id};\n";
        echo "-- COMMIT;\n";
    }
    
    echo "</pre></div></div>";
    
    echo "<div class='info-box' style='margin-top: 30px;'>
            <p><strong>📝 提示：</strong>生成时间: " . date('Y-m-d H:i:s') . "</p>
            <p><a href='?user_id={$admin_user_id}'>刷新此页面</a> | <a href='userlist.php'>返回用户列表</a></p>
          </div>";
    
    echo "</div></body></html>";
    
} catch (PDOException $e) {
    die("<div class='error-box'><h2>数据库错误</h2><p>" . htmlspecialchars($e->getMessage()) . "</p></div>");
} catch (Exception $e) {
    die("<div class='error-box'><h2>错误</h2><p>" . htmlspecialchars($e->getMessage()) . "</p></div>");
}
?>

