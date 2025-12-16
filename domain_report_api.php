<?php
session_start();
header('Content-Type: application/json');
require_once 'config.php';

try {
    $action = isset($_GET['action']) ? trim($_GET['action']) : '';

    /**
     * 解析并验证 company_id
     */
    $resolveCompanyId = function () use ($pdo) {
        if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
            $requestedCompanyId = (int)$_GET['company_id'];
            $userRole = strtolower($_SESSION['role'] ?? '');

            if ($userRole === 'owner') {
                $ownerId = $_SESSION['owner_id'] ?? $_SESSION['user_id'] ?? null;
                if (!$ownerId) {
                    throw new Exception('缺少 Owner 信息');
                }

                $stmt = $pdo->prepare("SELECT id FROM company WHERE id = ? AND owner_id = ? LIMIT 1");
                $stmt->execute([$requestedCompanyId, $ownerId]);
                if (!$stmt->fetchColumn()) {
                    throw new Exception('无权访问该公司');
                }

                return $requestedCompanyId;
            }

            if (!isset($_SESSION['company_id'])) {
                throw new Exception('缺少公司信息');
            }

            if ((int)$_SESSION['company_id'] !== $requestedCompanyId) {
                throw new Exception('无权访问该公司');
            }

            return $requestedCompanyId;
        }

        if (!isset($_SESSION['company_id'])) {
            throw new Exception('缺少公司信息');
        }

        return (int)$_SESSION['company_id'];
    };

    $company_id = $resolveCompanyId();

    if ($action === 'processes') {
        $stmt = $pdo->prepare("
            SELECT 
                p.id,
                p.process_id,
                d.name AS description
            FROM process p
            LEFT JOIN description d ON p.description_id = d.id
            WHERE p.company_id = ?
            ORDER BY p.process_id ASC
        ");
        $stmt->execute([$company_id]);
        $processes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted = array_map(function ($row) {
            $label = $row['process_id'];
            if (!empty($row['description'])) {
                $label .= ' (' . $row['description'] . ')';
            }

            return [
                'id' => (int)$row['id'],
                'process' => $row['process_id'],
                'description' => $row['description'],
                'display_text' => $label
            ];
        }, $processes);

        echo json_encode([
            'success' => true,
            'data' => $formatted
        ]);
        exit;
    }

    $date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
    $date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
    $process_id = isset($_GET['process_id']) ? (int)$_GET['process_id'] : null;

    if (empty($date_from) || empty($date_to)) {
        throw new Exception('开始日期和结束日期不能为空');
    }

    $date_from_obj = DateTime::createFromFormat('Y-m-d', $date_from);
    $date_to_obj = DateTime::createFromFormat('Y-m-d', $date_to);

    if (!$date_from_obj || !$date_to_obj) {
        throw new Exception('日期格式不正确，请使用 YYYY-MM-DD');
    }

    if ($date_from_obj > $date_to_obj) {
        throw new Exception('开始日期不能大于结束日期');
    }

    $sql = "
        SELECT 
            p.id AS process_pk,
            p.process_id,
            d.name AS description_name,
            COALESCE(SUM(ABS(dcd.processed_amount)), 0) AS turnover_total,
            COALESCE(SUM(CASE WHEN dcd.processed_amount > 0 THEN dcd.processed_amount ELSE 0 END), 0) AS win_total,
            COALESCE(SUM(CASE WHEN dcd.processed_amount < 0 THEN ABS(dcd.processed_amount) ELSE 0 END), 0) AS lose_total
        FROM data_captures dc
        INNER JOIN process p ON dc.process_id = p.id
        LEFT JOIN description d ON p.description_id = d.id
        INNER JOIN data_capture_details dcd ON dc.id = dcd.capture_id
        WHERE p.company_id = ?
          AND dc.capture_date BETWEEN ? AND ?
    ";

    $params = [$company_id, $date_from, $date_to];

    if (!empty($process_id)) {
        $sql .= " AND p.id = ? ";
        $params[] = $process_id;
    }

    $sql .= "
        GROUP BY p.id, p.process_id, d.name
        ORDER BY p.process_id ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $report_data = [];
    $total_turnover = 0;
    $total_win = 0;
    $total_lose = 0;

    foreach ($rows as $row) {
        $turnover = (float)$row['turnover_total'];
        $win = (float)$row['win_total'];
        $lose = (float)$row['lose_total'];
        $winLose = $win - $lose;

        $report_data[] = [
            'process_id' => (int)$row['process_pk'],
            'process' => $row['process_id'],
            'description' => $row['description_name'],
            'turnover' => $turnover,
            'win' => $win,
            'lose' => $lose,
            'win_lose' => $winLose
        ];

        $total_turnover += $turnover;
        $total_win += $win;
        $total_lose += $lose;
    }

    echo json_encode([
        'success' => true,
        'data' => $report_data,
        'totals' => [
            'turnover' => $total_turnover,
            'win' => $total_win,
            'lose' => $total_lose,
            'win_lose' => $total_win - $total_lose
        ],
        'date_from' => $date_from,
        'date_to' => $date_to
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('Domain Report API Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => '数据库查询失败'
    ]);
}

