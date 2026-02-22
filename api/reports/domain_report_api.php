<?php
/**
 * Domain Report API: process list + report by process (turnover, win, lose)
 * Path: api/reports/domain_report_api.php
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../permissions.php';
session_start();

function resolveCompanyId(PDO $pdo): int {
    if (!isset($_SESSION['user_id']) && !isset($_SESSION['login_id'])) {
        throw new Exception('User not logged in');
    }
    if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
        $requested = (int) $_GET['company_id'];
        $role = strtolower($_SESSION['role'] ?? '');
        if ($role === 'owner') {
            $ownerId = $_SESSION['owner_id'] ?? $_SESSION['user_id'] ?? null;
            if ($ownerId !== null) {
                $stmt = $pdo->prepare('SELECT id FROM company WHERE id = ? AND owner_id = ?');
                $stmt->execute([$requested, $ownerId]);
                if ($stmt->fetchColumn()) {
                    return $requested;
                }
            }
            throw new Exception('No access to this company');
        }
        if (isset($_SESSION['company_id']) && (int) $_SESSION['company_id'] === $requested) {
            return $requested;
        }
        throw new Exception('No access to this company');
    }
    if (!isset($_SESSION['company_id'])) {
        throw new Exception('Company is required');
    }
    return (int) $_SESSION['company_id'];
}

function jsonOut(bool $success, $data = null, string $message = '', array $extra = []): void {
    $out = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $out['data'] = $data;
    }
    foreach ($extra as $k => $v) {
        $out[$k] = $v;
    }
    echo json_encode($out);
}

try {
    $companyId = resolveCompanyId($pdo);
    $action = isset($_GET['action']) ? trim($_GET['action']) : '';

    if ($action === 'processes') {
        $sql = "SELECT p.id, p.process_id, d.name AS description_name
                FROM process p
                LEFT JOIN description d ON p.description_id = d.id
                WHERE p.company_id = ? AND p.status = 'active'";
        $params = [$companyId];
        list($sql, $params) = filterProcessesByPermissions($pdo, $sql, $params);
        $sql .= " ORDER BY p.process_id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                'id' => (string) $r['id'],
                'display_text' => $r['description_name']
                ? $r['process_id'] . ' (' . $r['description_name'] . ')'
                : $r['process_id']
            ];
        }
        jsonOut(true, $data);
        return;
    }

    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo = trim($_GET['date_to'] ?? '');
    if ($dateFrom === '' || $dateTo === '') {
        http_response_code(400);
        jsonOut(false, null, 'date_from and date_to are required');
        return;
    }
    $dateFromObj = DateTime::createFromFormat('Y-m-d', $dateFrom);
    $dateToObj = DateTime::createFromFormat('Y-m-d', $dateTo);
    if (!$dateFromObj || !$dateToObj) {
        http_response_code(400);
        jsonOut(false, null, 'Invalid date format (use Y-m-d)');
        return;
    }
    if ($dateFromObj > $dateToObj) {
        http_response_code(400);
        jsonOut(false, null, 'date_from must not be after date_to');
        return;
    }

    $processIdFilter = isset($_GET['process_id']) && $_GET['process_id'] !== '' ? (int) $_GET['process_id'] : null;

    $sql = "SELECT
                dc.process_id,
                p.process_id AS process_code,
                d.name AS description_name,
                COALESCE(SUM(ABS(dcd.processed_amount)), 0) AS turnover,
                COALESCE(SUM(CASE WHEN dcd.processed_amount > 0 THEN dcd.processed_amount ELSE 0 END), 0) AS win,
                COALESCE(SUM(CASE WHEN dcd.processed_amount < 0 THEN dcd.processed_amount ELSE 0 END), 0) AS lose
            FROM data_captures dc
            JOIN data_capture_details dcd ON dcd.capture_id = dc.id
            JOIN process p ON p.id = dc.process_id
            LEFT JOIN description d ON p.description_id = d.id
            WHERE dc.company_id = ? AND dc.capture_date BETWEEN ? AND ?";
    $params = [$companyId, $dateFrom, $dateTo];
    if ($processIdFilter !== null) {
        $sql .= " AND dc.process_id = ?";
        $params[] = $processIdFilter;
    }
    $sql .= " GROUP BY dc.process_id, p.process_id, d.name ORDER BY p.process_id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $list = [];
    $totals = ['turnover' => 0, 'win' => 0, 'lose' => 0, 'win_lose' => 0];
    foreach ($rows as $r) {
        $win = (float) $r['win'];
        $lose = (float) $r['lose'];
        $turnover = (float) $r['turnover'];
        $winLose = $win + $lose;
        $totals['turnover'] += $turnover;
        $totals['win'] += $win;
        $totals['lose'] += $lose;
        $totals['win_lose'] += $winLose;
        $list[] = [
            'process' => $r['process_code'],
            'description' => $r['description_name'],
            'turnover' => $turnover,
            'win' => $win,
            'lose' => $lose,
            'win_lose' => $winLose
        ];
    }

    jsonOut(true, $list, '', ['totals' => $totals]);

} catch (Exception $e) {
    http_response_code(400);
    jsonOut(false, null, $e->getMessage());
} catch (PDOException $e) {
    error_log('Domain Report API: ' . $e->getMessage());
    http_response_code(500);
    jsonOut(false, null, 'Database error');
}
