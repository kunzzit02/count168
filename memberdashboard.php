<?php
// 使用统一的session检查逻辑，但只允许 member 访问
require_once __DIR__ . '/session_check.php';

$userType = strtolower($_SESSION['user_type'] ?? '');
if ($userType !== 'member') {
    // 非 member 访问时，根据类型决定去向
    if ($userType === 'user') {
        header('Location: dashboard.php');
    } else {
        header('Location: index.php');
    }
    exit();
}

$name = $_SESSION['name'] ?? '';
$today = date('d/m/Y');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Home - EazyCount</title>
    <link rel="icon" type="image/png" href="images/count_logo.png">
    <style>
        /* 使用与 dashboard.php 一样的背景风格 */
        body.dashboard-page {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            background-color: #e9f1ff;
            background-image:
                radial-gradient(circle at 15% 20%, rgba(255, 255, 255, 0.95) 0%, rgba(255, 255, 255, 0) 48%),
                radial-gradient(circle at 70% 15%, rgba(255, 255, 255, 0.85) 0%, rgba(255, 255, 255, 0) 45%),
                radial-gradient(circle at 40% 70%, rgba(206, 232, 255, 0.55) 0%, rgba(255, 255, 255, 0) 60%),
                radial-gradient(circle at 80% 80%, rgba(255, 255, 255, 0.9) 0%, rgba(255, 255, 255, 0) 55%),
                linear-gradient(145deg, #97BFFC 0%, #AECFFA 40%, #f9fbff 100%);
            background-blend-mode: screen, screen, multiply, screen, normal;
            color: #334155;
            overflow-x: hidden;
            overflow-y: auto;
        }

        .dashboard-container {
            max-width: none;
            margin: 0;
            padding: 8px clamp(20px, 2.08vw, 40px) 8px clamp(180px, 14.06vw, 270px);
            width: 100%;
            min-height: 100vh;
            box-sizing: border-box;
            overflow: visible;
        }

        .dashboard-title {
            color: #002C49;
            text-align: left;
            margin-top: 0;
            margin-bottom: clamp(6px, 0.5vw, 10px);
            font-size: clamp(22px, 1.8vw, 32px);
            font-family: 'Amaranth', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-weight: 500;
            letter-spacing: -0.025em;
        }

        .member-welcome-card {
            background-color: white;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            padding: clamp(12px, 1vw, 20px);
            margin-top: clamp(10px, 0.9vw, 16px);
        }

        .member-welcome-title {
            font-size: clamp(18px, 1.4vw, 24px);
            font-weight: 600;
            color: #0f172a;
            margin: 0 0 8px 0;
        }

        .member-welcome-text {
            font-size: clamp(12px, 0.9vw, 16px);
            color: #4b5563;
            line-height: 1.6;
            margin: 0 0 6px 0;
        }

        .member-info-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 10px;
            font-size: clamp(11px, 0.85vw, 15px);
            color: #374151;
        }

        .member-info-pill {
            padding: 6px 10px;
            border-radius: 999px;
            background: #eef2ff;
            color: #1e3a8a;
            font-weight: 600;
        }

        .member-tip {
            margin-top: 12px;
            padding: 10px 12px;
            border-radius: 8px;
            background: #ecfeff;
            border: 1px solid #a5f3fc;
            font-size: clamp(11px, 0.85vw, 15px);
            color: #0f172a;
        }
    </style>
</head>
<body class="dashboard-page">
    <?php include 'sidebar.php'; ?>

    <div class="dashboard-container">
        <h1 class="dashboard-title">Member Home</h1>

        <div class="member-welcome-card">
            <h2 class="member-welcome-title">欢迎回来，<?php echo htmlspecialchars($name ?: 'Member'); ?>！</h2>
            <p class="member-welcome-text">
                这里是 Member 专用的首页。您可以通过左侧菜单进入 <strong>Win/Loss</strong> 页面查看自己的输赢记录。
            </p>
            <p class="member-welcome-text">
                右上角的 <strong>Win/Loss</strong> 菜单已经为您准备好所有可用公司的账户数据。
            </p>

            <div class="member-info-row">
                <span class="member-info-pill">今日日期：<?php echo htmlspecialchars($today); ?></span>
                <span class="member-info-pill">登录身份：Member</span>
            </div>

            <div class="member-tip">
                提示：<br>
                后台的交易 Dashboard（`dashboard.php`）现在仅对内部普通用户开放，Member 登录后将看到这个专属首页和 Win/Loss 报表。
            </div>
        </div>
    </div>
</body>
</html>


