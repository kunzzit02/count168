<?php
// 统一的 Session 检查
require_once __DIR__ . '/session_check.php';

// 只允许 member 登录用户访问
if (strtolower($_SESSION['user_type'] ?? '') !== 'member') {
    header('Location: index.php');
    exit();
}

$accountName = $_SESSION['name'] ?? '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Home - EazyCount</title>
    <link rel="icon" type="image/png" href="images/count_logo.png">
    <style>
        /* 背景样式与 dashboard 一致 */
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
            font-family: 'Amaranth', sans-serif;
            font-weight: 500;
            letter-spacing: -0.025em;
        }
    </style>
</head>
<body class="dashboard-page">
    <?php include 'sidebar.php'; ?>

    <div class="dashboard-container">
        <!-- 目前无需内容，预留一个简单标题方便后续扩展 -->
        <h1 class="dashboard-title">
            Member Home<?php echo $accountName ? ' - ' . htmlspecialchars($accountName, ENT_QUOTES, 'UTF-8') : ''; ?>
        </h1>
    </div>
</body>
</html>


