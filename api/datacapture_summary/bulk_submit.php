<?php
// Proxy endpoint for large batch submission.
// 目的：避开针对 summary_api.php?action=submit 的服务器/WAF 规则，
// 请求仍然走同一套后端提交逻辑。

// 如果没有显式指定 action，则强制为 save_summary（summary_api 会把它当成 submit 处理）
if (!isset($_GET['action']) || $_GET['action'] === '') {
    $_GET['action'] = 'save_summary';
}

// 直接复用 summary_api.php 中现有的完整提交实现
require __DIR__ . '/summary_api.php';

