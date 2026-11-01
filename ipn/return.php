<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$bot = e(cfg('bot_username'));
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Payment received</title>
<style>
  body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:16px}
  .card{background:#1e293b;border-radius:16px;padding:32px;max-width:420px;text-align:center}
  a{display:inline-block;margin-top:20px;background:#229ED9;color:#fff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:600}
  p{color:#94a3b8;line-height:1.5}
</style>
</head>
<body>
<div class="card">
  <h2>✅ Thank you!</h2>
  <p>Your payment is being processed. Your wallet will be credited automatically and you'll get a message from the bot as soon as it's confirmed.</p>
  <a href="https://t.me/<?= $bot ?>">Return to Telegram</a>
</div>
</body>
</html>
