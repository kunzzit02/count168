<?php
// 纭繚session宸插惎鍔?
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 妫€鏌ョ敤鎴锋槸鍚﹀凡鐧诲綍
if (!isset($_SESSION['user_id'])) {
    // 濡傛灉鏈櫥褰曪紝杈撳嚭JavaScript閲嶅畾鍚戝埌鐧诲綍椤?
    // 杩欐牱鍙互纭繚鏁翠釜椤甸潰閮藉仠姝㈠伐浣滐紝鑰屼笉浠呬粎鏄痵idebar娑堝け
    echo '<script>window.location.href = "index.php";</script>';
    exit();
}

$isMember = isset($_SESSION['user_type']) && strtolower($_SESSION['user_type']) === 'member';

// 鑾峰彇鐢ㄦ埛淇℃伅
$user_id = $_SESSION['user_id'];
$login_id = $_SESSION['login_id'] ?? '';
$name = $_SESSION['name'] ?? '';
$role = $_SESSION['role'] ?? '';

require_once 'config.php';
$permissions = [];

// 鑾峰彇鐢ㄦ埛鏉冮檺锛堜粎闈?member 鐢ㄦ埛锛?
if (!$isMember) {
    $stmt = $pdo->prepare("SELECT permissions FROM user WHERE id = ?");
    $stmt->execute([$user_id]);
    $userPermissions = $stmt->fetchColumn();
    $permissions = $userPermissions ? json_decode($userPermissions, true) : [];
}

// 妫€鏌ュ綋鍓嶇櫥褰曠敤鎴锋槸鍚︿负 owner/admin 涓斾笌 c168 鐩稿叧锛堟敮鎸佸閲?company锛?
$hasC168Access = false;
$companyId = $_SESSION['company_id'] ?? null;  // company 鐨勬暟瀛椾富閿紙绉诲埌澶栭潰锛岀‘淇濅綔鐢ㄥ煙姝ｇ‘锛?
if ($user_id) {
    $roleLower    = strtolower($role ?? '');
    $companyCode  = strtoupper($_SESSION['company_code'] ?? ''); // 鐧诲綍鏃堕€夌殑鍏徃浠ｇ爜

    if (in_array($roleLower, ['owner', 'admin'], true)) {
        // 鏉′欢1锛氱櫥褰曟椂閫夌殑鍏徃浠ｇ爜灏辨槸 c168
        if ($companyCode === 'C168') {
            $hasC168Access = true;
        } elseif ($companyId) {
            // 鏉′欢2锛氬綋鍓嶉€変腑鍏徃鍦?company 琛ㄤ腑纭涓?c168
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND UPPER(company_id) = 'C168'");
                $stmt->execute([$companyId]);
                $hasC168Access = $stmt->fetchColumn() > 0;
            } catch(PDOException $e) {
                error_log("妫€鏌?c168 鏉冮檺澶辫触: " . $e->getMessage());
                $hasC168Access = false;
            }
        }
    }
}

$avatarLetter = $login_id ? strtoupper($login_id[0]) : 'U';

// 澶村儚 ID 涓庤矾寰勬槧灏勶紙涓庡墠绔?avatarImages 涓€鑷达紝鐢ㄤ簬鏈嶅姟绔緭鍑哄垵濮?src 閬垮厤鍒囨崲椤甸潰闂儊锛?
$avatarImages = [
    'male1' => 'images/avatar1.png', 'male2' => 'images/avatar2.png', 'male3' => 'images/avatar3.png',
    'male4' => 'images/avatar4.png', 'male5' => 'images/avatar5.png', 'male6' => 'images/avatar6.png',
    'male7' => 'images/avatar7.png', 'male8' => 'images/avatar8.png', 'male9' => 'images/avatar9.png',
    'female1' => 'images/female1.png', 'female2' => 'images/female2.png', 'female3' => 'images/female3.png',
    'female4' => 'images/female4.png', 'female5' => 'images/female5.png', 'female6' => 'images/female6.png',
    'female7' => 'images/female7.png', 'female8' => 'images/female8.png', 'female9' => 'images/female9.png'
];
$avatarId = isset($_COOKIE['selectedAvatar']) && isset($avatarImages[$_COOKIE['selectedAvatar']])
    ? $_COOKIE['selectedAvatar']
    : 'male1';
$initialAvatarSrc = $avatarImages[$avatarId];

// 鑾峰彇褰撳墠鍏徃鐨勫埌鏈熸棩鏈?
$company_expiration_date = null;
$expiration_countdown_text = '';
$expiration_status = 'normal';
if ($companyId) {
    try {
        $stmt = $pdo->prepare("SELECT expiration_date FROM company WHERE id = ?");
        $stmt->execute([$companyId]);
        $company_expiration_date = $stmt->fetchColumn();
        
        // 鍦?PHP 绔绠楀€掕鏃讹紙浣跨敤 $now 閬垮厤瑕嗙洊鍖呭惈椤电殑 $today锛屽 member.php 鐨勬棩鏈熸樉绀猴級
        if ($company_expiration_date) {
            $now = new DateTime();
            $now->setTime(0, 0, 0);
            $expiration = new DateTime($company_expiration_date);
            $expiration->setTime(0, 0, 0);
            
            $diff = $now->diff($expiration);
            $diffDays = (int)$diff->format('%r%a'); // 甯︾鍙风殑澶╂暟宸?
            
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
        error_log("鑾峰彇鍏徃鍒版湡鏃ユ湡澶辫触: " . $e->getMessage());
        $company_expiration_date = null;
        $expiration_countdown_text = 'No expiration date';
        $expiration_status = 'normal';
    }
}

// 获取当前公司的 category 权限（Gambling/Bank/Loan/Rate/Money），用于 Data Capture 与 Maintenance > Process 等可见性
$companyHasGambling = false;
$companyCategories = [];
if ($companyId) {
    try {
        $stmt = $pdo->prepare("SELECT permissions FROM company WHERE id = ?");
        $stmt->execute([$companyId]);
        $permsJson = $stmt->fetchColumn();
        if ($permsJson) {
            $companyPerms = json_decode($permsJson, true);
            $companyCategories = is_array($companyPerms) ? $companyPerms : [];
            $companyHasGambling = in_array('Gambling', $companyCategories);
        }
    } catch (PDOException $e) {
        error_log("获取公司权限失败: " . $e->getMessage());
    }
}
?>
<!--
================================================================================
  sidebar.php 涓鸿 include 鐨勭墖娈碉紝涓嶅湪姝ゅ娣诲姞 <link> / <script src>銆?
  璇峰湪涓婚〉闈紙濡?account-list.php銆乨ashboard.php 绛夛級鐨?<head> 涓姞鍏ワ細
    <link rel="stylesheet" href="css/sidebar.css">
    <script src="js/sidebar.js?v=<?php echo time(); ?>" defer></script>
  濡傞渶 favicon 涓庡ご鍍忛鍔犺浇锛屽彲鍦ㄤ富椤甸潰 <head> 涓寜闇€娣诲姞锛?
    <link rel="icon" type="image/png" href="images/count_logo.png">
    <link rel="preload" href="(褰撳墠鐢ㄦ埛澶村儚 URL)" as="image">
================================================================================
-->
<!-- Sidebar HTML (CSS 已移至 css/sidebar.css，JS 逻辑已移至 js/sidebar.js) -->
<!-- Overlay -->
<div class="informationmenu-overlay"></div>

<!-- Sidebar Menu -->
<div class="informationmenu">
    <div class="informationmenu-header">
        <div class="header-logo-section">
            <img src="images/count_whitelogo.png" alt="EAZYCOUNT Logo" class="header-logo">
            <!-- 閫氱煡閾冮摏 -->
            <div class="notification-bell" title="Notifications" onclick="toggleNotificationPanel(event)">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12 2C10.34 2 9 3.34 9 5V5.29C6.72 6.15 5.12 8.39 5.01 11L5 11V16L3 18V19H21V18L19 16V11C18.88 8.39 17.28 6.15 15 5.29V5C15 3.34 13.66 2 12 2ZM12 22C10.9 22 10 21.1 10 20H14C14 21.1 13.1 22 12 22Z"/>
                </svg>
            </div>
        </div>

        <!-- 鐢ㄦ埛淇℃伅瀹瑰櫒锛堝ご鍍忓拰鐢ㄦ埛淇℃伅宸﹀彸鎺掔増锛?-->
        <div class="user-info-container">
            <!-- 娣诲姞澶村儚閫夋嫨鍣紙鏀逛负浣跨敤 PNG 鐓х墖锛?-->
            <div class="avatar-selector-container">
                <div class="current-avatar" id="currentAvatar" onclick="toggleAvatarOptions()">
                    <!-- 鏈嶅姟绔牴鎹?cookie 杈撳嚭鍒濆 src锛屽垏鎹㈤〉闈㈡椂棣栧睆鍗虫樉绀烘纭ご鍍忥紝閬垮厤闂儊 -->
                    <img id="currentAvatarImg" class="current-avatar-img" src="<?php echo htmlspecialchars($initialAvatarSrc); ?>" data-avatar-id="<?php echo htmlspecialchars($avatarId); ?>" alt="Avatar" fetchpriority="high" loading="eager">
                </div>
                
            <div class="avatar-options" id="avatarOptions">
                <div class="options-title">Choose Avatar</div>
                
                <!-- 鎬у埆閫夋嫨 -->
                <div class="gender-selection" id="genderSelection">
                    <button type="button" class="gender-btn active" onclick="selectGender('male')">Male</button>
                    <button type="button" class="gender-btn" onclick="selectGender('female')">Female</button>
                </div>

                <!-- 鐢锋€уご鍍忓垪琛?-->
                <div class="avatar-list show" id="maleAvatarList">
                    <div class="avatar-option" data-avatar-id="male1" onclick="selectAvatar('male1')">
                        <img src="images/avatar1.png" alt="Male Avatar 1" class="avatar-option-img">
                    </div>
                    <div class="avatar-option" data-avatar-id="male2" onclick="selectAvatar('male2')">
                        <img src="images/avatar2.png" alt="Male Avatar 2" class="avatar-option-img">
                    </div>
                    <div class="avatar-option" data-avatar-id="male3" onclick="selectAvatar('male3')">
                        <img src="images/avatar3.png" alt="Male Avatar 3" class="avatar-option-img">
                    </div>
                    <div class="avatar-option" data-avatar-id="male4" onclick="selectAvatar('male4')">
                        <img src="images/avatar4.png" alt="Male Avatar 4" class="avatar-option-img">
                    </div>
                    <div class="avatar-option" data-avatar-id="male5" onclick="selectAvatar('male5')">
                        <img src="images/avatar5.png" alt="Male Avatar 5" class="avatar-option-img">
                    </div>
                    <div class="avatar-option" data-avatar-id="male6" onclick="selectAvatar('male6')">
                        <img src="images/avatar6.png" alt="Male Avatar 6" class="avatar-option-img">
                    </div>
                    <div class="avatar-option" data-avatar-id="male7" onclick="selectAvatar('male7')">
                        <img src="images/avatar7.png" alt="Male Avatar 7" class="avatar-option-img">
                    </div>
                    <div class="avatar-option" data-avatar-id="male8" onclick="selectAvatar('male8')">
                        <img src="images/avatar8.png" alt="Male Avatar 8" class="avatar-option-img">
                    </div>
                    <div class="avatar-option" data-avatar-id="male9" onclick="selectAvatar('male9')">
                        <img src="images/avatar9.png" alt="Male Avatar 9" class="avatar-option-img">
                    </div>
                </div>

                <!-- 濂虫€уご鍍忓垪琛?-->
                <div class="avatar-list" id="femaleAvatarList">
                    <div class="avatar-option" data-avatar-id="female1" onclick="selectAvatar('female1')">
                        <img src="images/female1.png" alt="Female Avatar 1" class="avatar-option-img">
                    </div>
                    <div class="avatar-option" data-avatar-id="female2" onclick="selectAvatar('female2')">
                        <img src="images/female2.png" alt="Female Avatar 2" class="avatar-option-img">
                    </div>
                    <div class="avatar-option" data-avatar-id="female3" onclick="selectAvatar('female3')">
                        <img src="images/female3.png" alt="Female Avatar 3" class="avatar-option-img">
                    </div>
                    <div class="avatar-option" data-avatar-id="female4" onclick="selectAvatar('female4')">
                        <img src="images/female4.png" alt="Female Avatar 4" class="avatar-option-img">
                    </div>
                    <div class="avatar-option" data-avatar-id="female5" onclick="selectAvatar('female5')">
                        <img src="images/female5.png" alt="Female Avatar 5" class="avatar-option-img">
                    </div>
                    <div class="avatar-option" data-avatar-id="female6" onclick="selectAvatar('female6')">
                        <img src="images/female6.png" alt="Female Avatar 6" class="avatar-option-img">
                    </div>
                    <div class="avatar-option" data-avatar-id="female7" onclick="selectAvatar('female7')">
                        <img src="images/female7.png" alt="Female Avatar 7" class="avatar-option-img">
                    </div>
                    <div class="avatar-option" data-avatar-id="female8" onclick="selectAvatar('female8')">
                        <img src="images/female8.png" alt="Female Avatar 8" class="avatar-option-img">
                    </div>
                    <div class="avatar-option" data-avatar-id="female9" onclick="selectAvatar('female9')">
                        <img src="images/female9.png" alt="Female Avatar 9" class="avatar-option-img">
                    </div>
                </div>
            </div>
            </div>

            <div class="user-avatar-dropdown">
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($login_id); ?></div>
                    <div class="user-role"><?php echo ucfirst($role); ?></div>
                </div>
            </div>
        </div>
        <!-- 璇█鍒囨崲鎸夐挳 -->
        <!-- <div class="language-switcher">
            <div class="language-dropdown">
                <button class="language-btn" onclick="toggleLanguageDropdown()">
                    <img src="images/uk.png" alt="English" class="flag-icon" id="current-flag">
                    <span class="language-text" id="current-lang">English</span>
                    <span class="dropdown-arrow">&#9658;</span>
                </button>
                <div class="language-dropdown-list" id="languageDropdown">
                    <div class="language-option" onclick="selectLanguage('en')">
                        <img src="images/uk.png" alt="English" class="flag-icon">
                        <span>English</span>
                    </div>
                    <div class="language-option" onclick="selectLanguage('zh')">
                        <img src="images/china.png" alt="涓枃" class="flag-icon">
                        <span>涓枃</span>
                    </div>
                </div>
            </div>
        </div> -->
    </div>

    <div class="informationmenu-content">
        <div class="content-separator"></div>

        <?php if ($isMember): ?>
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

            <!-- Domain Section - 鍙湁涓?c168 鐩稿叧涓旇鑹蹭负 owner/admin 鐨勭敤鎴峰彲瑙?-->
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
                    鑷姩鐧诲綍绠＄悊
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

            <!-- Data Capture Section：用户有 datacapture 权限时输出，显隐由当前公司 Gambling 权限控制（含切换公司时即时更新） -->
            <?php if (empty($permissions) || in_array('datacapture', $permissions)): ?>
            <div class="informationmenu-section" id="sidebar-datacapture-section"<?php echo $companyHasGambling ? '' : ' style="display:none;"'; ?>>
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

            <!-- Report Section（仅当前公司有 Gambling 权限时显示） -->
            <?php if (empty($permissions) || in_array('report', $permissions)): ?>
            <div class="informationmenu-section" id="sidebar-report-section"<?php echo $companyHasGambling ? '' : ' style="display:none;"'; ?>>
                <div class="menu-item-wrapper">
                    <div class="informationmenu-section-title" data-section="report">
                        <svg class="section-icon" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 2 2h8c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/>
                        </svg>
                        Report
                        <span class="section-arrow">&#9658;</span>
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
                        <span class="section-arrow">&#9658;</span>
                    </div>
                    <div class="submenu" id="maintenance-submenu">
                        <div class="submenu-content">
                            <a href="capture_maintenance.php"
                               class="submenu-item"
                               id="maintenance-capture-link"<?php echo $companyHasGambling ? '' : ' style="display:none;"'; ?>>
                                <span>Data Capture</span>
                            </a>
                            <a href="transaction_maintenance.php" class="submenu-item">
                                <span>Transaction</span>
                            </a>
                            <a href="payment_maintenance.php" class="submenu-item">
                                <span>Payment</span>
                            </a>
                            <a href="formula_maintenance.php"
                               class="submenu-item"
                               id="maintenance-formula-link"<?php echo $companyHasGambling ? '' : ' style="display:none;"'; ?>>
                                <span>Formula</span>
                            </a>
                            <?php if (!empty($companyCategories) && in_array('Bank', $companyCategories)): ?>
                            <a href="bankprocess_maintenance.php" class="submenu-item">
                                <span>Process</span>
                            </a>
                            <?php endif; ?>
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
        <button type="button" class="btn btn-link-force-refresh" id="sidebarForceRefreshBtn" title="重新加载页面并拉取最新 JS/CSS">强制刷新缓存</button>
        <button class="btn logout-btn" onclick="handleLogout()">
            Logout
        </button>
    </div>
</div>

<!-- 閫氱煡闈㈡澘閬僵灞?-->
<div class="notification-overlay" id="notificationOverlay" onclick="closeNotificationPanel()"></div>

<!-- 閫氱煡闈㈡澘 -->
<div class="notification-panel" id="notificationPanel">
    <div class="notification-header">
        <h2>Announcements</h2>
        <button class="notification-close" onclick="closeNotificationPanel()" title="鍏抽棴">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>
    </div>
    <div class="notification-content" id="notificationContent">
        <!-- 鍏憡灏嗗湪杩欓噷鍔ㄦ€佸姞杞?-->
    </div>
</div>

<!-- Sidebar JavaScript: PHP 变量注入，调用外部 js/sidebar.js 中的 updateExpirationCountdown / updateSidebarDataCaptureVisibility -->
<script>
window.SIDEBAR_IS_MEMBER = <?php echo $isMember ? 'true' : 'false'; ?>;
window.SIDEBAR_EXPIRATION_DATE = '<?php echo $company_expiration_date ? addslashes($company_expiration_date) : ''; ?>';
window.SIDEBAR_COMPANY_HAS_GAMBLING = <?php echo $companyHasGambling ? 'true' : 'false'; ?>;
(function() {
    if (typeof updateExpirationCountdown === 'function') {
        if (window.SIDEBAR_EXPIRATION_DATE) {
            updateExpirationCountdown();
            setInterval(updateExpirationCountdown, 60000);
        }
    }
    if (typeof updateSidebarDataCaptureVisibility === 'function' && window.SIDEBAR_COMPANY_HAS_GAMBLING !== undefined) {
        updateSidebarDataCaptureVisibility(window.SIDEBAR_COMPANY_HAS_GAMBLING);
    }
})();
</script>
