<?php
session_start();
require_once 'config.php';

// 仅允许已登录用户访问维护页，保持与其他内部页一致
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EazyCount - Maintenance</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="images/count_logo.png">

    <style>
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
            min-height: 100vh;
            overflow-x: hidden;
        }

        .main-content {
            margin-left: clamp(160px, 11.98vw, 230px); /* 预留 sidebar 宽度 */
            /* padding: 30px clamp(24px, 3vw, 40px); */
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .maintenance-panel {
            max-width: 720px;
            width: 100%;
            background: rgba(255, 255, 255, 0.96);
            border-radius: 20px;
            padding: 28px 30px 32px;
            box-shadow: 0 16px 40px rgba(15, 76, 129, 0.18);
            backdrop-filter: blur(8px);
        }

        .maintenance-title {
            font-size: 30px;
            font-weight: 700;
            color: #0D2A5B;
            /* margin-bottom: 16px; */
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .maintenance-title i {
            color: #0D60FF;
            font-size: 24px;
        }

        .maintenance-desc {
            font-size: 13px;
            color: #607089;
            margin-bottom: 26px;
        }

        .progress-label {
            font-size: 20px;
            font-weight: 600;
            color: #0D60FF;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .progress-bar {
            position: relative;
            width: 100%;
            height: 24px;
            border-radius: 999px;
            background: #E3ECFF;
            overflow: hidden;
        }

        .progress-bar-fill {
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 40%;
            background: linear-gradient(90deg, #0D60FF, #63C4FF);
            border-radius: inherit;
            animation: loadingProgress 2.4s ease-in-out infinite;
        }

        .progress-bar-stripes {
            position: absolute;
            inset: 0;
            background-image: linear-gradient(
                135deg,
                rgba(255, 255, 255, 0.6) 25%,
                transparent 25%,
                transparent 50%,
                rgba(255, 255, 255, 0.6) 50%,
                rgba(255, 255, 255, 0.6) 75%,
                transparent 75%,
                transparent
            );
            background-size: 24px 24px;
            mix-blend-mode: soft-light;
            animation: moveStripes 0.9s linear infinite;
            pointer-events: none;
        }

        .maintenance-footer-text {
            margin-top: 18px;
            font-size: 12px;
            color: #8a9bb3;
        }

        @keyframes loadingProgress {
            0%   { transform: translateX(-60%); }
            50%  { transform: translateX(10%); }
            100% { transform: translateX(120%); }
        }

        @keyframes moveStripes {
            from { background-position: 0 0; }
            to   { background-position: 24px 0; }
        }

        /* @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px 16px;
            }

            .maintenance-panel {
                margin-top: 80px;
                padding: 22px 18px 26px;
            }

            .maintenance-title {
                font-size: 20px;
            }
        } */
    </style>
</head>
<body>

<?php include 'sidebar.php'; ?>

<div class="main-content">
    <div class="maintenance-panel">
        <h1 class="maintenance-title">
            <i class="fas fa-triangle-exclamation"></i>
            This page is under Maintenance
        </h1>

        <div class="progress-label">Maintenance in progress, we will update you soon.</div>
        <div class="progress-bar">
            <div class="progress-bar-fill"></div>
            <div class="progress-bar-stripes"></div>
        </div>
    </div>
</div>

</body>
</html>


