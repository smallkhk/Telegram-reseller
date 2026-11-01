<?php
declare(strict_types=1);

function tg(string $method, array $params = []): array
{
    $url = rtrim((string)cfg('telegram_api', 'https://api.telegram.org'), '/') . '/bot' . cfg('bot_token') . '/' . $method;
    $res = http_request('POST', $url, ['Content-Type: application/json'], $params);
    $json = $res['json'] ?? ['ok' => false, 'description' => 'No response'];
    if (empty($json['ok'])) {
        $desc = $json['description'] ?? '';
        // These are normal and not worth logging
        if (stripos($desc, 'message is not modified') === false && stripos($desc, 'query is too old') === false) {
            log_error("Telegram $method error: $desc");
        }
    }
    return $json;
}

function kb(array $rows): array
{
    return ['inline_keyboard' => $rows];
}

function btn(string $text, string $data): array
{
    return ['text' => $text, 'callback_data' => $data];
}

function urlbtn(string $text, string $url): array
{
    return ['text' => $text, 'url' => $url];
}

function send($chatId, string $text, ?array $kb = null, array $extra = []): array
{
    $p = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ] + $extra;
    if ($kb !== null) {
        $p['reply_markup'] = $kb;
    }
    return tg('sendMessage', $p);
}

/** Edit a message in place; if that is impossible, send a new one. */
function edit($chatId, ?int $msgId, string $text, ?array $kb = null): array
{
    if ($msgId === null) {
        return send($chatId, $text, $kb);
    }
    $p = [
        'chat_id' => $chatId,
        'message_id' => $msgId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];
    if ($kb !== null) {
        $p['reply_markup'] = $kb;
    }
    $res = tg('editMessageText', $p);
    if (empty($res['ok']) && stripos($res['description'] ?? '', 'not modified') === false) {
        return send($chatId, $text, $kb);
    }
    return $res;
}

/** Queue a callback answer (toast / alert). Sent once at the end of the callback. */
function toast(string $text, bool $alert = false): void
{
    $GLOBALS['cb_answer'] = ['text' => $text, 'show_alert' => $alert];
}

/** Split long text into Telegram-sized chunks on line boundaries. */
function chunk_lines(array $lines, int $max = 3800): array
{
    $chunks = [];
    $cur = '';
    foreach ($lines as $line) {
        if ($cur !== '' && mb_strlen($cur) + mb_strlen($line) + 1 > $max) {
            $chunks[] = $cur;
            $cur = '';
        }
        $cur .= ($cur === '' ? '' : "\n") . $line;
    }
    if ($cur !== '') {
        $chunks[] = $cur;
    }
    return $chunks;
}

function notify_admins(string $text): void
{
    foreach ((array)cfg('admin_ids', []) as $id) {
        send($id, $text);
    }
}
