<?php
session_start();
require_once 'config.php'; // 使用统一的数据库配置

// 超时时间（秒）
define('SESSION_TIMEOUT', 3600); // 1小时

// 检查remember me cookie自动登录
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    $remember_token = $_COOKIE['remember_token'];
    
    // 验证remember token
    $stmt = $pdo->prepare("SELECT * FROM user WHERE remember_token = ? AND remember_token_expires > NOW() AND company_id = 'c168' AND status = 'active'");
    $stmt->execute([$remember_token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        // 重新建立session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['login_id'] = $user['login_id'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['last_activity'] = time();
        
        // 更新最后登录时间
        $stmt = $pdo->prepare("UPDATE user SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);
    }
}

// 检查用户是否已登录
if (isset($_SESSION['user_id'])) {
    // 检查session超时（如果没有remember me的话）
    if (
        isset($_SESSION['last_activity']) &&
        (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) &&
        !isset($_COOKIE['remember_token'])
    ) {
        // 清除 session
        session_unset();
        session_destroy();
        
        // 重定向到登录页
        header("Location: index.php");
        exit();
    }
    
    // 更新活动时间戳
    $_SESSION['last_activity'] = time();
    
} else {
    // 未登录，重定向到登录页
    header("Location: index.php");
    exit();
}

// 获取用户信息
$user_id = $_SESSION['user_id'];
$login_id = $_SESSION['login_id'];
$name = $_SESSION['name'];
$role = $_SESSION['role'];

// 获取用户权限
$stmt = $pdo->prepare("SELECT permissions FROM user WHERE id = ?");
$stmt->execute([$user_id]);
$userPermissions = $stmt->fetchColumn();
$permissions = $userPermissions ? json_decode($userPermissions, true) : [];

$company_id = 'c168'; // 固定值
$avatarLetter = strtoupper($name[0]);
// 为JavaScript准备用户数据
$userData = [
    'name' => $name,
    'login_id' => $login_id,
    'role' => $role,
    'avatar_letter' => $avatarLetter,
    'permissions' => $permissions
];

// 权限检查
$canViewAnalytics = ($role === 'admin'); // 只有admin可以查看分析

// 处理logout请求
if (isset($_GET['logout'])) {
    // 清除数据库中的remember token
    if (isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("UPDATE user SET remember_token = NULL, remember_token_expires = NULL WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
    }
    
    // 清除session
    session_unset();
    session_destroy();
    
    // 清除所有相关cookies
    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time() - 3600, "/", "", false, true);
    }
    
    // 重定向到登录页
    header("Location: index.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EazyCount</title>
    <script>
        // 用户数据供JavaScript使用
        window.userData = <?php echo json_encode($userData); ?>;
    </script>
    <style>
        html, body {
            margin: 0;
            padding: 0;
            height: 100%;
            overflow: hidden;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #e9f1ff;
            background-image:
                radial-gradient(circle at 15% 20%, rgba(255, 255, 255, 0.95) 0%, rgba(255, 255, 255, 0) 48%),
                radial-gradient(circle at 70% 15%, rgba(255, 255, 255, 0.85) 0%, rgba(255, 255, 255, 0) 45%),
                radial-gradient(circle at 40% 70%, rgba(206, 232, 255, 0.55) 0%, rgba(255, 255, 255, 0) 60%),
                radial-gradient(circle at 80% 80%, rgba(255, 255, 255, 0.9) 0%, rgba(255, 255, 255, 0) 55%),
                linear-gradient(145deg, #97BFFC 0%, #AECFFA 40%, #f9fbff 100%);
            background-blend-mode: screen, screen, multiply, screen, normal;
            height: 100vh;
            overflow: hidden;
        }

        /* 右上角通知铃铛 */
        .notification-bell {
            position: fixed;
            top: 14px;
            right: 18px;
            width: clamp(38px, 2.6vw, 50px);
            height: clamp(38px, 2.6vw, 50px);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #1a237e;
            z-index: 1100; /* 比 sidebar 更高，始终在最上面 */
            transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
        }

        .notification-bell:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.3);
            background: #ffffff;
        }

        .notification-bell svg {
            width: clamp(22px, 1.56vw, 30px);
            height: clamp(22px, 1.56vw, 30px);
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

    </style>
</head>
<body>

    <!-- Include Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- 右上角小铃铛 -->
    <div class="notification-bell" title="Notifications" onclick="toggleNotificationPanel()">
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M12 2C10.34 2 9 3.34 9 5V5.29C6.72 6.15 5.12 8.39 5.01 11L5 11V16L3 18V19H21V18L19 16V11C18.88 8.39 17.28 6.15 15 5.29V5C15 3.34 13.66 2 12 2ZM12 22C10.9 22 10 21.1 10 20H14C14 21.1 13.1 22 12 22Z"/>
        </svg>
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

    <script>
        function toggleNotificationPanel(event) {
            const panel = document.getElementById('notificationPanel');
            const overlay = document.getElementById('notificationOverlay');
            
            if (panel.classList.contains('show')) {
                closeNotificationPanel();
            } else {
                panel.classList.add('show');
                overlay.classList.add('show');
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

        // Load announcements when page loads
        document.addEventListener('DOMContentLoaded', function() {
            loadAnnouncements();
        });
    </script>
</body>
</html>