<?php
// ============================================================
// JPYCレジ サーバー保存API
// 設置方法:
//   1. このファイルを jpyc-pos.html と同じフォルダにアップロード
//   2. 下の SYNC_KEY を自分だけの合言葉に必ず変更（例: 酒井運送-9x7Kp2）
//   3. アプリの「設定」タブで同じ合言葉を入力して保存
// データは同フォルダ内 jpyc-data/store.json に保存されます。
// ============================================================

$SYNC_KEY = 'CHANGE-ME';   // ← 必ず変更してください

$dir  = __DIR__ . '/jpyc-data';
$file = $dir . '/store.json';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($SYNC_KEY === 'CHANGE-ME') {
  http_response_code(500);
  echo json_encode(['error' => 'api.php内のSYNC_KEYを変更してください']);
  exit;
}

$key = $_SERVER['HTTP_X_SYNC_KEY'] ?? '';
if (!is_string($key) || !hash_equals($SYNC_KEY, $key)) {
  http_response_code(403);
  echo json_encode(['error' => '合言葉が一致しません']);
  exit;
}

if (!is_dir($dir)) {
  mkdir($dir, 0755, true);
  // 直接URLアクセスを禁止（Apache系）
  @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $body = file_get_contents('php://input');
  if (strlen($body) > 1000000) {
    http_response_code(413);
    echo json_encode(['error' => 'データが大きすぎます']);
    exit;
  }
  json_decode($body);
  if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'JSONが不正です']);
    exit;
  }
  if (@file_put_contents($file, $body, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['error' => '書き込みに失敗しました。フォルダの権限を確認してください']);
    exit;
  }
  echo json_encode(['ok' => true]);
} else {
  if (!file_exists($file)) { http_response_code(204); exit; }
  readfile($file);
}
