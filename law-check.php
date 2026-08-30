<?php
// ============================================================
// 道路交通法 改正なび — 更新チェッカー
//
// 警察庁・国土交通省などの公式ページを定期的に見に行き、
// 「改正」「施行」「軽貨物」などの新しいお知らせが出たら
// メールで知らせます。ページの中身は書き換えません。
// （何をどう載せるかは人が判断する前提の道具です）
//
// 設置方法:
//   1. traffic-law.html と同じフォルダにアップロード
//   2. 下の $MAIL_TO を自分のメールアドレスに変更
//   3. $RUN_KEY を自分だけの合言葉に変更
//   4. レンタルサーバーの管理画面で cron を設定
//        例）毎週月曜の朝8時に実行
//        0 8 * * 1  /usr/bin/php /home/ユーザー名/public_html/law-check.php
//      cronが使えない場合は、ブラウザで次のURLを開けば手動実行できます
//        https://例.com/law-check.php?key=合言葉
//
// 記録は同フォルダの law-check-data/ に保存されます。
// ============================================================

$MAIL_TO  = '';            // ← 通知先メールアドレス（例: 'you@example.com'）
$MAIL_FROM= '';            // ← 送信元。空ならサーバー既定。多くの場合は自分のドメインのアドレスにする
$RUN_KEY  = 'CHANGE-ME';   // ← ブラウザから実行するときの合言葉。必ず変更してください

// 見に行く公式ページ
$WATCH = [
  '警察庁 交通局'                   => 'https://www.npa.go.jp/bureau/traffic/index.html',
  '警察庁 自動運転'                 => 'https://www.npa.go.jp/bureau/traffic/selfdriving/index.html',
  '国交省 貨物軽自動車運送事業の安全対策' => 'https://www.mlit.go.jp/jidosha/jidosha_tk2_000172.html',
  '国交省 トラック適正化二法'        => 'https://www.mlit.go.jp/jidosha/jidosha_mn4_000019.html',
  '国交省 改正貨物自動車運送事業法'   => 'https://www.mlit.go.jp/jidosha/jidosha_mn4_000014.html',
  'NASVA 安全管理者講習'            => 'https://www.nasva.go.jp/fusegu/kamotsu_kousyu.html',
];

// この言葉を含む新着だけを拾う（絞りすぎると取りこぼすので広めに）
$KEYWORDS = ['改正','施行','公布','告示','軽貨物','貨物軽自動車','安全管理者',
             'パブリックコメント','意見募集','講習','届出','経過措置','義務'];

// ------------------------------------------------------------
$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
  header('Content-Type: text/plain; charset=utf-8');
  if ($RUN_KEY === 'CHANGE-ME') { http_response_code(500); exit("law-check.php内のRUN_KEYを変更してください\n"); }
  $k = $_GET['key'] ?? '';
  if (!is_string($k) || !hash_equals($RUN_KEY, $k)) { http_response_code(403); exit("合言葉が違います\n"); }
}

$dir = __DIR__ . '/law-check-data';
if (!is_dir($dir)) {
  if (!@mkdir($dir, 0755, true)) { exit("law-check-data フォルダを作れませんでした。書き込み権限を確認してください\n"); }
  @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
}
$snapFile = $dir . '/snapshot.json';
$logFile  = $dir . '/log.txt';

$snap = [];
if (is_file($snapFile)) {
  $decoded = json_decode((string)@file_get_contents($snapFile), true);
  if (is_array($decoded)) $snap = $decoded;
}
$firstRun = empty($snap);

/** ページを取ってきて、リンクの文言を1行ずつの配列にする */
function fetchLines(string $url): array {
  $html = false;
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS      => 3,
      CURLOPT_TIMEOUT        => 25,
      CURLOPT_USERAGENT      => 'traffic-law-navi-checker/1.0',
    ]);
    $html = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($html === false || $code >= 400) $html = false;
  }
  if ($html === false) {
    $ctx = stream_context_create(['http' => [
      'timeout' => 25, 'user_agent' => 'traffic-law-navi-checker/1.0', 'follow_location' => 1,
    ]]);
    $html = @file_get_contents($url, false, $ctx);
  }
  if ($html === false || $html === '') return [];

  // 文字コードをUTF-8にそろえる
  $enc = mb_detect_encoding($html, ['UTF-8','SJIS-win','EUC-JP','ISO-2022-JP'], true);
  if ($enc && $enc !== 'UTF-8') $html = mb_convert_encoding($html, 'UTF-8', $enc);

  // <a>タグの文言を拾う（新着一覧はほぼリンクなので、これで十分拾えます）
  $lines = [];
  if (preg_match_all('#<a\b[^>]*>(.*?)</a>#is', $html, $m)) {
    foreach ($m[1] as $inner) {
      $txt = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($inner), ENT_QUOTES, 'UTF-8')));
      if ($txt !== '' && mb_strlen($txt) >= 6 && mb_strlen($txt) <= 160) $lines[] = $txt;
    }
  }
  return array_values(array_unique($lines));
}

$report = [];
$errors = [];
foreach ($WATCH as $name => $url) {
  $lines = fetchLines($url);
  if (!$lines) { $errors[] = "$name … 取得できませんでした（$url）"; continue; }

  // そのURLを初めて見るときは、中身を記録するだけで通知しない
  // （監視先を後から追加したときに、既存のお知らせが大量に届くのを防ぐ）
  $isNewUrl = !isset($snap[$url]['lines']);
  $before = $snap[$url]['lines'] ?? [];
  $new = array_values(array_diff($lines, $before));

  // キーワードを含むものだけに絞る
  $hits = [];
  foreach ($new as $line) {
    foreach ($KEYWORDS as $kw) {
      if (mb_strpos($line, $kw) !== false) { $hits[] = $line; break; }
    }
  }
  if ($hits && !$isNewUrl) $report[] = ['name' => $name, 'url' => $url, 'hits' => $hits];

  $snap[$url] = ['name' => $name, 'lines' => $lines, 'checked' => date('c')];
}

@file_put_contents($snapFile, json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

// ------------------------------------------------------------
$now = date('Y-m-d H:i');
if ($firstRun) {
  $msg = "[$now] 初回実行のため、現在の内容を基準として記録しました。次回以降、増えた項目だけをお知らせします。\n";
} elseif (!$report) {
  $msg = "[$now] 新しいお知らせはありませんでした。\n";
} else {
  $body  = "道路交通法 改正なび — 公式サイトに新しいお知らせが出ています。\n";
  $body .= "内容を確認して、必要なら traffic-law.html の LAWS 一覧に追記してください。\n";
  $body .= str_repeat('-', 56) . "\n\n";
  foreach ($report as $r) {
    $body .= "■ {$r['name']}\n  {$r['url']}\n";
    foreach ($r['hits'] as $h) $body .= "   ・$h\n";
    $body .= "\n";
  }
  $body .= str_repeat('-', 56) . "\n";
  $body .= "※このメールは law-check.php が自動送信しています。\n";
  $body .= "※掲載内容の判断は人が行ってください。反則金額や施行日は必ず一次情報でご確認を。\n";

  $sent = false;
  if ($MAIL_TO !== '') {
    $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
    if ($MAIL_FROM !== '') $headers .= "From: $MAIL_FROM\r\n";
    $subject = '【改正なび】公式サイトに新しいお知らせ ' . count($report) . '件';
    if (function_exists('mb_send_mail')) { mb_language('Japanese'); mb_internal_encoding('UTF-8'); $sent = @mb_send_mail($MAIL_TO, $subject, $body, $headers); }
    else { $sent = @mail($MAIL_TO, $subject, $body, $headers); }
  }
  $msg  = "[$now] 新しいお知らせ " . count($report) . "件。"
        . ($MAIL_TO === '' ? "（\$MAIL_TOが未設定のためメールは送っていません）" : ($sent ? "メールを送信しました。" : "メール送信に失敗しました。")) . "\n";
  $msg .= $body . "\n";
}

@file_put_contents($logFile, $msg . "\n", FILE_APPEND | LOCK_EX);
if ($errors) @file_put_contents($logFile, "[$now] " . implode("\n", $errors) . "\n\n", FILE_APPEND | LOCK_EX);

echo $msg;
if ($errors) echo implode("\n", $errors) . "\n";
