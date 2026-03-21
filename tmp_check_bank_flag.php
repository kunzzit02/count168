<?php
require __DIR__ . '/config.php';

$columns = [];
foreach (['flag', 'issue_flag', 'status', 'company_id'] as $column) {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM bank_process LIKE ?");
    $stmt->execute([$column]);
    $columns[$column] = $stmt->rowCount() > 0;
}

echo json_encode(['columns' => $columns], JSON_UNESCAPED_UNICODE) . PHP_EOL;

$selectParts = ['id'];
if ($columns['company_id']) $selectParts[] = 'company_id';
if ($columns['status']) $selectParts[] = 'status';
if ($columns['flag']) $selectParts[] = '`flag`';
if ($columns['issue_flag']) $selectParts[] = 'issue_flag';

$sql = "SELECT " . implode(', ', $selectParts) . " FROM bank_process ORDER BY id DESC LIMIT 20";
$stmt = $pdo->query($sql);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
