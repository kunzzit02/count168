# Sidebar 组件使用说明

## 概述
`sidebar.php` 是一个独立的侧边栏组件，包含了完整的用户界面、样式和交互功能。其他页面只需要简单引入即可使用。

## 功能特性
- ✅ 用户权限控制
- ✅ 响应式设计
- ✅ 可折叠/展开
- ✅ 用户头像和下拉菜单
- ✅ 多级菜单支持
- ✅ 自动登录状态检查
- ✅ 完整的JavaScript交互

## 使用方法

### 1. 基本引入
在任何需要侧边栏的PHP页面中，添加以下代码：

```php
<?php
// 确保session已启动
session_start();

// 检查用户是否已登录（可选，sidebar.php内部也会检查）
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Page Title</title>
    <style>
        /* 你的页面样式 */
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #9abff7 0%, #d1def5 100%);
            min-height: 100vh;
        }
    </style>
</head>
<body>
    <!-- 引入侧边栏 -->
    <?php include 'sidebar.php'; ?>
    
    <!-- 你的页面内容 -->
    <div class="main-content">
        <h1>页面内容</h1>
        <p>这里是你的页面内容...</p>
    </div>
</body>
</html>
```

### 2. 权限控制
侧边栏会根据用户的权限自动显示/隐藏相应的菜单项。权限在 `user` 表的 `permissions` 字段中存储为JSON格式。

支持的权限包括：
- `home` - 首页
- `admin` - 管理员功能
- `account` - 账户管理
- `process` - 流程管理
- `datacapture` - 数据采集
- `payment` - 交易支付
- `report` - 报告
- `maintenance` - 维护

### 3. 样式调整
如果需要调整主内容区域的布局以适应侧边栏，可以添加以下CSS：

```css
.main-content {
    padding: 20px;
    margin-left: 0;
    transition: margin-left 0.3s ease;
}

.main-content.with-sidebar {
    margin-left: 250px; /* 侧边栏宽度 */
}
```

### 4. JavaScript 集成
侧边栏的JavaScript会自动加载，无需额外配置。如果需要监听侧边栏状态变化：

```javascript
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.querySelector('.informationmenu');
    const mainContent = document.querySelector('.main-content');
    
    if (sidebar && mainContent) {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.attributeName === 'class') {
                    if (sidebar.classList.contains('show')) {
                        mainContent.classList.add('with-sidebar');
                    } else {
                        mainContent.classList.remove('with-sidebar');
                    }
                }
            });
        });
        
        observer.observe(sidebar, { attributes: true });
    }
});
```

## 文件结构
```
project/
├── sidebar.php          # 独立的侧边栏组件
├── dashboard.php        # 使用侧边栏的示例页面
├── example-page.php     # 另一个使用示例
├── config.php          # 数据库配置（sidebar.php依赖）
└── SIDEBAR_README.md   # 本说明文件
```

## 依赖项
- PHP 7.0+
- PDO MySQL 扩展
- 数据库表：`user`（包含 `permissions` 字段）

## 注意事项
1. 确保 `config.php` 文件存在且配置正确
2. 用户必须已登录才能显示侧边栏
3. 侧边栏会自动检查用户权限
4. 所有样式和JavaScript都包含在 `sidebar.php` 中，无需额外引入

## 自定义菜单项
要添加新的菜单项，编辑 `sidebar.php` 文件中的相应部分：

```php
<!-- 新菜单项示例 -->
<div class="informationmenu-section">
    <div class="informationmenu-section-title" data-target="new-items">
        <svg class="section-icon" fill="currentColor" viewBox="0 0 24 24">
            <!-- SVG图标 -->
        </svg>
        新菜单
        <span class="section-arrow">🠊</span>
    </div>
    <div class="dropdown-menu-items" id="new-items">
        <div class="menu-item-wrapper">
            <a href="new-page.php" class="informationmenu-item">
                新页面
                <span class="informationmenu-arrow">›</span>
            </a>
        </div>
    </div>
</div>
```

## 故障排除
1. **侧边栏不显示**：检查用户是否已登录
2. **菜单项不显示**：检查用户权限设置
3. **样式问题**：确保没有CSS冲突
4. **JavaScript错误**：检查浏览器控制台错误信息
