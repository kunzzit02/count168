<?php
// 确保session已启动
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 检查用户是否已登录
if (!isset($_SESSION['user_id'])) {
    // 如果未登录，输出JavaScript重定向到登录页
    // 这样可以确保整个页面都停止工作，而不仅仅是sidebar消失
    echo '<script>window.location.href = "index.php";</script>';
    exit();
}

$isMember = isset($_SESSION['user_type']) && strtolower($_SESSION['user_type']) === 'member';

// 获取用户信息
$user_id = $_SESSION['user_id'];
$login_id = $_SESSION['login_id'] ?? '';
$name = $_SESSION['name'] ?? '';
$role = $_SESSION['role'] ?? '';

require_once 'config.php';
$permissions = [];

// 获取用户权限（仅非 member 用户）
if (!$isMember) {
    $stmt = $pdo->prepare("SELECT permissions FROM user WHERE id = ?");
    $stmt->execute([$user_id]);
    $userPermissions = $stmt->fetchColumn();
    $permissions = $userPermissions ? json_decode($userPermissions, true) : [];
}

// 检查当前登录用户是否为 owner/admin 且与 c168 相关（支持多重 company）
$hasC168Access = false;
$companyId = $_SESSION['company_id'] ?? null;  // company 的数字主键（移到外面，确保作用域正确）
if ($user_id) {
    $roleLower    = strtolower($role ?? '');
    $companyCode  = strtoupper($_SESSION['company_code'] ?? ''); // 登录时选的公司代码

    if (in_array($roleLower, ['owner', 'admin'], true)) {
        // 条件1：登录时选的公司代码就是 c168
        if ($companyCode === 'C168') {
            $hasC168Access = true;
        } elseif ($companyId) {
            // 条件2：当前选中公司在 company 表中确认为 c168
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND UPPER(company_id) = 'C168'");
                $stmt->execute([$companyId]);
                $hasC168Access = $stmt->fetchColumn() > 0;
            } catch(PDOException $e) {
                error_log("检查 c168 权限失败: " . $e->getMessage());
                $hasC168Access = false;
            }
        }
    }
}

$avatarLetter = $name ? strtoupper($name[0]) : 'U';

// 获取当前公司的到期日期
$company_expiration_date = null;
$expiration_countdown_text = '';
$expiration_status = 'normal';
if ($companyId) {
    try {
        $stmt = $pdo->prepare("SELECT expiration_date FROM company WHERE id = ?");
        $stmt->execute([$companyId]);
        $company_expiration_date = $stmt->fetchColumn();
        
        // 在 PHP 端计算倒计时
        if ($company_expiration_date) {
            $today = new DateTime();
            $today->setTime(0, 0, 0);
            $expiration = new DateTime($company_expiration_date);
            $expiration->setTime(0, 0, 0);
            
            $diff = $today->diff($expiration);
            $diffDays = (int)$diff->format('%r%a'); // 带符号的天数差
            
            if ($diffDays < 0) {
                $expiration_countdown_text = 'Expired';
                $expiration_status = 'expired';
            } else if ($diffDays === 0) {
                $expiration_countdown_text = 'Expires today';
                $expiration_status = 'warning';
            } else if ($diffDays <= 7) {
                $expiration_countdown_text = $diffDays . ' day' . ($diffDays > 1 ? 's' : '') . ' left';
                $expiration_status = 'warning';
            } else if ($diffDays <= 30) {
                $expiration_countdown_text = $diffDays . ' days left';
                $expiration_status = 'normal';
            } else {
                $months = floor($diffDays / 30);
                $days = $diffDays % 30;
                if ($days === 0) {
                    $expiration_countdown_text = $months . ' month' . ($months > 1 ? 's' : '') . ' left';
                } else {
                    $expiration_countdown_text = $months . 'm ' . $days . 'd left';
                }
                $expiration_status = 'normal';
            }
        } else {
            $expiration_countdown_text = 'No expiration date';
            $expiration_status = 'normal';
        }
    } catch(PDOException $e) {
        error_log("获取公司到期日期失败: " . $e->getMessage());
        $company_expiration_date = null;
        $expiration_countdown_text = 'No expiration date';
        $expiration_status = 'normal';
    }
}
?>

<!-- Sidebar CSS -->
<style>
    /* Sidebar 自己的字体设置，避免被各页面 body 的全局字体覆盖导致闪一下样式 */
    .informationmenu,
    .informationmenu * {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
    }

    /* 用户信息容器（包裹头像和用户信息） */
    .user-info-container {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        width: 100%;
        padding: clamp(4px, 0.52vw, 10px) clamp(8px, 0.83vw, 16px);
        margin-bottom: clamp(2px, 0.31vw, 6px);
        min-height: 50px;
        contain: layout style;
        will-change: auto;
        /* 确保头像选择菜单不被裁剪 */
        overflow: visible;
        /* 创建新的堆叠上下文，确保头像选择菜单能够显示在其他元素之上 */
        position: relative;
        z-index: 9999;
    }

    /* 登录后头像和下拉菜单样式 */
    .user-avatar-dropdown {
        position: relative;
        display: flex;
        align-items: center;
        flex-direction: row;
        gap: 0;
        cursor: pointer;
        padding: clamp(2px, 0.4vw, 8px);
        padding-left: 0px;
        border-radius: 25px;
        /* 只对背景色应用过渡，避免布局属性变化导致的闪烁 */
        transition: background-color 0.3s ease;
        text-align: left;
        color: white;
        flex-shrink: 0;
        /* 优化渲染性能 */
        min-width: 0;
        contain: layout style;
        /* 确保不会被头像选择菜单覆盖，但也不覆盖菜单 */
        z-index: 1;
    }

    .user-avatar-dropdown:hover {
        background: rgba(255, 255, 255, 0.1);
    }

    .user-avatar {
        width: 36px;
        height: 36px;
        background: white;
        color: #1a237e;
        font-weight: bold;
        font-size: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        user-select: none;
        box-sizing: border-box;
    }

    .avatar-selector-container {
        position: relative;
        display: flex;
        flex-direction: column;     
        align-items: center;
        margin-left: 0;
        flex-shrink: 0;
        width: fit-content;
        /* 优化渲染性能，防止页面切换时的布局重排 */
        min-width: clamp(40px, 3.65vw, 70px);
        /* 移除 paint 限制，允许头像选择菜单超出容器边界显示 */
        contain: layout style;
        /* 确保头像选择菜单不被裁剪 */
        overflow: visible;
        /* 创建新的堆叠上下文，确保子元素（头像选择菜单）的z-index能够覆盖其他元素 */
        z-index: 10000;
        isolation: isolate;
    }

    /* 当前头像显示 */
    .current-avatar {
        width: clamp(30px, 2.6vw, 50px);
        height: clamp(30px, 2.6vw, 50px);
        border-radius: 50%;
        cursor: pointer;
        /* 只对需要动画的属性应用过渡，避免页面切换时位置属性变化导致的闪烁 */
        transition: border-color 0.3s ease, box-shadow 0.3s ease, transform 0.3s ease;
        border: 2px solid rgba(255, 255, 255, 0.3);
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 24px;
        position: relative;
        overflow: hidden;
        box-sizing: border-box;
        /* 优化渲染性能，防止闪烁 - 强制 GPU 加速并隔离布局 */
        transform: translateZ(0);
        will-change: border-color, box-shadow;
        backface-visibility: hidden;
        -webkit-backface-visibility: hidden;
        /* 确保尺寸固定，避免 flex 布局重新计算时的抖动 */
        flex-shrink: 0;
    }

    .current-avatar:hover {
        border-color: rgba(255, 255, 255, 0.8);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    }

    /* 头像选择菜单 */
    .avatar-options {
        position: absolute;
        top: 75%;
        left: calc(100% + clamp(8px, 0.83vw, 16px));
        transform: translateY(-50%);
        background: rgba(255, 255, 255, 0.95);
        border-radius: 12px;
        padding: clamp(8px, 0.78vw, 15px);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
        backdrop-filter: blur(20px);
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.3s ease, visibility 0.3s ease, transform 0.3s ease;
        /* 使用非常高的 z-index 确保显示在所有内容之上 */
        z-index: 9999;
        width: clamp(120px, 10vw, 180px);
        max-height: clamp(300px, 40vh, 500px);
        overflow-y: auto;
    }

    .avatar-options.show {
        opacity: 1;
        visibility: visible;
        transform: translateY(-50%);
    }

    .avatar-options::before {
        content: '';
        position: absolute;
        top: 50%;
        left: -8px;
        transform: translateY(-50%);
        border-top: 8px solid transparent;
        border-bottom: 8px solid transparent;
        border-right: 8px solid rgba(255, 255, 255, 0.95);
    }

    .avatar-option {
        width: clamp(34px, 3vw, 56px);
        height: clamp(34px, 3vw, 56px);
        border-radius: 50%;
        cursor: pointer;
        transition: all 0.3s ease;
        border: 2px solid transparent;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        color: white;
        box-sizing: border-box;
        flex-shrink: 0;
    }

    .avatar-option:hover {
        border-color: #667eea;
        transform: scale(1.1);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    }

    .avatar-option.selected {
        border-color: #4facfe;
        box-shadow: 0 0 15px rgba(79, 172, 254, 0.5);
    }

    .options-title {
        text-align: center;
        color: #333;
        font-size: clamp(7px, 0.58vw, 11px);
        font-weight: 600;
        margin-bottom: clamp(4px, 0.42vw, 8px);
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    
    .gender-selection {
        display: flex;
        gap: clamp(6px, 0.63vw, 12px);
        margin-bottom: clamp(8px, 0.83vw, 16px);
        justify-content: center;
    }

    .gender-btn {
        flex: 1;
        padding: clamp(6px, 0.63vw, 12px);
        border: 2px solid rgba(102, 126, 234, 0.3);
        border-radius: 8px;
        background: rgba(255, 255, 255, 0.8);
        color: #667eea;
        font-size: clamp(8px, 0.73vw, 14px);
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        text-align: center;
    }

    .gender-btn:hover {
        background: rgba(102, 126, 234, 0.1);
        border-color: #667eea;
    }

    .gender-btn.active {
        background: #667eea;
        color: white;
        border-color: #667eea;
    }

    .avatar-list {
        display: none;
        grid-template-columns: repeat(3, 1fr);
        gap: clamp(6px, 0.63vw, 12px);
        margin-top: clamp(6px, 0.63vw, 12px);
        justify-items: center;
    }

    .avatar-list.show {
        display: grid;
    }

    .avatar-option {
        width: clamp(32px, 2.8vw, 48px);
        height: clamp(32px, 2.8vw, 48px);
    }

    .avatar-options::-webkit-scrollbar {
        width: 4px;
    }

    .avatar-options::-webkit-scrollbar-track {
        background: rgba(0, 0, 0, 0.05);
        border-radius: 2px;
    }

    .avatar-options::-webkit-scrollbar-thumb {
        background: rgba(102, 126, 234, 0.3);
        border-radius: 2px;
    }

    .avatar-options::-webkit-scrollbar-thumb:hover {
        background: rgba(102, 126, 234, 0.5);
    }

    .user-info {
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: flex-start;
        gap: 2px;
        margin-left: 0px;
        min-width: clamp(60px, 5vw, 100px);
        flex: 1;
    }

    .user-name {
        margin: 0;
        font-size: clamp(10px, 0.83vw, 16px);
        font-weight: 600;
        color: white;
        line-height: 1.2;
    }

    
    .user-role {
        font-size: clamp(9px, 0.57vw, 11px);
        font-weight: 500;
        color: rgba(255, 255, 255, 0.8);
        line-height: 1.2;
    }

    /* 左边的选项bar */
    .informationmenu-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        background: rgba(0, 0, 0, 0.05);
        z-index: 999;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
        pointer-events: none;
    }

    .informationmenu-overlay.show {
        opacity: 0;
        visibility: visible;
    }

    .informationmenu-overlay.hide {
        opacity: 0;
        visibility: hidden;
    }

    .informationmenu {
        width: clamp(160px, 11.98vw, 230px);
        height: 100vh;
        background: #002d49;
        backdrop-filter: blur(20px);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.8);
        position: fixed;
        left: 0;
        top: 0;
        overflow: visible;
        z-index: 1000;
        transform: translateX(0) translateZ(0);
        /* 只对transform应用过渡，避免页面切换时其他CSS属性变化导致的闪烁 */
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex;
        flex-direction: column;
        border-right: 1px solid rgba(255, 255, 255, 0.2);
        /* 优化渲染性能，防止闪烁 */
        will-change: transform;
        backface-visibility: hidden;
        -webkit-backface-visibility: hidden;
        /* 确保sidebar始终可见，不会被重新渲染影响 */
        visibility: visible;
        opacity: 1;
        /* 强制GPU加速，提高渲染稳定性 */
        -webkit-transform: translateX(0) translateZ(0);
    }

    .informationmenu.show {
        transform: translateX(0);
    }

    .informationmenu.hide {
        transform: translateX(-100%);
    }

    .informationmenu-header {
        padding: clamp(6px, 0.73vw, 14px) 10px clamp(6px, 0.52vw, 10px);
        border-bottom: 0px solid rgba(255, 255, 255, 0.1);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        /* 确保头像选择菜单不被裁剪 */
        overflow: visible;
    }

    .informationmenu-logo {
        width: 40px;
        height: 40px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: bold;
        font-size: 18px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    }

    .informationmenu-close-btn {
        width: 36px;
        height: 36px;
        border: none;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        color: white;
        transition: all 0.3s ease;
        backdrop-filter: blur(10px);
    }

    .informationmenu-close-btn:hover {
        background: rgba(255, 255, 255, 0.2);
        transform: scale(1.05);
    }

    .informationmenu-content {
        overflow-y: auto;
        overflow-x: hidden;
        flex: 1;
        display: flex;
        flex-direction: column;
        padding: 0;
    }

    .informationmenu-section {
        margin: 0;
    }

    .informationmenu-section-title {
        padding: clamp(8px, 0.83vw, 16px) clamp(12px, 1.04vw, 20px);
        font-size: clamp(10px, 0.84vw, 16px);
        font-weight: 600;
        color: white;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: flex-start;
        transition: all 0.3s ease;
        border-radius: 25px 0 0 25px;
        margin: 0;
        position: relative;
        overflow: hidden;
    }

    .informationmenu-section-title::before {
        content: '';
        position: absolute;
        top: 0;
        right: -100%;
        width: 100%;
        height: 100%;
        background: transparent;
        box-shadow: 
            inset 0 1px 0 rgba(255, 255, 255, 0.4),
            inset 0 -1px 0 rgba(255, 255, 255, 0.4);
        transition: right 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        z-index: -1;
        border-radius: 25px 0 0 25px;
    }

    .informationmenu-section-title:hover::before {
        right: 0;
    }

    .informationmenu-section-title:hover {
        color: white;
        transform: translateX(5px);
        box-shadow: 0 4px 20px rgba(79, 172, 254, 0.4);
    }

    .informationmenu-section-title:hover .section-icon {
        filter: brightness(0) invert(1);
        transform: scale(1.1);
    }

    .informationmenu-section-title:hover .section-arrow {
        color: white;
        transform: translateX(3px);
    }

    .informationmenu-section-title.active {
        background: rgba(255, 255, 255, 0.2);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
    }

    /* 添加active状态样式 */
    .informationmenu-section-title.current-page {
        background: #0E93F3;
        color: white;
        transform: translateX(5px);
        box-shadow: 0 4px 20px rgba(79, 172, 254, 0.4);
    }

    .informationmenu-section-title.current-page::before {
        right: 0;
    }

    .informationmenu-section-title.current-page .section-icon {
        filter: brightness(0) invert(1);
        transform: scale(1.1);
    }

    .informationmenu-section-title.current-page .section-arrow {
        color: white;
        transform: translateX(3px);
    }

    .section-arrow {
        font-size: clamp(8px, 0.625px, 12px);
        transition: transform 0.3s ease;
        margin-left: auto;
        color: rgba(255, 255, 255, 0.8);
    }

    .account-arrow {
        transform: none !important;
        transition: none !important;
    }

    .account-direct:hover .account-arrow {
        transform: translateX(3px) !important;
    }

    .account-direct.active .account-arrow {
        transform: none !important;
    }

    .informationmenu-section-title.active .section-arrow {
        transform: rotate(90deg);
    }

    .section-icon {
        width: clamp(16px, 1.04vw, 20px);
        height: clamp(16px, 1.04vw, 20px);
        margin-right: clamp(10px, 0.68vw, 13px);
        vertical-align: middle;
        flex-shrink: 0;
        object-fit: contain;
        filter: brightness(0) invert(1);
        opacity: 0.9;
    }

    /* 下拉显示的菜单项区域 */
    .dropdown-menu-items {
        max-height: 0;
        overflow: hidden;
        background: rgba(255, 255, 255, 0.05);
        margin: 0 10px;
        border-radius: 15px;
        backdrop-filter: blur(10px);
        opacity: 0;
        transform: translateY(-4px);
        padding: 0;
        pointer-events: none;
        transition:
            max-height 0.3s cubic-bezier(0.4, 0, 0.2, 1),
            opacity 0.22s ease-in-out,
            transform 0.22s ease-in-out,
            padding 0.22s ease-in-out;
        will-change: max-height, opacity, transform, padding;
    }

    .dropdown-menu-items.show {
        max-height: 500px;
        opacity: 1;
        transform: translateY(0);
        padding: 8px 0 10px;
        pointer-events: auto;
    }

    .menu-item-wrapper {
        position: relative;
    }

    .informationmenu-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: clamp(4px, 0.625vw, 12px) clamp(12px, 1.3px, 25px);
        color: rgba(255, 255, 255, 0.9);
        text-decoration: none;
        font-size: clamp(10px, 0.84vw, 16px);
        font-weight: 500;
        transition: all 0.3s ease;
        cursor: pointer;
        position: relative;
        border-radius: 10px;
        margin: 0px 10px;
    }

    .informationmenu-item:hover {
        background: rgba(255, 255, 255, 0.15);
        color: white;
        transform: translateX(0px);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    }

    .informationmenu-arrow {
        font-size: 12px;
        color: rgba(255, 255, 255, 0.6);
        transition: transform 0.3s ease;
    }

    .informationmenu-item:hover .informationmenu-arrow {
        transform: translateX(3px);
    }

    /* 子菜单 - 紧凑型，显示在菜单项旁边 */
    .submenu {
        position: fixed;
        width: clamp(100px, 10.42vw, 200px);
        min-height: auto;
        background:rgb(0, 84, 136);
        color: white;
        border-radius: 0 12px 12px 0;
        box-shadow: 4px 0 20px rgba(0, 0, 0, 0.3);
        z-index: 3000;
        opacity: 0;
        visibility: hidden;
        transform: translateX(-10px);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        pointer-events: none;
        overflow: hidden;
        backdrop-filter: blur(10px);
        margin-left: 0px;
    }

    .menu-item-wrapper:hover .informationmenu-section-title {
        background: rgba(255, 255, 255, 0.25);
        color: white;
    }

    .submenu-content {
        padding: clamp(2px, 0.42vw, 8px) 0;
    }

    .submenu-item {
        display: flex;
        align-items: center;
        padding: clamp(4px, 0.52vw, 10px) clamp(10px, 0.83vw, 16px);
        color: rgba(255, 255, 255, 0.9);
        text-decoration: none;
        font-size: clamp(8px, 0.84vw, 16px);
        font-weight: bold;
        transition: all 0.2s ease;
        cursor: pointer;
        position: relative;
    }

    .submenu-item:hover {
        background: rgba(255, 255, 255, 0.15);
        color: white;
    }

    .submenu-item::after {
        content: '›';
        margin-left: auto;
        font-weight: bold;
        transition: transform 0.2s ease;
        opacity: 0.6;
        font-size: 16px;
    }

    .submenu-item:hover::after {
        transform: translateX(3px);
        opacity: 1;
    }

    .btn:hover::after {
        transform: translateX(120%);
    }

    /* Logout Button Specific Styles */
    .logout-btn {
        background: linear-gradient(180deg, #63C4FF 0%, #0D60FF 100%);
        color: white;
        padding: clamp(6px, 0.42vw, 8px) 20px;
        font-size: clamp(10px, 0.83vw, 16px);
        width: clamp(70px, 6.25vw, 120px);
        border: none;
        border-radius: 6px;
        box-shadow: 0 2px 4px rgba(0, 123, 255, 0.3);
        --sweep-color: rgba(255, 255, 255, 0.2);
        cursor: pointer
    }

    .logout-btn:hover {
        background: linear-gradient(180deg, #0D60FF 0%, #63C4FF 100%);
        box-shadow: 0 4px 8px rgba(0, 123, 255, 0.4);
        transform: translateY(-1px);
    }

    .informationmenu-footer {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 25px;
        border-top: none;
        background: rgba(255, 255, 255, 0);
        margin-top: auto;
        flex-shrink: 0;
        backdrop-filter: blur(10px);
        gap: 0px;
    }

    /* 滚动条样式 */
    .informationmenu-content::-webkit-scrollbar {
        width: 6px;
    }

    .informationmenu-content::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 3px;
    }

    .informationmenu-content::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.3);
        border-radius: 3px;
    }

    .informationmenu-content::-webkit-scrollbar-thumb:hover {
        background: rgba(255, 255, 255, 0.5);
    }

    /* 添加一些微妙的动画 */
    @keyframes float {
        0%, 100% { transform: translateY(0px); }
        50% { transform: translateY(-2px); }
    }

    .header-logo-section {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: clamp(4px, 0.625vw, 12px);
        margin-bottom: clamp(4px, 0.52vw, 10px);
        width: 100%;
    }

    .header-logo {
        height: clamp(32px, 2.3vw, 44px);
        object-fit: contain;
        width: auto;
    }

    .content-separator {
        height: clamp(1px, 0.1vw, 2px);
        margin: clamp(0px, 0.52vw, 10px) 20px clamp(6px, 0.52vw, 10px) 20px;
        background: linear-gradient(
            to right, 
            transparent 0%, 
            rgba(255, 255, 255, 1) 50%, 
            transparent 100%
        );
        position: relative;
    }

    .content-separator::before {
        content: "";
        display: block;
        width: 0%;
        height: 1px;
        background: rgba(255, 255, 255, 0.6);
        box-shadow: 0 0 4px rgba(255, 255, 255, 0.3);
    }

    /* 语言切换按钮样式 */
    .language-switcher {
        display: flex;
        align-items: center;
        justify-content: center;
        margin-top: clamp(2px, 0.31vw, 6px);
        padding: clamp(0px, 0.21vw, 4px) 8px;
    }

    .language-dropdown {
        position: relative;
        display: inline-block;
    }

    .language-btn {
        display: flex;
        align-items: center;
        gap: clamp(4px, 0.42vw, 8px);
        padding: clamp(4px, 0.42vw, 8px) clamp(6px, 0.63vw, 12px);
        background: #9abff7;
        border: none;
        border-radius: clamp(4px, 0.42vw, 8px);
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        justify-content: space-between;
    }

    .language-btn:hover {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        transform: translateY(-1px);
    }

    .flag-icon {
        width: clamp(15px, 1.04vw, 20px);
        height: clamp(10px, 0.78vw, 15px);
        object-fit: cover;
        border-radius: clamp(0px, 0.1vw, 2px);
    }

    .language-text {
        font-size: clamp(7px, 0.63vw, 12px);
        font-weight: 600;
        color: #333;
    }

    .dropdown-arrow {
        font-size: clamp(6px, 0.52vw, 10px);
        color: #002c65;
        transition: transform 0.3s ease;
    }

    .language-btn.active .dropdown-arrow {
        transform: rotate(180deg);
    }

    .language-dropdown-list {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: #9abff7;
        border-radius: clamp(4px, 0.42vw, 8px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        opacity: 0;
        visibility: hidden;
        transform: translateY(-10px);
        transition: all 0.3s ease;
        z-index: 1000;
        margin-top: 4px;
        overflow: hidden;
    }

    .language-dropdown-list.show {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }

    .language-option {
        display: flex;
        align-items: center;
        gap: clamp(4px, 0.42vw, 8px);
        padding: clamp(4px, 0.42vw, 8px) clamp(6px, 0.63vw, 12px);
        cursor: pointer;
        transition: all 0.3s ease;
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
    }

    .language-option:last-child {
        border-bottom: none;
    }

    .language-option:hover {
        background: rgba(0, 0, 0, 0.05);
    }

    .language-option span {
        font-size: clamp(7px, 0.63vw, 12px);
        font-weight: 600;
        color: #333;
    }

    /* 通知铃铛样式 */
    .notification-bell {
        position: relative;
        width: clamp(26px, 1.88vw, 36px);
        height: clamp(26px, 1.88vw, 36px);
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.15);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: white;
        transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
        flex-shrink: 0;
    }

    .notification-bell:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        background: rgba(255, 255, 255, 0.25);
    }

    .notification-bell svg {
        width: clamp(16px, 1.14vw, 22px);
        height: clamp(16px, 1.14vw, 22px);
        transform-origin: 50% 10%;
        animation: bell-shake 1s ease-in-out infinite;
    }

    @keyframes bell-shake {
        0%   { transform: rotate(0deg); }
        15%  { transform: rotate(12deg); }
        30%  { transform: rotate(-10deg); }
        45%  { transform: rotate(8deg); }
        60%  { transform: rotate(-6deg); }
        75%  { transform: rotate(3deg); }
        100% { transform: rotate(0deg); }
    }

    /* 通知面板遮罩层 */
    .notification-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.3);
        z-index: 1200;
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.3s ease, visibility 0.3s ease;
    }

    .notification-overlay.show {
        opacity: 1;
        visibility: visible;
    }

    /* 通知面板 */
    .notification-panel {
        position: fixed;
        top: 0;
        right: -400px;
        width: clamp(260px, 20.83vw, 400px);
        height: 100vh;
        background: #ffffff;
        box-shadow: -4px 0 20px rgba(0, 0, 0, 0.15);
        z-index: 1300;
        transition: right 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .notification-panel.show {
        right: 0;
    }

    /* 通知面板头部 */
    .notification-header {
        padding: clamp(10px, 1.04vw, 20px) clamp(16px, 1.25vw, 24px);
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #f9fafb;
    }

    .notification-header h2 {
        margin: 0;
        font-size: clamp(14px, 1.04vw, 20px);
        font-weight: 600;
        color: #1a237e;
    }

    .notification-close {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        border: none;
        background: transparent;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #6b7280;
        transition: all 0.2s ease;
    }

    .notification-close:hover {
        background: #e5e7eb;
        color: #1a237e;
    }

    .notification-close svg {
        width: clamp(16px, 1.04vw, 20px);
        height: clamp(16px, 1.04vw, 20px);
    }

    /* 通知内容区域 */
    .notification-content {
        flex: 1;
        overflow-y: auto;
        padding: clamp(10px, 0.83vw, 16px);
    }

    .notification-item {
        padding: clamp(10px, 0.83vw, 16px);
        margin-bottom: clamp(8px, 0.625vw, 12px);
        background: #f9fafb;
        border-radius: 12px;
        border-left: 4px solid #1a237e;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .notification-item:hover {
        background: #f3f4f6;
        transform: translateX(-2px);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    .notification-item.unread {
        background: #eff6ff;
        border-left-color: #3b82f6;
    }

    .notification-title {
        font-size: clamp(10px, 0.73vw, 14px);
        font-weight: 600;
        color: #111827;
        margin-bottom: 6px;
    }

    .notification-message {
        font-size: clamp(9px, 0.68vw, 13px);
        color: #6b7280;
        line-height: 1.5;
        margin-bottom: 8px;
    }

    .notification-time {
        font-size: clamp(8px, 0.625vw, 12px);
        color: #9ca3af;
    }

    .notification-empty {
        text-align: center;
        padding: 60px 20px;
        color: #9ca3af;
    }

    .notification-empty svg {
        width: 64px;
        height: 64px;
        margin-bottom: 16px;
        opacity: 0.5;
    }

    .notification-empty p {
        margin: 0;
        font-size: 14px;
    }

    /* 公司到期倒计时样式 */
    .company-expiration-countdown {
        padding: clamp(4px, 0.42vw, 6px) clamp(6px, 0.63vw, 10px);
        margin-bottom: clamp(6px, 0.52vw, 10px);
        background: rgba(255, 255, 255, 0.12);
        border-radius: 6px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: clamp(4px, 0.42vw, 6px);
        transition: all 0.3s ease;
    }

    .company-expiration-countdown.expired {
        background: rgba(239, 68, 68, 0.4);
        border-color: rgba(239, 68, 68, 0.7);
    }

    .company-expiration-countdown.warning {
        background: rgba(251, 191, 36, 0.4);
        border-color: rgba(251, 191, 36, 0.7);
    }

    .company-expiration-countdown.normal {
        background: rgba(59, 130, 246, 0.35);
        border-color: rgba(59, 130, 246, 0.6);
    }

    .expiration-icon {
        width: clamp(10px, 0.83vw, 15px);
        height: clamp(10px, 0.83vw, 15px);
        flex-shrink: 0;
        color: white;
    }

    .company-expiration-countdown.expired .expiration-icon {
        color: #ffffff;
    }

    .company-expiration-countdown.warning .expiration-icon {
        color: #ffffff;
    }

    .company-expiration-countdown.normal .expiration-icon {
        color: #ffffff;
    }

    .expiration-content {
        display: flex;
        align-items: baseline;
        gap: clamp(3px, 0.31vw, 4px);
        flex-wrap: wrap;
        justify-content: center;
    }

    .expiration-label {
        font-size: clamp(6px, 0.625vw, 10px);
        font-weight: 700;
        color: #ffffff;
        margin: 0;
        line-height: 1.3;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
    }

    .expiration-countdown-text {
        font-size: clamp(6px, 0.625vw, 10px);
        font-weight: 600;
        color: #ffffff;
        margin: 0;
        line-height: 1.3;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
    }
</style>

<link rel="icon" type="image/png" href="images/count_logo.png">
<!-- Overlay -->
<div class="informationmenu-overlay"></div>

<!-- Sidebar Menu -->
<div class="informationmenu">
    <div class="informationmenu-header">
        <div class="header-logo-section">
            <img src="images/count_whitelogo.png" alt="EAZYCOUNT Logo" class="header-logo">
            <!-- 通知铃铛 -->
            <div class="notification-bell" title="Notifications" onclick="toggleNotificationPanel(event)">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12 2C10.34 2 9 3.34 9 5V5.29C6.72 6.15 5.12 8.39 5.01 11L5 11V16L3 18V19H21V18L19 16V11C18.88 8.39 17.28 6.15 15 5.29V5C15 3.34 13.66 2 12 2ZM12 22C10.9 22 10 21.1 10 20H14C14 21.1 13.1 22 12 22Z"/>
                </svg>
            </div>
        </div>

        <!-- 用户信息容器（头像和用户信息左右排版） -->
        <div class="user-info-container">
            <!-- 添加头像选择器（改为使用 PNG 照片） -->
            <div class="avatar-selector-container">
                <div class="current-avatar" id="currentAvatar" onclick="toggleAvatarOptions()">
                    <!-- 移除默认 src，避免每次切换页面先闪一下默认头像；实际头像由 JS 根据 localStorage 设置 -->
                    <img id="currentAvatarImg" alt="Avatar" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;backface-visibility:hidden;-webkit-backface-visibility:hidden;" loading="eager">
                    <script>
                        // 立即设置头像，避免闪烁（在DOMContentLoaded之前执行）
                        (function() {
                            const avatarImages = {
                                male1: 'images/avatar1.png',
                                male2: 'images/avatar2.png',
                                male3: 'images/avatar3.png',
                                male4: 'images/avatar4.png',
                                male5: 'images/avatar5.png',
                                male6: 'images/avatar6.png',
                                male7: 'images/avatar7.png',
                                male8: 'images/avatar8.png',
                                male9: 'images/avatar9.png',
                                female1: 'images/female1.png',
                                female2: 'images/female2.png',
                                female3: 'images/female3.png',
                                female4: 'images/female4.png',
                                female5: 'images/female5.png',
                                female6: 'images/female6.png',
                                female7: 'images/female7.png',
                                female8: 'images/female8.png',
                                female9: 'images/female9.png'
                            };
                            const savedAvatar = localStorage.getItem('selectedAvatar');
                            const avatarId = (savedAvatar && avatarImages[savedAvatar]) ? savedAvatar : 'male1';
                            const img = document.getElementById('currentAvatarImg');
                            if (img) {
                                // 直接设置 src，图片尺寸已固定，不会导致布局变化
                                img.src = avatarImages[avatarId];
                            }
                        })();
                    </script>
                </div>
                
            <div class="avatar-options" id="avatarOptions">
                <div class="options-title">Choose Avatar</div>
                
                <!-- 性别选择 -->
                <div class="gender-selection" id="genderSelection">
                    <button type="button" class="gender-btn active" onclick="selectGender('male')">Male</button>
                    <button type="button" class="gender-btn" onclick="selectGender('female')">Female</button>
                </div>

                <!-- 男性头像列表 -->
                <div class="avatar-list show" id="maleAvatarList">
                    <div class="avatar-option" data-avatar-id="male1" onclick="selectAvatar('male1')">
                        <img src="images/avatar1.png" alt="Male Avatar 1" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    </div>
                    <div class="avatar-option" data-avatar-id="male2" onclick="selectAvatar('male2')">
                        <img src="images/avatar2.png" alt="Male Avatar 2" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    </div>
                    <div class="avatar-option" data-avatar-id="male3" onclick="selectAvatar('male3')">
                        <img src="images/avatar3.png" alt="Male Avatar 3" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    </div>
                    <div class="avatar-option" data-avatar-id="male4" onclick="selectAvatar('male4')">
                        <img src="images/avatar4.png" alt="Male Avatar 4" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    </div>
                    <div class="avatar-option" data-avatar-id="male5" onclick="selectAvatar('male5')">
                        <img src="images/avatar5.png" alt="Male Avatar 5" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    </div>
                    <div class="avatar-option" data-avatar-id="male6" onclick="selectAvatar('male6')">
                        <img src="images/avatar6.png" alt="Male Avatar 6" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    </div>
                    <div class="avatar-option" data-avatar-id="male7" onclick="selectAvatar('male7')">
                        <img src="images/avatar7.png" alt="Male Avatar 7" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    </div>
                    <div class="avatar-option" data-avatar-id="male8" onclick="selectAvatar('male8')">
                        <img src="images/avatar8.png" alt="Male Avatar 8" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    </div>
                    <div class="avatar-option" data-avatar-id="male9" onclick="selectAvatar('male9')">
                        <img src="images/avatar9.png" alt="Male Avatar 9" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    </div>
                </div>

                <!-- 女性头像列表 -->
                <div class="avatar-list" id="femaleAvatarList">
                    <div class="avatar-option" data-avatar-id="female1" onclick="selectAvatar('female1')">
                        <img src="images/female1.png" alt="Female Avatar 1" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    </div>
                    <div class="avatar-option" data-avatar-id="female2" onclick="selectAvatar('female2')">
                        <img src="images/female2.png" alt="Female Avatar 2" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    </div>
                    <div class="avatar-option" data-avatar-id="female3" onclick="selectAvatar('female3')">
                        <img src="images/female3.png" alt="Female Avatar 3" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    </div>
                    <div class="avatar-option" data-avatar-id="female4" onclick="selectAvatar('female4')">
                        <img src="images/female4.png" alt="Female Avatar 4" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    </div>
                    <div class="avatar-option" data-avatar-id="female5" onclick="selectAvatar('female5')">
                        <img src="images/female5.png" alt="Female Avatar 5" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    </div>
                    <div class="avatar-option" data-avatar-id="female6" onclick="selectAvatar('female6')">
                        <img src="images/female6.png" alt="Female Avatar 6" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    </div>
                    <div class="avatar-option" data-avatar-id="female7" onclick="selectAvatar('female7')">
                        <img src="images/female7.png" alt="Female Avatar 7" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    </div>
                    <div class="avatar-option" data-avatar-id="female8" onclick="selectAvatar('female8')">
                        <img src="images/female8.png" alt="Female Avatar 8" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    </div>
                    <div class="avatar-option" data-avatar-id="female9" onclick="selectAvatar('female9')">
                        <img src="images/female9.png" alt="Female Avatar 9" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    </div>
                </div>
            </div>
            </div>

            <div class="user-avatar-dropdown">
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($name); ?></div>
                    <div class="user-role"><?php echo ucfirst($role); ?></div>
                </div>
            </div>
        </div>
        <!-- 语言切换按钮 -->
        <!-- <div class="language-switcher">
            <div class="language-dropdown">
                <button class="language-btn" onclick="toggleLanguageDropdown()">
                    <img src="images/uk.png" alt="English" class="flag-icon" id="current-flag">
                    <span class="language-text" id="current-lang">English</span>
                    <span class="dropdown-arrow">▼</span>
                </button>
                <div class="language-dropdown-list" id="languageDropdown">
                    <div class="language-option" onclick="selectLanguage('en')">
                        <img src="images/uk.png" alt="English" class="flag-icon">
                        <span>English</span>
                    </div>
                    <div class="language-option" onclick="selectLanguage('zh')">
                        <img src="images/china.png" alt="中文" class="flag-icon">
                        <span>中文</span>
                    </div>
                </div>
            </div>
        </div> -->
    </div>

    <div class="informationmenu-content">
        <div class="content-separator"></div>

        <?php if ($isMember): ?>
            <!-- Member Home -->
            <div class="informationmenu-section">
                <div class="informationmenu-section-title account-direct" data-page="memberdashboard.php" onclick="window.location.href='memberdashboard.php'">
                    <svg class="section-icon" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/>
                    </svg>
                    Home
                </div>
            </div>

            <!-- Member Win/Loss -->
            <div class="informationmenu-section">
                <div class="informationmenu-section-title account-direct" data-page="member.php" onclick="window.location.href='member.php'">
                    <svg class="section-icon" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M20 4H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/>
                    </svg>
                    Win/Loss
                </div>
            </div>
        <?php else: ?>
            <!-- Home Section -->
            <?php if (empty($permissions) || in_array('home', $permissions)): ?>
            <div class="informationmenu-section">
                <div class="informationmenu-section-title" data-page="dashboard.php" onclick="window.location.href='dashboard.php'">
                    <svg class="section-icon" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/>
                    </svg>
                    Home
                </div>                
            </div>
            <?php endif; ?>

            <!-- Domain Section - 只有与 c168 相关且角色为 owner/admin 的用户可见 -->
            <?php if ((empty($permissions) || in_array('domain', $permissions)) && $hasC168Access): ?>
            <div class="informationmenu-section">
                <div class="informationmenu-section-title" data-page="domain.php" onclick="window.location.href='domain.php'">
                    <svg class="section-icon" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm6.93 8h-3.46c-.14-2.01-.5-3.88-1.06-5.38 2.16.76 3.76 2.62 4.52 5.38zm-6.93 0h-4.9c.13-1.78.58-3.51 1.28-4.9.53-1.04 1.16-1.79 1.78-2.21.6-.41.98-.46 1.84-.46v7.57zm0 2v7.57c-.86 0-1.24-.05-1.84-.46-.62-.43-1.25-1.17-1.78-2.21-.7-1.39-1.15-3.12-1.28-4.9h4.9zm2 7.43V12h4.9c-.13 1.78-.58 3.51-1.28 4.9-.53 1.04-1.16 1.79-1.78 2.21-.6.41-.98.46-1.84.46zm0-9.43V4.43c.86 0 1.24.05 1.84.46.62.43 1.25 1.17 1.78 2.21.7 1.39 1.15 3.12 1.28 4.9h-4.9zM5.07 12h3.46c.14 2.01.5 3.88 1.06 5.38-2.16-.76-3.76-2.62-4.52-5.38z"/>
                    </svg>
                    Domain
                </div>
            </div>
            <?php endif; ?>

            <!-- Announcement Section - Only C168 owner/admin can see and access (to publish/manage announcements) -->
            <!-- All users can view announcements in dashboard, but only C168 can publish/manage -->
            <?php if ($hasC168Access): ?>
            <div class="informationmenu-section">
                <div class="informationmenu-section-title account-direct" data-page="announcement.php" onclick="window.location.href='announcement.php'">
                    <svg class="section-icon" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-3H6V9h12v2zm0-3H6V6h12v2z"/>
                    </svg>
                    Announcement
                </div>
            </div>
            <?php endif; ?>

            <!-- Auto Login Manager Section -->
            <!-- <div class="informationmenu-section">
                <div class="informationmenu-section-title account-direct" data-page="auto-login-manager.php" onclick="window.location.href='auto-login-manager.php'">
                    <svg class="section-icon" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/>
                    </svg>
                    自动登录管理
                </div>
            </div> -->

            <!-- Admin Section -->
            <?php if (empty($permissions) || in_array('admin', $permissions)): ?>
            <div class="informationmenu-section">
                <div class="informationmenu-section-title account-direct" data-page="userlist.php" onclick="window.location.href='userlist.php'">
                    <svg class="section-icon" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/>
                    </svg>
                    Admin
                </div>
            </div>
            <?php endif; ?>

            <!-- Account Section -->
            <?php if (empty($permissions) || in_array('account', $permissions)): ?>
            <div class="informationmenu-section">
                <div class="informationmenu-section-title account-direct" data-page="account-list.php" onclick="window.location.href='account-list.php'">
                    <svg class="section-icon" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                    </svg>
                    Account
                </div>
            </div>
            <?php endif; ?>

            <!-- Process Section -->
            <?php if (empty($permissions) || in_array('process', $permissions)): ?>
            <div class="informationmenu-section">
                <div class="informationmenu-section-title" data-page="processlist.php" onclick="window.location.href='processlist.php'">
                    <svg class="section-icon" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                    </svg>
                    Process
                </div>
            </div>
            <?php endif; ?>

            <!-- Data Capture Section -->
            <?php if (empty($permissions) || in_array('datacapture', $permissions)): ?>
            <div class="informationmenu-section">
                <div class="informationmenu-section-title" data-page="datacapture.php" onclick="window.location.href='datacapture.php'">
                    <svg class="section-icon" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/>
                    </svg>
                    Data Capture
                </div>
            </div>
            <?php endif; ?>

            <!-- Transaction Payment Section -->
            <?php if (empty($permissions) || in_array('payment', $permissions)): ?>
            <div class="informationmenu-section">
                <div class="informationmenu-section-title" data-page="transaction.php" onclick="window.location.href='transaction.php'">
                    <svg class="section-icon" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M20 4H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/>
                    </svg>
                    Transaction Payment
                </div>
            </div>
            <?php endif; ?>

            <!-- Report Section -->
            <?php if (empty($permissions) || in_array('report', $permissions)): ?>
            <div class="informationmenu-section">
                <div class="menu-item-wrapper">
                    <div class="informationmenu-section-title" data-section="report">
                        <svg class="section-icon" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 2 2h8c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/>
                        </svg>
                        Report
                        <span class="section-arrow">▶</span>
                    </div>
                    <div class="submenu" id="report-submenu">
                        <div class="submenu-content">
                            <a href="customer_report.php" class="submenu-item">
                                <span>Customer Report</span>
                            </a>
                            <a href="domain_report.php" class="submenu-item">
                                <span>Domain Report</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Maintenance Section -->
            <?php if (empty($permissions) || in_array('maintenance', $permissions)): ?>
            <div class="informationmenu-section">
                <div class="menu-item-wrapper">
                    <div class="informationmenu-section-title" data-section="maintenance">
                        <svg class="section-icon" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M22.7 19l-9.1-9.1c.9-2.3.4-5-1.5-6.9-2-2-5-2.4-7.4-1.3L9 6 6 9 1.6 4.7C.4 7.1.9 10.1 2.9 12.1c1.9 1.9 4.6 2.4 6.9 1.5l9.1 9.1c.4.4 1 .4 1.4 0l2.3-2.3c.5-.4.5-1.1.1-1.4z"/>
                        </svg>
                        Maintenance
                        <span class="section-arrow">▶</span>
                    </div>
                    <div class="submenu" id="maintenance-submenu">
                        <div class="submenu-content">
                            <a href="capture_maintenance.php" class="submenu-item">
                                <span>Data Capture</span>
                            </a>
                            <a href="transaction_maintenance.php" class="submenu-item">
                                <span>Transaction</span>
                            </a>
                            <a href="payment_maintenance.php" class="submenu-item">
                                <span>Payment</span>
                            </a>
                            <a href="formula_maintenance.php" class="submenu-item">
                                <span>Formula</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="informationmenu-footer">
        <?php if ($company_expiration_date): ?>
        <div class="company-expiration-countdown <?php echo $expiration_status; ?>" id="companyExpirationCountdown">
            <svg class="expiration-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <polyline points="12 6 12 12 16 14"></polyline>
            </svg>
            <div class="expiration-content">
                <span class="expiration-label">Exp:</span>
                <span class="expiration-countdown-text <?php echo $expiration_status; ?>" id="expirationCountdownText">
                    <?php echo htmlspecialchars($expiration_countdown_text); ?>
                </span>
            </div>
        </div>
        <?php endif; ?>
        <button class="btn logout-btn" onclick="handleLogout()">
            Logout
        </button>
    </div>
</div>

<!-- 通知面板遮罩层 -->
<div class="notification-overlay" id="notificationOverlay" onclick="closeNotificationPanel()"></div>

<!-- 通知面板 -->
<div class="notification-panel" id="notificationPanel">
    <div class="notification-header">
        <h2>Announcements</h2>
        <button class="notification-close" onclick="closeNotificationPanel()" title="关闭">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>
    </div>
    <div class="notification-content" id="notificationContent">
        <!-- 公告将在这里动态加载 -->
    </div>
</div>

<!-- Sidebar JavaScript -->
<script>
    // Sidebar functionality
    const sidebar = document.querySelector('.informationmenu');
    const overlay = document.querySelector('.informationmenu-overlay');
    const userAvatar = document.getElementById('user-avatar');
    const sidebarToggle = document.getElementById('sidebarToggle');

    // Show sidebar when clicking user avatar
    userAvatar?.addEventListener('click', function() {
        sidebar.classList.add('show');
        overlay.classList.add('show');
    });

    // Close sidebar function
    function closeSidebar() {
        sidebar.classList.remove('show');
        overlay.classList.remove('show');
        // Close all dropdown menus
        document.querySelectorAll('.dropdown-menu-items').forEach(dropdown => {
            dropdown.classList.remove('show');
        });
        document.querySelectorAll('.informationmenu-section-title').forEach(title => {
            title.classList.remove('active');
        });
    }

    // Close sidebar when clicking overlay
    overlay?.addEventListener('click', closeSidebar);

    // ESC key to close sidebar
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeSidebar();
        }
    });   

    // Section title click events
    document.querySelectorAll('.informationmenu-section-title').forEach(title => {
        let middleClickHandled = false;
        let ctrlClickHandled = false;
        
        // 添加 mousedown 事件来支持中键点击和 Ctrl+点击（优先处理，在 onclick 之前）
        title.addEventListener('mousedown', function(e) {
            const isMiddleClick = e.button === 1 || e.which === 2;
            const isCtrlClick = e.ctrlKey || e.metaKey; // metaKey 支持 Mac 的 Cmd 键
            const pageUrl = this.getAttribute('data-page');
            
            // 处理中键点击
            if (pageUrl && isMiddleClick) {
                e.preventDefault();
                e.stopPropagation();
                middleClickHandled = true;
                // 临时保存并移除 onclick，防止它执行
                const originalOnclick = this.onclick;
                this.onclick = null;
                window.open(pageUrl, '_blank');
                // 恢复 onclick（延迟恢复，确保 click 事件不会触发）
                setTimeout(() => {
                    this.onclick = originalOnclick;
                    middleClickHandled = false;
                }, 100);
                return false;
            }
            
            // 处理 Ctrl+点击（左键或右键都可以）
            if (pageUrl && isCtrlClick && (e.button === 0 || e.button === 2)) {
                e.preventDefault();
                e.stopPropagation();
                ctrlClickHandled = true;
                // 临时保存并移除 onclick，防止它执行
                const originalOnclick = this.onclick;
                this.onclick = null;
                window.open(pageUrl, '_blank');
                // 恢复 onclick（延迟恢复，确保 click 事件不会触发）
                setTimeout(() => {
                    this.onclick = originalOnclick;
                    ctrlClickHandled = false;
                }, 100);
                return false;
            } else {
                middleClickHandled = false;
                ctrlClickHandled = false;
            }
        }, true); // 使用捕获阶段，确保在其他事件之前执行
        
        title.addEventListener('click', function(e) {
            // 如果已经在 mousedown 中处理了中键点击或 Ctrl+点击，直接返回
            if (middleClickHandled || ctrlClickHandled) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
            
            // 检测 Ctrl+点击 - 在新窗口打开（备用处理）
            const isCtrlClick = e.ctrlKey || e.metaKey; // metaKey 支持 Mac 的 Cmd 键
            const pageUrl = this.getAttribute('data-page');
            
            if (pageUrl && isCtrlClick) {
                e.preventDefault();
                e.stopPropagation();
                // 临时移除 onclick 防止执行
                const originalOnclick = this.onclick;
                this.onclick = null;
                window.open(pageUrl, '_blank');
                // 恢复 onclick
                setTimeout(() => {
                    this.onclick = originalOnclick;
                }, 100);
                return false;
            }
            
            // 检测中键点击（滚轮按钮）- 作为备用处理
            const isMiddleClick = e.button === 1 || e.which === 2;
            
            // 如果有 data-page 属性且是中键点击，在新窗口打开
            if (pageUrl && isMiddleClick) {
                e.preventDefault();
                e.stopPropagation();
                window.open(pageUrl, '_blank');
                return false;
            }
            
            // 如果是中键点击但没有 data-page，阻止默认行为
            if (isMiddleClick) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
            
            const targetId = this.getAttribute('data-target');
            const section = this.getAttribute('data-section');
            
            // 如果是有 submenu 的 section（report 或 maintenance），不执行点击展开逻辑
            if (section === 'report' || section === 'maintenance') {
                return;
            }
            
            const targetDropdown = document.getElementById(targetId);

            // Normal toggle logic when sidebar is expanded
            // Close other section dropdowns
            document.querySelectorAll('.dropdown-menu-items').forEach(dropdown => {
                if (dropdown.id !== targetId) {
                    dropdown.classList.remove('show');
                }
            });

            // Remove other section title active states
            document.querySelectorAll('.informationmenu-section-title').forEach(t => {
                if (t !== this) {
                    t.classList.remove('active');
                }
            });

            // Toggle current section
            this.classList.toggle('active');
            targetDropdown?.classList.toggle('show');
        });
    });

    // Submenu item click effects (支持 Ctrl+点击)
    document.querySelectorAll('.submenu-item').forEach(item => {
        item.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            
            // 检测 Ctrl+点击 - 在新窗口打开
            const isCtrlClick = e.ctrlKey || e.metaKey; // metaKey 支持 Mac 的 Cmd 键
            
            if (isCtrlClick && href && href !== '#' && !href.startsWith('javascript:')) {
                e.preventDefault();
                e.stopPropagation();
                window.open(href, '_blank');
                return false;
            }
            
            // 检测中键点击（滚轮按钮）
            const isMiddleClick = e.button === 1 || e.which === 2;
            
            if (isMiddleClick && href && href !== '#' && !href.startsWith('javascript:')) {
                e.preventDefault();
                e.stopPropagation();
                window.open(href, '_blank');
                return false;
            }
            
            // 其他情况使用默认行为（正常导航）
        });
    });

    // Menu item click effects
    document.querySelectorAll('.informationmenu-item').forEach(item => {
        let middleClickHandled = false;
        
        // 添加 mousedown 事件来支持中键点击（优先处理）
        item.addEventListener('mousedown', function(e) {
            const isMiddleClick = e.button === 1 || e.which === 2;
            const href = this.getAttribute('href');
            
            if (isMiddleClick && href && href !== '#' && !href.startsWith('javascript:')) {
                e.preventDefault();
                e.stopPropagation();
                middleClickHandled = true;
                window.open(href, '_blank');
                return false;
            } else {
                middleClickHandled = false;
            }
        }, true); // 使用捕获阶段
        
        item.addEventListener('click', function(e) {
            // 如果已经在 mousedown 中处理了中键点击，直接返回
            if (middleClickHandled) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
            
            // 检测 Ctrl+点击 - 在新窗口打开
            const isCtrlClick = e.ctrlKey || e.metaKey; // metaKey 支持 Mac 的 Cmd 键
            const href = this.getAttribute('href');
            
            if (isCtrlClick && href && href !== '#' && !href.startsWith('javascript:')) {
                e.preventDefault();
                e.stopPropagation();
                window.open(href, '_blank');
                return false;
            }
            
            // 检测中键点击（滚轮按钮）- 作为备用处理
            const isMiddleClick = e.button === 1 || e.which === 2;

            // 如果是中键点击且有有效链接，在新窗口打开
            if (isMiddleClick && href && href !== '#' && !href.startsWith('javascript:')) {
                e.preventDefault();
                e.stopPropagation();
                window.open(href, '_blank');
                return false;
            }

            // Check if there's a real link
            if (href && href !== '#' && !href.startsWith('javascript:')) {
                // Has real link, allow normal navigation
                window.location.href = href;
                return;
            }

            // No real link, prevent default behavior
            e.preventDefault();

            // Remove other active states
            document.querySelectorAll('.informationmenu-item').forEach(i => i.classList.remove('active'));

            // Add active state to current item
            this.classList.add('active');
            
            // You can add custom logic here for items without real links
            console.log('Clicked menu item:', this.textContent);
        });
    });

    // 语言切换功能
    let currentLanguage = 'en';
    
    function toggleLanguageDropdown() {
        const dropdown = document.getElementById('languageDropdown');
        const button = document.querySelector('.language-btn');
        
        dropdown.classList.toggle('show');
        button.classList.toggle('active');
    }
    
    function selectLanguage(lang) {
        currentLanguage = lang;
        const dropdown = document.getElementById('languageDropdown');
        const button = document.querySelector('.language-btn');
        const currentFlag = document.getElementById('current-flag');
        const currentLang = document.getElementById('current-lang');
        
        // 更新按钮显示
        if (lang === 'en') {
            currentFlag.src = 'images/uk.png';
            currentFlag.alt = 'English';
            currentLang.textContent = 'English';
        } else if (lang === 'zh') {
            currentFlag.src = 'images/china.png';
            currentFlag.alt = '中文';
            currentLang.textContent = '中文';
        }
        
        // 关闭下拉菜单
        dropdown.classList.remove('show');
        button.classList.remove('active');
        
        // 保存语言选择到localStorage
        localStorage.setItem('selectedLanguage', lang);
        
        // 获取当前页面文件名
        const currentPage = window.location.pathname.split('/').pop();
        
        // 根据语言选择跳转到对应页面
        // if (lang === 'zh') {
        //     window.location.href = `cn/${currentPage}`;
        // } else if (lang === 'en') {
        //     if (window.location.pathname.includes('/cn/')) {
        //         window.location.href = `../${currentPage}`;
        //     }
        // }
        
        console.log('Language switched to:', lang);
    }

    // 页面加载时恢复语言选择
    document.addEventListener('DOMContentLoaded', function() {
        // 检测当前页面语言
        let currentLang = 'en';
        if (window.location.pathname.includes('/cn/')) {
            currentLang = 'zh';
        }
        
        // 更新按钮显示为当前语言
        const currentFlag = document.getElementById('current-flag');
        const currentLangText = document.getElementById('current-lang');
        
        if (currentLang === 'zh') {
            currentFlag.src = 'images/china.png';
            currentFlag.alt = '中文';
            currentLangText.textContent = '中文';
        } else {
            currentFlag.src = 'images/uk.png';
            currentFlag.alt = 'English';
            currentLangText.textContent = 'English';
        }
        
        // 保存当前语言到localStorage
        localStorage.setItem('selectedLanguage', currentLang);
    });
    
    // 点击其他地方关闭下拉菜单
    document.addEventListener('click', function(e) {
        const languageDropdown = document.querySelector('.language-dropdown');
        const dropdown = document.getElementById('languageDropdown');
        const button = document.querySelector('.language-btn');
        
        if (languageDropdown && !languageDropdown.contains(e.target)) {
            if (dropdown) dropdown.classList.remove('show');
            if (button) button.classList.remove('active');
        }
    });

    // Logout function
    function handleLogout() {
        if (confirm('Are you sure you want to logout?')) {
            window.location.href = 'dashboard.php?logout=1';
        }
    }

    console.log('Sidebar menu system loaded successfully');

    // 获取当前页面文件名
    function getCurrentPageName() {
        const path = window.location.pathname;
        return path.split('/').pop();
    }

    // 头像图片映射（请根据实际路径调整）
    const avatarImages = {
        male1: 'images/avatar1.png',
        male2: 'images/avatar2.png',
        male3: 'images/avatar3.png',
        male4: 'images/avatar4.png',
        male5: 'images/avatar5.png',
        male6: 'images/avatar6.png',
        male7: 'images/avatar7.png',
        male8: 'images/avatar8.png',
        male9: 'images/avatar9.png',
        female1: 'images/female1.png',
        female2: 'images/female2.png',
        female3: 'images/female3.png',
        female4: 'images/female4.png',
        female5: 'images/female5.png',
        female6: 'images/female6.png',
        female7: 'images/female7.png',
        female8: 'images/female8.png',
        female9: 'images/female9.png'
    };

    let currentAvatarId = 'male1'; // 默认头像

    function toggleAvatarOptions() {
        const options = document.getElementById('avatarOptions');
        const isShowing = options.classList.contains('show');
        
        if (!isShowing) {
            // 打开时重置到性别选择
            backToGenderSelection();
        }
        
        options.classList.toggle('show');
        
        // 更新选中状态
        updateSelectedAvatar();
    }

    function selectGender(gender) {
        const maleList = document.getElementById('maleAvatarList');
        const femaleList = document.getElementById('femaleAvatarList');
        const genderBtns = document.querySelectorAll('.gender-btn');
        
        // 更新按钮状态
        genderBtns.forEach(btn => {
            btn.classList.remove('active');
            if (btn.textContent.toLowerCase() === gender) {
                btn.classList.add('active');
            }
        });
        
        // 显示对应的头像列表，隐藏另一个
        if (gender === 'male') {
            maleList.classList.add('show');
            femaleList.classList.remove('show');
        } else {
            femaleList.classList.add('show');
            maleList.classList.remove('show');
        }
    }

    function backToGenderSelection() {
        const maleList = document.getElementById('maleAvatarList');
        const femaleList = document.getElementById('femaleAvatarList');
        const genderBtns = document.querySelectorAll('.gender-btn');
        
        // 重置为 Male active 状态
        genderBtns.forEach(btn => {
            btn.classList.remove('active');
            if (btn.textContent.toLowerCase() === 'male') {
                btn.classList.add('active');
            }
        });
        
        // 显示男性头像列表，隐藏女性头像列表
        maleList.classList.add('show');
        femaleList.classList.remove('show');
    }

    function selectAvatar(avatarId) {
        if (!avatarImages[avatarId]) {
            return;
        }
        currentAvatarId = avatarId;
        const currentAvatarImg = document.getElementById('currentAvatarImg');
        const options = document.getElementById('avatarOptions');
        if (currentAvatarImg) {
            currentAvatarImg.src = avatarImages[avatarId];
        }
        
        // 隐藏选项
        if (options) {
            options.classList.remove('show');
        }
        
        // 保存用户选择到localStorage（可选）
        localStorage.setItem('selectedAvatar', avatarId);
        
        // 更新选中样式
        updateSelectedAvatar();
        
        console.log('Avatar changed to:', avatarId);
    }

    function updateSelectedAvatar() {
        // 清除所有选中状态
        document.querySelectorAll('.avatar-option').forEach(option => {
            option.classList.remove('selected');
        });
        
        // 添加当前选中状态
        const selectedOption = document.querySelector(`.avatar-option[data-avatar-id="${currentAvatarId}"]`);
        if (selectedOption) {
            selectedOption.classList.add('selected');
        }
    }

    // 页面加载时恢复用户选择的头像
    document.addEventListener('DOMContentLoaded', function() {
        const savedAvatar = localStorage.getItem('selectedAvatar');
        const currentAvatarImg = document.getElementById('currentAvatarImg');

        if (savedAvatar && avatarImages[savedAvatar]) {
            currentAvatarId = savedAvatar;
        } else {
            currentAvatarId = 'male1';
        }

        if (currentAvatarImg) {
            currentAvatarImg.src = avatarImages[currentAvatarId];
        }
        updateSelectedAvatar();
        
        // 根据当前头像的性别设置默认显示
        if (currentAvatarId.startsWith('female')) {
            selectGender('female');
        } else {
            selectGender('male');
        }
    });


    // 点击其他地方关闭头像选择菜单
    document.addEventListener('click', function(e) {
        const avatarContainer = document.querySelector('.avatar-selector-container');
        const avatarOptions = document.getElementById('avatarOptions');
        
        if (avatarContainer && avatarOptions && 
            !avatarContainer.contains(e.target) && !avatarOptions.contains(e.target)) {
            avatarOptions.classList.remove('show');
        }
    });

    // 设置当前页面的高亮状态
    function setCurrentPageHighlight() {
        const currentPage = getCurrentPageName();
        
        // 移除所有现有的current-page类
        document.querySelectorAll('.informationmenu-section-title').forEach(title => {
            title.classList.remove('current-page');
        });
        
        // Maintenance 三个子页面统一高亮 Maintenance 主菜单
        const maintenancePages = [
            'capture_maintenance.php',
            'transaction_maintenance.php',
            'payment_maintenance.php',
            'formula_maintenance.php'
        ];
        if (maintenancePages.includes(currentPage)) {
            const maintenanceTitle = document.querySelector('.informationmenu-section-title[data-section="maintenance"]');
            if (maintenanceTitle) {
                maintenanceTitle.classList.add('current-page');
            }
        }
        
        // Report 两个子页面统一高亮 Report 主菜单
        const reportPages = [
            'customer_report.php',
            'domain_report.php'
        ];
        if (reportPages.includes(currentPage)) {
            const reportTitle = document.querySelector('.informationmenu-section-title[data-section="report"]');
            if (reportTitle) {
                reportTitle.classList.add('current-page');
            }
        }
        
        // 其他页面按 data-page 精确匹配
        document.querySelectorAll('.informationmenu-section-title').forEach(title => {
            const pageName = title.getAttribute('data-page');
            if (pageName === currentPage) {
                title.classList.add('current-page');
            }
        });
    }

    // 页面加载时设置高亮
    document.addEventListener('DOMContentLoaded', setCurrentPageHighlight);

    // 动态定位 submenu
    function positionSubmenu(wrapper) {
        const title = wrapper.querySelector('.informationmenu-section-title');
        const submenu = wrapper.querySelector('.submenu');
        
        if (!title || !submenu) return;
        
        const titleRect = title.getBoundingClientRect();
        const sidebar = document.querySelector('.informationmenu');
        const sidebarRect = sidebar.getBoundingClientRect();
        
        // 计算 submenu 的位置
        // left: sidebar 右边缘
        submenu.style.left = sidebarRect.right + 'px';
        // top: 与菜单项顶部对齐
        submenu.style.top = titleRect.top + 'px';
    }

    // 为所有有 submenu 的 wrapper 添加鼠标事件
    document.querySelectorAll('.menu-item-wrapper').forEach(wrapper => {
        const submenu = wrapper.querySelector('.submenu');
        if (submenu) {
            let hideTimeout = null;
            
            // 清除隐藏延迟
            function clearHideTimeout() {
                if (hideTimeout) {
                    clearTimeout(hideTimeout);
                    hideTimeout = null;
                }
            }
            
            // 显示 submenu
            function showSubmenu() {
                clearHideTimeout();
                positionSubmenu(wrapper);
                submenu.style.opacity = '1';
                submenu.style.visibility = 'visible';
                submenu.style.transform = 'translateX(0)';
                submenu.style.pointerEvents = 'auto';
            }
            
            // 隐藏 submenu（带延迟）
            function hideSubmenu() {
                clearHideTimeout();
                hideTimeout = setTimeout(function() {
                    submenu.style.opacity = '0';
                    submenu.style.visibility = 'hidden';
                    submenu.style.transform = 'translateX(-10px)';
                    submenu.style.pointerEvents = 'none';
                }, 100); // 0.1 秒延迟
            }
            
            // 鼠标进入 wrapper
            wrapper.addEventListener('mouseenter', function() {
                showSubmenu();
            });
            
            // 鼠标离开 wrapper
            wrapper.addEventListener('mouseleave', function() {
                hideSubmenu();
            });
            
            // 鼠标进入 submenu
            submenu.addEventListener('mouseenter', function() {
                showSubmenu();
            });
            
            // 鼠标离开 submenu
            submenu.addEventListener('mouseleave', function() {
                hideSubmenu();
            });
            
            // 当鼠标移动时也更新位置（处理滚动等情况）
            wrapper.addEventListener('mousemove', function() {
                positionSubmenu(wrapper);
            });
        }
    });

    // Notification panel functionality
    function toggleNotificationPanel(event) {
        const panel = document.getElementById('notificationPanel');
        const overlay = document.getElementById('notificationOverlay');
        
        if (panel.classList.contains('show')) {
            closeNotificationPanel();
        } else {
            panel.classList.add('show');
            overlay.classList.add('show');
            // 加载公告
            loadAnnouncements();
        }
        
        // 阻止事件冒泡
        if (event) {
            event.stopPropagation();
        }
    }

    function closeNotificationPanel() {
        const panel = document.getElementById('notificationPanel');
        const overlay = document.getElementById('notificationOverlay');
        
        panel.classList.remove('show');
        overlay.classList.remove('show');
    }

    // Load announcements
    async function loadAnnouncements() {
        try {
            const response = await fetch('announcement_get_dashboard_api.php');
            const result = await response.json();
            
            const contentContainer = document.getElementById('notificationContent');
            
            if (result.success && result.data && result.data.length > 0) {
                contentContainer.innerHTML = result.data.map(announcement => `
                    <div class="notification-item unread">
                        <div class="notification-title">${escapeHtml(announcement.title)}</div>
                        <div class="notification-message">${escapeHtml(announcement.content)}</div>
                        <div class="notification-time">${escapeHtml(announcement.created_at)}</div>
                    </div>
                `).join('');
                
                // Mark as read when clicking notification item
                const notificationItems = contentContainer.querySelectorAll('.notification-item');
                notificationItems.forEach(item => {
                    item.addEventListener('click', function() {
                        this.classList.remove('unread');
                    });
                });
            } else {
                contentContainer.innerHTML = `
                    <div class="notification-empty">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-3H6V9h12v2zm0-3H6V6h12v2z"/>
                        </svg>
                        <p>No announcements</p>
                    </div>
                `;
            }
        } catch (error) {
            console.error('Failed to load announcements:', error);
            const contentContainer = document.getElementById('notificationContent');
            contentContainer.innerHTML = `
                <div class="notification-empty">
                    <p>Failed to load announcements</p>
                </div>
            `;
        }
    }

    // HTML escape function
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // 点击其他地方关闭通知面板
    document.addEventListener('click', function(e) {
        const bell = document.querySelector('.notification-bell');
        const panel = document.getElementById('notificationPanel');
        const overlay = document.getElementById('notificationOverlay');
        
        // 如果点击的不是通知铃铛和通知面板内部，则关闭面板
        if (!bell.contains(e.target) && !panel.contains(e.target) && panel.classList.contains('show')) {
            closeNotificationPanel();
        }
    });

    // 公司到期倒计时功能
    <?php if ($company_expiration_date): ?>
    function calculateCountdown(expirationDate) {
        if (!expirationDate) return null;
        
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const exp = new Date(expirationDate);
        exp.setHours(0, 0, 0, 0);
        
        const diffTime = exp - today;
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        
        if (diffDays < 0) {
            return { text: 'Expired', days: diffDays, status: 'expired' };
        } else if (diffDays === 0) {
            return { text: 'Expires today', days: 0, status: 'warning' };
        } else if (diffDays <= 7) {
            return { text: `${diffDays} day${diffDays > 1 ? 's' : ''} left`, days: diffDays, status: 'warning' };
        } else if (diffDays <= 30) {
            return { text: `${diffDays} days left`, days: diffDays, status: 'normal' };
        } else {
            const months = Math.floor(diffDays / 30);
            const days = diffDays % 30;
            if (days === 0) {
                return { text: `${months} month${months > 1 ? 's' : ''} left`, days: diffDays, status: 'normal' };
            } else {
                return { text: `${months}m ${days}d left`, days: diffDays, status: 'normal' };
            }
        }
    }

    function formatDate(dateString) {
        if (!dateString) return '';
        const date = new Date(dateString);
        return date.toLocaleDateString('zh-CN', { year: 'numeric', month: 'long', day: 'numeric' });
    }

    function updateExpirationCountdown() {
        const expirationDate = '<?php echo $company_expiration_date ? $company_expiration_date : ''; ?>';
        const countdownContainer = document.getElementById('companyExpirationCountdown');
        const countdownText = document.getElementById('expirationCountdownText');
        
        if (!expirationDate || expirationDate.trim() === '' || !countdownText || !countdownContainer) {
            if (countdownText) {
                countdownText.textContent = 'No expiration date';
                countdownText.className = 'expiration-countdown-text normal';
            }
            if (countdownContainer) {
                countdownContainer.className = 'company-expiration-countdown normal';
            }
            return;
        }
        
        const countdown = calculateCountdown(expirationDate);
        
        if (countdown) {
            countdownText.textContent = countdown.text;
            countdownText.className = 'expiration-countdown-text ' + countdown.status;
            // 同时更新容器的状态类
            countdownContainer.className = 'company-expiration-countdown ' + countdown.status;
        } else {
            countdownText.textContent = 'No expiration date';
            countdownText.className = 'expiration-countdown-text normal';
            countdownContainer.className = 'company-expiration-countdown normal';
        }
    }

    // 页面加载时立即更新倒计时（不等待 DOMContentLoaded，因为初始值已在 PHP 中设置）
    // 每分钟更新一次倒计时
    setInterval(updateExpirationCountdown, 60000);
    <?php endif; ?>
</script>
