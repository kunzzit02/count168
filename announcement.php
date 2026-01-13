<?php
// 使用统一的session检查
require_once 'session_check.php';

// 检查当前登录用户是否为 owner/admin 且与 c168 相关
$user_id      = $_SESSION['user_id']  ?? null;
$user_role    = strtolower($_SESSION['role'] ?? '');
$company_id   = $_SESSION['company_id'] ?? null;
$company_code = strtoupper($_SESSION['company_code'] ?? '');

// 角色必须是 owner 或 admin
$isOwnerOrAdmin = in_array($user_role, ['owner', 'admin'], true);

// 检查是否为C168
$isC168ByCode = ($company_code === 'C168');
$isC168ById = false;
if ($company_id) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND UPPER(company_id) = 'C168'");
        $stmt->execute([$company_id]);
        $isC168ById = $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("Failed to check if current company is c168: " . $e->getMessage());
        $isC168ById = false;
    }
}

$hasC168Context = ($isC168ByCode || $isC168ById);

if (!$user_id || !$isOwnerOrAdmin || !$hasC168Context) {
    header("Location: dashboard.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href='https://fonts.googleapis.com/css?family=Amaranth' rel='stylesheet'>
    <link href='https://fonts.googleapis.com/css2?family=Amaranth:wght@400;700&display=swap' rel='stylesheet'>
    <link rel="stylesheet" href="accountCSS.css?v=<?php echo time(); ?>" />
    <title>Announcement Management</title>
    <?php include 'sidebar.php'; ?>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            height: 100vh;
            font-weight: 700;
            background-color: #e9f1ff;
            background-image:
                radial-gradient(circle at 15% 20%, rgba(255, 255, 255, 0.95) 0%, rgba(255, 255, 255, 0) 48%),
                radial-gradient(circle at 70% 15%, rgba(255, 255, 255, 0.85) 0%, rgba(255, 255, 255, 0) 45%),
                radial-gradient(circle at 40% 70%, rgba(206, 232, 255, 0.55) 0%, rgba(255, 255, 255, 0) 60%),
                radial-gradient(circle at 80% 80%, rgba(255, 255, 255, 0.9) 0%, rgba(255, 255, 255, 0) 55%),
                linear-gradient(145deg, #97BFFC 0%, #AECFFA 40%, #f9fbff 100%);
            background-blend-mode: screen, screen, multiply, screen, normal;
            overflow-x: hidden;
            overflow-y: hidden;
        }

        .container {
            max-width: none;
            margin: 0;
            padding: 1px 40px 20px clamp(180px, 14.06vw, 270px);
            width: 100%;
            height: 100vh;
            box-sizing: border-box;
            overflow: hidden;
        }

        h1 {
            color: #002C49;
            text-align: left;
            margin-top: clamp(12px, 1.04vw, 20px);
            margin-bottom: clamp(16px, 1.35vw, 26px);
            font-size: clamp(26px, 2.08vw, 40px);
            font-family: 'Amaranth';
            font-weight: 700;
            letter-spacing: -0.025em;
        }

        .announcement-layout {
            display: flex;
            gap: 24px;
            margin-top: 20px;
            overflow: hidden;
        }

        .announcement-form-section {
            flex: 0 0 400px;
            background: white;
            border-radius: 12px;
            padding: clamp(16px, 1.25vw, 24px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            height: fit-content;
            max-height: 100%;
            overflow-y: auto;
        }

        .announcement-list-section {
            flex: 1;
            background: white;
            border-radius: 12px;
            padding: clamp(16px, 1.25vw, 24px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            height: 100%;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }

        .form-group {
            margin-bottom: clamp(10px, 1.04vw, 20px);
        }

        .form-group label {
            display: block;
            margin-bottom: clamp(4px, 0.42vw, 8px);
            font-weight: 700;
            color: #334155;
            font-size: clamp(12px, 0.95vw, 14px);
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: clamp(6px, 0.52vw, 10px) clamp(8px, 0.625vw, 12px);
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: clamp(11px, 0.73vw, 14px);
            font-weight: 700;
            font-family: inherit;
            box-sizing: border-box;
            transition: border-color 0.3s;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-group textarea {
            height: clamp(120px, 9.4vw, 180px);
            resize: vertical;
        }

        .submit-btn {
            width: 100%;
            padding: clamp(8px, 0.625vw, 12px);
            background: linear-gradient(180deg, #63C4FF 0%, #0D60FF 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: clamp(12px, 0.83vw, 16px);
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            box-shadow: 0 2px 4px rgba(0, 123, 255, 0.3);
        }

        .submit-btn:hover {
            background: linear-gradient(180deg, #0D60FF 0%, #63C4FF 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 123, 255, 0.4);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        .announcement-list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: clamp(14px, 1.04vw, 20px);
            padding-bottom: clamp(10px, 0.83vw, 16px);
            border-bottom: 2px solid #e5e7eb;
            flex-shrink: 0;
        }

        .announcement-list-header h2 {
            margin: 0;
            color: #002C49;
            font-size: clamp(16px, 1.25vw, 24px);
            font-family: 'Amaranth';
        }

        .announcement-item {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: clamp(10px, 0.83vw, 16px);
            margin-bottom: clamp(10px, 0.83vw, 16px);
            transition: all 0.3s;
        }

        .announcement-item:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }

        .announcement-item-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: clamp(8px, 0.625vw, 12px);
        }

        .announcement-title {
            font-size: clamp(12px, 0.94vw, 18px);
            font-weight: 600;
            color: #111827;
            margin: 0;
            flex: 1;
        }

        .announcement-delete-btn {
            background: #ef4444;
            color: white;
            border: none;
            border-radius: 6px;
            padding: clamp(4px, 0.31vw, 6px) clamp(8px, 0.625vw, 12px);
            font-size: clamp(8px, 0.625vw, 12px);
            cursor: pointer;
            transition: background 0.2s;
            margin-left: 12px;
        }

        .announcement-delete-btn:hover {
            background: #dc2626;
        }

        .announcement-content {
            color: #6b7280;
            font-size: clamp(12px, 0.73vw, 14px);
            line-height: 1.6;
            margin-bottom: clamp(8px, 0.625vw, 12px);
            white-space: pre-wrap;
            word-break: break-word;
        }

        .announcement-meta {
            display: flex;
            justify-content: space-between;
            font-size: clamp(10px, 0.625vw, 12px);
            color: #9ca3af;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #9ca3af;
        }

        .empty-state p {
            margin: 0;
            font-size: 16px;
        }

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            z-index: 10000;
            opacity: 0;
            transform: translateX(100%);
            transition: all 0.3s;
        }

        .notification.show {
            opacity: 1;
            transform: translateX(0);
        }

        .notification.success {
            background: #10b981;
        }

        .notification.error {
            background: #ef4444;
        }

        /* 横线样式 - 超出container */
        .separator-line {
            width: 100vw;
            height: 2px;
            background-color: #939393;
            margin: 5px 0 -10px 0;
            position: relative;
            left: 50%;
            right: 50%;
            margin-left: -50vw;
            margin-right: -50vw;
        }

        /* 维护内容管理样式 */
        .maintenance-layout {
            display: flex;
            gap: 24px;
            margin-top: 40px;
            overflow: hidden;
        }

        .maintenance-form-section {
            flex: 0 0 400px;
            background: white;
            border-radius: 12px;
            padding: clamp(16px, 1.25vw, 24px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            height: fit-content;
            max-height: 100%;
            overflow-y: auto;
        }

        .maintenance-list-section {
            flex: 1;
            background: white;
            border-radius: 12px;
            padding: clamp(16px, 1.25vw, 24px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            height: 100%;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }

        .maintenance-item {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: clamp(10px, 0.83vw, 16px);
            margin-bottom: clamp(10px, 0.83vw, 16px);
            transition: all 0.3s;
        }

        .maintenance-item:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }

        .maintenance-item-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: clamp(8px, 0.625vw, 12px);
        }

        .maintenance-content {
            color: #6b7280;
            font-size: clamp(12px, 0.73vw, 14px);
            line-height: 1.6;
            margin-bottom: clamp(8px, 0.625vw, 12px);
            white-space: pre-wrap;
            word-break: break-word;
        }

        .maintenance-delete-btn {
            background: #ef4444;
            color: white;
            border: none;
            border-radius: 6px;
            padding: clamp(4px, 0.31vw, 6px) clamp(8px, 0.625vw, 12px);
            font-size: clamp(8px, 0.625vw, 12px);
            cursor: pointer;
            transition: background 0.2s;
            margin-left: 12px;
        }

        .maintenance-delete-btn:hover {
            background: #dc2626;
        }

        .maintenance-list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: clamp(14px, 1.04vw, 20px);
            padding-bottom: clamp(10px, 0.83vw, 16px);
            border-bottom: 2px solid #e5e7eb;
            flex-shrink: 0;
        }

        .maintenance-list-header h2 {
            margin: 0;
            color: #002C49;
            font-size: clamp(16px, 1.25vw, 24px);
            font-family: 'Amaranth';
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Announcement Management</h1>
        
        <div class="separator-line"></div>
        
        <div class="announcement-layout">
            <!-- Left: Create Announcement Form -->
            <div class="announcement-form-section">
                <h2 style="margin-top: 0; color: #002C49; font-family: 'Amaranth'; font-size: clamp(16px, 1.25vw, 24px); margin-bottom: clamp(8px, 0.73vw, 14px);">Create New Announcement</h2>
                <form id="announcementForm">
                    <div class="form-group">
                        <label for="title">Title *</label>
                        <input type="text" id="title" name="title" required maxlength="500" placeholder="Enter announcement title">
                    </div>
                    <div class="form-group">
                        <label for="content">Content *</label>
                        <textarea id="content" name="content" required placeholder="Enter announcement content"></textarea>
                    </div>
                    <button type="submit" class="submit-btn">Publish Announcement</button>
                </form>
            </div>

            <!-- Right: Published Announcements List -->
            <div class="announcement-list-section">
                <div class="announcement-list-header">
                    <h2>Published Announcements</h2>
                </div>
                <div id="announcementList" style="flex: 1; overflow-y: auto;">
                    <!-- 公告列表将在这里动态加载 -->
                </div>
            </div>
        </div>

        <!-- Maintenance Content Management Section -->
        <div class="separator-line" style="margin-top: 40px;"></div>
        
        <h1 style="margin-top: 40px;">Maintenance Content Management</h1>
        
        <div class="maintenance-layout">
            <!-- Left: Create Maintenance Content Form -->
            <div class="maintenance-form-section">
                <h2 style="margin-top: 0; color: #002C49; font-family: 'Amaranth'; font-size: clamp(16px, 1.25vw, 24px); margin-bottom: clamp(8px, 0.73vw, 14px);">Create New Maintenance Content</h2>
                <form id="maintenanceForm">
                    <div class="form-group">
                        <label for="maintenanceContent">Content *</label>
                        <textarea id="maintenanceContent" name="content" required placeholder="Enter maintenance content"></textarea>
                    </div>
                    <button type="submit" class="submit-btn">Publish Maintenance Content</button>
                </form>
            </div>

            <!-- Right: Published Maintenance Content List -->
            <div class="maintenance-list-section">
                <div class="maintenance-list-header">
                    <h2>Published Maintenance Content</h2>
                </div>
                <div id="maintenanceList" style="flex: 1; overflow-y: auto;">
                    <!-- 维护内容列表将在这里动态加载 -->
                </div>
            </div>
        </div>
    </div>

    <!-- 通知容器 -->
    <div id="notificationContainer"></div>

    <script>
        // 加载公告列表
        async function loadAnnouncements() {
            try {
                const response = await fetch('announcement_list_api.php');
                const result = await response.json();
                
                const listContainer = document.getElementById('announcementList');
                
                if (result.success && result.data.length > 0) {
                    listContainer.innerHTML = result.data.map(announcement => `
                        <div class="announcement-item">
                            <div class="announcement-item-header">
                                <h3 class="announcement-title">${escapeHtml(announcement.title)}</h3>
                                <button class="announcement-delete-btn" onclick="deleteAnnouncement(${announcement.id}, '${escapeHtml(announcement.title)}')">
                                    Delete
                                </button>
                            </div>
                            <div class="announcement-content">${escapeHtml(announcement.content)}</div>
                            <div class="announcement-meta">
                                <span>Created by: ${escapeHtml(announcement.created_by)}</span>
                                <span>Created at: ${escapeHtml(announcement.created_at)}</span>
                            </div>
                        </div>
                    `).join('');
                } else {
                    listContainer.innerHTML = `
                        <div class="empty-state">
                            <p>No announcements</p>
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Failed to load announcements:', error);
                showNotification('Failed to load announcements: ' + error.message, 'error');
            }
        }

        // Delete announcement
        async function deleteAnnouncement(id, title) {
            if (!confirm(`Are you sure you want to delete announcement "${title}"? This action cannot be undone.`)) {
                return;
            }

            try {
                const formData = new FormData();
                formData.append('id', id);

                const response = await fetch('announcement_delete_api.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    showNotification('Announcement deleted successfully', 'success');
                    loadAnnouncements();
                } else {
                    showNotification('Delete failed: ' + result.error, 'error');
                }
            } catch (error) {
                console.error('Failed to delete announcement:', error);
                showNotification('Failed to delete announcement: ' + error.message, 'error');
            }
        }

        // Submit form
        document.getElementById('announcementForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const title = document.getElementById('title').value.trim();
            const content = document.getElementById('content').value.trim();

            if (!title || !content) {
                showNotification('Please fill in both title and content', 'error');
                return;
            }

            try {
                const formData = new FormData();
                formData.append('title', title);
                formData.append('content', content);

                const response = await fetch('announcement_create_api.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    showNotification('Announcement published successfully', 'success');
                    document.getElementById('announcementForm').reset();
                    loadAnnouncements();
                } else {
                    showNotification('Publish failed: ' + result.error, 'error');
                }
            } catch (error) {
                console.error('Failed to publish announcement:', error);
                showNotification('Failed to publish announcement: ' + error.message, 'error');
            }
        });

        // 显示通知
        function showNotification(message, type = 'success') {
            const container = document.getElementById('notificationContainer');
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.textContent = message;
            container.appendChild(notification);

            setTimeout(() => {
                notification.classList.add('show');
            }, 10);

            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.remove();
                    }
                }, 300);
            }, 3000);
        }

        // HTML转义
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // 页面加载时获取公告列表
        document.addEventListener('DOMContentLoaded', function() {
            loadAnnouncements();
            loadMaintenanceContent();
        });

        // ========== Maintenance Content Functions ==========
        
        // 加载维护内容列表
        async function loadMaintenanceContent() {
            try {
                const response = await fetch('maintenance_list_api.php');
                const result = await response.json();
                
                const listContainer = document.getElementById('maintenanceList');
                
                if (result.success && result.data.length > 0) {
                    listContainer.innerHTML = result.data.map(maintenance => `
                        <div class="maintenance-item">
                            <div class="maintenance-item-header">
                                <div style="flex: 1;"></div>
                                <button class="maintenance-delete-btn" onclick="deleteMaintenanceContent(${maintenance.id})">
                                    Delete
                                </button>
                            </div>
                            <div class="maintenance-content">${escapeHtml(maintenance.content)}</div>
                            <div class="announcement-meta">
                                <span>Created by: ${escapeHtml(maintenance.created_by)}</span>
                                <span>Created at: ${escapeHtml(maintenance.created_at)}</span>
                            </div>
                        </div>
                    `).join('');
                } else {
                    listContainer.innerHTML = `
                        <div class="empty-state">
                            <p>No maintenance content</p>
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Failed to load maintenance content:', error);
                showNotification('Failed to load maintenance content: ' + error.message, 'error');
            }
        }

        // Delete maintenance content
        async function deleteMaintenanceContent(id) {
            if (!confirm(`Are you sure you want to delete this maintenance content? This action cannot be undone.`)) {
                return;
            }

            try {
                const formData = new FormData();
                formData.append('id', id);

                const response = await fetch('maintenance_delete_api.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    showNotification('Maintenance content deleted successfully', 'success');
                    loadMaintenanceContent();
                } else {
                    showNotification('Delete failed: ' + result.error, 'error');
                }
            } catch (error) {
                console.error('Failed to delete maintenance content:', error);
                showNotification('Failed to delete maintenance content: ' + error.message, 'error');
            }
        }

        // Submit maintenance form
        document.getElementById('maintenanceForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const content = document.getElementById('maintenanceContent').value.trim();

            if (!content) {
                showNotification('Please fill in the content', 'error');
                return;
            }

            try {
                const formData = new FormData();
                formData.append('content', content);

                const response = await fetch('maintenance_create_api.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    showNotification('Maintenance content published successfully', 'success');
                    document.getElementById('maintenanceForm').reset();
                    loadMaintenanceContent();
                } else {
                    showNotification('Publish failed: ' + result.error, 'error');
                }
            } catch (error) {
                console.error('Failed to publish maintenance content:', error);
                showNotification('Failed to publish maintenance content: ' + error.message, 'error');
            }
        });
    </script>
</body>
</html>

