<?php
// 使用统一的session检查
require_once 'session_check.php';

// 处理删除请求（只允许删除inactive状态的进程）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ids'])) {
    try {
        $ids = is_array($_POST['ids']) ? $_POST['ids'] : (isset($_POST['ids']) ? [$_POST['ids']] : []);
        $ids = array_map('intval', array_filter($ids));
        $permission = isset($_POST['permission']) ? trim($_POST['permission']) : '';

        if (!empty($ids)) {
            $company_id_session = $_SESSION['company_id'] ?? null;
            if (!$company_id_session) {
                header('Location: processlist.php?error=delete_failed');
                exit;
            }

            // Bank 类别：若有勾选到已设置 day_start 的记录则不允许删除，返回错误
            if ($permission === 'Bank') {
                $placeholders = str_repeat('?,', count($ids) - 1) . '?';
                $stmt = $pdo->prepare("SELECT id FROM bank_process WHERE id IN ($placeholders) AND company_id = ? AND status = 'inactive'");
                $params = array_merge($ids, [$company_id_session]);
                $stmt->execute($params);
                $inactiveIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (empty($inactiveIds)) {
                    header('Location: processlist.php?error=no_inactive_processes');
                    exit;
                }
                $stmt = $pdo->prepare("SELECT id FROM bank_process WHERE id IN ($placeholders) AND company_id = ? AND status = 'inactive' AND day_start IS NOT NULL");
                $stmt->execute($params);
                $withDayStart = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($withDayStart)) {
                    header('Location: processlist.php?error=bank_has_day_start');
                    exit;
                }
                $delPlaceholders = str_repeat('?,', count($inactiveIds) - 1) . '?';
                $stmt = $pdo->prepare("DELETE FROM bank_process WHERE id IN ($delPlaceholders) AND company_id = ? AND status = 'inactive'");
                $stmt->execute(array_merge($inactiveIds, [$company_id_session]));
                header('Location: processlist.php?success=deleted');
                exit;
            }

            // Gambling：原有 process 表删除逻辑
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            $stmt = $pdo->prepare("SELECT id, process_id, company_id FROM process WHERE id IN ($placeholders) AND status = 'inactive'");
            $stmt->execute($ids);
            $processesToDelete = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($processesToDelete)) {
                header('Location: processlist.php?error=no_inactive_processes');
                exit;
            }

            $processIds = array_column($processesToDelete, 'id');
            $processCompanyIds = array_unique(array_column($processesToDelete, 'company_id'));
            $formulaCount = 0;
            if (!empty($processIds)) {
                $idPlaceholders = str_repeat('?,', count($processIds) - 1) . '?';
                $formulaCheckParams = $processIds;
                if (!empty($processCompanyIds)) {
                    $companyPlaceholders = str_repeat('?,', count($processCompanyIds) - 1) . '?';
                    $formulaCheckSql = "SELECT COUNT(*) as count FROM data_capture_templates 
                                        WHERE process_id IN ($idPlaceholders) 
                                        AND company_id IN ($companyPlaceholders)";
                    $formulaCheckParams = array_merge($formulaCheckParams, $processCompanyIds);
                } else {
                    $formulaCheckSql = "SELECT COUNT(*) as count FROM data_capture_templates 
                                        WHERE process_id IN ($idPlaceholders)";
                }
                $stmt = $pdo->prepare($formulaCheckSql);
                $stmt->execute($formulaCheckParams);
                $formulaCount = (int) $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            }

            if ($formulaCount > 0) {
                header('Location: processlist.php?error=process_linked_to_formula');
                exit;
            }

            $deletePlaceholders = str_repeat('?,', count($processIds) - 1) . '?';
            $stmt = $pdo->prepare("DELETE FROM process WHERE id IN ($deletePlaceholders) AND status = 'inactive'");
            $stmt->execute($processIds);
            header('Location: processlist.php?success=deleted');
            exit;
        }
    } catch (PDOException $e) {
        error_log("Delete process error: " . $e->getMessage());
        header('Location: processlist.php?error=delete_failed');
        exit;
    }
}

// 获取初始参数（用于设置页面状态）
$searchTerm = isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '';
$showInactive = isset($_GET['showInactive']) ? true : false;
$showAll = isset($_GET['showAll']) ? true : false;

// 获取当前用户信息
$current_user_id = $_SESSION['user_id'] ?? null;
$current_user_role = $_SESSION['role'] ?? '';

// 获取当前用户关联的所有 company（用于显示 company 按钮）
$user_companies = [];
try {
    if ($current_user_id) {
        // 如果是 owner，获取所有拥有的 company
        if ($current_user_role === 'owner') {
            $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
            $stmt = $pdo->prepare("SELECT id, company_id FROM company WHERE owner_id = ? ORDER BY company_id ASC");
            $stmt->execute([$owner_id]);
            $user_companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // 普通用户，获取通过 user_company_map 关联的 company
            $stmt = $pdo->prepare("
                SELECT DISTINCT c.id, c.company_id 
                FROM company c
                INNER JOIN user_company_map ucm ON c.id = ucm.company_id
                WHERE ucm.user_id = ?
                ORDER BY c.company_id ASC
            ");
            $stmt->execute([$current_user_id]);
            $user_companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (PDOException $e) {
    error_log("Failed to get user company list: " . $e->getMessage());
}

// 如果 URL 中有 company_id 参数，使用它（用于切换 company）
$company_id = isset($_GET['company_id']) ? (int) $_GET['company_id'] : ($_SESSION['company_id'] ?? null);

// 验证 company_id 是否属于当前用户
if ($current_user_id && count($user_companies) > 0) {
    $valid_company = false;
    if ($company_id) {
        foreach ($user_companies as $comp) {
            if ($comp['id'] == $company_id) {
                $valid_company = true;
                break;
            }
        }
    }
    if (!$valid_company) {
        // 如果 company_id 无效或不存在，使用第一个 company
        $company_id = $user_companies[0]['id'];
        // 更新 session（确保登录后默认使用第一个 company）
        $_SESSION['company_id'] = $company_id;
    } elseif (isset($_GET['company_id']) && $company_id == (int) $_GET['company_id']) {
        // 如果 URL 中有 company_id 参数且验证通过，更新 session（实现跨页面同步）
        $_SESSION['company_id'] = $company_id;
    } elseif (!isset($_GET['company_id']) && $company_id == $_SESSION['company_id']) {
        // 如果使用 session 中的 company_id 且有效，确保 session 已设置（登录时设置的）
        $_SESSION['company_id'] = $company_id;
    }
} else {
    // 如果没有关联的 company，使用 session 中的 company_id
    $company_id = $_SESSION['company_id'] ?? null;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href='https://fonts.googleapis.com/css2?family=Amaranth:wght@400;700&display=swap' rel='stylesheet'>
    <title>Process List</title>
    <link rel="stylesheet" href="processCSS.css?v=<?php echo time(); ?>" />
    <link rel="stylesheet" href="accountCSS.css?v=<?php echo time(); ?>" />
    <?php include 'sidebar.php'; ?>
    <style>
        /* Input formatting - 统一管理输入框格式 */
        #add_process_id,
        #new_description_name,
        #add_remove_words,
        #add_replace_word_from,
        #add_replace_word_to,
        #add_remarks,
        #edit_remove_words,
        #edit_replace_word_from,
        #edit_replace_word_to,
        #edit_remarks {
            text-transform: uppercase;
        }

        /* 注意：searchInput 和 descriptionSearch 不使用 CSS text-transform，保持实际值的显示 */

        /* 描述列表删除按钮样式 */
        .description-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .description-item-left {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .description-delete-btn {
            border: none;
            background: transparent;
            color: #c00;
            font-size: 18px;
            cursor: pointer;
            padding: 4px 6px;
            line-height: 1;
        }

        .description-delete-btn:hover {
            color: #900;
        }

        /* Country Selection Modal (layout: left = Add/Available, right = Selected) */
        .country-selection-modal .modal-content {
            max-width: 56.25rem;
            width: 90%;
        }

        .country-selection-container {
            display: flex;
            gap: 0;
            height: clamp(300px, 26.04vw, 500px);
            flex-wrap: wrap;
        }

        .available-countries-section {
            flex: 1;
            border-right: 0.0625rem solid #e9ecef;
            padding-right: clamp(10px, 1.04vw, 20px);
            min-width: 20rem;
        }

        .selected-countries-section {
            flex: 1;
            min-width: 20rem;
        }

        .available-countries-section h3,
        .selected-countries-section h3 {
            margin-top: 0;
            margin-bottom: clamp(6px, 0.52vw, 10px);
            color: #495057;
            font-size: clamp(12px, 0.83vw, 16px);
        }

        .add-country-bar {
            margin-bottom: clamp(10px, 1.04vw, 20px);
            padding-bottom: clamp(10px, 1.04vw, 20px);
            border-bottom: 1px solid #e9ecef;
        }

        .add-country-bar h3 {
            margin: 0 0 clamp(6px, 0.52vw, 10px) 0;
            color: #495057;
            font-size: clamp(12px, 0.83vw, 16px);
            font-weight: bold;
        }

        .add-country-form {
            margin: 0;
        }

        .add-country-input-group {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .add-country-input-group input {
            width: 100%;
            padding: clamp(4px, 0.42vw, 8px) clamp(6px, 0.83vw, 16px);
            border: 1px solid #d1d5db;
            border-radius: clamp(4px, 0.42vw, 8px);
            font-size: clamp(8px, 0.73vw, 14px);
            box-sizing: border-box;
        }

        .country-search {
            margin-bottom: 0.9375rem;
        }

        .country-search input {
            width: 100%;
            padding: clamp(4px, 0.42vw, 8px) clamp(6px, 0.83vw, 16px);
            border: 1px solid #d1d5db;
            border-radius: clamp(4px, 0.42vw, 8px);
            font-size: clamp(8px, 0.73vw, 14px);
            box-sizing: border-box;
        }

        .country-list {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            padding: 0px 10px;
            background-color: #f8f9fa;
        }

        .selected-countries-list {
            max-height: 18.75rem;
            overflow-y: auto;
            border: 0.0625rem solid #e9ecef;
            border-radius: 0.25rem;
            padding: 0.625rem;
            background-color: #f8f9fa;
            margin-bottom: 25px;
        }

        .country-item {
            display: flex;
            align-items: center;
            gap: 8px;
            justify-content: space-between;
            padding: clamp(4px, 0.21vw, 8px) 0;
            border-bottom: 0.0625rem solid #e9ecef;
        }

        .country-item:last-child {
            border-bottom: none;
        }

        .country-item-left {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
        }

        .country-item input[type="checkbox"] {
            margin: 0;
            width: clamp(10px, 0.73vw, 14px);
        }

        .country-item label {
            margin: 0;
            font-size: clamp(10px, 0.73vw, 14px);
            cursor: pointer;
            flex: 1;
            color: #333;
        }

        .country-delete-btn {
            border: none;
            background: transparent;
            color: #c00;
            font-size: 18px;
            cursor: pointer;
            padding: 4px 6px;
            line-height: 1;
        }

        .country-delete-btn:hover {
            color: #900;
        }

        .country-item:hover {
            background-color: #e9ecef;
        }

        .selected-country-modal-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: clamp(2px, 0.42vw, 8px) 8px;
            border-bottom: 0.0625rem solid #e9ecef;
            background-color: #e3f2fd;
            border-radius: 0.25rem;
            margin-bottom: 0.5rem;
        }

        .selected-country-modal-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .selected-country-modal-item span {
            flex: 1;
            font-size: clamp(10px, 0.73vw, 14px);
            color: #1976d2;
            font-weight: 500;
        }

        .remove-country-modal {
            background: none;
            border: none;
            color: #1976d2;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            padding: 0;
            width: 1.25rem;
            height: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .remove-country-modal:hover {
            background-color: #1976d2;
            color: white;
        }

        .no-countries {
            text-align: center;
            color: #6c757d;
            font-size: clamp(10px, 0.78vw, 15px);
            font-style: italic;
            padding: clamp(20px, 2.08vw, 40px) 20px;
        }

        /* Bank Selection Modal (layout: left = Add/Available, right = Selected) */
        .bank-selection-modal .modal-content {
            max-width: 56.25rem;
            width: 90%;
        }

        .bank-selection-container {
            display: flex;
            gap: 0;
            height: clamp(300px, 26.04vw, 500px);
            flex-wrap: wrap;
        }

        .available-banks-section {
            flex: 1;
            border-right: 0.0625rem solid #e9ecef;
            padding-right: clamp(10px, 1.04vw, 20px);
            min-width: 20rem;
        }

        .selected-banks-section {
            flex: 1;
            padding-left: clamp(10px, 1.04vw, 20px);
            min-width: 20rem;
        }

        .available-banks-section h3,
        .selected-banks-section h3 {
            margin-top: 0;
            margin-bottom: clamp(6px, 0.52vw, 10px);
            color: #495057;
            font-size: clamp(12px, 0.83vw, 16px);
        }

        .add-bank-bar {
            margin-bottom: clamp(10px, 1.04vw, 20px);
            padding-bottom: clamp(10px, 1.04vw, 20px);
            border-bottom: 1px solid #e9ecef;
        }

        .add-bank-bar h3 {
            margin: 0 0 clamp(6px, 0.52vw, 10px) 0;
            color: #495057;
            font-size: clamp(12px, 0.83vw, 16px);
            font-weight: bold;
        }

        .add-bank-form {
            margin: 0;
        }

        .add-bank-input-group {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .add-bank-input-group input {
            width: 100%;
            padding: clamp(4px, 0.42vw, 8px) clamp(6px, 0.83vw, 16px);
            border: 1px solid #d1d5db;
            border-radius: clamp(4px, 0.42vw, 8px);
            font-size: clamp(8px, 0.73vw, 14px);
            box-sizing: border-box;
        }

        .bank-search {
            margin-bottom: 0.9375rem;
        }

        .bank-search input {
            width: 100%;
            padding: clamp(4px, 0.42vw, 8px) clamp(6px, 0.83vw, 16px);
            border: 1px solid #d1d5db;
            border-radius: clamp(4px, 0.42vw, 8px);
            font-size: clamp(8px, 0.73vw, 14px);
            box-sizing: border-box;
        }

        .bank-list {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            padding: 0px 10px;
            background-color: #f8f9fa;
        }

        .selected-banks-list {
            max-height: 18.75rem;
            overflow-y: auto;
            border: 0.0625rem solid #e9ecef;
            border-radius: 0.25rem;
            padding: 0.625rem;
            background-color: #f8f9fa;
        }

        .bank-item {
            display: flex;
            align-items: center;
            gap: 8px;
            justify-content: space-between;
            padding: clamp(4px, 0.21vw, 8px) 0;
            border-bottom: 0.0625rem solid #e9ecef;
        }

        .bank-item:last-child {
            border-bottom: none;
        }

        .bank-item-left {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
        }

        .bank-item input[type="checkbox"] {
            margin: 0;
            width: clamp(10px, 0.73vw, 14px);
        }

        .bank-item label {
            margin: 0;
            font-size: clamp(10px, 0.73vw, 14px);
            cursor: pointer;
            flex: 1;
            color: #333;
        }

        .bank-delete-btn {
            border: none;
            background: transparent;
            color: #c00;
            font-size: 18px;
            cursor: pointer;
            padding: 4px 6px;
            line-height: 1;
        }

        .bank-delete-btn:hover {
            color: #900;
        }

        .bank-item:hover {
            background-color: #e9ecef;
        }

        .selected-bank-modal-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: clamp(2px, 0.42vw, 8px) 8px;
            border-bottom: 0.0625rem solid #e9ecef;
            background-color: #e3f2fd;
            border-radius: 0.25rem;
            margin-bottom: 0.5rem;
        }

        .selected-bank-modal-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .selected-bank-modal-item span {
            flex: 1;
            font-size: clamp(10px, 0.73vw, 14px);
            color: #1976d2;
            font-weight: 500;
        }

        .remove-bank-modal {
            background: none;
            border: none;
            color: #1976d2;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            padding: 0;
            width: 1.25rem;
            height: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .remove-bank-modal:hover {
            background-color: #1976d2;
            color: white;
        }

        .no-banks {
            text-align: center;
            color: #6c757d;
            font-size: clamp(10px, 0.78vw, 15px);
            font-style: italic;
            padding: clamp(20px, 2.08vw, 40px) 20px;
        }

        /* Bank Modal Styles - Separate from Gambling modal */
        .bank-modal .bank-modal-content {
            max-width: 1000px;
            width: 90%;
        }

        .bank-form {
            display: flex;
            flex-direction: column;
            gap: 0;
        }

        .bank-form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            align-items: stretch;
            min-height: 0;
        }

        .bank-form-row+.bank-form-row {
            margin-top: 20px;
        }

        .bank-form-row-last {
            min-height: 160px;
        }

        .bank-form-cell {
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .bank-form-cell-left,
        .bank-form-cell-right {
            align-items: stretch;
        }

        .bank-form-cell .selected-countries-section {
            flex: 1;
            min-height: 120px;
            display: flex;
            flex-direction: column;
        }

        .bank-form-cell .selected-countries-list {
            flex: 1;
            min-height: 80px;
        }

        .bank-form-left {
            display: flex;
            flex-direction: column;
            gap: 25px;
        }

        .bank-form-right {
            display: flex;
            flex-direction: column;
            gap: 25px;
        }

        .bank-section {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .bank-section:first-child {
            margin-top: 0;
        }

        .bank-section-title {
            font-size: 16px;
            font-weight: bold;
            color: #002C49;
            margin-bottom: 10px;
            margin-top: 0;
            margin-left: 0;
            padding-left: 4px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e0e0e0;
            overflow: visible;
        }

        .bank-chinese {
            font-size: 12px;
            color: #666;
            font-weight: normal;
        }

        .bank-row-two-cols {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .bank-row-three-cols {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
        }

        .bank-form .form-row {
            margin-bottom: 0;
        }

        .bank-form .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 0;
        }

        .bank-form-left .bank-row-two-cols,
        .bank-form-left .bank-row-three-cols,
        .bank-form-cell-left .bank-row-two-cols,
        .bank-form-cell-left .bank-row-three-cols,
        .bank-form-cell-right .bank-row-two-cols,
        .bank-form-cell-right .bank-row-three-cols {
            display: grid;
            gap: 15px;
        }

        .bank-form-left .bank-row-two-cols,
        .bank-form-cell-left .bank-row-two-cols,
        .bank-form-cell-right .bank-row-two-cols {
            grid-template-columns: 1fr 1fr;
        }

        .bank-form-left .bank-row-type-name,
        .bank-form-cell-left .bank-row-type-name {
            grid-template-columns: 0.45fr 1fr;
        }

        .bank-form-left .bank-row-three-cols,
        .bank-form-cell-left .bank-row-three-cols,
        .bank-form-cell-right .bank-row-three-cols {
            grid-template-columns: 0.85fr 0.85fr 1fr;
        }

        .bank-form .form-group label {
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 0;
        }

        .select-with-add {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .select-with-add .bank-select {
            flex: 1;
        }

        .bank-add-btn {
            width: clamp(18px, 1.25vw, 24px);
            height: clamp(18px, 1.25vw, 24px);
            border-radius: 50%;
            background: linear-gradient(180deg, #63C4FF 0%, #0D60FF 100%);
            color: white;
            border: none;
            cursor: pointer;
            font-size: clamp(12px, 0.83vw, 16px);
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.2s ease;
        }

        .bank-add-btn:hover {
            background: linear-gradient(180deg, #0D60FF 0%, #63C4FF 100%);
            transform: scale(1.05);
        }

        .bank-input,
        .bank-select {
            width: 100%;
            padding: 12px 16px;
            border: 0.125rem solid #e5e7eb;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            box-sizing: border-box;
            background: #ffffff;
            color: #374151;
            font-family: inherit;
        }

        .bank-input:focus,
        .bank-select:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .account-select-with-buttons {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .account-select-with-buttons .custom-select-wrapper {
            flex: 1;
        }

        /* Card Merchant / Customer select bar: same design as other bank select bars */
        .bank-form .account-select-with-buttons .custom-select-button {
            width: 100%;
            padding: 12px 16px;
            padding-right: 2.5rem;
            border: 0.125rem solid #e5e7eb;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            box-sizing: border-box;
            background: #ffffff;
            color: #374151;
            font-family: inherit;
            cursor: pointer;
            text-align: left;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            position: relative;
        }

        .bank-form .account-select-with-buttons .custom-select-button:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .account-add-btn {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(180deg, #63C4FF 0%, #0D60FF 100%);
            color: white;
            border: none;
            cursor: pointer;
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.2s ease;
        }

        .account-add-btn:hover {
            background: linear-gradient(180deg, #0D60FF 0%, #63C4FF 100%);
            transform: scale(1.05);
        }

        .custom-select-wrapper {
            position: relative;
            width: 100%;
        }

        .custom-select-button {
            width: 100%;
            padding: 8px 30px 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: white;
            cursor: pointer;
            text-align: left;
            font-size: 14px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            position: relative;
        }

        .custom-select-button::after {
            content: '▼';
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 10px;
            color: #666;
            pointer-events: none;
        }

        .custom-select-button.open::after {
            content: '▲';
        }

        .custom-select-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            display: none;
            max-height: 300px;
            overflow: hidden;
            margin-top: 2px;
        }

        .custom-select-dropdown.show {
            display: block;
        }

        .custom-select-search {
            padding: 8px;
            border-bottom: 1px solid #eee;
            position: sticky;
            top: 0;
            background: white;
            z-index: 1;
        }

        .custom-select-search input {
            width: 100%;
            padding: 6px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .custom-select-options {
            max-height: 250px;
            overflow-y: auto;
        }

        .custom-select-option {
            padding: 8px 12px;
            cursor: pointer;
            font-size: 14px;
            border-bottom: 1px solid #f5f5f5;
        }

        .custom-select-option:hover {
            background-color: #f0f0f0;
        }

        .custom-select-option.selected {
            background-color: #e3f2fd;
            font-weight: bold;
        }

        .custom-select-option:last-child {
            border-bottom: none;
        }

        .custom-select-no-results {
            padding: 12px;
            text-align: center;
            color: #999;
            font-size: 14px;
        }

        .profit-sharing-with-add {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .profit-sharing-with-add .bank-input {
            flex: 1;
        }

        .bank-actions {
            grid-column: 1 / -1;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
    </style>
</head>

<body class="process-page">
    <div class="container">
        <div class="content">
            <div
                style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; margin-top: 20px;">
                <h1 class="page-title" style="margin: 0;">Process List</h1>
                <!-- Permission Filter -->
                <div id="process-list-permission-filter" class="process-company-filter process-permission-filter-header"
                    style="display: none;">
                    <span class="process-company-label">Category:</span>
                    <div id="process-list-permission-buttons" class="process-company-buttons">
                        <!-- Permission buttons will be loaded dynamically -->
                    </div>
                </div>
            </div>

            <div class="separator-line"></div>

            <div class="action-buttons-container">
                <div class="action-buttons">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <button class="btn btn-add" onclick="addProcess()">Add Process</button>
                        <div class="search-container">
                            <svg class="search-icon" fill="currentColor" viewBox="0 0 24 24">
                                <path
                                    d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z" />
                            </svg>
                            <input type="text" id="searchInput" placeholder="Search by Description" class="search-input"
                                value="<?php echo $searchTerm; ?>">
                        </div>
                        <div class="checkbox-section">
                            <input type="checkbox" id="showInactive" name="showInactive" <?php echo $showInactive ? 'checked' : ''; ?>>
                            <label for="showInactive">Show Inactive</label>
                        </div>
                        <div class="checkbox-section">
                            <input type="checkbox" id="showAll" name="showAll" <?php echo $showAll ? 'checked' : ''; ?>>
                            <label for="showAll">Show All</label>
                        </div>
                        <div class="checkbox-section" id="waitingCheckboxSection" style="display: none;">
                            <input type="checkbox" id="waiting" name="waiting">
                            <label for="waiting">Waiting</label>
                        </div>
                    </div>
                    <button class="btn btn-delete" id="processDeleteSelectedBtn" onclick="deleteSelected()"
                        title="Only inactive processes can be deleted" disabled>Delete</button>
                    <button class="btn btn-primary" id="processPostToTransactionBtn" onclick="postToTransactionSelected()"
                        title="将选中 Process 的 Buy Price / Sell Price / Profit 分别记入 Supplier / Customer / Company 账户" style="display: none;" disabled>Post to Transaction</button>
                </div>

                <?php if (count($user_companies) > 1): ?>
                    <div id="process-list-company-filter" class="process-company-filter"
                        style="display: flex; margin-top: 10px;">
                        <span class="process-company-label">Company:</span>
                        <div id="process-list-company-buttons" class="process-company-buttons">
                            <?php foreach ($user_companies as $comp): ?>
                                <button type="button"
                                    class="process-company-btn <?php echo $comp['id'] == $company_id ? 'active' : ''; ?>"
                                    data-company-id="<?php echo $comp['id']; ?>"
                                    onclick="switchProcessListCompany(<?php echo $comp['id']; ?>)">
                                    <?php echo htmlspecialchars($comp['company_id']); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- 包装器保证 th 与数据区同宽，列对齐 -->
            <div class="process-table-wrapper" id="processTableWrapper">
                <!-- Table Header -->
                <div class="table-header" id="tableHeader">
                    <!-- Gambling table headers (default) -->
                    <div class="header-item gambling-header">No</div>
                    <div class="header-item gambling-header">Process ID</div>
                    <div class="header-item gambling-header">Description</div>
                    <div class="header-item gambling-header">Status</div>
                    <div class="header-item gambling-header">Currency</div>
                    <div class="header-item gambling-header">Day Use</div>
                    <div class="header-item gambling-header">Action
                        <input type="checkbox" id="selectAllProcesses" title="Select all"
                            style="margin-left: 10px; cursor: pointer;" onchange="toggleSelectAllProcesses()">
                    </div>
                    <!-- Bank table headers (hidden by default) -->
                    <div class="header-item bank-header" style="display: none;">No</div>
                    <div class="header-item bank-header" style="display: none;">Supplier</div>
                    <div class="header-item bank-header" style="display: none;">Country</div>
                    <div class="header-item bank-header" style="display: none;">Bank</div>
                    <div class="header-item bank-header" style="display: none;">Types</div>
                    <div class="header-item bank-header" style="display: none;">Card Owner</div>
                    <div class="header-item bank-header" style="display: none;">Contract</div>
                    <div class="header-item bank-header" style="display: none;">Insurance</div>
                    <div class="header-item bank-header" style="display: none;">Customer</div>
                    <div class="header-item bank-header" style="display: none;">Cost</div>
                    <div class="header-item bank-header" style="display: none;">Price</div>
                    <div class="header-item bank-header" style="display: none;">Profit</div>
                    <div class="header-item bank-header" style="display: none;">Status</div>
                    <div class="header-item bank-header" style="display: none;">Date</div>
                    <div class="header-item bank-header bank-action-header" style="display: none;">Action
                        <input type="checkbox" title="Select all" class="header-action-checkbox"
                            style="margin-left: 10px; cursor: pointer;">
                    </div>
                </div>

                <!-- Process Cards List -->
                <div class="process-cards" id="processTableBody">
                    <div class="process-card">
                        <div class="card-item">Load the Data...</div>
                    </div>
                </div>
            </div>

            <!-- Bank 用真实 table 保证 th/td 列对齐 -->
            <div id="bankTableWrapper" class="bank-table-wrapper" style="display: none;">
                <table id="bankTable" class="bank-data-table">
                    <thead>
                        <tr id="bankTableHeadRow"></tr>
                    </thead>
                    <tbody id="bankTableBody"></tbody>
                </table>
            </div>

            <!-- 分页控件 - 浮动在右下角 -->
            <div class="pagination-container" id="paginationContainer">
                <button class="pagination-btn" id="prevBtn" onclick="prevPage()">◀</button>
                <span class="pagination-info" id="paginationInfo">1 of 1</span>
                <button class="pagination-btn" id="nextBtn" onclick="nextPage()">▶</button>
            </div>
        </div>
    </div>

    <!-- Edit Process Popup Modal -->
    <div id="editModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit Process</h2>
                <span class="close" onclick="closeEditModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="editProcessForm" class="process-form add-grid">
                    <input type="hidden" id="edit_process_id" name="id">
                    <input type="hidden" id="edit_description_id" name="description_id">
                    <input type="hidden" id="edit_status" name="status" value="active">

                    <!-- Left column -->
                    <div class="add-col">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit_process_name">Process Name *</label>
                                <input type="text" id="edit_process_name" name="process_name" required readonly
                                    style="background-color: #f5f5f5; cursor: not-allowed;">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit_description">Description</label>
                                <div class="input-with-icon">
                                    <input type="text" id="edit_description" name="description" readonly
                                        placeholder="Click + to select descriptions">
                                    <button type="button" class="add-icon" onclick="expandEditDescription()">+</button>
                                </div>
                            </div>
                        </div>

                        <!-- Selected Descriptions Display for Edit (hidden by default) -->
                        <div class="form-row" id="edit_selected_descriptions_display" style="display: none;">
                            <div class="form-group">
                                <label>Selected Descriptions</label>
                                <div class="selected-descriptions" id="edit_selected_descriptions_list"></div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit_currency">Currency</label>
                                <select id="edit_currency" name="currency_id">
                                    <option value="">Select Currency</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit_dts_modified" style="font-weight: 600; color: #666;">DTS
                                    Modified:</label>
                                <div id="edit_dts_modified" readonly
                                    style="background-color: #f5f5f5; cursor: not-allowed; margin-top: 5px; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; display: flex; justify-content: space-between; align-items: center; width: 100%; min-width: 200px; min-height: 38px; box-sizing: border-box;">
                                    <span id="edit_dts_modified_date" style="min-height: 1em;"></span>
                                    <span id="edit_dts_modified_user" style="font-weight: 600; min-height: 1em;"></span>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit_dts_created" style="font-weight: 600; color: #666;">DTS
                                    Created:</label>
                                <div id="edit_dts_created" readonly
                                    style="background-color: #f5f5f5; cursor: not-allowed; margin-top: 5px; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; display: flex; justify-content: space-between; align-items: center; width: 100%; min-width: 200px; min-height: 38px; box-sizing: border-box;">
                                    <span id="edit_dts_created_date" style="min-height: 1em;"></span>
                                    <span id="edit_dts_created_user" style="font-weight: 600; min-height: 1em;"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right column -->
                    <div class="add-col">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit_remove_words">Remove Words</label>
                                <input type="text" id="edit_remove_words" name="remove_word"
                                    placeholder="Enter words to remove">
                                <small class="field-help">(Use semicolon to separate multiple words, e.g.
                                    abc;cde;efg)</small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <div class="day-use-header">
                                    <label>Day Use</label>
                                    <div class="all-day-checkbox">
                                        <input type="checkbox" id="edit_all_day" name="all_day">
                                        <label for="edit_all_day">All Day</label>
                                    </div>
                                </div>
                                <div class="day-checkboxes" id="edit_day_checkboxes"></div>
                            </div>
                        </div>

                        <div class="form-row row-two-cols">
                            <div class="form-group">
                                <label for="edit_replace_word_from">Replace From</label>
                                <input type="text" id="edit_replace_word_from" name="replace_word_from"
                                    placeholder="Old word">
                                <small class="field-help">(Word to be replaced)</small>
                            </div>

                            <div class="form-group">
                                <label for="edit_replace_word_to">Replace To</label>
                                <input type="text" id="edit_replace_word_to" name="replace_word_to"
                                    placeholder="New word">
                                <small class="field-help">(Replacement word)</small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit_remarks">Remarks</label>
                                <textarea id="edit_remarks" name="remark" rows="5"
                                    placeholder="Enter remarks..."></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions add-actions">
                        <button type="submit" class="btn btn-save">Update Process</button>
                        <button type="button" class="btn btn-cancel" onclick="closeEditModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Process Popup Modal -->
    <div id="addModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add Process</h2>
                <span class="close" onclick="closeAddModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="addProcessForm" class="process-form add-grid">
                    <!-- Left column -->
                    <div class="add-col">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="add_copy_from">Copy From</label>
                                <select id="add_copy_from" name="copy_from">
                                    <option value="">Select Process to Copy</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="add_process_id">Process ID *</label>
                                <div class="input-with-checkbox">
                                    <input type="text" id="add_process_id" name="process_id"
                                        placeholder="Enter Process ID" required>
                                    <div class="checkbox-container">
                                        <input type="checkbox" id="add_multi_use" name="multi_use_purpose">
                                        <label for="add_multi_use">Multi-Process</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Multi-use Process Selection (hidden by default) -->
                        <div class="form-row" id="multi_use_processes" style="display: none;">
                            <div class="form-group">
                                <label>Select Multi-use Processes</label>
                                <div class="process-checkboxes" id="process_checkboxes"></div>
                                <div class="multi-use-actions">
                                    <button type="button" class="btn btn-save btn-small"
                                        onclick="confirmMultiUseProcessSelection()">Confirm</button>
                                </div>
                            </div>
                        </div>

                        <!-- Selected Processes Display (hidden by default) -->
                        <div class="form-row" id="selected_processes_display" style="display: none;">
                            <div class="form-group">
                                <label>Selected Multi-use Processes</label>
                                <div class="selected-processes" id="selected_processes_list"></div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="add_description">Description *</label>
                                <div class="input-with-icon">
                                    <input type="text" id="add_description" name="description" required readonly
                                        placeholder="Click + to select descriptions">
                                    <button type="button" class="add-icon" onclick="expandDescription()">+</button>
                                </div>
                            </div>
                        </div>

                        <!-- Selected Descriptions Display (hidden by default) -->
                        <div class="form-row" id="selected_descriptions_display" style="display: none;">
                            <div class="form-group">
                                <label>Selected Descriptions</label>
                                <div class="selected-descriptions" id="selected_descriptions_list"></div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="add_currency">Currency</label>
                                <select id="add_currency" name="currency_id">
                                    <option value="">Select Currency</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Right column -->
                    <div class="add-col">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="add_remove_words">Remove Words</label>
                                <input type="text" id="add_remove_words" name="remove_word"
                                    placeholder="Enter words to remove">
                                <small class="field-help">(Use semicolon to separate multiple words, e.g.
                                    abc;cde;efg)</small>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <div class="day-use-header">
                                    <label>Day Use</label>
                                    <div class="all-day-checkbox">
                                        <input type="checkbox" id="add_all_day" name="all_day">
                                        <label for="add_all_day">All Day</label>
                                    </div>
                                </div>
                                <div class="day-checkboxes" id="day_checkboxes"></div>
                            </div>
                        </div>
                        <div class="form-row row-two-cols">
                            <div class="form-group">
                                <label for="add_replace_word_from">Replace From</label>
                                <input type="text" id="add_replace_word_from" name="replace_word_from"
                                    placeholder="Old word">
                                <small class="field-help">(Word to be replaced)</small>
                            </div>
                            <div class="form-group">
                                <label for="add_replace_word_to">Replace To</label>
                                <input type="text" id="add_replace_word_to" name="replace_word_to"
                                    placeholder="New word">
                                <small class="field-help">(Replacement word)</small>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="add_remarks">Remarks</label>
                                <textarea id="add_remarks" name="remark" rows="5"
                                    placeholder="Enter remarks..."></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Actions: span full width -->
                    <div class="form-actions add-actions">
                        <button type="submit" class="btn btn-save">Add Process</button>
                        <button type="button" class="btn btn-cancel" onclick="closeAddModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add/Edit Process Popup Modal for Bank Category（与 Add 同格式，Edit 时预填并显示 Update） -->
    <div id="addBankModal" class="modal bank-modal" style="display: none;">
        <div class="modal-content bank-modal-content">
            <div class="modal-header">
                <h2 id="bankModalTitle">Add Process</h2>
                <span class="close" onclick="closeAddBankModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="addBankProcessForm" class="process-form bank-form">
                    <input type="hidden" id="bank_edit_id" name="id" value="">
                    <!-- Row 1: same height left & right -->
                    <div class="bank-form-row">
                        <div class="bank-form-cell bank-form-cell-left">
                            <h3 class="bank-section-title">Bank Information</h3>
                            <div class="form-row bank-row-two-cols">
                                <div class="form-group">
                                    <label for="bank_country">Country</label>
                                    <div class="select-with-add">
                                        <select id="bank_country" name="country" class="bank-select">
                                            <option value="">Select Country</option>
                                        </select>
                                        <button type="button" class="bank-add-btn" onclick="showAddCountryModal()"
                                            title="Add New Country">+</button>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="bank_bank">Bank</label>
                                    <div class="select-with-add">
                                        <select id="bank_bank" name="bank" class="bank-select">
                                            <option value="">Select Bank</option>
                                        </select>
                                        <button type="button" class="bank-add-btn" onclick="showAddBankModal()"
                                            title="Add New Bank">+</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="bank-form-cell bank-form-cell-right">
                            <h3 class="bank-section-title">Detail</h3>
                            <div class="form-row bank-row-two-cols">
                                <div class="form-group">
                                    <label for="bank_card_merchant">Supplier</label>
                                    <div class="account-select-with-buttons">
                                        <div class="custom-select-wrapper">
                                            <button type="button" class="custom-select-button" id="bank_card_merchant"
                                                data-placeholder="Select Account" name="card_merchant">Select
                                                Account</button>
                                            <div class="custom-select-dropdown" id="bank_card_merchant_dropdown">
                                                <div class="custom-select-search">
                                                    <input type="text" placeholder="Search account..."
                                                        autocomplete="off">
                                                </div>
                                                <div class="custom-select-options"></div>
                                            </div>
                                        </div>
                                        <button type="button" class="bank-add-btn"
                                            onclick="bankAccountPlusClick('bank_card_merchant')"
                                            title="Add New Account">+</button>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="bank_cost">Buy Price</label>
                                    <input type="text" id="bank_cost" name="cost" placeholder="Enter amount"
                                        class="bank-input" inputmode="decimal" autocomplete="off">
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Row 2 -->
                    <div class="bank-form-row">
                        <div class="bank-form-cell bank-form-cell-left">
                            <div class="form-row bank-row-two-cols bank-row-type-name">
                                <div class="form-group">
                                    <label for="bank_type">Type</label>
                                    <select id="bank_type" name="type" class="bank-select">
                                        <option value="">Select Type</option>
                                        <option value="PERSONAL">PERSONAL</option>
                                        <option value="BUSINESS">BUSINESS</option>
                                        <option value="ENTERPRISE">ENTERPRISE</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="bank_name">Name</label>
                                    <input type="text" id="bank_name" name="name" placeholder="Enter Name"
                                        class="bank-input" oninput="this.value=this.value.toUpperCase()">
                                </div>
                            </div>
                        </div>
                        <div class="bank-form-cell bank-form-cell-right">
                            <div class="form-row bank-row-two-cols">
                                <div class="form-group">
                                    <label for="bank_customer">Customer</label>
                                    <div class="account-select-with-buttons">
                                        <div class="custom-select-wrapper">
                                            <button type="button" class="custom-select-button" id="bank_customer"
                                                data-placeholder="Select Account" name="customer">Select
                                                Account</button>
                                            <div class="custom-select-dropdown" id="bank_customer_dropdown">
                                                <div class="custom-select-search">
                                                    <input type="text" placeholder="Search account..."
                                                        autocomplete="off">
                                                </div>
                                                <div class="custom-select-options"></div>
                                            </div>
                                        </div>
                                        <button type="button" class="bank-add-btn"
                                            onclick="bankAccountPlusClick('bank_customer')"
                                            title="Add New Account">+</button>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="bank_price">Sell Price</label>
                                    <input type="text" id="bank_price" name="price" placeholder="Enter amount"
                                        class="bank-input" inputmode="decimal" autocomplete="off">
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Row 3 -->
                    <div class="bank-form-row">
                        <div class="bank-form-cell bank-form-cell-left">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="bank_day_start">Day start</label>
                                    <input type="date" id="bank_day_start" name="day_start" class="bank-input">
                                </div>
                            </div>
                        </div>
                        <div class="bank-form-cell bank-form-cell-right">
                            <div class="form-row bank-row-two-cols">
                                <div class="form-group">
                                    <label for="bank_profit_account">Company</label>
                                    <div class="account-select-with-buttons">
                                        <div class="custom-select-wrapper">
                                            <button type="button" class="custom-select-button" id="bank_profit_account"
                                                data-placeholder="Select Account" name="profit_account">Select
                                                Account</button>
                                            <div class="custom-select-dropdown" id="bank_profit_account_dropdown">
                                                <div class="custom-select-search">
                                                    <input type="text" placeholder="Search account..."
                                                        autocomplete="off">
                                                </div>
                                                <div class="custom-select-options"></div>
                                            </div>
                                        </div>
                                        <button type="button" class="bank-add-btn"
                                            onclick="bankAccountPlusClick('bank_profit_account')"
                                            title="Add New Account">+</button>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="bank_profit">Profit</label>
                                    <input type="number" id="bank_profit" name="profit" placeholder="Auto calculated"
                                        class="bank-input" readonly style="background-color: #f5f5f5;">
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Row 4: Selected Profit Sharing | Contract & Insurance -->
                    <div class="bank-form-row bank-form-row-last">
                        <div class="bank-form-cell bank-form-cell-left">
                            <input type="hidden" id="bank_profit_sharing" name="profit_sharing">
                            <div class="selected-countries-section">
                                <div class="selected-profit-sharing-header"
                                    style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                    <h3 style="margin: 0;">Selected Profit Sharing</h3>
                                    <button type="button" class="bank-add-btn" onclick="showAddProfitSharingModal()"
                                        title="Add Profit Sharing">+</button>
                                </div>
                                <div class="selected-countries-list" id="selectedProfitSharingList">
                                    <div class="no-countries">No profit sharing selected</div>
                                </div>
                            </div>
                        </div>
                        <div class="bank-form-cell bank-form-cell-right">
                            <div class="form-row bank-row-two-cols">
                                <div class="form-group">
                                    <label for="bank_contract">Contract</label>
                                    <select id="bank_contract" name="contract" class="bank-select">
                                        <option value="">Select Contract</option>
                                        <option value="1 MONTH">1 MONTH</option>
                                        <option value="2 MONTHS">2 MONTHS</option>
                                        <option value="3 MONTHS">3 MONTHS</option>
                                        <option value="6 MONTHS">6 MONTHS</option>
                                        <option value="1+1">1+1</option>
                                        <option value="1+2">1+2</option>
                                        <option value="1+3">1+3</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="bank_insurance">Insurance</label>
                                    <input type="text" id="bank_insurance" name="insurance" placeholder="Enter amount"
                                        class="bank-input" inputmode="decimal" autocomplete="off">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Actions: span full width -->
                    <div class="form-actions bank-actions">
                        <button type="submit" class="btn btn-save" id="bankSubmitBtn">Add Process</button>
                        <button type="button" class="btn btn-cancel" onclick="closeAddBankModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Account Modal (same structure as datacapturesummary - for Card Merchant/Customer + button) -->
    <div id="addAccountModal" class="account-modal" style="display: none;">
        <div class="account-modal-content">
            <div class="account-modal-header">
                <h2>Add Account</h2>
                <span class="account-close" onclick="closeAddAccountModal()">&times;</span>
            </div>
            <div class="account-modal-body">
                <form id="addAccountForm" class="account-form">
                    <!-- Two columns: Personal Information and Payment -->
                    <div class="account-form-columns">
                        <div class="account-form-column">
                            <h3 class="account-section-header">Personal Information</h3>
                            <div class="account-form-group">
                                <label for="add_account_id">Account ID *</label>
                                <input type="text" id="add_account_id" name="account_id" required>
                            </div>
                            <div class="account-form-group">
                                <label for="add_name">Name *</label>
                                <input type="text" id="add_name" name="name" required>
                            </div>
                            <div class="account-form-group">
                                <label for="add_role">Role *</label>
                                <select id="add_role" name="role" required>
                                    <option value="">Select Role</option>
                                </select>
                            </div>
                            <div class="account-form-group">
                                <label for="add_password">Password *</label>
                                <input type="password" id="add_password" name="password" required>
                            </div>
                        </div>
                        <div class="account-form-column">
                            <h3 class="account-section-header">Payment</h3>
                            <div class="account-form-group">
                                <label>Payment Alert</label>
                                <div class="account-radio-group">
                                    <label class="account-radio-label">
                                        <input type="radio" name="add_payment_alert" value="1">
                                        Yes
                                    </label>
                                    <label class="account-radio-label">
                                        <input type="radio" name="add_payment_alert" value="0" checked>
                                        No
                                    </label>
                                </div>
                            </div>
                            <div class="account-form-row" id="add_alert_fields" style="display: none;">
                                <div class="account-form-group">
                                    <label for="add_alert_type">Alert Type</label>
                                    <select id="add_alert_type" name="alert_type">
                                        <option value="">Select Type</option>
                                        <option value="weekly">Weekly</option>
                                        <option value="monthly">Monthly</option>
                                        <?php for ($i = 1; $i <= 31; $i++): ?>
                                            <option value="<?php echo $i; ?>"><?php echo $i; ?> Days</option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="account-form-group">
                                    <label for="add_alert_start_date">Start Date</label>
                                    <input type="date" id="add_alert_start_date" name="alert_start_date">
                                </div>
                            </div>
                            <div class="account-form-group" id="add_alert_amount_row" style="display: none;">
                                <label for="add_alert_amount">Alert (Amount)</label>
                                <input type="number" id="add_alert_amount" name="alert_amount" step="0.01"
                                    placeholder="Enter amount (auto-converted to negative)">
                            </div>
                            <div class="account-form-group">
                                <label for="add_remark">Remark</label>
                                <textarea id="add_remark" name="remark" rows="1"
                                    style="resize: none; overflow-y: hidden; line-height: 1.5;"></textarea>
                            </div>
                        </div>
                    </div>
                    <!-- Advanced Account Section -->
                    <div class="account-form-section">
                        <div class="account-advance-section">
                            <h3>Advanced Account</h3>
                            <div class="account-other-currency">
                                <label>Other Currency:</label>
                                <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                                    <input type="text" id="addCurrencyInput"
                                        placeholder="Enter new currency code (e.g., USD)"
                                        style="flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                    <button type="button" class="account-btn-add-currency"
                                        onclick="addCurrencyFromInputBank('add'); return false;">Create
                                        Currency</button>
                                </div>
                                <div class="account-currency-list" id="addCurrencyList"></div>
                            </div>
                            <div class="account-other-currency" style="margin-top: 20px;">
                                <label>Company:</label>
                                <div class="account-currency-list" id="addCompanyList"></div>
                            </div>
                        </div>
                    </div>
                    <div class="account-form-actions">
                        <button type="submit" class="account-btn account-btn-save">Add Account</button>
                        <button type="button" class="account-btn account-btn-cancel"
                            onclick="closeAddAccountModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Account Modal (same as account-list.php - for + button when account selected) -->
    <div id="editAccountModal" class="account-modal" style="display: none;">
        <div class="account-modal-content">
            <div class="account-modal-header">
                <h2>Edit Account</h2>
                <span class="account-close" onclick="closeEditAccountModalFromBank()">&times;</span>
            </div>
            <div class="account-modal-body">
                <form id="editAccountForm" class="account-form">
                    <input type="hidden" id="edit_account_id" name="id">
                    <div class="account-form-columns">
                        <div class="account-form-column">
                            <h3 class="account-section-header">Personal Information</h3>
                            <div class="account-form-group">
                                <label for="edit_account_id_field">Account ID *</label>
                                <input type="text" id="edit_account_id_field" name="account_id" readonly>
                            </div>
                            <div class="account-form-group">
                                <label for="edit_name">Name *</label>
                                <input type="text" id="edit_name" name="name" required>
                            </div>
                            <div class="account-form-group">
                                <label for="edit_role">Role *</label>
                                <select id="edit_role" name="role" required>
                                    <option value="">Select Role</option>
                                </select>
                            </div>
                            <div class="account-form-group">
                                <label for="edit_password">Password *</label>
                                <input type="password" id="edit_password" name="password" required>
                            </div>
                        </div>
                        <div class="account-form-column">
                            <h3 class="account-section-header">Payment</h3>
                            <div class="account-form-group"></div>
                            <div class="account-form-group">
                                <label>Payment Alert</label>
                                <div class="account-radio-group">
                                    <label class="account-radio-label">
                                        <input type="radio" name="payment_alert" value="1">
                                        Yes
                                    </label>
                                    <label class="account-radio-label">
                                        <input type="radio" name="payment_alert" value="0">
                                        No
                                    </label>
                                </div>
                            </div>
                            <div class="account-form-row" id="edit_alert_fields" style="display: none;">
                                <div class="account-form-group">
                                    <label for="edit_alert_type">Alert Type</label>
                                    <select id="edit_alert_type" name="alert_type">
                                        <option value="">Select Type</option>
                                        <option value="weekly">Weekly</option>
                                        <option value="monthly">Monthly</option>
                                        <?php for ($i = 1; $i <= 31; $i++): ?>
                                            <option value="<?php echo $i; ?>"><?php echo $i; ?> Days</option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="account-form-group">
                                    <label for="edit_alert_start_date">Start Date</label>
                                    <input type="date" id="edit_alert_start_date" name="alert_start_date">
                                </div>
                            </div>
                            <div class="account-form-group" id="edit_alert_amount_row" style="display: none;">
                                <label for="edit_alert_amount">Alert (Amount)</label>
                                <input type="number" id="edit_alert_amount" name="alert_amount" step="0.01"
                                    placeholder="Enter amount (auto-converted to negative)">
                            </div>
                            <div class="account-form-group">
                                <label for="edit_remark">Remark</label>
                                <textarea id="edit_remark" name="remark" rows="1"
                                    style="resize: none; overflow-y: hidden; line-height: 1.5;"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="account-form-section">
                        <div class="account-advance-section">
                            <h3>Advanced Account</h3>
                            <div class="account-other-currency">
                                <label>Other Currency:</label>
                                <div style="display: flex; gap: 8px;">
                                    <input type="text" id="editCurrencyInput"
                                        placeholder="Enter new currency code (e.g., USD)"
                                        style="flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                    <button type="button" class="account-btn-add-currency"
                                        onclick="addCurrencyFromInputBank('edit'); return false;">Create
                                        Currency</button>
                                </div>
                                <div class="account-currency-list" id="editCurrencyList"></div>
                            </div>
                            <div class="account-other-currency" style="margin-top: 20px;">
                                <label>Company:</label>
                                <div class="account-currency-list" id="editCompanyList"></div>
                            </div>
                        </div>
                    </div>
                    <div class="account-form-actions">
                        <button type="submit" class="account-btn account-btn-save">Update Account</button>
                        <button type="button" class="account-btn account-btn-cancel"
                            onclick="closeEditAccountModalFromBank()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Profit Sharing Modal (account select + amount input) -->
    <div id="profitSharingModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 420px;">
            <div class="modal-header">
                <h2>Add Profit Sharing</h2>
                <span class="close" onclick="closeProfitSharingModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="profitSharingForm" class="bank-form" style="display: block;">
                    <div id="profitSharingRowsContainer">
                        <div class="form-row bank-row-two-cols profit-sharing-row">
                            <div class="form-group">
                                <label for="profit_sharing_account">Account</label>
                                <select id="profit_sharing_account" name="account_id"
                                    class="bank-select profit-sharing-account">
                                    <option value="">Select Account</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="profit_sharing_amount">Amount</label>
                                <input type="number" id="profit_sharing_amount" name="amount"
                                    class="bank-input profit-sharing-amount" placeholder="Enter amount" step="0.01"
                                    min="0">
                            </div>
                        </div>
                    </div>
                    <div class="profit-sharing-add-row-wrap" style="margin-top: 10px;">
                        <button type="button" class="bank-add-btn" id="profitSharingAddRowBtn"
                            title="Add another Account &amp; Amount">+</button>
                    </div>
                    <div class="form-actions bank-actions" style="margin-top: 16px;">
                        <button type="submit" class="btn btn-save">Add</button>
                        <button type="button" class="btn btn-cancel" onclick="closeProfitSharingModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Description Selection Modal -->
    <div id="descriptionSelectionModal" class="modal" style="display: none;">
        <div class="modal-content description-selection-modal">
            <div class="modal-header">
                <h2>Select or Add Description</h2>
                <span class="close" onclick="closeDescriptionSelectionModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="description-selection-container">
                    <!-- Left side - Selected descriptions -->
                    <div class="selected-descriptions-section">
                        <h3>Selected Descriptions</h3>
                        <div class="selected-descriptions-list" id="selectedDescriptionsInModal">
                            <!-- Selected descriptions will be displayed here -->
                        </div>
                    </div>

                    <!-- Right side - Add new and available descriptions -->
                    <div class="available-descriptions-section">
                        <!-- Add new description section -->
                        <div class="add-description-bar">
                            <h3>Add New Description</h3>
                            <form id="addDescriptionForm" class="add-description-form">
                                <div class="add-description-input-group">
                                    <input type="text" id="new_description_name" name="description_name"
                                        placeholder="Enter new description name..." required>
                                    <button type="submit" class="btn btn-save">Add</button>
                                </div>
                            </form>
                        </div>

                        <h3>Available Descriptions</h3>
                        <div class="description-search">
                            <input type="text" id="descriptionSearch" placeholder="Search descriptions..."
                                onkeyup="filterDescriptions()">
                        </div>
                        <div class="description-list" id="existingDescriptions">
                            <!-- Available descriptions will be loaded here -->
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel"
                        onclick="closeDescriptionSelectionModal()">Cancel</button>
                    <button type="button" class="btn btn-save" id="confirmDescriptionsBtn"
                        onclick="confirmDescriptions()">Confirm Selection</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Country Selection Modal (layout: left = Add/Available, right = Selected) -->
    <div id="countrySelectionModal" class="modal" style="display: none;">
        <div class="modal-content country-selection-modal">
            <div class="modal-header">
                <h2>Select or Add Country</h2>
                <span class="close" onclick="closeCountrySelectionModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="country-selection-container">
                    <!-- Left side - Add new and available countries -->
                    <div class="available-countries-section">
                        <div class="add-country-bar">
                            <h3>Add New Country</h3>
                            <form id="addCountryForm" class="add-country-form">
                                <div class="add-country-input-group">
                                    <input type="text" id="new_country_name" name="country_name"
                                        placeholder="Enter new country name..."
                                        oninput="this.value=this.value.toUpperCase()">
                                    <button type="submit" class="btn btn-save">Add</button>
                                </div>
                            </form>
                        </div>
                        <h3>Available Countries</h3>
                        <div class="country-search">
                            <input type="text" id="countrySearch" placeholder="Search countries..."
                                onkeyup="filterCountries()" oninput="this.value=this.value.toUpperCase()">
                        </div>
                        <div class="country-list" id="existingCountries">
                            <!-- Available countries will be loaded here -->
                        </div>
                    </div>
                    <!-- Right side - Selected countries -->
                    <div class="selected-countries-section">
                        <h3>Selected Countries</h3>
                        <div class="selected-countries-list" id="selectedCountriesInModal">
                            <!-- Selected countries will be displayed here -->
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-save" id="confirmCountriesBtn"
                        onclick="confirmCountries()">Confirm</button>
                    <button type="button" class="btn btn-cancel" onclick="closeCountrySelectionModal()">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bank Selection Modal (layout: left = Add/Available, right = Selected) -->
    <div id="bankSelectionModal" class="modal" style="display: none;">
        <div class="modal-content bank-selection-modal">
            <div class="modal-header">
                <h2>Select or Add Bank</h2>
                <span class="close" onclick="closeBankSelectionModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="bank-selection-container">
                    <!-- Left side - Add new and available banks -->
                    <div class="available-banks-section">
                        <div class="add-bank-bar">
                            <h3>Add New Bank</h3>
                            <form id="addBankForm" class="add-bank-form">
                                <div class="add-bank-input-group">
                                    <input type="text" id="new_bank_name" name="bank_name"
                                        placeholder="Enter new bank name..."
                                        oninput="this.value=this.value.toUpperCase()">
                                    <button type="submit" class="btn btn-save">Add</button>
                                </div>
                            </form>
                        </div>
                        <h3>Available Banks</h3>
                        <div class="bank-search">
                            <input type="text" id="bankSearch" placeholder="Search banks..." onkeyup="filterBanks()"
                                oninput="this.value=this.value.toUpperCase()">
                        </div>
                        <div class="bank-list" id="existingBanks">
                            <!-- Available banks will be loaded here -->
                        </div>
                    </div>
                    <!-- Right side - Selected banks -->
                    <div class="selected-banks-section">
                        <h3>Selected Banks</h3>
                        <div class="selected-banks-list" id="selectedBanksInModal">
                            <!-- Selected banks will be displayed here -->
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-save" id="confirmBanksBtn"
                        onclick="confirmBanks()">Confirm</button>
                    <button type="button" class="btn btn-cancel" onclick="closeBankSelectionModal()">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Notification Container -->
    <div id="processNotificationContainer" class="process-notification-container"></div>

    <!-- Confirm Delete Modal -->
    <div id="confirmDeleteModal" class="process-modal" style="display: none;">
        <div class="process-confirm-modal-content">
            <div class="process-confirm-icon-container">
                <svg class="process-confirm-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>
            <h2 class="process-confirm-title">Confirm Delete</h2>
            <p id="confirmDeleteMessage" class="process-confirm-message">This action cannot be undone.</p>
            <div class="process-confirm-actions">
                <button type="button" class="process-btn process-btn-cancel confirm-cancel"
                    onclick="closeConfirmDeleteModal()">Cancel</button>
                <button type="button" class="process-btn process-btn-delete confirm-delete"
                    onclick="confirmDelete()">Delete</button>
            </div>
        </div>
    </div>

    <script>
        // 全局变量
        let processes = [];
        let showInactive = <?php echo isset($_GET['showInactive']) ? 'true' : 'false'; ?>;
        let showAll = <?php echo isset($_GET['showAll']) ? 'true' : 'false'; ?>;
        let waiting = false;
        let currentPage = 1;
        const pageSize = 20;
        /** Bank 表头与数据行共用同一 grid-template-columns，保证列对齐 */
        const BANK_GRID_TEMPLATE_COLUMNS = '0.2fr 0.8fr 0.6fr 0.7fr 0.5fr 0.6fr 0.6fr 0.6fr 0.7fr 0.4fr 0.4fr 0.4fr 0.4fr 0.5fr 0.3fr';

        // 构造同目录 API URL
        function buildApiUrl(fileName) {
            const base = window.location.origin + window.location.pathname.replace(/[^/]*$/, '');
            return new URL(fileName, base);
        }

        // 从API获取数据
        async function fetchProcesses() {
            console.log('fetchProcesses called');
            try {
                const searchInput = document.getElementById('searchInput');
                if (!searchInput) {
                    console.error('searchInput element not found');
                    return;
                }
                const searchTerm = searchInput.value;
                const url = buildApiUrl('processlistapi.php');

                // 添加当前选择的 company_id
                const currentCompanyId = <?php echo json_encode($company_id); ?>;
                if (currentCompanyId) {
                    url.searchParams.set('company_id', currentCompanyId);
                }

                // 添加权限过滤
                if (selectedPermission) {
                    url.searchParams.set('permission', selectedPermission);
                }

                if (searchTerm.trim()) {
                    url.searchParams.set('search', searchTerm);
                }
                if (showInactive) {
                    url.searchParams.set('showInactive', '1');
                }
                if (showAll) {
                    url.searchParams.set('showAll', '1');
                }
                if (waiting) {
                    url.searchParams.set('waiting', '1');
                }

                console.log('fetchProcesses ->', url.toString());
                const response = await fetch(url);

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const result = await response.json();
                console.log('API Response:', result);

                if (result.success) {
                    processes = result.data;
                    // 根据类别进行不同的排序
                    if (selectedPermission === 'Bank') {
                        // Bank 类别的排序逻辑（可以根据需要调整）
                        processes.sort((a, b) => {
                            const aKey = String(a.supplier || '').toLowerCase();
                            const bKey = String(b.supplier || '').toLowerCase();
                            if (aKey < bKey) return -1;
                            if (aKey > bKey) return 1;
                            return 0;
                        });
                    } else {
                        // Gambling 类别的排序逻辑（原有逻辑）
                        processes.sort((a, b) => {
                            const aKey = String(a.process_name || '').toLowerCase();
                            const bKey = String(b.process_name || '').toLowerCase();
                            if (aKey < bKey) return -1;
                            if (aKey > bKey) return 1;
                            const aDesc = String(a.description || a.description_name || '').toLowerCase();
                            const bDesc = String(b.description || b.description_name || '').toLowerCase();
                            if (aDesc < bDesc) return -1;
                            if (aDesc > bDesc) return 1;
                            return 0;
                        });
                    }
                    const totalPages = Math.max(1, Math.ceil(processes.length / pageSize));
                    if (currentPage > totalPages) currentPage = totalPages;
                    renderTable();
                    renderPagination();
                } else {
                    console.error('API error:', result.error);
                    showNotification('Failed to get data: ' + result.error, 'danger');
                    showError('API error: ' + result.error);
                }
            } catch (error) {
                console.error('Network error:', error);
                showNotification('Network connection failed: ' + error.message, 'danger');
                showError('Network connection failed: ' + error.message);
            }
        }

        function renderTable() {
            if (selectedPermission === 'Bank') {
                renderBankTable();
                return;
            }
            const container = document.getElementById('processTableBody');
            container.innerHTML = '';

            if (processes.length === 0) {
                const emptyCard = document.createElement('div');
                emptyCard.className = 'process-card';
                emptyCard.innerHTML = `<div class="card-item" style="text-align: left; padding: 20px; grid-column: 1 / -1;">No process data found</div>`;
                container.appendChild(emptyCard);
                return;
            }

            let pageItems, startIndex;
            if (showAll) {
                pageItems = processes;
                startIndex = 0;
            } else {
                const totalPages = Math.max(1, Math.ceil(processes.length / pageSize));
                if (currentPage > totalPages) currentPage = totalPages;
                startIndex = (currentPage - 1) * pageSize;
                const endIndex = Math.min(startIndex + pageSize, processes.length);
                pageItems = processes.slice(startIndex, endIndex);
            }

            // Gambling 类别的表格
            {
                // Gambling 类别的表格（原有逻辑）
                pageItems.forEach((process, idx) => {
                    const card = document.createElement('div');
                    card.className = 'process-card';
                    card.setAttribute('data-id', process.id);
                    // 恢复 Gambling 表格的列数（7列）
                    card.style.gridTemplateColumns = '0.3fr 0.8fr 1.1fr 0.2fr 0.3fr 1.1fr 0.19fr';

                    const statusClass = process.status === 'active' ? 'status-active' : 'status-inactive';

                    card.innerHTML = `
                        <div class="card-item">${startIndex + idx + 1}</div>
                        <div class="card-item">${escapeHtml((process.process_name || '').toUpperCase())}</div>
                        <div class="card-item">${escapeHtml((process.description || '').toUpperCase())}</div>
                        <div class="card-item">
                            <span class="role-badge ${statusClass} status-clickable" onclick="toggleProcessStatus(${process.id}, '${process.status}')" title="Click to toggle status" style="cursor: pointer;">
                                ${escapeHtml((process.status || '').toUpperCase())}
                            </span>
                        </div>
                        <div class="card-item">${escapeHtml(process.currency || '')}</div>
                        <div class="card-item">${escapeHtml(process.day_use || process.day_name || '')}</div>
                        <div class="card-item">
                            <button class="edit-btn" onclick="editProcess(${process.id})" aria-label="Edit" title="Edit">
                                <img src="images/edit.svg" alt="Edit" />
                            </button>
                            ${process.status === 'active' ? '' : `<input type="checkbox" class="row-checkbox" data-id="${process.id}" title="Select for deletion" onchange="updateDeleteButton()" style="margin-left: 10px;">`}
                        </div>
                    `;
                    container.appendChild(card);
                });
            }
            renderPagination();
            updateSelectAllProcessesVisibility();
        }

        /** Bank 用真实 table 渲染，th/td 列由浏览器对齐 */
        function renderBankTable() {
            const headRow = document.getElementById('bankTableHeadRow');
            const tbody = document.getElementById('bankTableBody');
            if (!headRow || !tbody) return;

            const thLabels = ['No', 'Supplier', 'Country', 'Bank', 'Types', 'Card Owner', 'Contract', 'Insurance', 'Customer', 'Cost', 'Price', 'Profit', 'Status', 'Date', 'Action'];
            headRow.innerHTML = thLabels.map((label, i) => {
                if (label === 'No') return '<th class="bank-th-no">' + escapeHtml(label) + '</th>';
                if (label === 'Country') return '<th class="bank-th-country">' + escapeHtml(label) + '</th>';
                if (label === 'Types') return '<th class="bank-th-types">' + escapeHtml(label) + '</th>';
                if (label === 'Card Owner') return '<th class="bank-th-card-owner">' + escapeHtml(label) + '</th>';
                if (label === 'Status') return '<th class="bank-th-status">' + escapeHtml(label) + '</th>';
                if (label === 'Action') {
                    const showActionCheckbox = showInactive || showAll;
                    return '<th class="bank-th-action">Action' + (showActionCheckbox ? ' <input type="checkbox" id="selectAllBankProcesses" class="header-action-checkbox" title="Select all" style="margin-left: 10px; cursor: pointer;" onchange="toggleSelectAllBankProcesses()">' : '') + '</th>';
                }
                return '<th>' + escapeHtml(label) + '</th>';
            }).join('');

            tbody.innerHTML = '';
            const contractMap = { '1': '1 MONTH', '1 month': '1 MONTH', '2': '2 MONTHS', '2 months': '2 MONTHS', '3': '3 MONTHS', '3 months': '3 MONTHS', '6': '6 MONTHS', '6 months': '6 MONTHS', '1+1': '1+1', '1+2': '1+2', '1+3': '1+3' };
            const todayStr = new Date().toISOString().slice(0, 10);
            function getContractStateClass(dayStart, dayEnd) {
                if (!dayStart && !dayEnd) return '';
                if (dayStart && todayStr < dayStart) return 'contract-pending';
                if (dayEnd && todayStr > dayEnd) return 'contract-expired';
                if (dayStart && dayEnd && todayStr >= dayStart && todayStr <= dayEnd) return 'contract-active';
                if (dayStart && todayStr >= dayStart) return 'contract-active';
                return 'contract-expired';
            }
            // When Waiting is checked, only show rows where contract is pending (yellow)
            let listToShow = processes;
            if (waiting) {
                listToShow = processes.filter(function (p) { return getContractStateClass(p.day_start || null, p.day_end || null) === 'contract-pending'; });
            }
            window.__bankFilteredLength = waiting ? listToShow.length : null;

            if (listToShow.length === 0) {
                tbody.innerHTML = '<tr><td colspan="15" class="bank-empty-cell">No process data found</td></tr>';
                renderPagination();
                updateSelectAllProcessesVisibility();
                return;
            }

            let pageItems, startIndex;
            if (showAll) {
                pageItems = listToShow;
                startIndex = 0;
            } else {
                const totalPages = Math.max(1, Math.ceil(listToShow.length / pageSize));
                if (currentPage > totalPages) currentPage = totalPages;
                startIndex = (currentPage - 1) * pageSize;
                pageItems = listToShow.slice(startIndex, Math.min(startIndex + pageSize, listToShow.length));
            }

            function dashIfEmpty(val) {
                if (val == null) return '-';
                const s = String(val).trim();
                return s === '' ? '-' : val;
            }
            pageItems.forEach((process, idx) => {
                const statusClass = process.status === 'active' ? 'status-active' : (process.status === 'waiting' ? 'status-waiting' : 'status-inactive');
                const contract = process.contract ? (contractMap[process.contract] || process.contract) : '';
                const contractClass = getContractStateClass(process.day_start || null, process.day_end || null);
                const contractCell = (contract && contractClass)
                    ? '<span class="contract-badge ' + contractClass + '">' + escapeHtml(contract) + '</span>'
                    : (contract ? escapeHtml(contract) : escapeHtml('-'));
                const cost = dashIfEmpty(process.cost);
                const price = dashIfEmpty(process.price);
                const profit = dashIfEmpty(process.profit);
                const statusBadge = '<span class="role-badge ' + statusClass + ' status-clickable" onclick="toggleProcessStatus(' + process.id + ', \'' + process.status + '\')" title="Click to toggle status" style="cursor: pointer;">' + escapeHtml((process.status || '').toUpperCase()) + '</span>';
                const actionCell = '<button class="edit-btn" onclick="editProcess(' + process.id + ')" aria-label="Edit" title="Edit"><img src="images/edit.svg" alt="Edit" /></button>' +
                    '<input type="checkbox" class="row-checkbox bank-checkbox" data-id="' + process.id + '" title="' + (process.status === 'active' ? 'Select for post to transaction' : 'Select for deletion') + '" onchange="updateDeleteButton(); updatePostToTransactionButton();" style="margin-left: 10px;">';
                const tr = document.createElement('tr');
                tr.setAttribute('data-id', process.id);
                tr.setAttribute('data-status', process.status || '');
                tr.innerHTML = '<td class="bank-td-no">' + (startIndex + idx + 1) + '</td>' +
                    '<td>' + escapeHtml(dashIfEmpty(process.card_lower)) + '</td>' +
                    '<td class="bank-td-country">' + escapeHtml(dashIfEmpty(process.country)) + '</td>' +
                    '<td>' + escapeHtml(dashIfEmpty(process.bank)) + '</td>' +
                    '<td class="bank-td-types">' + escapeHtml(dashIfEmpty(process.types)) + '</td>' +
                    '<td class="bank-td-card-owner">' + escapeHtml(dashIfEmpty(process.supplier)) + '</td>' +
                    '<td>' + contractCell + '</td>' +
                    '<td>' + escapeHtml(dashIfEmpty(process.insurance)) + '</td>' +
                    '<td>' + escapeHtml(dashIfEmpty(process.customer)) + '</td>' +
                    '<td>' + escapeHtml(String(cost)) + '</td>' +
                    '<td>' + escapeHtml(String(price)) + '</td>' +
                    '<td>' + escapeHtml(String(profit)) + '</td>' +
                    '<td class="bank-td-status">' + statusBadge + '</td>' +
                    '<td>' + escapeHtml(dashIfEmpty((process.date === '0000-00-00' || !process.date) ? '' : process.date)) + '</td>' +
                    '<td class="bank-td-action">' + actionCell + '</td>';
                tbody.appendChild(tr);
            });

            renderPagination();
            updateSelectAllProcessesVisibility();
            updateDeleteButton();
        }

        /** 仅调整数据列宽度与 th 一致，th 不改；双 rAF 确保布局完成后再取宽 */
        function syncBankTableColumnWidth() {
            if (selectedPermission !== 'Bank') return;
            const tableHeader = document.getElementById('tableHeader');
            const processTableBody = document.getElementById('processTableBody');
            if (!tableHeader || !processTableBody) return;
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    const rect = tableHeader.getBoundingClientRect();
                    processTableBody.style.setProperty('--table-header-width', rect.width + 'px');
                });
            });
        }

        function renderPagination() {
            // 如果 showAll 为 true，隐藏分页控件
            if (showAll) {
                const paginationContainer = document.getElementById('paginationContainer');
                paginationContainer.style.display = 'none';
                return;
            }
            const totalCount = (selectedPermission === 'Bank' && window.__bankFilteredLength != null) ? window.__bankFilteredLength : processes.length;
            const totalPages = Math.max(1, Math.ceil(totalCount / pageSize));

            // 更新分页控件信息
            document.getElementById('paginationInfo').textContent = `${currentPage} of ${totalPages}`;

            // 更新按钮状态
            const isPrevDisabled = currentPage <= 1;
            const isNextDisabled = currentPage >= totalPages;

            document.getElementById('prevBtn').disabled = isPrevDisabled;
            document.getElementById('nextBtn').disabled = isNextDisabled;

            // 始终显示分页控件
            const paginationContainer = document.getElementById('paginationContainer');
            paginationContainer.style.display = 'flex';
        }

        function goToPage(page) {
            const totalCount = (selectedPermission === 'Bank' && window.__bankFilteredLength != null) ? window.__bankFilteredLength : processes.length;
            const totalPages = Math.max(1, Math.ceil(totalCount / pageSize));
            const newPage = Math.min(Math.max(1, page), totalPages);
            if (newPage !== currentPage) {
                currentPage = newPage;
                renderTable();
                renderPagination();
            }
        }

        function prevPage() { goToPage(currentPage - 1); }
        function nextPage() { goToPage(currentPage + 1); }

        function showError(message) {
            const container = document.getElementById('processTableBody');
            container.innerHTML = `
                <div class="process-card">
                    <div class="card-item" style="text-align: center; padding: 20px; color: red; grid-column: 1 / -1;">
                        ${escapeHtml(message)}
                    </div>
                </div>
            `;
            showNotification(message, 'danger');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text == null ? '' : String(text);
            return div.innerHTML;
        }

        // Notification functions
        function showNotification(message, type = 'success') {
            const container = document.getElementById('processNotificationContainer');

            // 检查现有通知数量，最多保留2个
            const existingNotifications = container.querySelectorAll('.process-notification');
            if (existingNotifications.length >= 2) {
                // 移除最旧的通知
                const oldestNotification = existingNotifications[0];
                oldestNotification.classList.remove('show');
                setTimeout(() => {
                    if (oldestNotification.parentNode) {
                        oldestNotification.remove();
                    }
                }, 300);
            }

            // 创建新通知
            const notification = document.createElement('div');
            notification.className = `process-notification process-notification-${type}`;
            notification.textContent = message;

            // 添加到容器
            container.appendChild(notification);

            // 触发显示动画
            setTimeout(() => {
                notification.classList.add('show');
            }, 10);

            // 1.5秒后开始消失动画
            setTimeout(() => {
                notification.classList.remove('show');
                // 0.3秒后完全移除
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.remove();
                    }
                }, 300);
            }, 1500);
        }

        // 其他必要的函数
        function addProcess() {
            if (selectedPermission === 'Bank') {
                window.selectedProfitSharingEntries = [];
                loadAddBankProcessData().then(async () => {
                    const countryEl = document.getElementById('bank_country');
                    await loadBanksByCountry(countryEl ? countryEl.value : '');
                    renderSelectedProfitSharing();
                    document.getElementById('addBankModal').style.display = 'block';
                });
            } else {
                loadAddProcessData();
                document.getElementById('addModal').style.display = 'block';
            }
        }

        function closeAddBankModal() {
            document.getElementById('addBankModal').style.display = 'none';
            document.getElementById('bank_edit_id').value = '';
            window.selectedProfitSharingEntries = [];
            const titleEl = document.getElementById('bankModalTitle');
            const submitBtn = document.getElementById('bankSubmitBtn');
            if (titleEl) titleEl.textContent = 'Add Process';
            if (submitBtn) submitBtn.textContent = 'Add Process';
            document.getElementById('addBankProcessForm').reset();
            document.getElementById('bank_edit_id').value = '';
            const profitInput = document.getElementById('bank_profit');
            if (profitInput) profitInput.value = '';
            const cardMerchantBtn = document.getElementById('bank_card_merchant');
            const customerBtn = document.getElementById('bank_customer');
            if (cardMerchantBtn) {
                cardMerchantBtn.textContent = cardMerchantBtn.getAttribute('data-placeholder') || 'Select Account';
                cardMerchantBtn.removeAttribute('data-value');
            }
            if (customerBtn) {
                customerBtn.textContent = customerBtn.getAttribute('data-placeholder') || 'Select Account';
                customerBtn.removeAttribute('data-value');
            }
            const profitAccountBtn = document.getElementById('bank_profit_account');
            if (profitAccountBtn) {
                profitAccountBtn.textContent = profitAccountBtn.getAttribute('data-placeholder') || 'Select Account';
                profitAccountBtn.removeAttribute('data-value');
            }
        }

        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
            document.getElementById('addProcessForm').reset();

            // 重置 multi-use 状态
            const multiUseCheckbox = document.getElementById('add_multi_use');
            const multiUsePanel = document.getElementById('multi_use_processes');
            const selectedProcessesDisplay = document.getElementById('selected_processes_display');
            const processInput = document.getElementById('add_process_id');

            if (multiUseCheckbox) {
                multiUseCheckbox.checked = false;
            }
            if (multiUsePanel) {
                multiUsePanel.style.display = 'none';
            }
            if (selectedProcessesDisplay) {
                selectedProcessesDisplay.style.display = 'none';
            }
            if (processInput) {
                processInput.disabled = false;
                processInput.style.backgroundColor = 'white';
                processInput.style.cursor = 'default';
                processInput.setAttribute('required', 'required');
            }

            // 清除所有 process 复选框
            const processCheckboxes = document.querySelectorAll('#process_checkboxes input[type="checkbox"]');
            processCheckboxes.forEach(cb => cb.checked = false);

            // 清除选中的 processes
            if (window.selectedProcesses) {
                window.selectedProcesses = [];
            }
            const selectedProcessesList = document.getElementById('selected_processes_list');
            if (selectedProcessesList) {
                selectedProcessesList.innerHTML = '';
            }

            // 清除选中的描述
            if (window.selectedDescriptions) {
                window.selectedDescriptions = [];
            }
            document.getElementById('selected_descriptions_display').style.display = 'none';
            document.getElementById('add_description').value = '';

            // 清除 All Day 复选框
            const allDayCheckbox = document.getElementById('add_all_day');
            if (allDayCheckbox) {
                allDayCheckbox.checked = false;
            }
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
            document.getElementById('editProcessForm').reset();

            // 清除 All Day 复选框
            const allDayCheckbox = document.getElementById('edit_all_day');
            if (allDayCheckbox) {
                allDayCheckbox.checked = false;
            }

            // 清除选中的描述
            if (window.selectedDescriptions) {
                window.selectedDescriptions = [];
            }
            document.getElementById('edit_selected_descriptions_display').style.display = 'none';
            document.getElementById('edit_description').value = '';
        }

        /** Bank 编辑：打开与 Add 同格式的弹窗，预填数据，提交时走 update_process */
        async function openBankEditModal(id) {
            try {
                const response = await fetch(buildApiUrl(`processlistapi.php?action=get_process&id=${id}&permission=Bank`));
                const result = await response.json();
                if (!result.success || !result.data) {
                    showNotification(result.error || 'Failed to load process data', 'danger');
                    return;
                }
                const process = result.data;
                await loadAddBankProcessData();
                document.getElementById('bank_edit_id').value = process.id;
                document.getElementById('bankModalTitle').textContent = 'Edit Process';
                document.getElementById('bankSubmitBtn').textContent = 'Update Process';
                const countrySelect = document.getElementById('bank_country');
                const bankSelect = document.getElementById('bank_bank');
                if (process.country) {
                    if (!Array.from(countrySelect.options).some(o => o.value === process.country)) {
                        const opt = document.createElement('option');
                        opt.value = process.country;
                        opt.textContent = process.country;
                        countrySelect.appendChild(opt);
                    }
                    countrySelect.value = process.country;
                    await loadBanksByCountry(process.country);
                } else {
                    countrySelect.value = '';
                    await loadBanksByCountry('');
                }
                if (process.bank) {
                    if (!Array.from(bankSelect.options).some(o => o.value === process.bank)) {
                        const opt = document.createElement('option');
                        opt.value = process.bank;
                        opt.textContent = process.bank;
                        bankSelect.appendChild(opt);
                    }
                    bankSelect.value = process.bank;
                } else {
                    bankSelect.value = '';
                }
                document.getElementById('bank_type').value = process.type || '';
                document.getElementById('bank_name').value = process.name || '';
                const cardMerchantBtn = document.getElementById('bank_card_merchant');
                const customerBtn = document.getElementById('bank_customer');
                if (cardMerchantBtn && process.card_merchant_id) {
                    cardMerchantBtn.setAttribute('data-value', process.card_merchant_id);
                    cardMerchantBtn.textContent = process.card_merchant_name || process.card_merchant_id || 'Select Account';
                } else if (cardMerchantBtn) {
                    cardMerchantBtn.removeAttribute('data-value');
                    cardMerchantBtn.textContent = cardMerchantBtn.getAttribute('data-placeholder') || 'Select Account';
                }
                if (customerBtn && process.customer_id) {
                    customerBtn.setAttribute('data-value', process.customer_id);
                    customerBtn.textContent = (process.customer_account || process.customer_name || process.customer_id) || 'Select Account';
                } else if (customerBtn) {
                    customerBtn.removeAttribute('data-value');
                    customerBtn.textContent = customerBtn.getAttribute('data-placeholder') || 'Select Account';
                }
                const profitAccountBtn = document.getElementById('bank_profit_account');
                if (profitAccountBtn && process.profit_account_id) {
                    profitAccountBtn.setAttribute('data-value', process.profit_account_id);
                    profitAccountBtn.textContent = (process.profit_account_name || process.profit_account_id) || 'Select Account';
                } else if (profitAccountBtn) {
                    profitAccountBtn.removeAttribute('data-value');
                    profitAccountBtn.textContent = profitAccountBtn.getAttribute('data-placeholder') || 'Select Account';
                }
                document.getElementById('bank_contract').value = process.contract || '';
                document.getElementById('bank_insurance').value = process.insurance != null && process.insurance !== '' ? process.insurance : '';
                document.getElementById('bank_cost').value = process.cost != null && process.cost !== '' ? process.cost : '';
                document.getElementById('bank_price').value = process.price != null && process.price !== '' ? process.price : '';
                document.getElementById('bank_profit').value = process.profit != null && process.profit !== '' ? process.profit : '';
                const dayStart = process.day_start || '';
                document.getElementById('bank_day_start').value = dayStart ? (dayStart.length === 10 ? dayStart : dayStart.split(' ')[0]) : '';
                document.getElementById('bank_profit_sharing').value = process.profit_sharing || '';
                // Parse profit_sharing string (e.g. "BB - 6, AA - 10") into selectedProfitSharingEntries
                window.selectedProfitSharingEntries = [];
                const psStr = (process.profit_sharing || '').trim();
                if (psStr) {
                    psStr.split(',').forEach(function (part) {
                        const t = part.trim();
                        const dash = t.lastIndexOf(' - ');
                        if (dash > -1) {
                            window.selectedProfitSharingEntries.push({
                                accountId: '',
                                accountText: t.substring(0, dash).trim(),
                                amount: t.substring(dash + 3).trim()
                            });
                        }
                    });
                }
                renderSelectedProfitSharing();
                document.getElementById('addBankModal').style.display = 'block';
            } catch (error) {
                console.error('Error opening bank edit modal:', error);
                showNotification('Failed to load process data', 'danger');
            }
        }

        async function editProcess(id) {
            try {
                if (selectedPermission === 'Bank') {
                    await openBankEditModal(id);
                    return;
                }
                await loadEditProcessData();
                let getProcessUrl = `processlistapi.php?action=get_process&id=${id}`;
                const response = await fetch(buildApiUrl(getProcessUrl));
                const result = await response.json();
                if (result.success && result.data) {
                    const process = result.data;
                    document.getElementById('edit_process_id').value = process.id;
                    document.getElementById('edit_description_id').value = process.description_id || '';
                    document.getElementById('edit_process_name').value = process.process_name || '';
                    document.getElementById('edit_status').value = process.status || 'active';

                    // Set currency - ensure type matching like account-list.php
                    const currencySelect = document.getElementById('edit_currency');
                    if (process.currency_id) {
                        const currencyIdStr = String(process.currency_id);
                        // Check if the option exists in the dropdown
                        const optionExists = Array.from(currencySelect.options).some(opt => opt.value === currencyIdStr);
                        if (optionExists) {
                            currencySelect.value = currencyIdStr;
                        } else {
                            console.warn('Currency ID not found in dropdown:', currencyIdStr, 'Available options:', Array.from(currencySelect.options).map(opt => opt.value));
                            if (process.currency_warning) {
                                showNotification('Warning: The original currency does not belong to your company. Please select a currency manually.', 'danger');
                            }
                        }
                    } else if (process.currency_warning) {
                        // 如果 currency_id 为空但有警告，说明原货币不属于当前公司
                        // 尝试根据货币代码自动匹配当前公司的相同货币
                        if (process.currency_code) {
                            const currencyCode = process.currency_code.toUpperCase();
                            const matchingOption = Array.from(currencySelect.options).find(opt =>
                                opt.textContent.toUpperCase() === currencyCode
                            );
                            if (matchingOption) {
                                currencySelect.value = matchingOption.value;
                                console.log('Auto-matched currency by code:', currencyCode, '-> ID:', matchingOption.value);
                            } else {
                                showNotification('Warning: The original currency (' + currencyCode + ') does not belong to your company. Please select a currency manually.', 'danger');
                            }
                        } else {
                            showNotification('Warning: The original currency does not belong to your company. Please select a currency manually.', 'danger');
                        }
                    }

                    document.getElementById('edit_remove_words').value = process.remove_word || '';

                    // Handle replace word fields
                    if (process.replace_word) {
                        const parts = process.replace_word.split(' == ');
                        document.getElementById('edit_replace_word_from').value = parts[0] || '';
                        document.getElementById('edit_replace_word_to').value = parts[1] || '';
                    } else {
                        document.getElementById('edit_replace_word_from').value = '';
                        document.getElementById('edit_replace_word_to').value = '';
                    }

                    // Handle remarks
                    if (process.remarks) {
                        try {
                            const meta = JSON.parse(process.remarks);
                            let remarksText = '';
                            if (meta.user_remarks) {
                                remarksText = meta.user_remarks;
                            }
                            document.getElementById('edit_remarks').value = remarksText;
                        } catch (e) {
                            document.getElementById('edit_remarks').value = process.remarks;
                        }
                    }

                    // Handle day use checkboxes
                    if (process.day_use) {
                        const dayIdsArray = process.day_use.split(',');
                        dayIdsArray.forEach(dayId => {
                            const checkbox = document.querySelector(`#edit_day_checkboxes input[name="edit_day_use[]"][value="${dayId.trim()}"]`);
                            if (checkbox) checkbox.checked = true;
                        });
                        // 更新 All Day 复选框状态
                        updateAllDayCheckbox('edit');
                    }

                    // Handle description - initialize selected descriptions
                    const descInput = document.getElementById('edit_description');
                    let descriptionNames = [];

                    if (process.description_names && Array.isArray(process.description_names) && process.description_names.length > 0) {
                        descriptionNames = process.description_names;
                    } else if (process.description_names && typeof process.description_names === 'string') {
                        // 如果是逗号分隔的字符串，分割它
                        descriptionNames = process.description_names.split(',').map(d => d.trim()).filter(d => d);
                    } else if (process.description_name) {
                        descriptionNames = [process.description_name];
                    }

                    // 初始化选中的描述
                    window.selectedDescriptions = descriptionNames;

                    if (descInput) {
                        if (descriptionNames.length > 0) {
                            descInput.value = `${descriptionNames.length} description(s) selected`;
                            // 显示选中的描述列表
                            displayEditSelectedDescriptions(descriptionNames);
                        } else {
                            descInput.value = '';
                        }
                    }

                    // Populate read-only information fields (date on left, user on right)
                    const dtsModified = process.dts_modified || '';
                    const modifiedBy = process.modified_by || '';
                    const dtsCreated = process.dts_created || '';
                    const createdBy = process.created_by || '';

                    // DTS Modified 只有在真正修改过时才显示（不为空且不等于创建时间）
                    // 如果为空或等于创建时间，表示从未修改过，显示为空
                    let displayModifiedDate = '';
                    let displayModifiedBy = '';
                    if (dtsModified && dtsModified !== dtsCreated) {
                        displayModifiedDate = dtsModified;
                        displayModifiedBy = modifiedBy || '';
                    }

                    document.getElementById('edit_dts_modified_date').textContent = displayModifiedDate;
                    document.getElementById('edit_dts_modified_user').textContent = displayModifiedBy;
                    document.getElementById('edit_dts_created_date').textContent = dtsCreated || '';
                    document.getElementById('edit_dts_created_user').textContent = createdBy || '';

                    // Show modal
                    document.getElementById('editModal').style.display = 'block';
                } else {
                    showNotification('Failed to load process data: ' + (result.error || 'Unknown error'), 'danger');
                }
            } catch (error) {
                console.error('Error loading process data:', error);
                showNotification('Failed to load process data', 'danger');
            }
        }

        // 存储待删除的 ID 列表
        let pendingDeleteIds = [];

        function deleteSelected() {
            const selectedCheckboxes = document.querySelectorAll('.row-checkbox:checked:not([disabled])');

            if (selectedCheckboxes.length === 0) {
                showNotification('Please select processes to delete', 'danger');
                return;
            }

            // 收集选中的 ID
            pendingDeleteIds = Array.from(selectedCheckboxes).map(cb => cb.dataset.id);

            // 显示确认对话框
            const message = `Are you sure you want to delete ${pendingDeleteIds.length} process(es)? This action cannot be undone.`;
            document.getElementById('confirmDeleteMessage').textContent = message;
            document.getElementById('confirmDeleteModal').style.display = 'block';
        }

        // 全选/取消全选所有流程
        function toggleSelectAllBankProcesses() {
            const selectAllCheckbox = document.getElementById('selectAllBankProcesses');
            if (!selectAllCheckbox) {
                console.error('selectAllBankProcesses checkbox not found');
                return;
            }

            const allCheckboxes = Array.from(document.querySelectorAll('.bank-checkbox')).filter(cb => !cb.disabled);
            console.log('Found bank checkboxes:', allCheckboxes.length, 'Select all checked:', selectAllCheckbox.checked);

            allCheckboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });

            updateDeleteButton();
        }

        function toggleSelectAllProcesses() {
            const selectAllCheckbox = document.getElementById('selectAllProcesses');
            if (!selectAllCheckbox) {
                console.error('selectAllProcesses checkbox not found');
                return;
            }

            // 根据类别选择不同的复选框
            let allCheckboxes;
            if (selectedPermission === 'Bank') {
                allCheckboxes = Array.from(document.querySelectorAll('.bank-checkbox')).filter(cb => !cb.disabled);
            } else {
                allCheckboxes = Array.from(document.querySelectorAll('.row-checkbox:not(.bank-checkbox)')).filter(cb => !cb.disabled);
            }

            console.log('Found checkboxes:', allCheckboxes.length, 'Select all checked:', selectAllCheckbox.checked);

            allCheckboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });

            updateDeleteButton();
        }

        // 根据当前页面是否有可删除项，显示/隐藏全选框（Bank 用 visibility 保留表头空间，避免错位）
        function updateSelectAllProcessesVisibility() {
            if (selectedPermission === 'Bank') {
                const selectAllBankCheckbox = document.getElementById('selectAllBankProcesses');
                if (!selectAllBankCheckbox) return;

                const anyBankCheckbox = document.querySelectorAll('.bank-checkbox').length > 0;
                selectAllBankCheckbox.style.visibility = anyBankCheckbox ? 'visible' : 'hidden';
                selectAllBankCheckbox.style.display = 'inline-block';
                selectAllBankCheckbox.disabled = !anyBankCheckbox;
                if (!anyBankCheckbox) {
                    selectAllBankCheckbox.checked = false;
                }
            } else {
                const selectAllCheckbox = document.getElementById('selectAllProcesses');
                if (!selectAllCheckbox) return;

                const anyRowCheckbox = document.querySelectorAll('.row-checkbox:not(.bank-checkbox)').length > 0;
                selectAllCheckbox.style.display = anyRowCheckbox ? 'inline-block' : 'none';
                if (!anyRowCheckbox) {
                    selectAllCheckbox.checked = false;
                }
            }
        }

        function updateDeleteButton() {
            // 根据类别选择不同的复选框
            let selectedCheckboxes;
            let allCheckboxes;
            let selectAllCheckbox;

            if (selectedPermission === 'Bank') {
                selectedCheckboxes = document.querySelectorAll('.bank-checkbox:checked');
                allCheckboxes = Array.from(document.querySelectorAll('.bank-checkbox')).filter(cb => !cb.disabled);
                selectAllCheckbox = document.getElementById('selectAllBankProcesses');
            } else {
                selectedCheckboxes = document.querySelectorAll('.row-checkbox:not(.bank-checkbox):checked');
                allCheckboxes = Array.from(document.querySelectorAll('.row-checkbox:not(.bank-checkbox)')).filter(cb => !cb.disabled);
                selectAllCheckbox = document.getElementById('selectAllProcesses');
            }

            const deleteBtn = document.getElementById('processDeleteSelectedBtn');

            // 更新全选 checkbox 状态
            if (selectAllCheckbox && allCheckboxes.length > 0) {
                const allSelected = allCheckboxes.length > 0 &&
                    allCheckboxes.every(cb => cb.checked);
                selectAllCheckbox.checked = allSelected;
            }

            let deleteEnabled = false;
            if (selectedPermission === 'Bank' && selectedCheckboxes.length > 0) {
                const hasInactive = Array.from(selectedCheckboxes).some(cb => {
                    const row = cb.closest('tr');
                    return row && row.getAttribute('data-status') !== 'active';
                });
                deleteEnabled = hasInactive;
            } else if (selectedCheckboxes.length > 0) {
                deleteEnabled = true;
            }

            if (selectedCheckboxes.length > 0) {
                deleteBtn.textContent = `Delete (${selectedCheckboxes.length})`;
                deleteBtn.disabled = !deleteEnabled;
            } else {
                deleteBtn.textContent = 'Delete';
                deleteBtn.disabled = true;
            }

            updatePostToTransactionButton();
        }

        function updatePostToTransactionButton() {
            const postBtn = document.getElementById('processPostToTransactionBtn');
            if (!postBtn) return;
            postBtn.style.display = selectedPermission === 'Bank' ? 'inline-block' : 'none';
            if (selectedPermission !== 'Bank') {
                postBtn.disabled = true;
                return;
            }
            const selectedCheckboxes = document.querySelectorAll('.bank-checkbox:checked');
            const activeSelectedIds = Array.from(selectedCheckboxes).filter(cb => {
                const row = cb.closest('tr');
                return row && row.getAttribute('data-status') === 'active';
            }).map(cb => cb.dataset.id);
            postBtn.disabled = activeSelectedIds.length === 0;
            postBtn.textContent = activeSelectedIds.length > 0 ? `Post to Transaction (${activeSelectedIds.length})` : 'Post to Transaction';
        }

        async function postToTransactionSelected() {
            const selectedCheckboxes = document.querySelectorAll('.bank-checkbox:checked');
            const activeSelectedIds = Array.from(selectedCheckboxes).filter(cb => {
                const row = cb.closest('tr');
                return row && row.getAttribute('data-status') === 'active';
            }).map(cb => cb.dataset.id);
            if (activeSelectedIds.length === 0) {
                showNotification('请先勾选要入账的 Process（仅 active 的 Process 可入账）', 'warning');
                return;
            }
            if (!confirm('确定将选中的 ' + activeSelectedIds.length + ' 个 Process 入账？\n\nBuy Price → Supplier 账户\nSell Price → Customer 账户\nProfit → Company 账户\n\n将在 Transaction 页面生成对应交易记录。')) {
                return;
            }
            try {
                const formData = new FormData();
                activeSelectedIds.forEach(id => formData.append('ids[]', id));
                const response = await fetch(buildApiUrl('process_post_to_transaction_api.php'), {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    showNotification(result.message || '入账成功', 'success');
                    updateDeleteButton();
                    fetchProcesses();
                } else {
                    showNotification(result.error || '入账失败', 'danger');
                }
            } catch (err) {
                console.error('Post to transaction error:', err);
                showNotification('入账请求失败: ' + err.message, 'danger');
            }
        }

        // 切换流程状态
        async function toggleProcessStatus(processId, currentStatus) {
            try {
                const formData = new FormData();
                formData.append('id', processId);
                if (selectedPermission === 'Bank') {
                    formData.append('permission', 'Bank');
                }
                const response = await fetch(buildApiUrl('toggleprocessstatusapi.php'), {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    const process = processes.find(p => p.id === processId);
                    if (process) {
                        process.status = result.newStatus;
                        if (result.newDayEnd) process.day_end = result.newDayEnd;
                    }

                    const shouldShow = showAll ? true : (showInactive ? result.newStatus === 'inactive' : result.newStatus === 'active');

                    if (!shouldShow) {
                        const processIndex = processes.findIndex(p => p.id === processId);
                        if (processIndex > -1) processes.splice(processIndex, 1);
                        renderTable();
                    } else if (result.newDayEnd) {
                        // If day_end changed, we must re-render to update the Date cell and Contract class logic
                        renderTable();
                    } else {
                        // Manual DOM update for simple status change
                        const statusClass = result.newStatus === 'active' ? 'status-active' : (result.newStatus === 'waiting' ? 'status-waiting' : 'status-inactive');
                        const statusBadge = `<span class="role-badge ${statusClass} status-clickable" onclick="toggleProcessStatus(${processId}, '${result.newStatus}')" title="Click to toggle status" style="cursor: pointer;">${escapeHtml(result.newStatus.toUpperCase())}</span>`;
                        const actionCellHtml = '<button class="edit-btn" onclick="editProcess(' + processId + ')" aria-label="Edit" title="Edit"><img src="images/edit.svg" alt="Edit" /></button>' +
                            '<input type="checkbox" class="row-checkbox bank-checkbox" data-id="' + processId + '" title="' + (result.newStatus === 'active' ? 'Select for post to transaction' : 'Select for deletion') + '" onchange="updateDeleteButton(); updatePostToTransactionButton();" style="margin-left: 10px;">';

                        if (selectedPermission === 'Bank') {
                            const row = document.querySelector('#bankTableBody tr[data-id="' + processId + '"]');
                            if (row) {
                                row.setAttribute('data-status', result.newStatus || '');
                                const cells = row.querySelectorAll('td');
                                if (cells.length >= 15) {
                                    cells[12].innerHTML = statusBadge;
                                    cells[14].innerHTML = actionCellHtml;
                                }
                            }
                        } else {
                            const card = document.querySelector(`.process-card[data-id="${processId}"]`);
                            if (card) {
                                const items = card.querySelectorAll('.card-item');
                                if (items.length > 3) {
                                    items[3].innerHTML = statusBadge;
                                    const actionCell = items[6];
                                    if (actionCell) {
                                        const existingCheckbox = actionCell.querySelector('.row-checkbox');
                                        if (result.newStatus === 'active') {
                                            if (existingCheckbox) existingCheckbox.remove();
                                        } else {
                                            if (!existingCheckbox) {
                                                const checkbox = document.createElement('input');
                                                checkbox.type = 'checkbox';
                                                checkbox.className = 'row-checkbox';
                                                checkbox.dataset.id = String(processId);
                                                checkbox.title = 'Select for deletion';
                                                checkbox.style.marginLeft = '10px';
                                                checkbox.onchange = updateDeleteButton;
                                                actionCell.appendChild(checkbox);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }


                    updateDeleteButton();
                    updateSelectAllProcessesVisibility();

                    const statusText = result.newStatus === 'active' ? 'activated' : 'deactivated';
                    showNotification(`Process status changed to ${statusText}`, 'success');
                } else {
                    showNotification(result.error || 'Status toggle failed', 'danger');
                }
            } catch (error) {
                console.error('Error:', error);
                showNotification('Status toggle failed', 'danger');
            }
        }

        // 全局变量：当前描述选择模式（'add' 或 'edit'）
        let descriptionSelectionMode = 'add';

        function expandDescription() {
            descriptionSelectionMode = 'add';
            loadExistingDescriptions();
            updateSelectedDescriptionsInModal();
            const modal = document.getElementById('descriptionSelectionModal');
            if (modal) modal.style.display = 'block';
        }

        function expandEditDescription() {
            descriptionSelectionMode = 'edit';
            loadExistingDescriptions();
            updateSelectedDescriptionsInModal();
            const modal = document.getElementById('descriptionSelectionModal');
            if (modal) modal.style.display = 'block';
        }

        async function loadExistingDescriptions() {
            try {
                const response = await fetch(buildApiUrl('addprocessapi.php'));
                const result = await response.json();
                if (result.success) {
                    const descriptionsList = document.getElementById('existingDescriptions');
                    if (!descriptionsList) return;
                    descriptionsList.innerHTML = '';
                    if (Array.isArray(result.descriptions) && result.descriptions.length > 0) {
                        result.descriptions.forEach(description => {
                            const item = document.createElement('div');
                            item.className = 'description-item';

                            const left = document.createElement('div');
                            left.className = 'description-item-left';

                            const checkbox = document.createElement('input');
                            checkbox.type = 'checkbox';
                            checkbox.name = 'available_descriptions';
                            checkbox.value = description.name;
                            checkbox.id = `desc_${description.id}`;
                            checkbox.dataset.descriptionId = description.id;

                            const label = document.createElement('label');
                            label.htmlFor = `desc_${description.id}`;
                            label.textContent = description.name.toUpperCase();

                            left.appendChild(checkbox);
                            left.appendChild(label);

                            const deleteBtn = document.createElement('button');
                            deleteBtn.type = 'button';
                            deleteBtn.className = 'description-delete-btn';
                            deleteBtn.title = 'Delete description';
                            deleteBtn.setAttribute('aria-label', 'Delete description');
                            deleteBtn.innerHTML = '&times;';
                            deleteBtn.addEventListener('click', (e) => {
                                e.preventDefault();
                                e.stopPropagation();
                                deleteDescription(description.id, description.name, item);
                            });

                            item.appendChild(left);
                            item.appendChild(deleteBtn);
                            descriptionsList.appendChild(item);

                            checkbox.addEventListener('change', function () {
                                if (this.checked) {
                                    moveDescriptionToSelected(this);
                                } else {
                                    moveDescriptionToAvailable(this);
                                }
                            });

                            // 如果是编辑模式且该描述已被选中，自动选中并移动到已选择列表
                            if (descriptionSelectionMode === 'edit' && window.selectedDescriptions && window.selectedDescriptions.includes(description.name)) {
                                checkbox.checked = true;
                                moveDescriptionToSelected(checkbox);
                            }
                        });
                    } else {
                        descriptionsList.innerHTML = '<div class="no-descriptions">No descriptions found</div>';
                    }
                } else {
                    showNotification('Failed to load descriptions: ' + (result.error || 'Unknown error'), 'danger');
                }
            } catch (e) {
                console.error('Error loading descriptions:', e);
                showNotification('Failed to load descriptions', 'danger');
            }
        }

        function updateSelectedDescriptionsInModal() {
            const selectedList = document.getElementById('selectedDescriptionsInModal');
            if (!selectedList) return;
            selectedList.innerHTML = '';
            const selections = Array.isArray(window.selectedDescriptions) ? window.selectedDescriptions : [];
            if (selections.length > 0) {
                selections.forEach((desc, idx) => {
                    const div = document.createElement('div');
                    div.className = 'selected-description-modal-item';
                    div.innerHTML = `
                        <span>${desc.toUpperCase()}</span>
                        <button type="button" class="remove-description-modal" onclick="moveDescriptionBackToAvailable('${desc}', '${Date.now() + idx}')">&times;</button>
                    `;
                    selectedList.appendChild(div);
                });
            } else {
                selectedList.innerHTML = '<div class="no-descriptions">No descriptions selected</div>';
            }
        }

        function moveDescriptionToSelected(checkbox) {
            const descriptionName = checkbox.value;
            const descriptionId = checkbox.dataset.descriptionId;
            const descriptionItem = checkbox.closest('.description-item');
            if (!Array.isArray(window.selectedDescriptions)) window.selectedDescriptions = [];
            if (!window.selectedDescriptions.includes(descriptionName)) {
                window.selectedDescriptions.push(descriptionName);
            }
            const selectedList = document.getElementById('selectedDescriptionsInModal');
            // remove placeholder
            const placeholder = selectedList.querySelector('.no-descriptions');
            if (placeholder) placeholder.remove();
            const newItem = document.createElement('div');
            newItem.className = 'selected-description-modal-item';
            newItem.innerHTML = `
                <span>${descriptionName.toUpperCase()}</span>
                <button type="button" class="remove-description-modal" onclick="moveDescriptionBackToAvailable('${descriptionName}', '${descriptionId}')">&times;</button>
            `;
            selectedList.appendChild(newItem);
            // remove from available list
            if (descriptionItem) descriptionItem.remove();
        }

        function moveDescriptionToAvailable(checkbox) {
            const descriptionName = checkbox.value;
            const descriptionId = checkbox.dataset.descriptionId;
            const descriptionItem = checkbox.closest('.description-item');

            // Remove from selected descriptions array
            if (window.selectedDescriptions) {
                const index = window.selectedDescriptions.indexOf(descriptionName);
                if (index > -1) {
                    window.selectedDescriptions.splice(index, 1);
                }
            }

            // Remove from selected list
            const selectedList = document.getElementById('selectedDescriptionsInModal');
            const selectedItems = selectedList.querySelectorAll('.selected-description-modal-item');
            selectedItems.forEach(item => {
                if (item.querySelector('span').textContent === descriptionName) {
                    item.remove();
                }
            });
            if (!selectedList.querySelector('.selected-description-modal-item')) {
                const empty = document.createElement('div');
                empty.className = 'no-descriptions';
                empty.textContent = 'No descriptions selected';
                selectedList.appendChild(empty);
            }
        }

        function moveDescriptionBackToAvailable(descriptionName, descriptionId) {
            // remove from selected list
            if (Array.isArray(window.selectedDescriptions)) {
                const idx = window.selectedDescriptions.indexOf(descriptionName);
                if (idx > -1) window.selectedDescriptions.splice(idx, 1);
            }
            const selectedList = document.getElementById('selectedDescriptionsInModal');
            selectedList.querySelectorAll('.selected-description-modal-item').forEach(item => {
                if (item.querySelector('span')?.textContent === descriptionName) item.remove();
            });
            if (!selectedList.querySelector('.selected-description-modal-item')) {
                const empty = document.createElement('div');
                empty.className = 'no-descriptions';
                empty.textContent = 'No descriptions selected';
                selectedList.appendChild(empty);
            }
            // add back to available list
            const list = document.getElementById('existingDescriptions');
            if (list) {
                const item = document.createElement('div');
                item.className = 'description-item';

                const left = document.createElement('div');
                left.className = 'description-item-left';

                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.name = 'available_descriptions';
                cb.value = descriptionName;
                cb.id = `desc_${descriptionId}`;
                cb.dataset.descriptionId = descriptionId;

                const label = document.createElement('label');
                label.htmlFor = `desc_${descriptionId}`;
                label.textContent = descriptionName.toUpperCase();

                left.appendChild(cb);
                left.appendChild(label);

                const deleteBtn = document.createElement('button');
                deleteBtn.type = 'button';
                deleteBtn.className = 'description-delete-btn';
                deleteBtn.title = 'Delete description';
                deleteBtn.setAttribute('aria-label', 'Delete description');
                deleteBtn.innerHTML = '&times;';
                deleteBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    deleteDescription(descriptionId, descriptionName, item);
                });

                item.appendChild(left);
                item.appendChild(deleteBtn);
                list.appendChild(item);

                cb.addEventListener('change', function () {
                    if (this.checked) moveDescriptionToSelected(this);
                    else moveDescriptionToAvailable(this);
                });
            }
        }

        async function deleteDescription(descriptionId, descriptionName, itemElement) {
            if (!descriptionId) return;
            const confirmed = confirm(`Are you sure you want to delete description ${descriptionName}? This action cannot be undone.`);
            if (!confirmed) return;

            try {
                const formData = new FormData();
                formData.append('action', 'delete_description');
                formData.append('description_id', descriptionId);

                const response = await fetch(buildApiUrl('addprocessapi.php'), {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                if (result.success) {
                    if (itemElement && itemElement.parentNode) {
                        itemElement.remove();
                    }

                    if (Array.isArray(window.selectedDescriptions)) {
                        window.selectedDescriptions = window.selectedDescriptions.filter(desc => desc !== descriptionName);
                    }

                    updateSelectedDescriptionsInModal();

                    // 根据当前模式更新相应的显示
                    if (descriptionSelectionMode === 'edit') {
                        displayEditSelectedDescriptions(window.selectedDescriptions || []);
                        const editDescInput = document.getElementById('edit_description');
                        if (editDescInput) {
                            editDescInput.value = (window.selectedDescriptions && window.selectedDescriptions.length > 0)
                                ? `${window.selectedDescriptions.length} description(s) selected`
                                : '';
                        }
                    } else {
                        displaySelectedDescriptions(window.selectedDescriptions || []);
                        const addDescInput = document.getElementById('add_description');
                        if (addDescInput) {
                            addDescInput.value = (window.selectedDescriptions && window.selectedDescriptions.length > 0)
                                ? `${window.selectedDescriptions.length} description(s) selected`
                                : '';
                        }
                    }

                    const descriptionsList = document.getElementById('existingDescriptions');
                    if (descriptionsList && !descriptionsList.querySelector('.description-item')) {
                        descriptionsList.innerHTML = '<div class="no-descriptions">No descriptions found</div>';
                    }

                    showNotification('Description deleted successfully', 'success');
                } else {
                    showNotification(result.error || 'Failed to delete description', 'danger');
                }
            } catch (error) {
                console.error('Error deleting description:', error);
                showNotification('Failed to delete description', 'danger');
            }
        }

        function closeDescriptionSelectionModal() {
            document.getElementById('descriptionSelectionModal').style.display = 'none';
        }

        // 加载添加表单所需的数据
        async function loadAddProcessData() {
            try {
                const response = await fetch(buildApiUrl('addprocessapi.php'));
                const result = await response.json();

                if (result.success) {
                    // 填充 currency 下拉列表
                    const currencySelect = document.getElementById('add_currency');
                    currencySelect.innerHTML = '<option value="">Select Currency</option>';
                    result.currencies.forEach(currency => {
                        const option = document.createElement('option');
                        option.value = currency.id;
                        option.textContent = currency.code;
                        currencySelect.appendChild(option);
                    });

                    // 填充 copy from 下拉列表
                    const copyFromSelect = document.getElementById('add_copy_from');
                    copyFromSelect.innerHTML = '<option value="">Select Process to Copy From</option>';
                    if (result.existingProcesses && result.existingProcesses.length > 0) {
                        // 按 A-Z 排序：先按 process_name 排序，如果相同则按 description_name 排序
                        const sortedProcesses = [...result.existingProcesses].sort((a, b) => {
                            const aName = (a.process_name || 'Unknown').toUpperCase();
                            const bName = (b.process_name || 'Unknown').toUpperCase();
                            if (aName !== bName) {
                                return aName.localeCompare(bName);
                            }
                            const aDesc = (a.description_name || 'No Description').toUpperCase();
                            const bDesc = (b.description_name || 'No Description').toUpperCase();
                            return aDesc.localeCompare(bDesc);
                        });

                        sortedProcesses.forEach(process => {
                            const option = document.createElement('option');
                            option.value = process.process_id;
                            option.textContent = `${process.process_name || 'Unknown'} - ${process.description_name || 'No Description'}`;
                            copyFromSelect.appendChild(option);
                        });
                    }

                    // 填充 process 复选框（用于 multi-use）
                    const processCheckboxes = document.getElementById('process_checkboxes');
                    if (processCheckboxes) {
                        processCheckboxes.innerHTML = '';
                        if (result.processes && result.processes.length > 0) {
                            // 获取唯一的process_id列表
                            const uniqueProcessIds = [...new Set(result.processes.map(p => p.process_name))];
                            uniqueProcessIds.forEach(processId => {
                                const checkboxItem = document.createElement('div');
                                checkboxItem.className = 'checkbox-item';
                                checkboxItem.innerHTML = `
                                    <input type="checkbox" id="process_${processId}" name="selected_processes[]" value="${processId}">
                                    <label for="process_${processId}">${processId}</label>
                                `;
                                processCheckboxes.appendChild(checkboxItem);
                            });

                            // 添加process复选框变化监听器
                            const processCheckboxesInputs = processCheckboxes.querySelectorAll('input[type="checkbox"]');
                            processCheckboxesInputs.forEach(checkbox => {
                                checkbox.addEventListener('change', function () {
                                    updateSelectedProcessesDisplay();
                                });
                            });
                        }
                    }

                    // 填充 day 复选框
                    const dayCheckboxes = document.getElementById('day_checkboxes');
                    dayCheckboxes.innerHTML = '';
                    if (result.days && result.days.length > 0) {
                        result.days.forEach(day => {
                            const checkboxItem = document.createElement('div');
                            checkboxItem.className = 'checkbox-item';
                            checkboxItem.innerHTML = `
                                <input type="checkbox" id="add_day_${day.id}" name="day_use[]" value="${day.id}">
                                <label for="add_day_${day.id}">${day.day_name}</label>
                            `;
                            dayCheckboxes.appendChild(checkboxItem);
                        });

                        // 为每个 day 复选框添加事件监听器
                        dayCheckboxes.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                            checkbox.addEventListener('change', function () {
                                updateAllDayCheckbox('add');
                            });
                        });
                    }

                    // 为 All Day 复选框添加事件监听器
                    const allDayCheckbox = document.getElementById('add_all_day');
                    if (allDayCheckbox) {
                        allDayCheckbox.addEventListener('change', function () {
                            const dayCheckboxes = document.querySelectorAll('#day_checkboxes input[type="checkbox"]');
                            dayCheckboxes.forEach(checkbox => {
                                checkbox.checked = this.checked;
                            });
                        });
                    }
                } else {
                    showNotification('Failed to load form data: ' + result.error, 'danger');
                }
            } catch (error) {
                console.error('Error loading form data:', error);
                showNotification('Failed to load form data', 'danger');
            }
        }

        // Load edit form data (currencies, days, etc.)
        async function loadEditProcessData() {
            try {
                const response = await fetch(buildApiUrl('addprocessapi.php'));
                const result = await response.json();

                if (result.success) {
                    // Populate currency dropdown
                    const currencySelect = document.getElementById('edit_currency');
                    currencySelect.innerHTML = '<option value="">Select Currency</option>';
                    result.currencies.forEach(currency => {
                        const option = document.createElement('option');
                        option.value = currency.id;
                        option.textContent = currency.code;
                        currencySelect.appendChild(option);
                    });

                    // Populate day checkboxes
                    const dayCheckboxes = document.getElementById('edit_day_checkboxes');
                    dayCheckboxes.innerHTML = '';
                    if (result.days && result.days.length > 0) {
                        result.days.forEach(day => {
                            const checkboxItem = document.createElement('div');
                            checkboxItem.className = 'checkbox-item';
                            checkboxItem.innerHTML = `
                                <input type="checkbox" id="edit_day_${day.id}" name="edit_day_use[]" value="${day.id}">
                                <label for="edit_day_${day.id}">${day.day_name}</label>
                            `;
                            dayCheckboxes.appendChild(checkboxItem);
                        });

                        // 为每个 day 复选框添加事件监听器
                        dayCheckboxes.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                            checkbox.addEventListener('change', function () {
                                updateAllDayCheckbox('edit');
                            });
                        });
                    }

                    // 为 All Day 复选框添加事件监听器
                    const allDayCheckbox = document.getElementById('edit_all_day');
                    if (allDayCheckbox) {
                        allDayCheckbox.addEventListener('change', function () {
                            const dayCheckboxes = document.querySelectorAll('#edit_day_checkboxes input[type="checkbox"]');
                            dayCheckboxes.forEach(checkbox => {
                                checkbox.checked = this.checked;
                            });
                        });
                    }
                } else {
                    showNotification('Failed to load form data: ' + result.error, 'danger');
                }
            } catch (error) {
                console.error('Error loading edit form data:', error);
                showNotification('Failed to load form data', 'danger');
            }
        }

        function confirmDescriptions() {
            if (window.selectedDescriptions && window.selectedDescriptions.length > 0) {
                if (descriptionSelectionMode === 'edit') {
                    // 编辑模式：更新编辑表单的字段
                    const editDescInput = document.getElementById('edit_description');
                    if (editDescInput) {
                        editDescInput.value = `${window.selectedDescriptions.length} description(s) selected`;
                    }
                    // 显示选中的描述列表
                    displayEditSelectedDescriptions(window.selectedDescriptions);
                } else {
                    // 添加模式：更新添加表单的字段
                    document.getElementById('add_description').value = `${window.selectedDescriptions.length} description(s) selected`;
                    // Display selected descriptions
                    displaySelectedDescriptions(window.selectedDescriptions);
                }

                closeDescriptionSelectionModal();
            } else {
                showNotification('Please select at least one description', 'danger');
            }
        }

        function filterDescriptions() {
            const term = (document.getElementById('descriptionSearch')?.value || '').toLowerCase();
            const items = document.querySelectorAll('#existingDescriptions .description-item');
            items.forEach(item => {
                const text = item.querySelector('label')?.textContent?.toLowerCase() || '';
                item.style.display = text.includes(term) ? 'block' : 'none';
            });
        }

        // Display selected descriptions
        function displaySelectedDescriptions(descriptions) {
            const displayDiv = document.getElementById('selected_descriptions_display');
            const listDiv = document.getElementById('selected_descriptions_list');

            if (descriptions.length > 0) {
                displayDiv.style.display = 'block';
                listDiv.innerHTML = '';

                descriptions.forEach((desc, index) => {
                    const descItem = document.createElement('div');
                    descItem.className = 'selected-description-item';
                    descItem.innerHTML = `
                        <span>${desc.toUpperCase()}</span>
                        <button type="button" class="remove-description" onclick="removeDescription(${index})">&times;</button>
                    `;
                    listDiv.appendChild(descItem);
                });

                // Store selected descriptions for form submission
                window.selectedDescriptions = descriptions;
            } else {
                displayDiv.style.display = 'none';
                window.selectedDescriptions = [];
            }
        }

        // Display selected descriptions for edit mode
        function displayEditSelectedDescriptions(descriptions) {
            const displayDiv = document.getElementById('edit_selected_descriptions_display');
            const listDiv = document.getElementById('edit_selected_descriptions_list');

            if (descriptions.length > 0) {
                displayDiv.style.display = 'block';
                listDiv.innerHTML = '';

                descriptions.forEach((desc, index) => {
                    const descItem = document.createElement('div');
                    descItem.className = 'selected-description-item';
                    descItem.innerHTML = `
                        <span>${desc.toUpperCase()}</span>
                        <button type="button" class="remove-description" onclick="removeEditDescription(${index})">&times;</button>
                    `;
                    listDiv.appendChild(descItem);
                });

                // Store selected descriptions for form submission
                window.selectedDescriptions = descriptions;
            } else {
                displayDiv.style.display = 'none';
                window.selectedDescriptions = [];
            }
        }

        // Remove a description from selection
        function removeDescription(index) {
            if (window.selectedDescriptions) {
                window.selectedDescriptions.splice(index, 1);
                displaySelectedDescriptions(window.selectedDescriptions);

                // Update input field
                if (window.selectedDescriptions.length > 0) {
                    document.getElementById('add_description').value = `${window.selectedDescriptions.length} description(s) selected`;
                } else {
                    document.getElementById('add_description').value = '';
                    document.getElementById('selected_descriptions_display').style.display = 'none';
                }
            }
        }

        // Remove a description from edit selection
        function removeEditDescription(index) {
            if (window.selectedDescriptions) {
                window.selectedDescriptions.splice(index, 1);
                displayEditSelectedDescriptions(window.selectedDescriptions);

                // Update input field
                const editDescInput = document.getElementById('edit_description');
                if (editDescInput) {
                    if (window.selectedDescriptions.length > 0) {
                        editDescInput.value = `${window.selectedDescriptions.length} description(s) selected`;
                    } else {
                        editDescInput.value = '';
                        document.getElementById('edit_selected_descriptions_display').style.display = 'none';
                    }
                }
            }
        }

        // ===== Multi-use (process_id) helpers =====
        function updateSelectedProcessesDisplay() {
            const selectedCheckboxes = document.querySelectorAll('#process_checkboxes input[type="checkbox"]:checked');
            const displayDiv = document.getElementById('selected_processes_display');
            const listDiv = document.getElementById('selected_processes_list');
            if (!displayDiv || !listDiv) return;
            if (selectedCheckboxes.length > 0) {
                displayDiv.style.display = 'block';
                listDiv.innerHTML = '';
                const selected = [];
                selectedCheckboxes.forEach(cb => {
                    const pid = cb.value;
                    selected.push(pid);
                    const item = document.createElement('div');
                    item.className = 'selected-process-item';
                    item.innerHTML = `
                        <span>${pid}</span>
                        <button type="button" class="remove-process" onclick="removeProcess('${pid}')">&times;</button>
                    `;
                    listDiv.appendChild(item);
                });
                window.selectedProcesses = selected;
            } else {
                displayDiv.style.display = 'none';
                listDiv.innerHTML = '';
                if (window.selectedProcesses) window.selectedProcesses = [];
            }
        }

        function confirmMultiUseProcessSelection() {
            updateSelectedProcessesDisplay();
            const panel = document.getElementById('multi_use_processes');
            if (panel) panel.style.display = 'none';
            const displayDiv = document.getElementById('selected_processes_display');
            if (displayDiv) displayDiv.style.display = (window.selectedProcesses && window.selectedProcesses.length > 0) ? 'block' : 'none';
        }

        function removeProcess(processId) {
            const cb = document.querySelector(`#process_checkboxes input[type="checkbox"][value="${CSS.escape(processId)}"]`);
            if (cb) {
                cb.checked = false;
                updateSelectedProcessesDisplay();
            }
        }

        function closeConfirmDeleteModal() {
            document.getElementById('confirmDeleteModal').style.display = 'none';
        }

        async function confirmDelete() {
            if (pendingDeleteIds.length === 0) {
                closeConfirmDeleteModal();
                return;
            }

            try {
                // 创建 FormData 发送删除请求
                // PHP 期望 $_POST['ids'] 为数组，使用 ids[] 格式
                const formData = new FormData();
                pendingDeleteIds.forEach(id => {
                    formData.append('ids[]', id);
                });

                // 提交表单（使用表单提交以便跟随重定向）
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = buildApiUrl('processlist.php').toString();
                pendingDeleteIds.forEach(id => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'ids[]';
                    input.value = id;
                    form.appendChild(input);
                });
                if (selectedPermission === 'Bank') {
                    const permInput = document.createElement('input');
                    permInput.type = 'hidden';
                    permInput.name = 'permission';
                    permInput.value = 'Bank';
                    form.appendChild(permInput);
                }
                document.body.appendChild(form);
                form.submit();

            } catch (error) {
                console.error('Delete error:', error);
                showNotification('Delete failed: ' + error.message, 'danger');
                closeConfirmDeleteModal();
                pendingDeleteIds = [];
            }
        }

        // 更新 All Day 复选框状态
        function updateAllDayCheckbox(mode) {
            const prefix = mode === 'add' ? 'add' : 'edit';
            const allDayCheckbox = document.getElementById(`${prefix}_all_day`);
            const dayCheckboxes = document.querySelectorAll(`#${prefix === 'add' ? 'day_checkboxes' : 'edit_day_checkboxes'} input[type="checkbox"]`);

            if (allDayCheckbox && dayCheckboxes.length > 0) {
                const allChecked = Array.from(dayCheckboxes).every(checkbox => checkbox.checked);
                allDayCheckbox.checked = allChecked;
            }
        }

        // 强制输入大写字母
        function forceUppercase(input) {
            const cursorPosition = input.selectionStart;
            const upperValue = input.value.toUpperCase();
            input.value = upperValue;
            input.setSelectionRange(cursorPosition, cursorPosition);
        }

        // 事件监听器
        let searchTimeout;
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            // 搜索框：只允许字母和数字
            searchInput.addEventListener('input', function () {
                const cursorPosition = this.selectionStart;
                // 只保留大写字母和数字
                const filteredValue = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase();
                this.value = filteredValue;
                this.setSelectionRange(cursorPosition, cursorPosition);

                // 搜索功能
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    currentPage = 1;
                    fetchProcesses();
                }, 300);
            });

            // 粘贴事件处理
            searchInput.addEventListener('paste', function () {
                setTimeout(() => {
                    const cursorPosition = this.selectionStart;
                    const filteredValue = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase();
                    this.value = filteredValue;
                    this.setSelectionRange(cursorPosition, cursorPosition);
                }, 0);
            });
        }

        const showInactiveCheckbox = document.getElementById('showInactive');
        if (showInactiveCheckbox) {
            showInactiveCheckbox.addEventListener('change', function () {
                showInactive = this.checked;
                // 如果勾选了 Show Inactive，取消 Show All
                if (showInactive) {
                    document.getElementById('showAll').checked = false;
                    showAll = false;
                }
                currentPage = 1;
                fetchProcesses();
            });
        }

        // Real-time filter when Show All checkbox changes
        const showAllCheckbox = document.getElementById('showAll');
        if (showAllCheckbox) {
            showAllCheckbox.addEventListener('change', function () {
                showAll = this.checked;
                // 如果勾选了 Show All，取消 Show Inactive
                if (showAll) {
                    document.getElementById('showInactive').checked = false;
                    showInactive = false;
                }
                // 重置到第一页（当切换回分页模式时）
                if (!showAll) {
                    currentPage = 1;
                }
                fetchProcesses();
            });
        }

        // Real-time filter when Waiting checkbox changes (only for Bank category)
        const waitingCheckbox = document.getElementById('waiting');
        if (waitingCheckbox) {
            waitingCheckbox.addEventListener('change', function () {
                waiting = this.checked;
                currentPage = 1;
                fetchProcesses();
            });
        }

        // 处理添加表单提交
        const addProcessForm = document.getElementById('addProcessForm');
        if (addProcessForm) {
            addProcessForm.addEventListener('submit', async function (e) {
                e.preventDefault();

                // 获取 multi-use 相关元素
                const multiUseCheckbox = document.getElementById('add_multi_use');
                const processInput = document.getElementById('add_process_id');

                // 验证用户是否选择了 process 或 multi-use processes
                if (!multiUseCheckbox.checked && (!processInput.value || !processInput.value.trim())) {
                    showNotification('Please enter Process ID or enable Multi-use Purpose', 'danger');
                    return;
                }

                if (multiUseCheckbox.checked && (!window.selectedProcesses || window.selectedProcesses.length === 0)) {
                    showNotification('Please select at least one process for Multi-use Purpose', 'danger');
                    return;
                }

                // 验证是否选择了描述
                if (!window.selectedDescriptions || window.selectedDescriptions.length === 0) {
                    showNotification('Please select at least one description', 'danger');
                    return;
                }

                const formData = new FormData(this);

                // 显式带上 Copy From（保证同步源会写入 sync_source_process_id）
                const copyFromSelect = document.getElementById('add_copy_from');
                if (copyFromSelect && copyFromSelect.value && copyFromSelect.value.trim() !== '') {
                    formData.set('copy_from', copyFromSelect.value.trim());
                }

                // 添加选中的 descriptions
                formData.append('selected_descriptions', JSON.stringify(window.selectedDescriptions));

                // 添加选中的 processes (如果是 multi-use)
                if (multiUseCheckbox.checked && window.selectedProcesses && window.selectedProcesses.length > 0) {
                    formData.append('selected_processes', JSON.stringify(window.selectedProcesses));
                }

                // 添加选中的 day use
                const selectedDays = [];
                document.querySelectorAll('#day_checkboxes input[name="day_use[]"]:checked').forEach(checkbox => {
                    selectedDays.push(checkbox.value);
                });
                formData.append('day_use', selectedDays.join(','));

                try {
                    const response = await fetch(buildApiUrl('addprocessapi.php'), {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.success) {
                        let message = result.message || 'Process added successfully!';
                        // 如果有 copy_from 相关的调试信息，添加到消息中
                        if (result.copy_from_used !== undefined) {
                            console.log('Copy from used:', result.copy_from_used, 'Sync source set:', result.sync_source_set);
                            console.log('Source templates found:', result.source_templates_found);
                            console.log('Templates copied:', result.copied_templates_count);
                            if (result.copy_from_used && result.source_templates_found === 0) {
                                message += ' (No templates found to copy)';
                            }
                            if (result.copy_from_used && result.sync_source_set) {
                                message += ' [Sync enabled: changes will sync to these processes]';
                            } else if (result.copy_from_used && !result.sync_source_set) {
                                message += ' (Sync not set: source process not found for this company)';
                            }
                        }
                        showNotification(message, 'success');
                        closeAddModal();
                        fetchProcesses(); // 刷新列表
                    } else {
                        let errorMessage = result.error || 'Unknown error occurred';
                        showNotification(errorMessage, 'danger');
                    }
                } catch (error) {
                    console.error('Error adding process:', error);
                    showNotification('Failed to add process', 'danger');
                }
            });
        }

        // 处理 Bank Add/Edit Process 表单提交（Edit 时走 update_process）
        const addBankProcessForm = document.getElementById('addBankProcessForm');
        if (addBankProcessForm) {
            addBankProcessForm.addEventListener('submit', async function (e) {
                e.preventDefault();
                const country = document.getElementById('bank_country').value;
                const bank = document.getElementById('bank_bank').value;
                const type = document.getElementById('bank_type').value;
                const name = document.getElementById('bank_name').value;
                if (!country || !bank || !type || !name) {
                    showNotification('Please fill in all required fields (Country, Bank, Type, Name)', 'danger');
                    return;
                }
                const editId = document.getElementById('bank_edit_id').value;
                const formData = new FormData(this);
                formData.append('permission', 'Bank');
                const cardMerchantBtn = document.getElementById('bank_card_merchant');
                const customerBtn = document.getElementById('bank_customer');
                const profitAccountBtn = document.getElementById('bank_profit_account');
                if (cardMerchantBtn && cardMerchantBtn.getAttribute('data-value')) {
                    formData.append('card_merchant_id', cardMerchantBtn.getAttribute('data-value'));
                }
                if (customerBtn && customerBtn.getAttribute('data-value')) {
                    formData.append('customer_id', customerBtn.getAttribute('data-value'));
                }
                if (profitAccountBtn && profitAccountBtn.getAttribute('data-value')) {
                    formData.append('profit_account_id', profitAccountBtn.getAttribute('data-value'));
                }
                var dayStartVal = document.getElementById('bank_day_start').value;
                var contractVal = (document.getElementById('bank_contract') && document.getElementById('bank_contract').value) || '';
                var months = parseInt(contractVal.match(/\d+/), 10) || 0;
                if (dayStartVal && months > 0) {
                    var d = new Date(dayStartVal + 'T00:00:00');
                    d.setMonth(d.getMonth() + months);
                    formData.set('day_end', d.toISOString().slice(0, 10));
                } else {
                    formData.set('day_end', '');
                }
                try {
                    if (editId) {
                        formData.append('id', editId);
                        const response = await fetch(buildApiUrl('processlistapi.php?action=update_process'), {
                            method: 'POST',
                            body: formData
                        });
                        const result = await response.json();
                        if (result.success) {
                            showNotification(result.message || 'Process updated successfully!', 'success');
                            closeAddBankModal();
                            fetchProcesses();
                        } else {
                            showNotification(result.error || 'Update failed', 'danger');
                        }
                        return;
                    }
                    const response = await fetch(buildApiUrl('addprocessapi.php'), {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    if (result.success) {
                        const cardMerchantId = cardMerchantBtn && cardMerchantBtn.getAttribute('data-value') ? cardMerchantBtn.getAttribute('data-value') : null;
                        const customerId = customerBtn && customerBtn.getAttribute('data-value') ? customerBtn.getAttribute('data-value') : null;
                        if (cardMerchantId) await ensureAccountHasCountryCurrency(cardMerchantId);
                        if (customerId) await ensureAccountHasCountryCurrency(customerId);
                        showNotification('Bank process added successfully!', 'success');
                        closeAddBankModal();
                        fetchProcesses();
                    } else {
                        showNotification(result.error || 'Unknown error occurred', 'danger');
                    }
                } catch (error) {
                    console.error('Error saving bank process:', error);
                    showNotification('Failed to save bank process', 'danger');
                }
            });
        }

        // Insurance、Buy Price、Sell Price 只允许数字、逗号、句号
        function allowOnlyNumberCommaPeriod(el) {
            if (!el) return;
            el.addEventListener('input', function () {
                this.value = this.value.replace(/[^\d.,]/g, '');
            });
        }
        allowOnlyNumberCommaPeriod(document.getElementById('bank_insurance'));
        allowOnlyNumberCommaPeriod(document.getElementById('bank_cost'));
        allowOnlyNumberCommaPeriod(document.getElementById('bank_price'));

        // 处理编辑表单提交
        const editProcessForm = document.getElementById('editProcessForm');
        if (editProcessForm) {
            editProcessForm.addEventListener('submit', async function (e) {
                e.preventDefault();

                const formData = new FormData(this);

                if (selectedPermission === 'Bank') {
                    formData.append('permission', 'Bank');
                }

                // Add selected descriptions
                if (window.selectedDescriptions && window.selectedDescriptions.length > 0) {
                    formData.append('selected_descriptions', JSON.stringify(window.selectedDescriptions));
                }

                // Add selected day use checkboxes
                const selectedDays = [];
                document.querySelectorAll('#edit_day_checkboxes input[name="edit_day_use[]"]:checked').forEach(checkbox => {
                    selectedDays.push(checkbox.value);
                });
                formData.append('day_use', selectedDays.join(','));

                try {
                    const response = await fetch(buildApiUrl('processlistapi.php?action=update_process'), {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.success) {
                        const message = result.message || 'Process updated successfully!';
                        showNotification(message, 'success');
                        closeEditModal();
                        fetchProcesses(); // Refresh the list
                    } else {
                        let errorMessage = result.error || 'Unknown error occurred';
                        showNotification(errorMessage, 'danger');
                    }
                } catch (error) {
                    console.error('Error updating process:', error);
                    showNotification('Failed to update process', 'danger');
                }
            });
        }

        // 处理添加新描述表单提交
        const addDescriptionForm = document.getElementById('addDescriptionForm');
        if (addDescriptionForm) {
            addDescriptionForm.addEventListener('submit', async function (e) {
                e.preventDefault();

                const descriptionName = document.getElementById('new_description_name').value.trim();
                if (!descriptionName) {
                    showNotification('Please enter description name', 'danger');
                    return;
                }

                try {
                    const formData = new FormData();
                    formData.append('action', 'add_description');
                    formData.append('description_name', descriptionName);

                    const response = await fetch(buildApiUrl('addprocessapi.php'), {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.success) {
                        showNotification('Description added successfully!', 'success');
                        document.getElementById('new_description_name').value = ''; // Clear input field

                        // 重新加载描述列表
                        await loadExistingDescriptions();

                        // 如果有新添加的描述ID，自动选中它
                        if (result.description_id) {
                            const newCheckbox = document.getElementById(`desc_${result.description_id}`);
                            if (newCheckbox) {
                                newCheckbox.checked = true;
                                moveDescriptionToSelected(newCheckbox);
                            }
                        }
                    } else {
                        // 如果是重复的 description，显示英文提示
                        if (result.duplicate || (result.error && result.error.includes('already exists'))) {
                            showNotification('Description name already exists', 'danger');
                        } else {
                            showNotification('Failed to add description: ' + (result.error || 'Unknown error'), 'danger');
                        }
                    }
                } catch (error) {
                    console.error('Error adding description:', error);
                    showNotification('Failed to add description', 'danger');
                }
            });
        }

        // Add Country form submit (in modal: save to DB via API, then add to Available; user selects to move to Selected)
        const addCountryForm = document.getElementById('addCountryForm');
        if (addCountryForm) {
            addCountryForm.addEventListener('submit', async function (e) {
                e.preventDefault();
                const nameInput = document.getElementById('new_country_name');
                const countryName = (nameInput && nameInput.value) ? nameInput.value.trim() : '';
                if (!countryName) {
                    showNotification('Please enter a country name', 'danger');
                    return;
                }
                try {
                    const formData = new FormData();
                    formData.append('country', countryName);
                    const res = await fetch(buildApiUrl('processlistapi.php?action=add_country'), { method: 'POST', body: formData });
                    const result = await res.json();
                    if (!result.success) {
                        showNotification(result.error || 'Failed to save country', 'danger');
                        return;
                    }
                } catch (err) {
                    console.error(err);
                    showNotification('Failed to save country', 'danger');
                    return;
                }
                if (!availableCountriesList.includes(countryName)) {
                    availableCountriesList.push(countryName);
                    availableCountriesList.sort((a, b) => a.localeCompare(b));
                }
                loadExistingCountries();
                if (nameInput) nameInput.value = '';
                showNotification('Country added to available list', 'success');
            });
        }

        // Add Bank form submit (in modal: add new bank to Available only; user selects it to move to Selected)
        const addBankFormEl = document.getElementById('addBankForm');
        if (addBankFormEl) {
            addBankFormEl.addEventListener('submit', function (e) {
                e.preventDefault();
                const nameInput = document.getElementById('new_bank_name');
                const bankName = (nameInput && nameInput.value) ? nameInput.value.trim() : '';
                if (!bankName) {
                    showNotification('Please enter a bank name', 'danger');
                    return;
                }
                if (!availableBanksList.includes(bankName)) {
                    availableBanksList.push(bankName);
                    availableBanksList.sort((a, b) => a.localeCompare(b));
                }
                loadExistingBanks();
                if (nameInput) nameInput.value = '';
                showNotification('Bank added to available list', 'success');
            });
        }

        // Add Account modal state (same as datacapturesummary)
        let selectedCurrencyIdsForAdd = [];
        let selectedCompanyIdsForAdd = [<?php echo json_encode($company_id); ?>];
        let deletedCurrencyIds = [];
        let bankAccountCurrencies = [];
        // Edit Account modal state (for + button when account selected)
        let selectedCompanyIdsForEdit = [];
        let currentEditAccountIdForBank = null;

        let bankAccountRoles = [];
        async function loadEditDataBank() {
            try {
                const res = await fetch(buildApiUrl('editdataapi.php'));
                const result = await res.json();
                if (!result.success) return;
                bankAccountCurrencies = result.currencies || [];
                bankAccountRoles = result.roles || [];
                const addRoleSelect = document.getElementById('add_role');
                if (addRoleSelect) {
                    addRoleSelect.innerHTML = '<option value="">Select Role</option>';
                    bankAccountRoles.forEach(code => {
                        const opt = document.createElement('option');
                        opt.value = code;
                        opt.textContent = code;
                        addRoleSelect.appendChild(opt);
                    });
                }
            } catch (e) {
                console.error('loadEditDataBank', e);
            }
        }

        function toggleAlertFieldsBank(type) {
            const isAdd = type === 'add';
            const paymentAlert = document.querySelector(isAdd ? 'input[name="add_payment_alert"]:checked' : 'input[name="payment_alert"]:checked');
            const alertFields = document.getElementById(isAdd ? 'add_alert_fields' : 'edit_alert_fields');
            const alertAmountRow = document.getElementById(isAdd ? 'add_alert_amount_row' : 'edit_alert_amount_row');
            if (paymentAlert && paymentAlert.value === '1') {
                if (alertFields) alertFields.style.display = 'flex';
                if (alertAmountRow) alertAmountRow.style.display = 'block';
            } else {
                if (alertFields) alertFields.style.display = 'none';
                if (alertAmountRow) alertAmountRow.style.display = 'none';
            }
        }

        function validatePaymentAlertForAddBank() {
            const paymentAlert = document.querySelector('input[name="add_payment_alert"]:checked');
            const alertType = document.getElementById('add_alert_type');
            const alertStartDate = document.getElementById('add_alert_start_date');
            const alertAmount = document.getElementById('add_alert_amount');
            if (paymentAlert && paymentAlert.value === '1') {
                if (!alertType || !alertType.value || !alertStartDate || !alertStartDate.value) {
                    showNotification('When Payment Alert is Yes, both Alert Type and Start Date must be filled.', 'danger');
                    return false;
                }
                if (alertAmount && alertAmount.value && (isNaN(parseFloat(alertAmount.value)) || parseFloat(alertAmount.value) >= 0)) {
                    showNotification('Alert Amount must be a negative number.', 'danger');
                    return false;
                }
            }
            return true;
        }

        function validatePaymentAlertForEditBank() {
            const paymentAlert = document.querySelector('input[name="payment_alert"]:checked');
            const alertType = document.getElementById('edit_alert_type');
            const alertStartDate = document.getElementById('edit_alert_start_date');
            const alertAmount = document.getElementById('edit_alert_amount');
            if (paymentAlert && paymentAlert.value === '1') {
                if (!alertType || !alertType.value || !alertStartDate || !alertStartDate.value) {
                    showNotification('When Payment Alert is Yes, both Alert Type and Start Date must be filled.', 'danger');
                    return false;
                }
                if (alertAmount && alertAmount.value && (isNaN(parseFloat(alertAmount.value)) || parseFloat(alertAmount.value) >= 0)) {
                    showNotification('Alert Amount must be a negative number.', 'danger');
                    return false;
                }
            }
            return true;
        }

        async function loadAccountCurrenciesBank(accountId, type) {
            const listId = type === 'add' ? 'addCurrencyList' : 'editCurrencyList';
            const listElement = document.getElementById(listId);
            if (!listElement) return;
            listElement.innerHTML = '';
            if (type === 'add' && !accountId) deletedCurrencyIds = [];
            try {
                const url = accountId
                    ? buildApiUrl('account_currency_api.php?action=get_available_currencies&account_id=' + accountId)
                    : buildApiUrl('account_currency_api.php?action=get_available_currencies');
                const response = await fetch(url);
                const result = await response.json();
                if (!result.success || !Array.isArray(result.data) || result.data.length === 0) {
                    listElement.innerHTML = '<div class="currency-toggle-note">No currencies available.</div>';
                    return;
                }
                const isAddMode = type === 'add' && !accountId;
                let currencyToAutoSelect = null;
                if (isAddMode && selectedCurrencyIdsForAdd.length === 0) {
                    const myr = result.data.find(c => String(c.code || '').toUpperCase() === 'MYR');
                    currencyToAutoSelect = myr || (result.data.length ? result.data.sort((a, b) => a.id - b.id)[0] : null);
                }
                result.data.forEach(currency => {
                    if (deletedCurrencyIds.includes(currency.id)) return;
                    const code = String(currency.code || '').toUpperCase();
                    const item = document.createElement('div');
                    item.className = 'account-currency-item currency-toggle-item';
                    item.setAttribute('data-currency-id', currency.id);
                    const codeSpan = document.createElement('span');
                    codeSpan.className = 'currency-code-text';
                    codeSpan.textContent = code;
                    const deleteBtn = document.createElement('button');
                    deleteBtn.className = 'currency-delete-btn';
                    deleteBtn.innerHTML = '×';
                    deleteBtn.setAttribute('type', 'button');
                    deleteBtn.setAttribute('title', 'Delete currency permanently');
                    deleteBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        deleteCurrencyPermanentlyBank(currency.id, code, item);
                    });
                    item.appendChild(codeSpan);
                    item.appendChild(deleteBtn);
                    if (currency.is_linked) item.classList.add('selected');
                    else if (isAddMode && selectedCurrencyIdsForAdd.includes(currency.id)) item.classList.add('selected');
                    else if (isAddMode && currencyToAutoSelect && currency.id === currencyToAutoSelect.id) {
                        item.classList.add('selected');
                        if (!selectedCurrencyIdsForAdd.includes(currency.id)) selectedCurrencyIdsForAdd.push(currency.id);
                    }
                    if (isAddMode) {
                        codeSpan.addEventListener('click', (e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            const shouldSelect = !item.classList.contains('selected');
                            if (shouldSelect) {
                                item.classList.add('selected');
                                if (!selectedCurrencyIdsForAdd.includes(currency.id)) selectedCurrencyIdsForAdd.push(currency.id);
                            } else {
                                item.classList.remove('selected');
                                selectedCurrencyIdsForAdd = selectedCurrencyIdsForAdd.filter(id => id !== currency.id);
                            }
                        });
                    } else if (type === 'edit' && accountId) {
                        codeSpan.addEventListener('click', (e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            const shouldSelect = !item.classList.contains('selected');
                            toggleAccountCurrencyBank(accountId, currency.id, code, shouldSelect, item);
                        });
                    }
                    listElement.appendChild(item);
                });
            } catch (error) {
                console.error('Error loading account currencies:', error);
                listElement.innerHTML = '<div class="currency-toggle-note">Failed to load currencies.</div>';
            }
        }

        async function toggleAccountCurrencyBank(accountId, currencyId, code, shouldSelect, itemElement) {
            const previousState = itemElement.classList.contains('selected');
            if (shouldSelect) itemElement.classList.add('selected');
            else itemElement.classList.remove('selected');
            try {
                const action = shouldSelect ? 'add_currency' : 'remove_currency';
                const res = await fetch(buildApiUrl('account_currency_api.php?action=' + action), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ account_id: accountId, currency_id: currencyId })
                });
                const result = await res.json();
                if (result.success) {
                    showNotification(shouldSelect ? 'Currency ' + code + ' added to account' : 'Currency ' + code + ' removed from account', 'success');
                } else {
                    if (previousState) itemElement.classList.add('selected');
                    else itemElement.classList.remove('selected');
                    showNotification(result.error || 'Currency update failed', 'danger');
                }
            } catch (e) {
                if (previousState) itemElement.classList.add('selected');
                else itemElement.classList.remove('selected');
                showNotification('Currency update failed', 'danger');
            }
        }

        async function deleteCurrencyPermanentlyBank(currencyId, currencyCode, itemElement) {
            if (!confirm('Are you sure you want to permanently delete currency ' + currencyCode + '? This action cannot be undone.')) return;
            try {
                const res = await fetch(buildApiUrl('deletecurrencyapi.php'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: currencyId })
                });
                const data = await res.json();
                if (data.success) {
                    if (itemElement && itemElement.parentNode) itemElement.remove();
                    if (!deletedCurrencyIds.includes(currencyId)) deletedCurrencyIds.push(currencyId);
                    showNotification('Currency ' + currencyCode + ' deleted successfully!', 'success');
                } else {
                    showNotification(data.error || 'Failed to delete currency', 'danger');
                }
            } catch (e) {
                showNotification('Failed to delete currency', 'danger');
            }
        }

        async function loadAccountCompaniesBank(accountId, type) {
            const listId = type === 'add' ? 'addCompanyList' : 'editCompanyList';
            const listElement = document.getElementById(listId);
            if (!listElement) return;
            listElement.innerHTML = '';
            if (type === 'add' && !accountId) {
                const currentCompanyId = <?php echo json_encode($company_id); ?>;
                if (currentCompanyId && !selectedCompanyIdsForAdd.includes(currentCompanyId))
                    selectedCompanyIdsForAdd.push(currentCompanyId);
            }
            try {
                const url = accountId
                    ? buildApiUrl('account_company_api.php?action=get_available_companies&account_id=' + accountId)
                    : buildApiUrl('account_company_api.php?action=get_available_companies');
                const response = await fetch(url);
                const result = await response.json();
                if (!result.success || !Array.isArray(result.data) || result.data.length === 0) {
                    listElement.innerHTML = '<div class="currency-toggle-note">No companies available.</div>';
                    return;
                }
                const isAddMode = type === 'add' && !accountId;
                const isEditMode = type === 'edit' && accountId;
                if (isEditMode) selectedCompanyIdsForEdit = [];
                result.data.forEach(company => {
                    const code = String(company.company_code || '').toUpperCase();
                    const item = document.createElement('div');
                    item.className = 'account-currency-item currency-toggle-item';
                    item.setAttribute('data-company-id', company.id);
                    item.textContent = code;
                    if (company.is_linked) {
                        item.classList.add('selected');
                        if (isEditMode) selectedCompanyIdsForEdit.push(company.id);
                    } else if (isAddMode && selectedCompanyIdsForAdd.includes(company.id)) item.classList.add('selected');
                    if (isAddMode) {
                        item.addEventListener('click', (e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            const shouldSelect = !item.classList.contains('selected');
                            if (shouldSelect) {
                                item.classList.add('selected');
                                if (!selectedCompanyIdsForAdd.includes(company.id)) selectedCompanyIdsForAdd.push(company.id);
                            } else {
                                item.classList.remove('selected');
                                selectedCompanyIdsForAdd = selectedCompanyIdsForAdd.filter(id => id !== company.id);
                            }
                        });
                    } else if (isEditMode) {
                        item.addEventListener('click', (e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            const shouldSelect = !item.classList.contains('selected');
                            if (shouldSelect) {
                                item.classList.add('selected');
                                if (!selectedCompanyIdsForEdit.includes(company.id)) selectedCompanyIdsForEdit.push(company.id);
                            } else {
                                item.classList.remove('selected');
                                selectedCompanyIdsForEdit = selectedCompanyIdsForEdit.filter(id => id !== company.id);
                            }
                        });
                    }
                    listElement.appendChild(item);
                });
            } catch (error) {
                console.error('Error loading account companies:', error);
                listElement.innerHTML = '<div class="currency-toggle-note">Failed to load companies.</div>';
            }
        }

        async function addCurrencyFromInputBank(type) {
            const isEdit = type === 'edit';
            const input = document.getElementById(isEdit ? 'editCurrencyInput' : 'addCurrencyInput');
            const currencyCode = (input && input.value.trim() || '').toUpperCase();
            if (!currencyCode) {
                showNotification('Please enter currency code', 'danger');
                if (input) input.focus();
                return false;
            }
            const existing = bankAccountCurrencies.find(c => (c.code || '').toUpperCase() === currencyCode);
            if (existing) {
                showNotification('Currency ' + currencyCode + ' already exists', 'info');
                if (input) input.value = '';
                return;
            }
            try {
                const currentCompanyId = <?php echo json_encode($company_id); ?>;
                const res = await fetch(buildApiUrl('addcurrencyapi.php'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ code: currencyCode, company_id: currentCompanyId })
                });
                const result = await res.json();
                if (result.success && result.data) {
                    const newCurrencyId = result.data.id;
                    bankAccountCurrencies.push({ id: newCurrencyId, code: result.data.code });
                    if (isEdit && currentEditAccountIdForBank) {
                        await loadAccountCurrenciesBank(currentEditAccountIdForBank, 'edit');
                        const linkRes = await fetch(buildApiUrl('account_currency_api.php?action=add_currency'), {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ account_id: currentEditAccountIdForBank, currency_id: newCurrencyId })
                        });
                        const linkResult = await linkRes.json();
                        if (linkResult.success) {
                            await loadAccountCurrenciesBank(currentEditAccountIdForBank, 'edit');
                            showNotification('Currency ' + currencyCode + ' created and linked to account', 'success');
                        } else {
                            showNotification('Currency ' + currencyCode + ' created, link failed', 'warning');
                        }
                    } else {
                        await loadAccountCurrenciesBank(null, 'add');
                        showNotification('Currency ' + currencyCode + ' created successfully', 'success');
                    }
                    if (input) input.value = '';
                } else {
                    showNotification(result.error || 'Failed to create currency', 'danger');
                }
            } catch (e) {
                showNotification('Failed to create currency', 'danger');
            }
            return false;
        }

        // Add Account form submit (same as datacapturesummary - addaccountapi.php + link currencies/companies)
        const addAccountFormEl = document.getElementById('addAccountForm');
        if (addAccountFormEl) {
            addAccountFormEl.addEventListener('submit', async function (e) {
                e.preventDefault();
                if (!validatePaymentAlertForAddBank()) return;
                const formData = new FormData(this);
                const paymentAlert = document.querySelector('input[name="add_payment_alert"]:checked');
                if (paymentAlert) {
                    formData.set('payment_alert', paymentAlert.value);
                    if (paymentAlert.value === '0' || paymentAlert.value === 0) {
                        formData.set('alert_type', '');
                        formData.set('alert_start_date', '');
                        formData.set('alert_amount', '');
                    }
                }
                const currentCompanyId = <?php echo json_encode($company_id); ?>;
                if (currentCompanyId) formData.set('company_id', currentCompanyId);
                if (selectedCurrencyIdsForAdd.length > 0) formData.set('currency_ids', JSON.stringify(selectedCurrencyIdsForAdd));
                if (selectedCompanyIdsForAdd.length > 0) formData.set('company_ids', JSON.stringify(selectedCompanyIdsForAdd));
                try {
                    const response = await fetch(buildApiUrl('addaccountapi.php'), { method: 'POST', body: formData });
                    const result = await response.json();
                    if (result.success) {
                        const newAccountId = result.data && result.data.id;
                        let hasErrors = false;
                        if (selectedCurrencyIdsForAdd.length > 0 && newAccountId) {
                            try {
                                const currencyPromises = selectedCurrencyIdsForAdd.map(currencyId =>
                                    fetch(buildApiUrl('account_currency_api.php?action=add_currency'), {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/json' },
                                        body: JSON.stringify({ account_id: newAccountId, currency_id: currencyId })
                                    }).then(r => r.json())
                                );
                                const currencyResults = await Promise.all(currencyPromises);
                                if (currencyResults.some(r => !r.success)) hasErrors = true;
                            } catch (err) { hasErrors = true; }
                        }
                        if (selectedCompanyIdsForAdd.length > 0 && newAccountId) {
                            try {
                                const companyPromises = selectedCompanyIdsForAdd.map(companyId =>
                                    fetch(buildApiUrl('account_company_api.php?action=add_company'), {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/json' },
                                        body: JSON.stringify({ account_id: newAccountId, company_id: companyId })
                                    }).then(r => r.json())
                                );
                                const companyResults = await Promise.all(companyPromises);
                                if (companyResults.some(r => !r.success)) hasErrors = true;
                            } catch (err) { hasErrors = true; }
                        }
                        if (hasErrors) showNotification('Account created successfully, but some associations failed.', 'warning');
                        else if (selectedCurrencyIdsForAdd.length > 0 || selectedCompanyIdsForAdd.length > 0) showNotification('Account added successfully with currencies and companies!', 'success');
                        else showNotification('Account added successfully!', 'success');
                        selectedCurrencyIdsForAdd = [];
                        selectedCompanyIdsForAdd = currentCompanyId ? [currentCompanyId] : [];
                        closeAddAccountModal();
                        await loadBankAccounts();
                        refreshBankAccountDropdowns();
                        if (newAccountId) {
                            const cardBtn = document.getElementById('bank_card_merchant');
                            const customerBtn = document.getElementById('bank_customer');
                            const displayText = result.data.account_id || result.data.name || String(newAccountId);
                            if (cardBtn && !cardBtn.getAttribute('data-value')) {
                                cardBtn.textContent = displayText;
                                cardBtn.setAttribute('data-value', newAccountId);
                            } else if (customerBtn && !customerBtn.getAttribute('data-value')) {
                                customerBtn.textContent = displayText;
                                customerBtn.setAttribute('data-value', newAccountId);
                            }
                        }
                    } else {
                        showNotification(result.error || 'Failed to add account', 'danger');
                    }
                } catch (err) {
                    console.error('Add account error', err);
                    showNotification('Failed to add account', 'danger');
                }
            });
        }

        const editAccountFormEl = document.getElementById('editAccountForm');
        if (editAccountFormEl) {
            editAccountFormEl.addEventListener('submit', async function (e) {
                e.preventDefault();
                if (!validatePaymentAlertForEditBank()) return;
                const formData = new FormData(this);
                const paymentAlert = formData.get('payment_alert');
                if (paymentAlert === '0' || paymentAlert === 0) {
                    formData.set('alert_type', '');
                    formData.set('alert_start_date', '');
                    formData.set('alert_amount', '');
                }
                if (Array.isArray(selectedCompanyIdsForEdit) && selectedCompanyIdsForEdit.length > 0) {
                    formData.set('company_ids', JSON.stringify(selectedCompanyIdsForEdit));
                }
                try {
                    const response = await fetch(buildApiUrl('updateaccountapi.php'), { method: 'POST', body: formData });
                    const result = await response.json();
                    if (result.success) {
                        showNotification('Account updated successfully!', 'success');
                        closeEditAccountModalFromBank();
                        await loadBankAccounts();
                        refreshBankAccountDropdowns();
                    } else {
                        showNotification(result.error || 'Account update failed', 'danger');
                    }
                } catch (err) {
                    console.error('Edit account error', err);
                    showNotification('Update failed', 'danger');
                }
            });
        }

        const profitSharingFormEl = document.getElementById('profitSharingForm');
        if (profitSharingFormEl) {
            profitSharingFormEl.addEventListener('submit', function (e) {
                e.preventDefault();
                const rows = document.querySelectorAll('#profitSharingRowsContainer .profit-sharing-row');
                if (!window.selectedProfitSharingEntries) window.selectedProfitSharingEntries = [];
                let added = 0;
                rows.forEach(function (row) {
                    const accountSelect = row.querySelector('.profit-sharing-account');
                    const amountInput = row.querySelector('.profit-sharing-amount');
                    if (!accountSelect || !amountInput) return;
                    const accountId = (accountSelect.value || '').trim();
                    const rawAmount = (amountInput.value || '').trim();
                    if (!accountId || rawAmount === '') return;
                    const accountText = accountSelect.options[accountSelect.selectedIndex] ? accountSelect.options[accountSelect.selectedIndex].text : '';
                    const num = parseFloat(rawAmount);
                    const amount = (isNaN(num) ? rawAmount : num.toFixed(2));
                    window.selectedProfitSharingEntries.push({ accountId: accountId, accountText: accountText, amount: amount });
                    added++;
                });
                if (added === 0) {
                    showNotification('Please select at least one Account and enter Amount.', 'warning');
                    return;
                }
                renderSelectedProfitSharing();
                closeProfitSharingModal();
            });
        }

        const profitSharingAddRowBtn = document.getElementById('profitSharingAddRowBtn');
        if (profitSharingAddRowBtn) {
            profitSharingAddRowBtn.addEventListener('click', function () {
                addProfitSharingRow();
            });
        }

        // 页面加载完成后执行
        // Profit calculation flag to prevent duplicate listeners
        let bankProfitCalculatorsInitialized = false;

        // Load countries from server (persist after refresh)
        async function loadCountriesFromServer() {
            const select = document.getElementById('bank_country');
            if (!select) return;
            const currentVal = (select.value || '').trim();
            try {
                const res = await fetch(buildApiUrl('processlistapi.php?action=get_countries'));
                const result = await res.json();
                const list = (result.success && result.data) ? result.data : [];
                select.innerHTML = '';
                const opt0 = document.createElement('option');
                opt0.value = '';
                opt0.textContent = 'Select Country';
                select.appendChild(opt0);
                list.forEach(function (c) {
                    const opt = document.createElement('option');
                    opt.value = c;
                    opt.textContent = c;
                    select.appendChild(opt);
                });
                if (currentVal && list.indexOf(currentVal) >= 0) select.value = currentVal;
                else select.value = '';
            } catch (e) {
                console.warn('loadCountriesFromServer', e);
            }
        }

        // Load Bank Add Process Data (do not pre-fill Country dropdown; it only shows Selected from modal)
        async function loadAddBankProcessData() {
            try {
                await loadBankAccounts();
                initBankAccountSelect('bank_card_merchant', 'bank_card_merchant_dropdown');
                initBankAccountSelect('bank_customer', 'bank_customer_dropdown');
                initBankAccountSelect('bank_profit_account', 'bank_profit_account_dropdown');
                updateBankAddButtonTitles();

                // 设置 Profit 自动计算（只初始化一次）
                if (!bankProfitCalculatorsInitialized) {
                    const costInput = document.getElementById('bank_cost');
                    const priceInput = document.getElementById('bank_price');
                    const profitInput = document.getElementById('bank_profit');

                    if (costInput && priceInput && profitInput) {
                        function calculateProfit() {
                            const cost = parseFloat(costInput.value) || 0;
                            const price = parseFloat(priceInput.value) || 0;
                            const profit = price - cost;
                            profitInput.value = profit.toFixed(2);
                        }

                        costInput.addEventListener('input', calculateProfit);
                        priceInput.addEventListener('input', calculateProfit);
                        bankProfitCalculatorsInitialized = true;
                    }
                }
            } catch (error) {
                console.error('Error loading bank process data:', error);
            }
        }

        // 按 Country 加载 Bank 下拉选项（Country-Bank 联动）
        async function loadBanksByCountry(country) {
            const select = document.getElementById('bank_bank');
            if (!select) return;
            const currentBank = (select.value || '').trim();
            select.innerHTML = '';
            const opt0 = document.createElement('option');
            opt0.value = '';
            opt0.textContent = 'Select Bank';
            select.appendChild(opt0);
            if (!country || (country = String(country).trim()) === '') {
                if (currentBank) select.value = '';
                return;
            }
            try {
                const url = buildApiUrl('processlistapi.php?action=get_banks_by_country&country=' + encodeURIComponent(country));
                const res = await fetch(url);
                const result = await res.json();
                const banks = (result.success && result.data) ? result.data : [];
                banks.forEach(function (b) {
                    const opt = document.createElement('option');
                    opt.value = b;
                    opt.textContent = b;
                    select.appendChild(opt);
                });
                if (currentBank && banks.indexOf(currentBank) >= 0) select.value = currentBank;
                else select.value = '';
            } catch (e) {
                console.warn('loadBanksByCountry', e);
                if (currentBank) select.value = '';
            }
        }

        // Country 变更时刷新 Bank 下拉，并清空 Bank 若不在新列表中
        (function () {
            const countrySelect = document.getElementById('bank_country');
            if (countrySelect) {
                countrySelect.addEventListener('change', function () {
                    loadBanksByCountry(this.value);
                });
            }
        })();

        // Country field: user may enter country name (Malaysia -> MYR) or currency code directly (MYR, SGD)
        const COUNTRY_TO_CURRENCY = { 'Malaysia': 'MYR', 'Singapore': 'SGD' };

        function resolveCurrencyCodeFromCountryField(value) {
            if (!value || (value = String(value).trim()) === '') return null;
            if (COUNTRY_TO_CURRENCY[value]) return COUNTRY_TO_CURRENCY[value];
            if (value.length >= 2 && value.length <= 5) return value.toUpperCase();
            return null;
        }

        async function ensureAccountHasCountryCurrency(accountId) {
            if (!accountId) return;
            const countrySelect = document.getElementById('bank_country');
            const countryOrCurrency = (countrySelect && countrySelect.value) ? String(countrySelect.value).trim() : '';
            const currencyCode = resolveCurrencyCodeFromCountryField(countryOrCurrency);
            if (!currencyCode) return;
            try {
                const apiUrl = buildApiUrl('addprocessapi.php');
                const res = await fetch(apiUrl);
                const result = await res.json();
                if (!result.success) return;
                const currencies = result.currencies || [];
                let currency = currencies.find(c => (c.code || '').toUpperCase() === currencyCode);
                if (!currency || !currency.id) {
                    const currentCompanyId = <?php echo json_encode($company_id ?? null); ?>;
                    const createRes = await fetch(buildApiUrl('addcurrencyapi.php'), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ code: currencyCode, company_id: currentCompanyId || undefined })
                    });
                    const createResult = await createRes.json();
                    if (createResult.success && createResult.data) {
                        currency = { id: createResult.data.id, code: createResult.data.code || currencyCode };
                    } else if (createResult.error && (createResult.error + '').toLowerCase().includes('already exists')) {
                        const refetch = await fetch(apiUrl);
                        const refetchResult = await refetch.json();
                        if (refetchResult.success && Array.isArray(refetchResult.currencies)) {
                            currency = refetchResult.currencies.find(c => (c.code || '').toUpperCase() === currencyCode);
                        }
                    }
                    if (!currency || !currency.id) {
                        console.warn('ensureAccountHasCountryCurrency: could not get or create currency', currencyCode);
                        return;
                    }
                }
                const getCurrUrl = buildApiUrl('account_currency_api.php?action=get_account_currencies&account_id=' + accountId);
                const getCurrRes = await fetch(getCurrUrl);
                const getCurrResult = await getCurrRes.json();
                if (getCurrResult.success && Array.isArray(getCurrResult.data)) {
                    const alreadyHas = getCurrResult.data.some(c => (c.currency_id || c.id) === currency.id || (c.currency_code || '').toUpperCase() === currencyCode);
                    if (alreadyHas) return;
                }
                const addUrl = buildApiUrl('account_currency_api.php?action=add_currency');
                const addRes = await fetch(addUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ account_id: accountId, currency_id: currency.id })
                });
                const addResult = await addRes.json();
                if (addResult.success) {
                    showNotification(currencyCode + ' added to account', 'success');
                }
            } catch (e) {
                console.warn('ensureAccountHasCountryCurrency', e);
            }
        }

        // Load accounts for Bank form
        async function loadBankAccounts() {
            try {
                const currentCompanyId = <?php echo json_encode($company_id); ?>;
                const url = buildApiUrl('accountlistapi.php');
                if (currentCompanyId) {
                    url.searchParams.set('company_id', currentCompanyId);
                }

                const response = await fetch(url);
                const result = await response.json();

                if (result.success && result.data) {
                    window.bankAccounts = result.data;
                }
            } catch (error) {
                console.error('Error loading accounts:', error);
            }
        }

        // Initialize Bank Account Select (custom dropdown with search, like datacapturesummary Account)
        function initBankAccountSelect(buttonId, dropdownId) {
            const accountButton = document.getElementById(buttonId);
            const accountDropdown = document.getElementById(dropdownId);
            const searchInput = accountDropdown?.querySelector('.custom-select-search input');
            const optionsContainer = accountDropdown?.querySelector('.custom-select-options');

            if (!accountButton || !accountDropdown || !searchInput || !optionsContainer) return;

            let isOpen = false;

            // Load accounts into dropdown (Profit Account: only role === 'profit')
            const placeholderText = accountButton.getAttribute('data-placeholder') || 'Select Account';
            const isProfitAccountSelect = (buttonId === 'bank_profit_account');
            function loadAccounts() {
                optionsContainer.innerHTML = '';
                // Always read filter from this dropdown's search input so search matches what user sees
                const filterLower = (searchInput.value || '').toLowerCase().trim();
                let accounts = window.bankAccounts || [];
                if (isProfitAccountSelect) {
                    accounts = accounts.filter(acc => (acc.role || '').toLowerCase() === 'profit');
                }

                // Always add "Select Account" as first option so user can clear selection
                {
                    const selectOpt = document.createElement('div');
                    selectOpt.className = 'custom-select-option';
                    selectOpt.setAttribute('data-value', '');
                    selectOpt.textContent = 'Select Account';
                    selectOpt.addEventListener('click', () => {
                        accountButton.textContent = placeholderText;
                        accountButton.setAttribute('data-value', '');
                        accountDropdown.style.display = 'none';
                        isOpen = false;
                        updateBankAddButtonTitles();
                    });
                    optionsContainer.appendChild(selectOpt);
                }

                // Filter by the same text we display so search matches what user sees (exact match on displayed string)
                function getDisplayText(account) {
                    return String(account.account_id ?? account.name ?? '').trim();
                }
                let filteredAccounts = accounts.filter(account => {
                    const displayText = getDisplayText(account).toLowerCase();
                    return !filterLower || displayText.includes(filterLower);
                });
                // Sort alphabetically by display text
                filteredAccounts = filteredAccounts.slice().sort((a, b) => {
                    const ta = getDisplayText(a).toLowerCase();
                    const tb = getDisplayText(b).toLowerCase();
                    return ta.localeCompare(tb);
                });

                if (filteredAccounts.length === 0) {
                    const noResults = document.createElement('div');
                    noResults.className = 'custom-select-no-results';
                    noResults.textContent = 'No accounts found';
                    optionsContainer.appendChild(noResults);
                } else {
                    filteredAccounts.forEach(account => {
                        const option = document.createElement('div');
                        option.className = 'custom-select-option';
                        option.setAttribute('data-value', account.id);
                        option.textContent = getDisplayText(account);
                        option.addEventListener('click', () => {
                            accountButton.textContent = getDisplayText(account);
                            accountButton.setAttribute('data-value', account.id);
                            accountDropdown.style.display = 'none';
                            isOpen = false;
                            updateBankAddButtonTitles();
                        });
                        optionsContainer.appendChild(option);
                    });
                }
            }

            // Initial load
            loadAccounts();

            // Search input handler: loadAccounts() reads filter from searchInput.value
            searchInput.addEventListener('input', () => {
                loadAccounts();
            });

            // Toggle dropdown: clear search so filter is fresh, then load
            accountButton.addEventListener('click', (e) => {
                e.stopPropagation();
                if (isOpen) {
                    accountDropdown.style.display = 'none';
                    isOpen = false;
                } else {
                    accountDropdown.style.display = 'block';
                    isOpen = true;
                    searchInput.value = '';
                    loadAccounts();
                    searchInput.focus();
                }
            });

            // Close on outside click
            document.addEventListener('click', (e) => {
                if (!accountButton.contains(e.target) && !accountDropdown.contains(e.target)) {
                    accountDropdown.style.display = 'none';
                    isOpen = false;
                }
            });
        }

        // Country Selection Modal
        const DEFAULT_COUNTRIES = [];
        let availableCountriesList = [];

        async function showAddCountryModal() {
            // Previously added countries go to Available only; Selected is empty by default.
            window.selectedCountries = [];
            let allCountries = [];
            try {
                const res = await fetch(buildApiUrl('processlistapi.php?action=get_countries'));
                const result = await res.json();
                allCountries = (result.success && result.data) ? result.data : [];
            } catch (e) { console.warn('get_countries', e); }
            loadExistingCountries(allCountries);
            updateSelectedCountriesInModal();
            const modal = document.getElementById('countrySelectionModal');
            if (modal) {
                modal.classList.add('show');
                modal.style.display = 'block';
            }
        }

        function loadExistingCountries(allFromServer) {
            const select = document.getElementById('bank_country');
            const existingOptions = [];
            if (select && select.options) {
                for (let i = 0; i < select.options.length; i++) {
                    const v = (select.options[i].value || '').trim();
                    if (v) existingOptions.push(v);
                }
            }
            const all = allFromServer && allFromServer.length > 0
                ? [...new Set([...DEFAULT_COUNTRIES, ...allFromServer, ...(availableCountriesList || [])])].sort((a, b) => a.localeCompare(b))
                : [...new Set([...DEFAULT_COUNTRIES, ...existingOptions, ...(availableCountriesList || [])])].sort((a, b) => a.localeCompare(b));
            const selectedSet = new Set(window.selectedCountries || []);
            const combined = all.filter(name => !selectedSet.has(name));
            availableCountriesList = combined;

            const listEl = document.getElementById('existingCountries');
            if (!listEl) return;
            listEl.innerHTML = '';
            combined.forEach((name, index) => {
                const id = 'country_' + (Date.now() + index);
                const item = document.createElement('div');
                item.className = 'country-item';
                const left = document.createElement('div');
                left.className = 'country-item-left';
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.name = 'available_countries';
                checkbox.value = name;
                checkbox.id = id;
                checkbox.dataset.countryId = id;
                const label = document.createElement('label');
                label.htmlFor = id;
                label.textContent = name;
                left.appendChild(checkbox);
                left.appendChild(label);
                const deleteBtn = document.createElement('button');
                deleteBtn.type = 'button';
                deleteBtn.className = 'country-delete-btn';
                deleteBtn.title = 'Remove from list';
                deleteBtn.innerHTML = '&times;';
                deleteBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    removeCountryFromAvailable(name, item);
                });
                item.appendChild(left);
                item.appendChild(deleteBtn);
                listEl.appendChild(item);
                checkbox.addEventListener('change', function () {
                    if (this.checked) moveCountryToSelected(this);
                    else moveCountryToAvailable(this);
                });
            });
        }

        function updateSelectedCountriesInModal() {
            const selectedList = document.getElementById('selectedCountriesInModal');
            if (!selectedList) return;
            selectedList.innerHTML = '';
            const current = (document.getElementById('bank_country')?.value || '').trim();
            if (!window.selectedCountries) window.selectedCountries = [];
            if (current && !window.selectedCountries.includes(current)) {
                window.selectedCountries = [current];
            }
            if (window.selectedCountries.length > 0) {
                window.selectedCountries.forEach((name, idx) => {
                    const div = document.createElement('div');
                    div.className = 'selected-country-modal-item';
                    const safeName = (name || '').replace(/'/g, "\\'");
                    div.innerHTML = '<span>' + escapeHtml(name) + '</span><button type="button" class="remove-country-modal" onclick="moveCountryBackToAvailable(\'' + safeName + '\', \'cid' + idx + '\')">&times;</button>';
                    selectedList.appendChild(div);
                });
            } else {
                selectedList.innerHTML = '<div class="no-countries">No countries selected</div>';
            }
        }

        function filterCountries() {
            const term = (document.getElementById('countrySearch')?.value || '').toLowerCase();
            const items = document.querySelectorAll('#existingCountries .country-item');
            items.forEach(item => {
                const text = item.querySelector('label')?.textContent?.toLowerCase() || '';
                item.style.display = text.includes(term) ? 'block' : 'none';
            });
        }

        function moveCountryToSelected(checkbox) {
            const name = checkbox.value;
            const id = checkbox.dataset.countryId;
            const item = checkbox.closest('.country-item');
            if (!window.selectedCountries) window.selectedCountries = [];
            if (!window.selectedCountries.includes(name)) window.selectedCountries.push(name);
            const selectedList = document.getElementById('selectedCountriesInModal');
            const placeholder = selectedList.querySelector('.no-countries');
            if (placeholder) placeholder.remove();
            const div = document.createElement('div');
            div.className = 'selected-country-modal-item';
            const safeName = (name || '').replace(/'/g, "\\'");
            div.innerHTML = '<span>' + escapeHtml(name) + '</span><button type="button" class="remove-country-modal" onclick="moveCountryBackToAvailable(\'' + safeName + '\', \'' + id + '\')">&times;</button>';
            selectedList.appendChild(div);
            if (item) item.remove();
        }

        function moveCountryBackToAvailable(countryName, countryId) {
            if (window.selectedCountries) {
                const idx = window.selectedCountries.indexOf(countryName);
                if (idx > -1) window.selectedCountries.splice(idx, 1);
            }
            const selectedList = document.getElementById('selectedCountriesInModal');
            selectedList.querySelectorAll('.selected-country-modal-item').forEach(item => {
                if (item.querySelector('span')?.textContent === countryName) item.remove();
            });
            if (!selectedList.querySelector('.selected-country-modal-item')) {
                selectedList.innerHTML = '<div class="no-countries">No countries selected</div>';
            }
            const listEl = document.getElementById('existingCountries');
            if (!listEl) return;
            const id = 'country_' + (countryId || Date.now());
            const newItem = document.createElement('div');
            newItem.className = 'country-item';
            const left = document.createElement('div');
            left.className = 'country-item-left';
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.name = 'available_countries';
            cb.value = countryName;
            cb.id = id;
            cb.dataset.countryId = id;
            const label = document.createElement('label');
            label.htmlFor = id;
            label.textContent = countryName;
            left.appendChild(cb);
            left.appendChild(label);
            const delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'country-delete-btn';
            delBtn.innerHTML = '&times;';
            delBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                removeCountryFromAvailable(countryName, newItem);
            });
            newItem.appendChild(left);
            newItem.appendChild(delBtn);
            listEl.appendChild(newItem);
            cb.addEventListener('change', function () {
                if (this.checked) moveCountryToSelected(this);
                else moveCountryToAvailable(this);
            });
        }

        function moveCountryToAvailable(checkbox) {
            const name = checkbox.value;
            const item = checkbox.closest('.country-item');
            if (window.selectedCountries) {
                const idx = window.selectedCountries.indexOf(name);
                if (idx > -1) window.selectedCountries.splice(idx, 1);
            }
            document.getElementById('selectedCountriesInModal').querySelectorAll('.selected-country-modal-item').forEach(el => {
                if (el.querySelector('span')?.textContent === name) el.remove();
            });
            const selectedList = document.getElementById('selectedCountriesInModal');
            if (!selectedList.querySelector('.selected-country-modal-item')) {
                selectedList.innerHTML = '<div class="no-countries">No countries selected</div>';
            }
        }

        function removeCountryFromAvailable(countryName, itemEl) {
            if (itemEl && itemEl.parentNode) itemEl.remove();
        }

        function closeCountrySelectionModal() {
            const modal = document.getElementById('countrySelectionModal');
            if (modal) {
                modal.classList.remove('show');
                modal.style.display = 'none';
            }
            const form = document.getElementById('addCountryForm');
            if (form) form.reset();
            const search = document.getElementById('countrySearch');
            if (search) search.value = '';
            document.querySelectorAll('input[name="available_countries"]').forEach(cb => cb.checked = false);
        }

        function confirmCountries() {
            const select = document.getElementById('bank_country');
            if (!select) { closeCountrySelectionModal(); return; }
            // Dropdown shows only Selected countries, not Available.
            select.innerHTML = '';
            const opt0 = document.createElement('option');
            opt0.value = '';
            opt0.textContent = 'Select Country';
            select.appendChild(opt0);
            (window.selectedCountries || []).forEach(function (name) {
                const n = (name || '').trim();
                if (!n) return;
                const opt = document.createElement('option');
                opt.value = n;
                opt.textContent = n;
                select.appendChild(opt);
            });
            if (window.selectedCountries && window.selectedCountries.length > 0) {
                select.value = window.selectedCountries[0] || '';
            }
            closeCountrySelectionModal();
        }

        // Bank Selection Modal
        const DEFAULT_BANKS = [];
        let availableBanksList = [];

        async function showAddBankModal() {
            const countrySelect = document.getElementById('bank_country');
            const country = (countrySelect && countrySelect.value) ? String(countrySelect.value).trim() : '';
            if (!country) {
                showNotification('Please select Country first', 'danger');
                return;
            }
            await loadBanksByCountry(country);
            // Previously added banks go to Available only; Selected is empty by default.
            window.selectedBanks = [];
            await loadExistingBanks(country);
            updateSelectedBanksInModal();
            const modal = document.getElementById('bankSelectionModal');
            if (modal) {
                modal.classList.add('show');
                modal.style.display = 'block';
            }
        }

        async function loadExistingBanks(countryForApi) {
            let all = [];
            if (countryForApi) {
                try {
                    const url = buildApiUrl('processlistapi.php?action=get_banks_by_country&country=' + encodeURIComponent(countryForApi));
                    const res = await fetch(url);
                    const result = await res.json();
                    all = (result.success && result.data) ? result.data : [];
                    all = [...new Set([...all, ...(availableBanksList || [])])].sort((a, b) => a.localeCompare(b));
                } catch (e) {
                    all = [...(availableBanksList || [])].sort((a, b) => a.localeCompare(b));
                }
            } else {
                const select = document.getElementById('bank_bank');
                const existingOptions = [];
                if (select && select.options) {
                    for (let i = 0; i < select.options.length; i++) {
                        const v = (select.options[i].value || '').trim();
                        if (v) existingOptions.push(v);
                    }
                }
                all = [...new Set([...DEFAULT_BANKS, ...existingOptions, ...(availableBanksList || [])])].sort((a, b) => a.localeCompare(b));
            }
            const selectedSet = new Set(window.selectedBanks || []);
            const combined = all.filter(name => !selectedSet.has(name));
            availableBanksList = combined;

            const listEl = document.getElementById('existingBanks');
            if (!listEl) return;
            listEl.innerHTML = '';
            combined.forEach((name, index) => {
                const id = 'bank_' + (Date.now() + index);
                const item = document.createElement('div');
                item.className = 'bank-item';
                const left = document.createElement('div');
                left.className = 'bank-item-left';
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.name = 'available_banks';
                checkbox.value = name;
                checkbox.id = id;
                checkbox.dataset.bankId = id;
                const label = document.createElement('label');
                label.htmlFor = id;
                label.textContent = name;
                left.appendChild(checkbox);
                left.appendChild(label);
                const deleteBtn = document.createElement('button');
                deleteBtn.type = 'button';
                deleteBtn.className = 'bank-delete-btn';
                deleteBtn.title = 'Remove from list';
                deleteBtn.innerHTML = '&times;';
                deleteBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    removeBankFromAvailable(name, item);
                });
                item.appendChild(left);
                item.appendChild(deleteBtn);
                listEl.appendChild(item);
                checkbox.addEventListener('change', function () {
                    if (this.checked) moveBankToSelected(this);
                    else moveBankToAvailable(this);
                });
            });
        }

        function updateSelectedBanksInModal() {
            const selectedList = document.getElementById('selectedBanksInModal');
            if (!selectedList) return;
            selectedList.innerHTML = '';
            const current = (document.getElementById('bank_bank')?.value || '').trim();
            if (!window.selectedBanks) window.selectedBanks = [];
            if (current && !window.selectedBanks.includes(current)) {
                window.selectedBanks = [current];
            }
            if (window.selectedBanks.length > 0) {
                window.selectedBanks.forEach((name, idx) => {
                    const div = document.createElement('div');
                    div.className = 'selected-bank-modal-item';
                    const safeName = (name || '').replace(/'/g, "\\'");
                    div.innerHTML = '<span>' + escapeHtml(name) + '</span><button type="button" class="remove-bank-modal" onclick="moveBankBackToAvailable(\'' + safeName + '\', \'bid' + idx + '\')">&times;</button>';
                    selectedList.appendChild(div);
                });
            } else {
                selectedList.innerHTML = '<div class="no-banks">No banks selected</div>';
            }
        }

        function filterBanks() {
            const term = (document.getElementById('bankSearch')?.value || '').toLowerCase();
            const items = document.querySelectorAll('#existingBanks .bank-item');
            items.forEach(item => {
                const text = item.querySelector('label')?.textContent?.toLowerCase() || '';
                item.style.display = text.includes(term) ? 'block' : 'none';
            });
        }

        function moveBankToSelected(checkbox) {
            const name = checkbox.value;
            const id = checkbox.dataset.bankId;
            const item = checkbox.closest('.bank-item');
            if (!window.selectedBanks) window.selectedBanks = [];
            if (!window.selectedBanks.includes(name)) window.selectedBanks.push(name);
            const selectedList = document.getElementById('selectedBanksInModal');
            const placeholder = selectedList.querySelector('.no-banks');
            if (placeholder) placeholder.remove();
            const div = document.createElement('div');
            div.className = 'selected-bank-modal-item';
            const safeName = (name || '').replace(/'/g, "\\'");
            div.innerHTML = '<span>' + escapeHtml(name) + '</span><button type="button" class="remove-bank-modal" onclick="moveBankBackToAvailable(\'' + safeName + '\', \'' + id + '\')">&times;</button>';
            selectedList.appendChild(div);
            if (item) item.remove();
        }

        function moveBankBackToAvailable(bankName, bankId) {
            if (window.selectedBanks) {
                const idx = window.selectedBanks.indexOf(bankName);
                if (idx > -1) window.selectedBanks.splice(idx, 1);
            }
            const selectedList = document.getElementById('selectedBanksInModal');
            selectedList.querySelectorAll('.selected-bank-modal-item').forEach(item => {
                if (item.querySelector('span')?.textContent === bankName) item.remove();
            });
            if (!selectedList.querySelector('.selected-bank-modal-item')) {
                selectedList.innerHTML = '<div class="no-banks">No banks selected</div>';
            }
            const listEl = document.getElementById('existingBanks');
            if (!listEl) return;
            const id = 'bank_' + (bankId || Date.now());
            const newItem = document.createElement('div');
            newItem.className = 'bank-item';
            const left = document.createElement('div');
            left.className = 'bank-item-left';
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.name = 'available_banks';
            cb.value = bankName;
            cb.id = id;
            cb.dataset.bankId = id;
            const label = document.createElement('label');
            label.htmlFor = id;
            label.textContent = bankName;
            left.appendChild(cb);
            left.appendChild(label);
            const delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'bank-delete-btn';
            delBtn.innerHTML = '&times;';
            delBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                removeBankFromAvailable(bankName, newItem);
            });
            newItem.appendChild(left);
            newItem.appendChild(delBtn);
            listEl.appendChild(newItem);
            cb.addEventListener('change', function () {
                if (this.checked) moveBankToSelected(this);
                else moveBankToAvailable(this);
            });
        }

        function moveBankToAvailable(checkbox) {
            const name = checkbox.value;
            const item = checkbox.closest('.bank-item');
            if (window.selectedBanks) {
                const idx = window.selectedBanks.indexOf(name);
                if (idx > -1) window.selectedBanks.splice(idx, 1);
            }
            document.getElementById('selectedBanksInModal').querySelectorAll('.selected-bank-modal-item').forEach(el => {
                if (el.querySelector('span')?.textContent === name) el.remove();
            });
            const selectedList = document.getElementById('selectedBanksInModal');
            if (!selectedList.querySelector('.selected-bank-modal-item')) {
                selectedList.innerHTML = '<div class="no-banks">No banks selected</div>';
            }
        }

        function removeBankFromAvailable(bankName, itemEl) {
            if (itemEl && itemEl.parentNode) itemEl.remove();
        }

        function closeBankSelectionModal() {
            const modal = document.getElementById('bankSelectionModal');
            if (modal) {
                modal.classList.remove('show');
                modal.style.display = 'none';
            }
            const form = document.getElementById('addBankForm');
            if (form) form.reset();
            const search = document.getElementById('bankSearch');
            if (search) search.value = '';
            document.querySelectorAll('input[name="available_banks"]').forEach(cb => cb.checked = false);
        }

        async function confirmBanks() {
            const countrySelect = document.getElementById('bank_country');
            const country = (countrySelect && countrySelect.value) ? String(countrySelect.value).trim() : '';
            const banksToSave = [].concat(window.selectedBanks || [], availableBanksList || []);
            const uniqueBanks = [...new Set(banksToSave.map(function (n) { return (n || '').trim(); }).filter(Boolean))];
            if (country && uniqueBanks.length > 0) {
                try {
                    const fd = new FormData();
                    fd.append('country', country);
                    uniqueBanks.forEach(function (b) { fd.append('banks[]', b); });
                    const res = await fetch(buildApiUrl('processlistapi.php?action=save_country_banks'), { method: 'POST', body: fd });
                    const result = await res.json();
                    if (!result.success) console.warn('save_country_banks', result.error);
                } catch (e) { console.warn('save_country_banks', e); }
            }
            const select = document.getElementById('bank_bank');
            if (!select) { closeBankSelectionModal(); return; }
            const existing = new Set();
            for (let i = 0; i < select.options.length; i++) {
                const v = (select.options[i].value || '').trim();
                if (v) existing.add(v);
            }
            uniqueBanks.length && uniqueBanks.forEach(function (n) {
                if (!existing.has(n)) {
                    const opt = document.createElement('option');
                    opt.value = n;
                    opt.textContent = n;
                    select.appendChild(opt);
                    existing.add(n);
                }
            });
            if (window.selectedBanks && window.selectedBanks.length > 0) {
                select.value = window.selectedBanks[0] || '';
            }
            closeBankSelectionModal();
        }

        // Placeholder functions for add modals

        async function showAddAccountModal() {
            const modal = document.getElementById('addAccountModal');
            if (!modal) return;
            modal.style.display = 'block';
            modal.classList.add('show');
            await loadEditDataBank();
            await loadAccountCurrenciesBank(null, 'add');
            await loadAccountCompaniesBank(null, 'add');
        }

        function closeAddAccountModal() {
            const modal = document.getElementById('addAccountModal');
            if (modal) {
                modal.style.display = 'none';
                modal.classList.remove('show');
            }
            const form = document.getElementById('addAccountForm');
            if (form) form.reset();
            selectedCurrencyIdsForAdd = [];
            deletedCurrencyIds = [];
            const currentCompanyId = <?php echo json_encode($company_id); ?>;
            selectedCompanyIdsForAdd = currentCompanyId ? [currentCompanyId] : [];
        }

        function updateBankAddButtonTitles() {
            ['bank_card_merchant', 'bank_customer', 'bank_profit_account'].forEach(fieldId => {
                const btn = document.getElementById(fieldId);
                const addBtn = btn && btn.closest('.account-select-with-buttons') && btn.closest('.account-select-with-buttons').querySelector('.bank-add-btn');
                if (addBtn) addBtn.title = (btn.getAttribute('data-value') ? 'Edit Account' : 'Add New Account');
            });
        }

        function bankAccountPlusClick(fieldId) {
            const btn = document.getElementById(fieldId);
            const accountId = btn && btn.getAttribute('data-value');
            if (accountId) {
                openEditAccountModalFromBank(parseInt(accountId, 10));
            } else {
                showAddAccountModal();
            }
        }

        async function openEditAccountModalFromBank(accountId) {
            currentEditAccountIdForBank = accountId;
            selectedCompanyIdsForEdit = [];
            deletedCurrencyIds = [];
            try {
                const res = await fetch(buildApiUrl('getaccountapi.php?id=' + accountId));
                const result = await res.json();
                if (!result.success || !result.data) {
                    showNotification(result.error || 'Failed to load account', 'danger');
                    return;
                }
                const account = result.data;
                document.getElementById('edit_account_id').value = account.id;
                document.getElementById('edit_account_id_field').value = (account.account_id || '').toUpperCase();
                document.getElementById('edit_name').value = (account.name || '').toUpperCase();
                document.getElementById('edit_password').value = account.password || '';
                let alertType = account.alert_type || (account.alert_day ? String(account.alert_day).toLowerCase() : '');
                if (account.alert_day && parseInt(account.alert_day) >= 1 && parseInt(account.alert_day) <= 31) alertType = account.alert_day;
                document.getElementById('edit_alert_type').value = alertType;
                document.getElementById('edit_alert_start_date').value = account.alert_start_date || account.alert_specific_date || '';
                document.getElementById('edit_alert_amount').value = account.alert_amount || '';
                document.getElementById('edit_remark').value = (account.remark || '').toUpperCase();
                const paymentAlert = account.payment_alert == 1 ? '1' : '0';
                const radio = document.querySelector('input[name="payment_alert"][value="' + paymentAlert + '"]');
                if (radio) radio.checked = true;
                toggleAlertFieldsBank('edit');
                await loadEditDataBank();
                const roleSelect = document.getElementById('edit_role');
                if (roleSelect) {
                    roleSelect.innerHTML = '<option value="">Select Role</option>';
                    const roles = bankAccountRoles.length ? bankAccountRoles : ['PROFIT', 'STAFF', 'OWNER'];
                    const accountRoleUpper = (account.role || '').trim().toUpperCase();
                    roles.forEach(code => {
                        const opt = document.createElement('option');
                        opt.value = code;
                        opt.textContent = code;
                        if (String(code).toUpperCase() === accountRoleUpper) opt.selected = true;
                        roleSelect.appendChild(opt);
                    });
                }
                await loadAccountCurrenciesBank(accountId, 'edit');
                await loadAccountCompaniesBank(accountId, 'edit');
                document.getElementById('editAccountModal').style.display = 'block';
                document.getElementById('editAccountModal').classList.add('show');
            } catch (e) {
                console.error('openEditAccountModalFromBank', e);
                showNotification('Failed to load account', 'danger');
            }
        }

        function closeEditAccountModalFromBank() {
            const modal = document.getElementById('editAccountModal');
            if (modal) {
                modal.style.display = 'none';
                modal.classList.remove('show');
            }
            const form = document.getElementById('editAccountForm');
            if (form) form.reset();
            selectedCompanyIdsForEdit = [];
            deletedCurrencyIds = [];
            currentEditAccountIdForBank = null;
        }

        function refreshBankAccountDropdowns() {
            const accounts = window.bankAccounts || [];
            ['bank_card_merchant', 'bank_customer'].forEach(buttonId => {
                const btn = document.getElementById(buttonId);
                const dropdown = document.getElementById(buttonId + '_dropdown');
                const optionsContainer = dropdown?.querySelector('.custom-select-options');
                if (!optionsContainer) return;
                optionsContainer.innerHTML = '';
                accounts.forEach(account => {
                    const option = document.createElement('div');
                    option.className = 'custom-select-option';
                    option.setAttribute('data-value', account.id);
                    option.textContent = account.account_id || account.name || '';
                    option.addEventListener('click', () => {
                        if (btn) {
                            btn.textContent = account.account_id || account.name || '';
                            btn.setAttribute('data-value', account.id);
                        }
                        if (dropdown) dropdown.style.display = 'none';
                    });
                    optionsContainer.appendChild(option);
                });
            });
        }

        function populateProfitSharingAccountSelect(selectEl) {
            if (!selectEl) return;
            selectEl.innerHTML = '<option value="">Select Account</option>';
            const accounts = window.bankAccounts || [];
            accounts.forEach(acc => {
                const opt = document.createElement('option');
                opt.value = acc.id;
                opt.textContent = acc.account_id || acc.name || String(acc.id);
                selectEl.appendChild(opt);
            });
        }

        function addProfitSharingRow() {
            const container = document.getElementById('profitSharingRowsContainer');
            if (!container) return;
            const row = document.createElement('div');
            row.className = 'form-row bank-row-two-cols profit-sharing-row';
            const selectId = 'profit_sharing_account_' + Date.now();
            const amountId = 'profit_sharing_amount_' + Date.now();
            row.innerHTML = '<div class="form-group"><label for="' + selectId + '">Account</label><select id="' + selectId + '" name="account_id" class="bank-select profit-sharing-account"><option value="">Select Account</option></select></div><div class="form-group"><label for="' + amountId + '">Amount</label><input type="number" id="' + amountId + '" name="amount" class="bank-input profit-sharing-amount" placeholder="Enter amount" step="0.01" min="0"></div>';
            container.appendChild(row);
            const newSelect = row.querySelector('.profit-sharing-account');
            populateProfitSharingAccountSelect(newSelect);
        }

        async function showAddProfitSharingModal() {
            if (!window.bankAccounts || window.bankAccounts.length === 0) {
                await loadBankAccounts();
            }
            const container = document.getElementById('profitSharingRowsContainer');
            if (container) {
                const rows = container.querySelectorAll('.profit-sharing-row');
                for (let i = 1; i < rows.length; i++) rows[i].remove();
            }
            const selectEl = document.getElementById('profit_sharing_account');
            if (selectEl) {
                populateProfitSharingAccountSelect(selectEl);
                selectEl.value = '';
            }
            const amountEl = document.getElementById('profit_sharing_amount');
            if (amountEl) amountEl.value = '';
            const modal = document.getElementById('profitSharingModal');
            if (modal) {
                modal.style.display = 'block';
                modal.classList.add('show');
            }
        }

        function closeProfitSharingModal() {
            const modal = document.getElementById('profitSharingModal');
            if (modal) {
                modal.style.display = 'none';
                modal.classList.remove('show');
            }
            const container = document.getElementById('profitSharingRowsContainer');
            if (container) {
                const rows = container.querySelectorAll('.profit-sharing-row');
                for (let i = 1; i < rows.length; i++) rows[i].remove();
            }
            const form = document.getElementById('profitSharingForm');
            if (form) form.reset();
        }

        // Selected Profit Sharing list (array of { accountId, accountText, amount })
        window.selectedProfitSharingEntries = [];

        function renderSelectedProfitSharing() {
            const container = document.getElementById('selectedProfitSharingList');
            const mainInput = document.getElementById('bank_profit_sharing');
            if (!container) return;
            const entries = window.selectedProfitSharingEntries || [];
            if (entries.length === 0) {
                container.innerHTML = '<div class="no-countries">No profit sharing selected</div>';
                if (mainInput) mainInput.value = '';
                return;
            }
            const parts = [];
            container.innerHTML = '';
            entries.forEach(function (entry, index) {
                const amt = entry.amount;
                const displayAmount = (amt !== '' && amt != null && !isNaN(parseFloat(amt))) ? parseFloat(amt).toFixed(2) : (amt || '');
                const text = (entry.accountText || '') + ' - ' + displayAmount;
                parts.push(text);
                const div = document.createElement('div');
                div.className = 'selected-country-modal-item';
                div.dataset.index = String(index);
                div.innerHTML = '<span>' + (typeof escapeHtml === 'function' ? escapeHtml(text) : text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')) + '</span><button type="button" class="remove-country-modal" onclick="removeProfitSharingEntry(' + index + ')">&times;</button>';
                container.appendChild(div);
            });
            if (mainInput) mainInput.value = parts.join(', ');
        }

        function removeProfitSharingEntry(index) {
            if (!window.selectedProfitSharingEntries || index < 0 || index >= window.selectedProfitSharingEntries.length) return;
            window.selectedProfitSharingEntries.splice(index, 1);
            renderSelectedProfitSharing();
        }

        document.addEventListener('DOMContentLoaded', function () {
            // Add Account modal: payment alert toggle
            document.querySelectorAll('input[name="add_payment_alert"]').forEach(radio => {
                radio.addEventListener('change', function () { toggleAlertFieldsBank('add'); });
            });
            // Edit Account modal: payment alert toggle
            document.querySelectorAll('input[name="payment_alert"]').forEach(radio => {
                radio.addEventListener('change', function () { toggleAlertFieldsBank('edit'); });
            });
            // Edit Account modal: uppercase for edit_name, edit_remark, editCurrencyInput
            ['edit_name', 'edit_remark', 'editCurrencyInput'].forEach(inputId => {
                const input = document.getElementById(inputId);
                if (input) {
                    input.addEventListener('input', function () { forceUppercase(this); });
                    input.addEventListener('paste', function () { setTimeout(() => forceUppercase(this), 0); });
                }
            });
            const editCurrencyInput = document.getElementById('editCurrencyInput');
            if (editCurrencyInput) {
                editCurrencyInput.addEventListener('keypress', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); addCurrencyFromInputBank('edit'); }
                });
            }
            // Add Account modal: uppercase for account fields and currency input
            ['add_account_id', 'add_name', 'add_remark', 'addCurrencyInput'].forEach(inputId => {
                const input = document.getElementById(inputId);
                if (input) {
                    input.addEventListener('input', function () { forceUppercase(this); });
                    input.addEventListener('paste', function () { setTimeout(() => forceUppercase(this), 0); });
                }
            });
            const addCurrencyInput = document.getElementById('addCurrencyInput');
            if (addCurrencyInput) {
                addCurrencyInput.addEventListener('keypress', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); addCurrencyFromInputBank('add'); }
                });
            }

            // 统一管理需要大写的输入框
            const uppercaseInputs = [
                'add_process_id',
                'new_description_name',
                'add_remove_words',
                'add_replace_word_from',
                'add_replace_word_to',
                'add_remarks',
                'edit_remove_words',
                'edit_replace_word_from',
                'edit_replace_word_to',
                'edit_remarks'
            ];

            // 为所有需要大写的输入框添加事件监听
            uppercaseInputs.forEach(inputId => {
                const input = document.getElementById(inputId);
                if (input) {
                    // 输入时转换为大写
                    input.addEventListener('input', function () {
                        forceUppercase(this);
                    });

                    // 粘贴时也转换为大写
                    input.addEventListener('paste', function () {
                        setTimeout(() => forceUppercase(this), 0);
                    });
                }
            });

            // 描述搜索框：只允许字母和数字
            const descSearchInput = document.getElementById('descriptionSearch');
            if (descSearchInput) {
                descSearchInput.addEventListener('input', function () {
                    const cursorPosition = this.selectionStart;
                    const filteredValue = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase();
                    this.value = filteredValue;
                    this.setSelectionRange(cursorPosition, cursorPosition);
                });

                descSearchInput.addEventListener('paste', function () {
                    setTimeout(() => {
                        const cursorPosition = this.selectionStart;
                        const filteredValue = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase();
                        this.value = filteredValue;
                        this.setSelectionRange(cursorPosition, cursorPosition);
                    }, 0);
                });
            }

            // 处理 multi-use 复选框变化
            const multiUseToggle = document.getElementById('add_multi_use');
            const multiUsePanel = document.getElementById('multi_use_processes');
            const processInput = document.getElementById('add_process_id');
            if (multiUseToggle && multiUsePanel && processInput) {
                multiUseToggle.addEventListener('change', async function () {
                    if (this.checked) {
                        multiUsePanel.style.display = 'block';
                        processInput.disabled = true;
                        processInput.value = '';
                        processInput.style.backgroundColor = '#f8f9fa';
                        processInput.style.cursor = 'not-allowed';
                        processInput.removeAttribute('required');
                        // 勾选 Multi-Process 后：若已选 Copy From，自动将 Description 与 Copy From 的账号同步（含 Data Capture Formula 在提交时由后端复制）
                        const copyFromSelect = document.getElementById('add_copy_from');
                        if (copyFromSelect && copyFromSelect.value) {
                            try {
                                await syncFormFromCopyFrom(copyFromSelect.value);
                            } catch (e) {
                                console.error('Multi-Process: sync from Copy From failed', e);
                            }
                        }
                    } else {
                        multiUsePanel.style.display = 'none';
                        const selectedDisplay = document.getElementById('selected_processes_display');
                        if (selectedDisplay) selectedDisplay.style.display = 'none';
                        processInput.disabled = false;
                        processInput.style.backgroundColor = 'white';
                        processInput.style.cursor = 'default';
                        processInput.setAttribute('required', 'required');
                        const listDiv = document.getElementById('selected_processes_list');
                        if (listDiv) listDiv.innerHTML = '';
                        if (window.selectedProcesses) window.selectedProcesses = [];
                        // uncheck all
                        document.querySelectorAll('#process_checkboxes input[type="checkbox"]').forEach(cb => cb.checked = false);
                    }
                });
            }

            // 从 Copy From 同步到表单（含 Description/账号；Data Capture Formula 在提交时由后端复制）
            async function syncFormFromCopyFrom(processId) {
                if (!processId) return;
                const currencySelect = document.getElementById('add_currency');
                if (!currencySelect || currencySelect.options.length <= 1) {
                    await loadAddProcessData();
                }
                const response = await fetch(buildApiUrl(`addprocessapi.php?action=copy_from&process_id=${processId}`));
                const result = await response.json();
                if (!result.success || !result.data) {
                    throw new Error(result.error || 'Unknown error');
                }
                const data = result.data;
                // 填充货币
                if (data.currency_id) {
                    const currencyIdStr = String(data.currency_id);

                    // 函数：尝试设置 currency 值
                    const setCurrencyValue = () => {
                        // 检查选项是否存在
                        const optionExists = Array.from(currencySelect.options).some(opt => opt.value === currencyIdStr);
                        if (optionExists) {
                            currencySelect.value = currencyIdStr;
                            console.log('Currency set successfully:', currencyIdStr);
                            return true;
                        }
                        return false;
                    };

                    // 立即尝试设置
                    if (!setCurrencyValue()) {
                        // 如果失败，等待下拉列表加载完成
                        console.log('Currency dropdown not ready, waiting...');
                        let attempts = 0;
                        const maxAttempts = 10; // 减少到10次（1秒）
                        const checkInterval = setInterval(() => {
                            attempts++;
                            if (setCurrencyValue() || attempts >= maxAttempts) {
                                clearInterval(checkInterval);
                                if (attempts >= maxAttempts && currencySelect.value !== currencyIdStr) {
                                    // 检查是否有警告信息
                                    if (data.currency_warning) {
                                        console.warn('Currency ID', currencyIdStr, 'does not belong to current company. Available options:', Array.from(currencySelect.options).map(opt => ({ value: opt.value, text: opt.text })));
                                        showNotification('Warning: The original currency does not belong to your company. Please select a currency manually.', 'danger');
                                    } else {
                                        console.error('Failed to set currency after', maxAttempts, 'attempts. Currency ID:', currencyIdStr, 'Available options:', Array.from(currencySelect.options).map(opt => ({ value: opt.value, text: opt.text })));
                                        showNotification('Warning: Currency could not be set automatically. Please select manually.', 'danger');
                                    }
                                }
                            }
                        }, 100);
                    }
                } else if (data.currency_warning) {
                    // 如果 currency_id 为空但有警告，说明原货币不属于当前公司
                    // 尝试根据货币代码自动匹配当前公司的相同货币
                    if (data.currency_code) {
                        const currencyCode = data.currency_code.toUpperCase();
                        const matchingOption = Array.from(currencySelect.options).find(opt =>
                            opt.textContent.toUpperCase() === currencyCode
                        );
                        if (matchingOption) {
                            currencySelect.value = matchingOption.value;
                            console.log('Auto-matched currency by code:', currencyCode, '-> ID:', matchingOption.value);
                        } else {
                            showNotification('Warning: The original currency (' + currencyCode + ') does not belong to your company. Please select a currency manually.', 'danger');
                        }
                    } else {
                        showNotification('Warning: The original currency does not belong to your company. Please select a currency manually.', 'danger');
                    }
                }

                // 填充移除词汇
                if (data.remove_word) {
                    document.getElementById('add_remove_words').value = data.remove_word;
                }

                // 填充替换词汇
                if (data.replace_word_from) {
                    document.getElementById('add_replace_word_from').value = data.replace_word_from;
                }
                if (data.replace_word_to) {
                    document.getElementById('add_replace_word_to').value = data.replace_word_to;
                }

                // 填充备注
                if (data.remark) {
                    // 如果 remark 是 JSON 格式，尝试解析
                    try {
                        const meta = JSON.parse(data.remark);
                        if (meta.user_remarks) {
                            document.getElementById('add_remarks').value = meta.user_remarks;
                        } else {
                            document.getElementById('add_remarks').value = data.remark;
                        }
                    } catch (e) {
                        document.getElementById('add_remarks').value = data.remark;
                    }
                }

                // 填充 day use checkboxes
                if (data.day_use) {
                    const dayIdsArray = data.day_use.split(',');
                    dayIdsArray.forEach(dayId => {
                        const checkbox = document.querySelector(`#day_checkboxes input[name="day_use[]"][value="${dayId.trim()}"]`);
                        if (checkbox) checkbox.checked = true;
                    });
                    // 更新 All Day 复选框状态
                    updateAllDayCheckbox('add');
                }

                // 自动选择 description
                if (data.description_name) {
                    // 先清空之前选择的 description
                    if (window.selectedDescriptions) {
                        // 将之前选择的 description 移回可用列表
                        window.selectedDescriptions.forEach(descName => {
                            const existingCheckbox = document.querySelector(`#existingDescriptions input[type="checkbox"][value="${CSS.escape(descName)}"]`);
                            if (existingCheckbox) {
                                existingCheckbox.checked = false;
                            }
                        });
                        window.selectedDescriptions = [];
                    }

                    // 确保 descriptions 列表已加载
                    await loadExistingDescriptions();

                    // 查找对应的 description 复选框
                    const descriptionName = data.description_name.trim();
                    const descriptionCheckbox = document.querySelector(`#existingDescriptions input[type="checkbox"][value="${CSS.escape(descriptionName)}"]`);

                    if (descriptionCheckbox) {
                        // 选中该复选框
                        descriptionCheckbox.checked = true;
                        // 移动到已选择列表
                        moveDescriptionToSelected(descriptionCheckbox);
                        // 更新显示
                        document.getElementById('add_description').value = `${window.selectedDescriptions.length} description(s) selected`;
                        displaySelectedDescriptions(window.selectedDescriptions);
                    } else {
                        console.warn('Description not found in available list:', descriptionName);
                        // 如果找不到，仍然设置到 selectedDescriptions 中
                        if (!window.selectedDescriptions) {
                            window.selectedDescriptions = [];
                        }
                        if (!window.selectedDescriptions.includes(descriptionName)) {
                            window.selectedDescriptions.push(descriptionName);
                            document.getElementById('add_description').value = `${window.selectedDescriptions.length} description(s) selected`;
                            displaySelectedDescriptions(window.selectedDescriptions);
                        }
                    }
                }
            }

            // 处理 copy-from 下拉选择变化
            const copyFromSelect = document.getElementById('add_copy_from');
            if (copyFromSelect) {
                copyFromSelect.addEventListener('change', async function () {
                    const processId = this.value;
                    if (!processId) {
                        document.getElementById('add_currency').value = '';
                        document.getElementById('add_remove_words').value = '';
                        document.getElementById('add_replace_word_from').value = '';
                        document.getElementById('add_replace_word_to').value = '';
                        document.getElementById('add_remarks').value = '';
                        document.querySelectorAll('#day_checkboxes input[name="day_use[]"]').forEach(cb => cb.checked = false);
                        if (window.selectedDescriptions) window.selectedDescriptions = [];
                        document.getElementById('add_description').value = '';
                        document.getElementById('selected_descriptions_display').style.display = 'none';
                        document.getElementById('selected_descriptions_list').innerHTML = '';
                        document.querySelectorAll('#existingDescriptions input[type="checkbox"]').forEach(cb => cb.checked = false);
                        return;
                    }
                    try {
                        await syncFormFromCopyFrom(processId);
                    } catch (error) {
                        console.error('Error loading copy-from data:', error);
                        showNotification('Failed to load process data: ' + (error.message || 'Unknown error'), 'danger');
                    }
                });
            }

            // 检查 URL 参数并显示相应的消息
            const urlParams = new URLSearchParams(window.location.search);
            const errorParam = urlParams.get('error');
            const successParam = urlParams.get('success');

            if (errorParam === 'process_linked_to_formula') {
                showNotification('Cannot delete: This process is linked to a formula. Please remove the related formula records first.', 'danger');
                // 清除 URL 参数
                window.history.replaceState({}, document.title, window.location.pathname);
            } else if (errorParam === 'bank_has_day_start') {
                showNotification('Delete failed: Processes with Day Start set cannot be deleted.', 'danger');
                window.history.replaceState({}, document.title, window.location.pathname);
            } else if (errorParam === 'no_inactive_processes') {
                showNotification('Cannot delete: Only inactive processes can be deleted.', 'danger');
                window.history.replaceState({}, document.title, window.location.pathname);
            } else if (errorParam === 'delete_failed') {
                showNotification('Delete failed. Please try again.', 'danger');
                window.history.replaceState({}, document.title, window.location.pathname);
            } else if (successParam === 'deleted') {
                showNotification('Deleted successfully!', 'success');
                window.history.replaceState({}, document.title, window.location.pathname);
            }

            console.log('DOM loaded, calling fetchProcesses...');
            try {
                loadPermissionButtons().then(() => {
                    fetchProcesses();
                });
            } catch (error) {
                console.error('Error in fetchProcesses:', error);
                showError('Error loading data: ' + error.message);
            }
        });

        window.addEventListener('resize', function () {
            if (selectedPermission === 'Bank') syncBankTableColumnWidth();
        });

        // 切换 process list 的 company
        // 当前选择的权限
        let selectedPermission = null;

        // 加载权限按钮
        async function loadPermissionButtons() {
            const currentCompanyId = <?php echo json_encode($company_id); ?>;
            const currentCompanyCode = <?php echo json_encode(isset($user_companies) && count($user_companies) > 0 ? array_values(array_filter($user_companies, function ($c) use ($company_id) {
                return $c['id'] == $company_id;
            }))[0]['company_id'] ?? '' : ''); ?>;

            if (!currentCompanyCode) {
                document.getElementById('process-list-permission-filter').style.display = 'none';
                return;
            }

            try {
                const response = await fetch('domainapi.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'get_company_permissions',
                        company_id: currentCompanyCode
                    })
                });

                const result = await response.json();
                const permissions = result.success && result.permissions ? result.permissions : ['Gambling', 'Bank', 'Loan', 'Rate', 'Money'];

                const permissionContainer = document.getElementById('process-list-permission-buttons');
                permissionContainer.innerHTML = '';

                if (permissions.length > 0) {
                    document.getElementById('process-list-permission-filter').style.display = 'flex';

                    permissions.forEach(permission => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'process-company-btn';
                        btn.textContent = permission;
                        btn.dataset.permission = permission;
                        btn.onclick = () => switchPermission(permission);
                        permissionContainer.appendChild(btn);
                    });

                    // 尝试从 localStorage 恢复之前选择的权限
                    const savedPermission = localStorage.getItem(`selectedPermission_${currentCompanyCode}`);
                    if (savedPermission && permissions.includes(savedPermission)) {
                        switchPermission(savedPermission);
                    } else if (permissions.length > 0 && !selectedPermission) {
                        // 如果没有保存的权限，默认选择第一个
                        switchPermission(permissions[0]);
                    }
                } else {
                    document.getElementById('process-list-permission-filter').style.display = 'none';
                }
            } catch (error) {
                console.error('Error loading permissions:', error);
                document.getElementById('process-list-permission-filter').style.display = 'none';
            }
        }

        // 切换权限
        function switchPermission(permission) {
            selectedPermission = permission;

            // 保存到 localStorage
            const currentCompanyCode = <?php echo json_encode(isset($user_companies) && count($user_companies) > 0 ? array_values(array_filter($user_companies, function ($c) use ($company_id) {
                return $c['id'] == $company_id;
            }))[0]['company_id'] ?? '' : ''); ?>;
            if (currentCompanyCode) {
                localStorage.setItem(`selectedPermission_${currentCompanyCode}`, permission);
            }

            // 更新按钮状态
            const buttons = document.querySelectorAll('#process-list-permission-buttons .process-company-btn');
            buttons.forEach(btn => {
                if (btn.dataset.permission === permission) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });

            // 根据类别显示/隐藏 waiting 复选框和更新表格头部
            const waitingSection = document.getElementById('waitingCheckboxSection');
            const gamblingHeaders = document.querySelectorAll('.gambling-header');
            const bankHeaders = document.querySelectorAll('.bank-header');
            const selectAllGambling = document.getElementById('selectAllProcesses');
            const selectAllBank = document.getElementById('selectAllBankProcesses');
            const tableHeader = document.getElementById('tableHeader');
            const processCards = document.querySelectorAll('.process-card');

            const processTableBodyEl = document.getElementById('processTableBody');
            const processTableWrapperEl = document.getElementById('processTableWrapper');
            const bankTableWrapperEl = document.getElementById('bankTableWrapper');
            if (permission === 'Bank') {
                if (processTableWrapperEl) processTableWrapperEl.style.display = 'none';
                if (bankTableWrapperEl) bankTableWrapperEl.style.display = 'block';
                if (processTableBodyEl) processTableBodyEl.classList.add('bank-mode');
                if (waitingSection) waitingSection.style.display = 'flex';
                gamblingHeaders.forEach(header => header.style.display = 'none');
                bankHeaders.forEach(header => header.style.display = 'flex');
                if (selectAllGambling) selectAllGambling.style.display = 'none';
                if (selectAllBank) selectAllBank.style.display = 'inline-block';
                if (tableHeader) tableHeader.style.gridTemplateColumns = BANK_GRID_TEMPLATE_COLUMNS;
                processCards.forEach(card => { card.style.gridTemplateColumns = BANK_GRID_TEMPLATE_COLUMNS; });
            } else {
                if (processTableWrapperEl) processTableWrapperEl.style.display = 'grid';
                if (bankTableWrapperEl) bankTableWrapperEl.style.display = 'none';
                if (processTableBodyEl) processTableBodyEl.classList.remove('bank-mode');
                if (processTableBodyEl) processTableBodyEl.style.removeProperty('--table-header-width');
                if (waitingSection) {
                    waitingSection.style.display = 'none';
                }
                // 显示 Gambling 表格头部，隐藏 Bank 表格头部
                gamblingHeaders.forEach(header => header.style.display = 'flex');
                bankHeaders.forEach(header => header.style.display = 'none');
                if (selectAllGambling) selectAllGambling.style.display = 'inline-block';
                if (selectAllBank) selectAllBank.style.display = 'none';

                // 恢复 Gambling 表格的列数（7列）
                if (tableHeader) {
                    tableHeader.style.gridTemplateColumns = '0.3fr 0.8fr 1.1fr 0.2fr 0.3fr 1fr 0.3fr';
                }
                processCards.forEach(card => {
                    card.style.gridTemplateColumns = '0.3fr 0.8fr 1.1fr 0.2fr 0.3fr 1.1fr 0.19fr';
                });
            }

            // 重新加载数据
            currentPage = 1;
            fetchProcesses();
        }

        async function switchProcessListCompany(companyId) {
            // 先更新 session
            try {
                const response = await fetch(`update_company_session_api.php?company_id=${companyId}`);
                const result = await response.json();
                if (!result.success) {
                    console.error('Failed to update session:', result.error);
                    // 即使 API 失败，也继续刷新页面（PHP 端会处理）
                }
            } catch (error) {
                console.error('Error updating session:', error);
                // 即使 API 失败，也继续刷新页面（PHP 端会处理）
            }

            const url = new URL(window.location.href);
            url.searchParams.set('company_id', companyId);
            window.location.href = url.toString();
        }
    </script>
</body>

</html>

</html>