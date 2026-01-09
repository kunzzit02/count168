<?php
// 确保session已启动
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 检查用户是否已登录
if (!isset($_SESSION['user_id'])) {
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
$companyId = $_SESSION['company_id'] ?? null;
if ($user_id) {
    $roleLower    = strtolower($role ?? '');
    $companyCode  = strtoupper($_SESSION['company_code'] ?? '');

    if (in_array($roleLower, ['owner', 'admin'], true)) {
        if ($companyCode === 'C168') {
            $hasC168Access = true;
        } elseif ($companyId) {
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
        
        if ($company_expiration_date) {
            $today = new DateTime();
            $today->setTime(0, 0, 0);
            $expiration = new DateTime($company_expiration_date);
            $expiration->setTime(0, 0, 0);
            
            $diff = $today->diff($expiration);
            $diffDays = (int)$diff->format('%r%a');
            
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
    /* Sidebar 自己的字体设置 */
    .informationmenu,
    .informationmenu * {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
    }

    /* 用户信息容器 - 优化间距和布局 */
    .user-info-container {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        width: 100%;
        padding: 16px 12px;
        margin-bottom: 8px;
        min-height: 64px;
        contain: layout style;
        will-change: auto;
        overflow: visible;
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
        padding: 6px;
        padding-left: 0px;
        border-radius: 25px;
        transition: background-color 0.3s ease;
        text-align: left;
        color: white;
        flex-shrink: 0;
        min-width: 0;
        contain: layout style;
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
        margin-right: 12px;
        flex-shrink: 0;
        width: fit-content;
        min-width: 48px;
        contain: layout style;
        overflow: visible;
        z-index: 10000;
        isolation: isolate;
    }

    /* 当前头像显示 */
    .current-avatar {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        cursor: pointer;
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
        transform: translateZ(0);
        will-change: border-color, box-shadow;
        backface-visibility: hidden;
        -webkit-backface-visibility: hidden;
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
        left: calc(100% + 12px);
        transform: translateY(-50%);
        background: rgba(255, 255, 255, 0.95);
        border-radius: 12px;
        padding: 12px;
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
        backdrop-filter: blur(20px);
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.3s ease, visibility 0.3s ease, transform 0.3s ease;
        z-index: 9999;
        width: 160px;
        max-height: 400px;
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
        width: 44px;
        height: 44px;
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
        font-size: 11px;
        font-weight: 600;
        margin-bottom: 8px;
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    
    .gender-selection {
        display: flex;
        gap: 8px;
        margin-bottom: 12px;
        justify-content: center;
    }

    .gender-btn {
        flex: 1;
        padding: 8px;
        border: 2px solid rgba(102, 126, 234, 0.3);
        border-radius: 8px;
        background: rgba(255, 255, 255, 0.8);
        color: #667eea;
        font-size: 12px;
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
        gap: 8px;
        margin-top: 8px;
        justify-items: center;
    }

    .avatar-list.show {
        display: grid;
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
        gap: 4px;
        margin-left: 0px;
        min-width: 80px;
        flex: 1;
    }

    .user-name {
        margin: 0;
        font-size: 15px;
        font-weight: 600;
        color: white;
        line-height: 1.3;
        letter-spacing: 0.3px;
    }

    .user-role {
        font-size: 11px;
        font-weight: 500;
        color: rgba(255, 255, 255, 0.75);
        line-height: 1.3;
        text-transform: capitalize;
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
        width: 230px;
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
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex;
        flex-direction: column;
        border-right: 1px solid rgba(255, 255, 255, 0.2);
        will-change: transform;
        backface-visibility: hidden;
        -webkit-backface-visibility: hidden;
        visibility: visible;
        opacity: 1;
        -webkit-transform: translateX(0) translateZ(0);
    }

    .informationmenu.show {
        transform: translateX(0);
    }

    .informationmenu.hide {
        transform: translateX(-100%);
    }

    .informationmenu-header {
        padding: 20px 16px 16px;
        border-bottom: 0px solid rgba(255, 255, 255, 0.1);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
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
        padding: 12px 20px;
        font-size: 14px;
        font-weight: 600;
        color: white;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: flex-start;
        transition: all 0.3s ease;
        border-radius: 25px 0 0 25px;
        margin: 2px 0;
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
        font-size: 10px;
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
        width: 18px;
        height: 18px;
        margin-right: 12px;
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
        padding: 10px 20px;
        color: rgba(255, 255, 255, 0.9);
        text-decoration: none;
        font-size: 13px;
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

    /* 子菜单 */
    .submenu {
        position: fixed;
        width: 180px;
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
        padding: 6px 0;
    }

    .submenu-item {
        display: flex;
        align-items: center;
        padding: 10px 16px;
        color: rgba(255, 255, 255, 0.9);
        text-decoration: none;
        font-size: 13px;
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

    /* Logout Button */
    .logout-btn {
        background: linear-gradient(180deg, #63C4FF 0%, #0D60FF 100%);
        color: white;
        padding: 10px 24px;
        font-size: 14px;
        font-weight: 600;
        width: 140px;
        border: none;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0, 123, 255, 0.3);
        --sweep-color: rgba(255, 255, 255, 0.2);
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .logout-btn:hover {
        background: linear-gradient(180deg, #0D60FF 0%, #63C4FF 100%);
        box-shadow: 0 4px 12px rgba(0, 123, 255, 0.5);
        transform: translateY(-2px);
    }

    .informationmenu-footer {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 20px;
        border-top: none;
        background: rgba(255, 255, 255, 0);
        margin-top: auto;
        flex-shrink: 0;
        backdrop-filter: blur(10px);
        gap: 12px;
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

    @keyframes float {
        0%, 100% { transform: translateY(0px); }
        50% { transform: translateY(-2px); }
    }

    .header-logo-section {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        margin-bottom: 12px;
        width: 100%;
    }

    .header-logo {
        height: 42px;
        object-fit: contain;
        width: auto;
    }

    /* 优化分隔线 - 更细更精致 */
    .content-separator {
        height: 1px;
        margin: 16px 24px;
        background: linear-gradient(
            to right, 
            transparent 0%, 
            rgba(255, 255, 255, 0.3) 50%, 
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

    /* 通知铃铛样式 */
    .notification-bell {
        position: relative;
        width: 36px;
        height: 36px;
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
        width: 20px;
        height: 20px;
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
        width: 360px;
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
        padding: 20px 24px;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #f9fafb;
    }

    .notification-header h2 {
        margin: 0;
        font-size: 18px;
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
        width: 18px;
        height: 18px;
    }

    /* 通知内容区域 */
    .notification-content {
        flex: 1;
        overflow-y: auto;
        padding: 16px;
    }

    .notification-item {
        padding: 14px;
        margin-bottom: 12px;
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
        font-size: 14px;
        font-weight: 600;
        color: #111827;
        margin-bottom: 6px;
    }

    .notification-message {
        font-size: 13px;
        color: #6b7280;
        line-height: 1.5;
        margin-bottom: 8px;
    }

    .notification-time {
        font-size: 11px;
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
        padding: 10px 14px;
        margin-bottom: 12px;
        background: rgba(255, 255, 255, 0.12);
        border-radius: 8px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
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
        width: 14px;
        height: 14px;
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
        gap: 6px;
        flex-wrap: wrap;
        justify-content: center;
    }

    .expiration-label {
        font-size: 11px;
        font-weight: 700;
        color: #ffffff;
        margin: 0;
        line-height: 1.4;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
    }

    .expiration-countdown-text {
        font-size: 11px;
        font-weight: 600;
        color: #ffffff;
        margin: 0;
        line-height: 1.4;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
    }

    /* 添加菜单分组间距 */
    .informationmenu-section + .informationmenu-section {
        margin-top: 4px;
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

        <!-- 用户信息容器 -->
        <div class="user-info-container">
            <!-- 头像选择器 -->
            <div class="avatar-selector-container">
                <div class="current-avatar" id="currentAvatar" onclick="toggleAvatarOptions()">
                    <img id="currentAvatarImg" alt="Avatar" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;backface-visibility:hidden;-webkit-backface-visibility:hidden;" loading="eager">
                    <script>
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
    </div>

    <div class="informationmenu-content">
        <div class="content-separator"></div>

        <?php if ($isMember): ?>
            <!-- Member Home -->
            <div class="informationmenu-section">
                <div class="informationmenu-section-title account-direct" data-page="dashboard.php" onclick="window.location.href='dashboard.php'">
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

            <!-- Domain Section -->
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

            <!-- Announcement Section -->
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

    userAvatar?.addEventListener('click', function() {
        sidebar.classList.add('show');
        overlay.classList.add('show');
    });

    function closeSidebar() {
        sidebar.classList.remove('show');
        overlay.classList.remove('show');
        document.querySelectorAll('.dropdown-menu-items').forEach(dropdown => {
            dropdown.classList.remove('show');
        });
        document.querySelectorAll('.informationmenu-section-title').forEach(title => {
            title.classList.remove('active');
        });
    }

    overlay?.addEventListener('click', closeSidebar);

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeSidebar();
        }
    });   

    document.querySelectorAll('.informationmenu-section-title').forEach(title => {
        let middleClickHandled = false;
        let ctrlClickHandled = false;
        
        title.addEventListener('mousedown', function(e) {
            const isMiddleClick = e.button === 1 || e.which === 2;
            const isCtrlClick = e.ctrlKey || e.metaKey;
            const pageUrl = this.getAttribute('data-page');
            
            if (pageUrl && isMiddleClick) {
                e.preventDefault();
                e.stopPropagation();
                middleClickHandled = true;
                const originalOnclick = this.onclick;
                this.onclick = null;
                window.open(pageUrl, '_blank');
                setTimeout(() => {
                    this.onclick = originalOnclick;
                    middleClickHandled = false;
                }, 100);
                return false;
            }
            
            if (pageUrl && isCtrlClick && (e.button === 0 || e.button === 2)) {
                e.preventDefault();
                e.stopPropagation();
                ctrlClickHandled = true;
                const originalOnclick = this.onclick;
                this.onclick = null;
                window.open(pageUrl, '_blank');
                setTimeout(() => {
                    this.onclick = originalOnclick;
                    ctrlClickHandled = false;
                }, 100);
                return false;
            } else {
                middleClickHandled = false;
                ctrlClickHandled = false;
            }
        }, true);
        
        title.addEventListener('click', function(e) {
            if (middleClickHandled || ctrlClickHandled) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
            
            const isCtrlClick = e.ctrlKey || e.metaKey;
            const pageUrl = this.getAttribute('data-page');
            
            if (pageUrl && isCtrlClick) {
                e.preventDefault();
                e.stopPropagation();
                const originalOnclick = this.onclick;
                this.onclick = null;
                window.open(pageUrl, '_blank');
                setTimeout(() => {
                    this.onclick = originalOnclick;
                }, 100);
                return false;
            }
            
            const isMiddleClick = e.button === 1 || e.which === 2;
            
            if (pageUrl && isMiddleClick) {
                e.preventDefault();
                e.stopPropagation();
                window.open(pageUrl, '_blank');
                return false;
            }
            
            if (isMiddleClick) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
            
            const targetId = this.getAttribute('data-target');
            const section = this.getAttribute('data-section');
            
            if (section === 'report' || section === 'maintenance') {
                return;
            }
            
            const targetDropdown = document.getElementById(targetId);

            document.querySelectorAll('.dropdown-menu-items').forEach(dropdown => {
                if (dropdown.id !== targetId) {
                    dropdown.classList.remove('show');
                }
            });

            document.querySelectorAll('.informationmenu-section-title').forEach(t => {
                if (t !== this) {
                    t.classList.remove('active');
                }
            });

            this.classList.toggle('active');
            targetDropdown?.classList.toggle('show');
        });
    });

    document.querySelectorAll('.submenu-item').forEach(item => {
        item.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            
            const isCtrlClick = e.ctrlKey || e.metaKey;
            
            if (isCtrlClick && href && href !== '#' && !href.startsWith('javascript:')) {
                e.preventDefault();
                e.stopPropagation();
                window.open(href, '_blank');
                return false;
            }
            
            const isMiddleClick = e.button === 1 || e.which === 2;
            
            if (isMiddleClick && href && href !== '#' && !href.startsWith('javascript:')) {
                e.preventDefault();
                e.stopPropagation();
                window.open(href, '_blank');
                return false;
            }
        });
    });

    document.querySelectorAll('.informationmenu-item').forEach(item => {
        let middleClickHandled = false;
        
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
        }, true);
        
        item.addEventListener('click', function(e) {
            if (middleClickHandled) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
            
            const isCtrlClick = e.ctrlKey || e.metaKey;
            const href = this.getAttribute('href');
            
            if (isCtrlClick && href && href !== '#' && !href.startsWith('javascript:')) {
                e.preventDefault();
                e.stopPropagation();
                window.open(href, '_blank');
                return false;
            }
            
            const isMiddleClick = e.button === 1 || e.which === 2;

            if (isMiddleClick && href && href !== '#' && !href.startsWith('javascript:')) {
                e.preventDefault();
                e.stopPropagation();
                window.open(href, '_blank');
                return false;
            }

            if (href && href !== '#' && !href.startsWith('javascript:')) {
                window.location.href = href;
                return;
            }

            e.preventDefault();

            document.querySelectorAll('.informationmenu-item').forEach(i => i.classList.remove('active'));

            this.classList.add('active');
            
            console.log('Clicked menu item:', this.textContent);
        });
    });

    function handleLogout() {
        if (confirm('Are you sure you want to logout?')) {
            window.location.href = 'dashboard.php?logout=1';
        }
    }

    console.log('Sidebar menu system loaded successfully');

    function getCurrentPageName() {
        const path = window.location.pathname;
        return path.split('/').pop();
    }

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

    let currentAvatarId = 'male1';

    function toggleAvatarOptions() {
        const options = document.getElementById('avatarOptions');
        const isShowing = options.classList.contains('show');
        
        if (!isShowing) {
            backToGenderSelection();
        }
        
        options.classList.toggle('show');
        
        updateSelectedAvatar();
    }

    function selectGender(gender) {
        const maleList = document.getElementById('maleAvatarList');
        const femaleList = document.getElementById('femaleAvatarList');
        const genderBtns = document.querySelectorAll('.gender-btn');
        
        genderBtns.forEach(btn => {
            btn.classList.remove('active');
            if (btn.textContent.toLowerCase() === gender) {
                btn.classList.add('active');
            }
        });
        
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
        
        genderBtns.forEach(btn => {
            btn.classList.remove('active');
            if (btn.textContent.toLowerCase() === 'male') {
                btn.classList.add('active');
            }
        });
        
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
        
        if (options) {
            options.classList.remove('show');
        }
        
        localStorage.setItem('selectedAvatar', avatarId);
        
        updateSelectedAvatar();
        
        console.log('Avatar changed to:', avatarId);
    }

    function updateSelectedAvatar() {
        document.querySelectorAll('.avatar-option').forEach(option => {
            option.classList.remove('selected');
        });
        
        const selectedOption = document.querySelector(`.avatar-option[data-avatar-id="${currentAvatarId}"]`);
        if (selectedOption) {
            selectedOption.classList.add('selected');
        }
    }

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
        
        if (currentAvatarId.startsWith('female')) {
            selectGender('female');
        } else {
            selectGender('male');
        }
    });

    document.addEventListener('click', function(e) {
        const avatarContainer = document.querySelector('.avatar-selector-container');
        const avatarOptions = document.getElementById('avatarOptions');
        
        if (avatarContainer && avatarOptions && 
            !avatarContainer.contains(e.target) && !avatarOptions.contains(e.target)) {
            avatarOptions.classList.remove('show');
        }
    });

    function setCurrentPageHighlight() {
        const currentPage = getCurrentPageName();
        
        document.querySelectorAll('.informationmenu-section-title').forEach(title => {
            title.classList.remove('current-page');
        });
        
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
        
        document.querySelectorAll('.informationmenu-section-title').forEach(title => {
            const pageName = title.getAttribute('data-page');
            if (pageName === currentPage) {
                title.classList.add('current-page');
            }
        });
    }

    document.addEventListener('DOMContentLoaded', setCurrentPageHighlight);

    function positionSubmenu(wrapper) {
        const title = wrapper.querySelector('.informationmenu-section-title');
        const submenu = wrapper.querySelector('.submenu');
        
        if (!title || !submenu) return;
        
        const titleRect = title.getBoundingClientRect();
        const sidebar = document.querySelector('.informationmenu');
        const sidebarRect = sidebar.getBoundingClientRect();
        
        submenu.style.left = sidebarRect.right + 'px';
        submenu.style.top = titleRect.top + 'px';
    }

    document.querySelectorAll('.menu-item-wrapper').forEach(wrapper => {
        const submenu = wrapper.querySelector('.submenu');
        if (submenu) {
            let hideTimeout = null;
            
            function clearHideTimeout() {
                if (hideTimeout) {
                    clearTimeout(hideTimeout);
                    hideTimeout = null;
                }
            }
            
            function showSubmenu() {
                clearHideTimeout();
                positionSubmenu(wrapper);
                submenu.style.opacity = '1';
                submenu.style.visibility = 'visible';
                submenu.style.transform = 'translateX(0)';
                submenu.style.pointerEvents = 'auto';
            }
            
            function hideSubmenu() {
                clearHideTimeout();
                hideTimeout = setTimeout(function() {
                    submenu.style.opacity = '0';
                    submenu.style.visibility = 'hidden';
                    submenu.style.transform = 'translateX(-10px)';
                    submenu.style.pointerEvents = 'none';
                }, 100);
            }
            
            wrapper.addEventListener('mouseenter', function() {
                showSubmenu();
            });
            
            wrapper.addEventListener('mouseleave', function() {
                hideSubmenu();
            });
            
            submenu.addEventListener('mouseenter', function() {
                showSubmenu();
            });
            
            submenu.addEventListener('mouseleave', function() {
                hideSubmenu();
            });
            
            wrapper.addEventListener('mousemove', function() {
                positionSubmenu(wrapper);
            });
        }
    });

    function toggleNotificationPanel(event) {
        const panel = document.getElementById('notificationPanel');
        const overlay = document.getElementById('notificationOverlay');
        
        if (panel.classList.contains('show')) {
            closeNotificationPanel();
        } else {
            panel.classList.add('show');
            overlay.classList.add('show');
            loadAnnouncements();
        }
        
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

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    document.addEventListener('click', function(e) {
        const bell = document.querySelector('.notification-bell');
        const panel = document.getElementById('notificationPanel');
        const overlay = document.getElementById('notificationOverlay');
        
        if (!bell.contains(e.target) && !panel.contains(e.target) && panel.classList.contains('show')) {
            closeNotificationPanel();
        }
    });

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
            countdownContainer.className = 'company-expiration-countdown ' + countdown.status;
        } else {
            countdownText.textContent = 'No expiration date';
            countdownText.className = 'expiration-countdown-text normal';
            countdownContainer.className = 'company-expiration-countdown normal';
        }
    }

    setInterval(updateExpirationCountdown, 60000);
    <?php endif; ?>
</script>