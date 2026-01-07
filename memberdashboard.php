<?php
// Member 专属首页（登录后 Home）
require_once __DIR__ . '/session_check.php';

// 仅允许 member 类型访问
if (strtolower($_SESSION['user_type'] ?? '') !== 'member') {
    header('Location: index.php');
    exit();
}

$name = $_SESSION['name'] ?? '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Dashboard - EazyCount</title>
    <link rel="icon" type="image/png" href="images/count_logo.png">
    <style>
        /* 使用与 dashboard.php 一致的背景风格 */
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
            background-color: #ffffff;
            border-radius: 12px;
            padding: clamp(16px, 1.5vw, 28px);
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.16);
            border: 1px solid rgba(148, 163, 184, 0.35);
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .member-welcome-title {
            font-size: clamp(18px, 1.4vw, 26px);
            font-weight: 700;
            color: #0f172a;
        }

        .member-welcome-subtitle {
            font-size: clamp(13px, 0.95vw, 16px);
            color: #4b5563;
        }

        .member-actions {
            margin-top: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .member-action-btn {
            padding: 8px 16px;
            border-radius: 999px;
            border: none;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }

        .member-action-primary {
            background: linear-gradient(180deg, #63C4FF 0%, #0D60FF 100%);
            color: #ffffff;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.35);
        }

        .member-action-primary:hover {
            background: linear-gradient(180deg, #0D60FF 0%, #63C4FF 100%);
            transform: translateY(-1px);
        }

        .member-action-secondary {
            background: #e5efff;
            color: #1d4ed8;
        }

        .member-action-secondary:hover {
            background: #d1ddff;
        }
    </style>
</head>
<body class="dashboard-page">
    <?php include 'sidebar.php'; ?>
</body>
</html>


