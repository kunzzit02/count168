<?php
// 使用统一的session检查
require_once 'session_check.php';

// 不缓存 HTML，部署后刷新即可拿到带最新 ?v= 的页面
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// 获取 company_id（session_check.php已确保用户已登录）
$current_user_role = $_SESSION['role'] ?? '';
$current_user_id = $_SESSION['user_id'] ?? null;

// 获取当前用户关联的所有 company（用于显示 company 按钮）
$user_companies = [];
try {
    if ($current_user_id) {
        // 如果是 owner，获取所有拥有的 company
        if ($current_user_role === 'owner') {
            $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
            $stmt = $pdo->prepare("SELECT id, company_id FROM company WHERE owner_id = ? ORDER BY company_id ASC");
            $stmt->execute([$owner_id]);
            $user_companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // 普通用户，获取通过 user_company_map 关联的 company
            $stmt = $pdo->prepare("
                SELECT DISTINCT c.id, c.company_id 
                FROM company c
                INNER JOIN user_company_map ucm ON c.id = ucm.company_id
                WHERE ucm.user_id = ?
                ORDER BY c.company_id ASC
            ");
            $stmt->execute([$current_user_id]);
            $user_companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch(PDOException $e) {
    error_log("Failed to get user company list: " . $e->getMessage());
}

// 如果 URL 中有 company_id 参数，使用它（用于切换 company）
$company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : ($_SESSION['company_id'] ?? null);

// 验证 company_id 是否属于当前用户
if ($current_user_id && count($user_companies) > 0) {
    $valid_company = false;
    if ($company_id) {
        foreach ($user_companies as $comp) {
            if ($comp['id'] == $company_id) {
                $valid_company = true;
                break;
            }
        }
    }
    if (!$valid_company) {
        // 如果 company_id 无效或不存在，使用第一个 company
        $company_id = $user_companies[0]['id'];
        // 更新 session（确保登录后默认使用第一个 company）
        $_SESSION['company_id'] = $company_id;
    } elseif (isset($_GET['company_id']) && $company_id == (int)$_GET['company_id']) {
        // 如果 URL 中有 company_id 参数且验证通过，更新 session（实现跨页面同步）
        $_SESSION['company_id'] = $company_id;
    } elseif (!isset($_GET['company_id']) && $company_id == $_SESSION['company_id']) {
        // 如果使用 session 中的 company_id 且有效，确保 session 已设置（登录时设置的）
        $_SESSION['company_id'] = $company_id;
    }
} else {
    // 如果没有关联的 company，使用 session 中的 company_id
    $company_id = $_SESSION['company_id'] ?? null;
}

// Get owner shadow record
$owner_shadow = null;
try {
    $stmt = $pdo->prepare("
        SELECT o.id, o.owner_code as login_id, o.name, o.email, 'owner' as role, o.status, NULL as last_login, NULL as created_by, 1 as is_owner_shadow
        FROM owner o
        INNER JOIN company c ON c.owner_id = o.id
        WHERE c.id = ?
    ");
    $stmt->execute([$company_id]);
    $owner_shadow = $stmt->fetch(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    // 如果查询失败，继续执行，不影响其他用户显示
    error_log("Failed to get owner shadow record: " . $e->getMessage());
}

// Get users data
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            u.id,
            u.login_id,
            u.name,
            u.email,
            u.role,
            u.status,
            u.last_login,
            u.created_by,
            0 as is_owner_shadow
        FROM user u
        INNER JOIN user_company_map ucm ON u.id = ucm.user_id
        WHERE ucm.company_id = ?
        ORDER BY 
        CASE 
            WHEN login_id REGEXP '^[0-9]' THEN 0 
            ELSE 1 
        END,
        CASE 
            WHEN login_id REGEXP '^[0-9]' THEN CAST(login_id AS UNSIGNED)
            ELSE ASCII(UPPER(login_id))
        END,
        login_id ASC");
    $stmt->execute([$company_id]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 将owner影子记录添加到列表最前面（只有owner账号自己能看到）
    if ($owner_shadow && $current_user_role === 'owner') {
        array_unshift($users, $owner_shadow);
    }
} catch(PDOException $e) {
    die("Query failed: " . $e->getMessage());
}

// Get accounts data - filter current company through account_company association table
try {
    $accountStmt = $pdo->prepare("
        SELECT 
            a.id, 
            a.account_id, 
            a.name, 
            a.status
        FROM account a
        INNER JOIN account_company ac ON ac.account_id = a.id
        WHERE ac.company_id = ?
        ORDER BY a.account_id ASC
    ");
    $accountStmt->execute([$company_id]);
    $accounts = $accountStmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    error_log("Account query failed: " . $e->getMessage());
    $accounts = [];
}

// Get processes data - filter by same company_id
// 注意：在 userlist.php 中加载 process 列表时，不使用权限过滤
// 这样管理员可以自由选择给用户分配哪些 process 权限，不受当前登录用户权限限制
try {
    $processStmt = $pdo->prepare("
        SELECT 
            p.id,
            p.process_id,
            d.name AS description,
            p.status
        FROM process p
        LEFT JOIN description d ON p.description_id = d.id
        WHERE p.status = 'active' AND p.company_id = ?
        ORDER BY p.process_id ASC
    ");
    $processStmt->execute([$company_id]);
    $processes = $processStmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    error_log("Process query failed: " . $e->getMessage());
    $processes = [];
}
?>

<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
    $assetVer = function ($file) {
        $path = __DIR__ . '/' . $file;
        return file_exists($path) ? filemtime($path) : time();
    };
    ?>
    <link href='https://fonts.googleapis.com/css?family=Amaranth' rel='stylesheet'>
    <title>User List</title>
    <link rel="stylesheet" href="css/sidebar.css?v=<?php echo $assetVer('css/sidebar.css'); ?>">
    <script src="js/sidebar.js?v=<?php echo $assetVer('js/sidebar.js'); ?>"></script>
    <?php include 'sidebar.php'; ?>
    <link rel="stylesheet" href="css/userlist.css?v=<?php echo $assetVer('css/userlist.css'); ?>">
</head>
<body>
    <div id="notificationContainer" class="notification-container"></div>
    <div class="container">
        <h1>User List</h1>
        
        <div class="separator-line"></div>

        <div class="action-buttons-container" style="margin-bottom: 20px;">
            <div class="action-buttons" style="display: flex; justify-content: space-between; align-items: center;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <button class="btn btn-add" onclick="openAddModal()">Add User</button>
                    <div class="search-container">
                        <svg class="search-icon" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
                            </svg>
                        <input type="text" id="searchInput" placeholder="Search by Login Id or Name" class="search-input">
                    </div>
                    <div class="checkbox-section">
                        <input type="checkbox" id="showInactive" name="showInactive">
                        <label for="showInactive">Show Inactive</label>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <button class="btn btn-delete" id="deleteSelectedBtn" onclick="deleteSelected()">Delete</button>
                </div>
            </div>
            
            <!-- Company Buttons (显示多个 company 时) -->
            <?php if (count($user_companies) > 1): ?>
            <div id="user-list-company-filter" class="transaction-company-filter" style="display: flex; padding: 0 20px 15px 20px;">
                <span class="transaction-company-label">Company:</span>
                <div id="user-list-company-buttons" class="transaction-company-buttons">
                    <?php foreach($user_companies as $comp): ?>
                        <button type="button" 
                                class="transaction-company-btn <?php echo $comp['id'] == $company_id ? 'active' : ''; ?>" 
                                data-company-id="<?php echo $comp['id']; ?>"
                                onclick="switchUserListCompany(<?php echo $comp['id']; ?>, '<?php echo htmlspecialchars($comp['company_id']); ?>')">
                            <?php echo htmlspecialchars($comp['company_id']); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>    
        
        <!-- 表头 -->
        <div class="table-header">
            <div class="header-item">No</div>
            <div class="header-item header-sortable" onclick="sortByLoginId()">
                Login Id
                <span class="sort-indicator" id="sortLoginIdIndicator">▲</span>
            </div>
            <div class="header-item">Name</div>
            <div class="header-item">Email</div>
            <div class="header-item header-sortable" onclick="sortByRole()">
                Role
                <span class="sort-indicator" id="sortRoleIndicator"></span>
            </div>
            <div class="header-item">Status</div>
            <div class="header-item">Last Login</div>
            <div class="header-item">Created By</div>
            <div class="header-item">Action
                <input type="checkbox" id="selectAllUsers" title="Select all" style="margin-left: 10px; cursor: pointer;" onchange="toggleSelectAllUsers()">
            </div>
        </div>
        
        <div class="table-container">
            <!-- 用户卡片列表 -->
            <div class="user-cards" id="userTableBody">
                <?php foreach($users as $index => $user): 
                    $is_owner_shadow = isset($user['is_owner_shadow']) && $user['is_owner_shadow'] == 1;
                    $user_role = strtolower($user['role'] ?? '');
                    $is_admin_user = $user_role === 'admin';
                    $is_owner_user = $user_role === 'owner';
                    
                    // 定义低权限角色（不能编辑/删除 admin 和 owner）
                    $low_privilege_roles = ['manager', 'supervisor', 'accountant', 'audit', 'customer service'];
                    $is_low_privilege_user = in_array(strtolower($current_user_role), $low_privilege_roles);
                    
                    // 判断是否可以编辑/删除：
                    // 1. 用户不能删除自己
                    // 2. owner shadow: 只有 owner 本人可以编辑/删除
                    // 3. 低权限角色: 不能编辑/删除 admin 和 owner
                    // 4. 不能删除同等级的角色
                    // 5. 其他情况: 可以编辑/删除（包括 admin 编辑其他 admin，但编辑权限由层级关系控制）
                    $is_self = ($current_user_id && $user['id'] == $current_user_id);
                    
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
                    $current_user_level = $role_hierarchy[strtolower($current_user_role)] ?? 999;
                    $target_user_level = $role_hierarchy[strtolower($user_role)] ?? 999;
                    $is_same_level = ($current_user_level === $target_user_level && !$is_self);
                    $is_higher_level = ($target_user_level < $current_user_level); // 数字越小，层级越高
                    
                    if ($is_self) {
                        $can_edit_delete = true; // 可以编辑自己，但不能删除
                        $can_delete = false; // 不能删除自己
                    } elseif ($is_owner_shadow) {
                        $can_edit_delete = $current_user_role === 'owner';
                        $can_delete = $current_user_role === 'owner';
                    } elseif ($is_low_privilege_user && ($is_admin_user || $is_owner_user)) {
                        $can_edit_delete = false; // 低权限角色不能编辑/删除 admin 和 owner
                        $can_delete = false;
                    } elseif ($is_same_level) {
                        $can_edit_delete = true; // 可以编辑同等级用户，但不能删除
                        $can_delete = false; // 不能删除同等级用户
                    } elseif ($is_higher_level) {
                        $can_edit_delete = true; // 可以编辑高阶用户，但不能删除
                        $can_delete = false; // 不能删除比自己层级更高的用户
                    } else {
                        // 允许编辑和删除（目标用户层级更低）
                        // 具体的编辑权限（哪些字段可以编辑）由 JavaScript 的层级关系控制
                        $can_edit_delete = true;
                        $can_delete = true;
                    }
                    
                    // 判断是否可以切换状态（与编辑/删除逻辑相同，但不能切换自己的状态）
                    $can_toggle_status = $can_edit_delete && !$is_self;
                ?>
                <div class="user-card <?php echo ($index % 2 == 0) ? 'row-even' : 'row-odd'; ?>" 
                     data-id="<?php echo $user['id']; ?>" 
                     data-is-owner-shadow="<?php echo $is_owner_shadow ? '1' : '0'; ?>"
                     data-login-id="<?php echo htmlspecialchars($user['login_id']); ?>"
                     data-name="<?php echo htmlspecialchars($user['name']); ?>"
                     data-email="<?php echo htmlspecialchars($user['email'] ?? ''); ?>"
                     data-role="<?php echo htmlspecialchars($user['role']); ?>"
                     data-status="<?php echo htmlspecialchars($user['status']); ?>"
                     data-last-login="<?php echo $user['last_login'] ? date('Y-m-d H:i', strtotime($user['last_login'])) : ''; ?>"
                     data-created-by="<?php echo htmlspecialchars($user['created_by'] ?? ''); ?>">
                    <div class="card-item"><?php echo $index + 1; ?></div>
                    <div class="card-item"><?php echo htmlspecialchars($user['login_id']); ?></div>
                    <div class="card-item"><?php echo htmlspecialchars($user['name']); ?></div>
                    <div class="card-item"><?php echo htmlspecialchars($user['email'] ?? '-'); ?></div>
                    <div class="card-item uppercase-text">
                        <span class="role-badge role-<?php echo str_replace(' ', '-', $user['role']); ?>">
                            <?php echo strtoupper(htmlspecialchars($user['role'])); ?>
                        </span>
                    </div>
                    <div class="card-item uppercase-text">
                        <?php 
                        if ($can_toggle_status && !$is_self): 
                        ?>
                            <span class="role-badge <?php echo $user['status'] == 'active' ? 'status-active' : 'status-inactive'; ?> status-clickable" onclick="toggleUserStatus(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['status']); ?>', <?php echo $is_owner_shadow ? 'true' : 'false'; ?>)" title="Click to toggle status" style="cursor: pointer;">
                                <?php echo strtoupper(htmlspecialchars($user['status'])); ?>
                            </span>
                        <?php else: ?>
                            <span class="role-badge <?php echo $user['status'] == 'active' ? 'status-active' : 'status-inactive'; ?>" style="cursor: not-allowed; opacity: 0.6;" title="<?php echo $is_self ? 'You cannot toggle your own status' : 'No permission to toggle status'; ?>">
                                <?php echo strtoupper(htmlspecialchars($user['status'])); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="card-item"><?php echo $user['last_login'] ? date('Y-m-d H:i', strtotime($user['last_login'])) : '-'; ?></div>
                    <div class="card-item uppercase-text"><?php echo strtoupper(htmlspecialchars($user['created_by'] ?? '-')); ?></div>
                    <div class="card-item">
                        <?php if ($can_edit_delete): ?>
                            <button class="btn btn-edit edit-btn" onclick="editUser(<?php echo $user['id']; ?>, <?php echo $is_owner_shadow ? 'true' : 'false'; ?>)" aria-label="Edit">
                                <img src="images/edit.svg" alt="Edit">
                            </button>
                        <?php else: ?>
                            <button class="btn btn-edit edit-btn" disabled style="opacity: 0.3; cursor: not-allowed;" aria-label="Edit Disabled">
                                <img src="images/edit.svg" alt="Edit Disabled">
                            </button>
                        <?php endif; ?>
                        <?php $is_active_status = strtolower($user['status'] ?? '') === 'active'; ?>
                        <?php if (!$is_active_status): ?>
                            <?php if ($can_delete): ?>
                                <input type="checkbox" class="user-checkbox" value="<?php echo $user['id']; ?>" data-is-owner-shadow="<?php echo $is_owner_shadow ? '1' : '0'; ?>" data-role="<?php echo htmlspecialchars($user_role); ?>" onchange="updateDeleteButton()">
                            <?php else: ?>
                                <input type="checkbox" class="user-checkbox" disabled style="opacity: 0.3; cursor: not-allowed;" title="<?php 
                                    if ($is_self) {
                                        echo 'You cannot delete your own account';
                                    } elseif ($is_same_level) {
                                        echo 'You cannot delete accounts with the same role level';
                                    } elseif ($is_higher_level) {
                                        echo 'You cannot delete accounts with higher role level';
                                    } else {
                                        echo 'No permission to delete';
                                    }
                                ?>">
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <!-- 分页控件 -->
        <div class="pagination-container" id="paginationContainer">
            <button class="pagination-btn" id="prevBtn" onclick="changePage(-1)">◀</button>
            <span class="pagination-info" id="paginationInfo">1 of 10</span>
            <button class="pagination-btn" id="nextBtn" onclick="changePage(1)">▶</button>
        </div>
    </div>

    <!-- Custom Confirmation Modal -->
    <div id="confirmModal" class="modal">
        <div class="confirm-modal-content">
            <div class="confirm-icon-container">
                <svg class="confirm-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <h2 class="confirm-title">Confirm Delete</h2>
            <p id="confirmMessage" class="confirm-message"></p>
            <div class="confirm-actions">
                <button type="button" class="btn btn-cancel confirm-cancel" onclick="closeConfirmModal()">Cancel</button>
                <button type="button" class="btn btn-delete confirm-delete" id="confirmDeleteBtn">Delete</button>
            </div>
        </div>
    </div>

    <!-- User Modal -->
    <div id="userModal" class="modal">
        <div class="modal-content" style="max-width: 1920px;">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2 id="modalTitle">Add User</h2>
            <div class="modal-body" style="display: flex; gap: clamp(20px, 1.5630px;">
                <!-- Left Panel - User Info -->
                 <div class="user-info-panel" style="flex: 1;">
                     <h3>User Information</h3>
                     <form id="userForm">
                         <input type="hidden" id="userId" name="id">
                         <input type="hidden" id="status" name="status" value="active">

                         <!-- User info grid：编辑模式下两列布局（左三、右两 + Company） -->
                         <div class="user-info-grid">
                             <div class="form-group user-info-field">
                                 <label for="login_id">Login ID *</label>
                                 <input type="text" id="login_id" name="login_id" required>
                             </div>

                            <?php 
                            // 检查当前公司是否是c168
                            $is_c168_company = false;
                            if ($company_id) {
                                try {
                                    $stmt = $pdo->prepare("SELECT company_id FROM company WHERE id = ? AND UPPER(company_id) = 'C168'");
                                    $stmt->execute([$company_id]);
                                    if ($stmt->fetch()) {
                                        $is_c168_company = true;
                                    }
                                } catch (PDOException $e) {
                                    error_log("Company check error: " . $e->getMessage());
                                }
                            }
                            ?>
                            
                            <?php if ($is_c168_company): ?>
                            <!-- C168公司：密码和二级密码在同一行左右排版 -->
                            <div class="form-group user-info-field password-row-container" id="passwordRowContainer">
                                <div class="password-field-wrapper" id="passwordGroup">
                                    <label for="password">Password *</label>
                                    <input type="password" id="password" name="password">
                                </div>
                                <div class="password-field-wrapper" id="secondaryPasswordGroup">
                                    <label for="secondary_password">Secondary Password (6 digits)</label>
                                    <input type="password" id="secondary_password" name="secondary_password" maxlength="6" pattern="[0-9]{6}" placeholder="Enter 6-digit password">
                                </div>
                            </div>
                            <div class="form-group user-info-field" style="margin-top: -10px; margin-bottom: 10px;">
                                <small style="color: #64748b; font-size: 12px; display: block;"></small>
                            </div>
                            <?php else: ?>
                            <!-- 非C168公司：只显示密码字段 -->
                            <div class="form-group user-info-field" id="passwordGroup">
                                <label for="password">Password *</label>
                                <input type="password" id="password" name="password">
                            </div>
                            <?php endif; ?>

                            <div class="form-group user-info-field">
                                <label for="name">Name *</label>
                                <input type="text" id="name" name="name" required>
                            </div>

                             <div class="form-group user-info-field">
                                 <label for="role">Role *</label>
                                 <select id="role" name="role" required>
                                     <option value="">Select Role</option>
                                     <option value="admin">Admin</option>
                                     <option value="manager">Manager</option>
                                     <option value="supervisor">Supervisor</option>
                                     <option value="accountant">Accountant</option>
                                     <option value="audit">Audit</option>
                                     <option value="customer service">Customer Service</option>
                                 </select>
                             </div>

                             <div class="form-group user-info-field">
                                 <label for="email">Email *</label>
                                 <input type="email" id="email" name="email" required>
                             </div>

                            <div class="form-group user-info-field company-field-group">
                                <label>Company *</label>
                                <div id="user-company-buttons-container" class="transaction-company-buttons" style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px;">
                                    <!-- Company buttons will be dynamically added here -->
                                </div>
                            </div>
                         </div>

                          <div class="form-actions">
                              <button type="submit" class="btn btn-save">Save</button>
                              <button type="button" class="btn btn-cancel" onclick="closeModal()">Cancel</button>
                          </div>
                     </form>
                 </div>

            <!-- Right Panel - Permissions -->
            <div class="permissions-panel" style="flex: 1;">
                <h3>Permissions</h3>
                <div class="permissions-panel-wrapper">
                     <!-- Left Part - General Permissions Container -->
                     <div id="sidebarPermissionsWrapper" class="permissions-container-wrapper" style="display: flex; flex-direction: column;">
                        <div class="permissions-container">
                            <div class="permission-item">
                                <label class="permission-label">
                                    <input type="checkbox" name="permissions[]" value="home" class="permission-checkbox">
                                    <span class="permission-name">
                                        <svg class="permission-icon" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/>
                                        </svg>
                                        Home
                                    </span>
                                </label>
                            </div>
                            
                            <div class="permission-item">
                                <label class="permission-label">
                                    <input type="checkbox" name="permissions[]" value="admin" class="permission-checkbox">
                                    <span class="permission-name">
                                        <svg class="permission-icon" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/>
                                        </svg>
                                        Admin
                                    </span>
                                </label>
                            </div>
                            
                            <div class="permission-item">
                                <label class="permission-label">
                                    <input type="checkbox" name="permissions[]" value="account" class="permission-checkbox">
                                    <span class="permission-name">
                                        <svg class="permission-icon" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                                        </svg>
                                        Account
                                    </span>
                                </label>
                            </div>
                            
                            <div class="permission-item">
                                <label class="permission-label">
                                    <input type="checkbox" name="permissions[]" value="process" class="permission-checkbox">
                                    <span class="permission-name">
                                        <svg class="permission-icon" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                                        </svg>
                                        Process
                                    </span>
                                </label>
                            </div>
                            
                            <div class="permission-item">
                                <label class="permission-label">
                                    <input type="checkbox" name="permissions[]" value="datacapture" class="permission-checkbox">
                                    <span class="permission-name">
                                        <svg class="permission-icon" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/>
                                        </svg>
                                        Data Capture
                                    </span>
                                </label>
                            </div>
                            
                            <div class="permission-item">
                                <label class="permission-label">
                                    <input type="checkbox" name="permissions[]" value="payment" class="permission-checkbox">
                                    <span class="permission-name">
                                        <svg class="permission-icon" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M20 4H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/>
                                        </svg>
                                        Transaction Payment
                                    </span>
                                </label>
                            </div>
                            
                            <div class="permission-item">
                                <label class="permission-label">
                                    <input type="checkbox" name="permissions[]" value="report" class="permission-checkbox">
                                    <span class="permission-name">
                                        <svg class="permission-icon" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 2 2h8c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/>
                                        </svg>
                                        Report
                                    </span>
                                </label>
                            </div>
                            
                            <div class="permission-item">
                                <label class="permission-label">
                                    <input type="checkbox" name="permissions[]" value="maintenance" class="permission-checkbox">
                                    <span class="permission-name">
                                        <svg class="permission-icon" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M22.7 19l-9.1-9.1c.9-2.3.4-5-1.5-6.9-2-2-5-2.4-7.4-1.3L9 6 6 9 1.6 4.7C.4 7.1.9 10.1 2.9 12.1c1.9 1.9 4.6 2.4 6.9 1.5l9.1 9.1c.4.4 1 .4 1.4 0l2.3-2.3c.5-.4.5-1.1.1-1.4z"/>
                                        </svg>
                                        Maintenance
                                    </span>
                                </label>
                            </div>
                        </div>
                        <div class="permissions-actions" style="margin-top: 10px; padding-top: clamp(12px, 1.04vw, 20px); border-top: 1px solid #eee;">
                            <button type="button" class="btn btn-secondary" onclick="selectAllPermissions()">Select All</button>
                            <button type="button" class="btn btn-clearall" onclick="clearAllPermissions()">Clear All</button>
                        </div>
                    </div>
                    
                    <!-- Right Part - Account and Process Permissions (only shown in edit mode) -->
                    <div id="accountProcessPermissionsSection" style="display: none; flex-direction: row; gap: clamp(12px, 1.25vw, 24px); min-width: 0; overflow-y: auto; max-height: calc(98vh - clamp(120px, 12.5vw, 200px)); min-height: clamp(400px, 36.46vw, 700px);">
                    <!-- Account Permissions -->
                    <div class="form-group" style="flex: 1; margin-bottom: 0; margin-top: 0; display: flex; flex-direction: column;">
                        <label style="font-size: clamp(12px, 0.94vw, 18px); font-weight: bold; color: #1a237e; margin-bottom: clamp(4px, 0.52vw, 10px); display: block;">Account</label>
                        <div class="account-grid" id="accountGrid" style="display: flex; flex-direction: column; gap: 0px; max-height: clamp(400px, 40vw, 600px); overflow-y: auto; border: 1px solid #ddd; border-radius: 6px; background-color: #ffffffff; padding: clamp(8px, 0.78vw, 15px);">
                            <?php 
                            $colCount = 0;
                            foreach($accounts as $account): 
                                if ($colCount % 3 == 0) {
                                    if ($colCount > 0) echo '</div>'; // Close previous row
                                    echo '<div class="account-row" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: clamp(2px, 0.26vw, 5px); margin-bottom: clamp(2px, 0.26vw, 5px);">';
                                }
                            ?>
                                <div class="account-item-compact" data-search="<?php echo strtolower($account['account_id']); ?>" style="display: flex; align-items: center; padding: clamp(0px, 0.1vw, 2px) clamp(2px, 0.21vw, 4px); margin-bottom: 0px; border-radius: 4px; transition: background-color 0.2s; background-color: white; border: 1px solid #eee;">
                                    <input type="checkbox" 
                                        id="account_<?php echo $account['id']; ?>" 
                                        value="<?php echo $account['id']; ?>"
                                        data-account-id="<?php echo htmlspecialchars($account['account_id']); ?>"
                                        onchange="updateAccountSelection()"
                                        style="margin: 1px 3px 1px 4px; width: clamp(8px, 0.73vw, 14px); height: clamp(8px, 0.73vw, 14px); flex-shrink: 0;">
                                    <label for="account_<?php echo $account['id']; ?>" class="account-label" style="font-size: small; font-weight: 800; color: #333; cursor: pointer; flex: 1; min-width: 0; word-break: break-all; line-height: 1.2;">
                                        <?php echo htmlspecialchars($account['account_id']); ?>
                                    </label>
                                </div>
                            <?php 
                                $colCount++;
                                endforeach;
                                if ($colCount > 0) echo '</div>'; // Close last row
                            ?>
                        </div>
                        <div class="account-control-buttons" style="display: flex; gap: 10px; justify-content: center; margin: clamp(8px, 0.73vw, 14px) 0px 0px;">
                            <button type="button" class="btn-account-control" onclick="selectAllAccounts()" style="background: linear-gradient(180deg, #44e44d 0%, #227426 100%); color: white; font-family: 'Amaranth'; width: clamp(80px, 6.25vw, 120px); padding: clamp(6px, 0.42vw, 8px) 0px; font-size: clamp(10px, 0.83vw, 16px); border: none; border-radius: 6px; box-shadow: 0 2px 4px rgba(0, 123, 255, 0.3); cursor: pointer;">Select All</button>
                            <button type="button" class="btn-clearall" onclick="clearAllAccounts()" style="background: linear-gradient(180deg, #F30E12 0%, #A91215 100%); color: white; font-family: 'Amaranth'; width: clamp(90px, 6.25vw, 120px); padding: clamp(6px, 0.42vw, 8px) 20px; font-size: clamp(10px, 0.83vw, 16px); border: none; border-radius: 6px; box-shadow: 0 2px 4px rgba(220, 53, 69, 0.3); cursor: pointer;">Clear All</button>
                        </div>
                    </div>
                    
                    <!-- Process Permissions -->
                    <div class="form-group" style="flex: 1; margin-bottom: 0; margin-top: 0; display: flex; flex-direction: column;">
                        <label style="font-size: clamp(12px, 0.94vw, 18px); font-weight: bold; color: #1a237e; margin-bottom: clamp(4px, 0.52vw, 10px); display: block;">Process</label>
                        <div class="account-grid" id="processGrid" style="display: flex; flex-direction: column; gap: 0px; max-height: clamp(400px, 40vw, 600px); overflow-y: auto; border: 1px solid #ddd; border-radius: 6px; background-color: #ffffffff; padding: clamp(8px, 0.78vw, 15px);">
                            <?php 
                            $colCount = 0;
                            foreach($processes as $process): 
                                if ($colCount % 3 == 0) {
                                    if ($colCount > 0) echo '</div>'; // Close previous row
                                    echo '<div class="account-row" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: clamp(2px, 0.26vw, 5px); margin-bottom: clamp(2px, 0.26vw, 5px);">';
                                }
                            ?>
                                <div class="account-item-compact" data-search="<?php echo strtolower($process['process_id'] . ' ' . $process['description']); ?>" style="display: flex; align-items: center; padding: clamp(0px, 0.1vw, 2px) clamp(2px, 0.21vw, 4px); margin-bottom: 0px; border-radius: 4px; transition: background-color 0.2s; background-color: white; border: 1px solid #eee;">
                                    <input type="checkbox" 
                                        id="process_<?php echo $process['id']; ?>" 
                                        value="<?php echo $process['id']; ?>"
                                        data-process-name="<?php echo htmlspecialchars($process['process_id']); ?>"
                                        data-process-description="<?php echo htmlspecialchars($process['description']); ?>"
                                        onchange="updateProcessSelection()"
                                        style="margin: 1px 3px 1px 4px; width: clamp(8px, 0.73vw, 14px); height: clamp(8px, 0.73vw, 14px); flex-shrink: 0;">
                                     <label for="process_<?php echo $process['id']; ?>" class="account-label" style="font-size: small ; font-weight: 800; color: #333; cursor: pointer; flex: 1; min-width: 0; word-break: break-all; line-height: 1.2;">
                                         <?php echo htmlspecialchars($process['process_id']); ?>
                                         <?php if (!empty($process['description'])): ?>
                                             <br>
                                             <?php echo htmlspecialchars($process['description']); ?>
                                         <?php endif; ?>
                                     </label>
                                </div>
                            <?php 
                                $colCount++;
                                endforeach;
                                if ($colCount > 0) echo '</div>'; // Close last row
                            ?>
                        </div>
                        <div class="account-control-buttons" style="display: flex; gap: 10px; justify-content: center; margin: clamp(8px, 0.73vw, 14px) 0px 0px;">
                            <button type="button" class="btn-account-control" onclick="selectAllProcesses()" style="background: linear-gradient(180deg, #44e44d 0%, #227426 100%); color: white; font-family: 'Amaranth'; width: clamp(80px, 6.25vw, 120px); padding: clamp(6px, 0.42vw, 8px) 0px; font-size: clamp(10px, 0.83vw, 16px); border: none; border-radius: 6px; box-shadow: 0 2px 4px rgba(0, 123, 255, 0.3); cursor: pointer;">Select All</button>
                            <button type="button" class="btn-clearall" onclick="clearAllProcesses()" style="background: linear-gradient(180deg, #F30E12 0%, #A91215 100%); color: white; font-family: 'Amaranth'; width: clamp(90px, 6.25vw, 120px); padding: clamp(6px, 0.42vw, 8px) 20px; font-size: clamp(10px, 0.83vw, 16px); border: none; border-radius: 6px; box-shadow: 0 2px 4px rgba(220, 53, 69, 0.3); cursor: pointer;">Clear All</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        window.USERLIST_CURRENT_USER_ID = <?php echo json_encode($current_user_id); ?>;
        window.USERLIST_CURRENT_USER_ROLE = '<?php echo strtolower($current_user_role); ?>';
        window.USERLIST_CURRENT_COMPANY_ID = <?php echo json_encode($_SESSION['company_id'] ?? null); ?>;
    </script>
    <script src="js/userlist.js?v=<?php echo $assetVer('js/userlist.js'); ?>"></script>
</body>
</html>
