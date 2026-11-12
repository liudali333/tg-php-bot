<?php
declare(strict_types=1);

/**
 * Telegram 菜单助手 - Telegram 多级菜单机器人
 * PHP 8.3 + Docker + Mihomo
 */

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
set_time_limit(0);

$token = getenv('TG_BOT_TOKEN');
$proxy = getenv('TG_PROXY') ?: '';

if (!$token) {
    die("错误：未配置 TG_BOT_TOKEN\n");
}

define('BOT_API', "https://api.telegram.org/bot{$token}/");
define('BASE_DIR', __DIR__);
define('MENU_FILE', BASE_DIR . '/menus.json');
define('OFFSET_FILE', BASE_DIR . '/data/offset.json');
define('MEDIA_DIR', BASE_DIR . '/media');

function tg(string $method, array $params = []): array
{
    global $proxy;

    $ch = curl_init(BOT_API . $method);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    if ($proxy !== '') {
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
    }

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException("网络错误: {$error}");
    }

    $result = json_decode($body, true);

    if (!is_array($result) || !($result['ok'] ?? false)) {
        $description = $result['description'] ?? $body;
        throw new RuntimeException(
            "Telegram API HTTP {$status}: {$description}"
        );
    }

    return $result;
}

function loadMenus(): array
{
    $content = file_get_contents(MENU_FILE);

    if ($content === false) {
        throw new RuntimeException('无法读取 menus.json');
    }

    $config = json_decode(
        $content,
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    if (!isset($config['pages']['home'])) {
        throw new RuntimeException('缺少 home 主菜单');
    }

    return $config;
}

function buildKeyboard(array $buttons): string
{
    $rows = [];

    foreach ($buttons as $button) {
        $item = ['text' => $button['label']];

        if (isset($button['url'])) {
            $item['url'] = $button['url'];
        } elseif (isset($button['page'])) {
            $item['callback_data'] = 'p:' . $button['page'];
        } elseif (isset($button['reply'])) {
            $item['callback_data'] = 'r:' . $button['reply'];
        } else {
            continue;
        }

        // 每个按钮占一行
        $rows[] = [$item];
    }

    return json_encode(
        ['inline_keyboard' => $rows],
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
}

function showPage(
    int|string $chatId,
    string $pageId,
    ?int $messageId = null
): void {
    $config = loadMenus();
    $page = $config['pages'][$pageId] ?? null;

    if ($page === null) {
        tg('sendMessage', [
            'chat_id' => $chatId,
            'text' => '菜单不存在，请发送 /start'
        ]);
        return;
    }

    $text = trim(
        ($page['title'] ?? '') . "\n\n" .
        ($page['text'] ?? '')
    );

    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'reply_markup' => buildKeyboard(
            $page['buttons'] ?? []
        )
    ];

    if ($messageId !== null) {
        $params['message_id'] = $messageId;

        try {
            tg('editMessageText', $params);
        } catch (RuntimeException $e) {
            if (str_contains(
                $e->getMessage(),
                'message is not modified'
            )) {
                return;
            }
            throw $e;
        }
    } else {
        tg('sendMessage', $params);
    }
}

function getMediaPath(string $filename): string
{
    $base = realpath(MEDIA_DIR);

    if ($base === false) {
        throw new RuntimeException('media 目录不存在');
    }

    $path = realpath($base . '/' . $filename);

    if (
        $path === false ||
        !str_starts_with($path, $base . DIRECTORY_SEPARATOR) ||
        !is_file($path)
    ) {
        throw new RuntimeException('媒体文件不存在: ' . $filename);
    }

    return $path;
}

function sendReply(
    int|string $chatId,
    string $replyId
): void {
    $config = loadMenus();
    $reply = $config['replies'][$replyId] ?? null;

    if ($reply === null) {
        tg('sendMessage', [
            'chat_id' => $chatId,
            'text' => '回复内容不存在'
        ]);
        return;
    }

    if (!empty($reply['text'])) {
        tg('sendMessage', [
            'chat_id' => $chatId,
            'text' => $reply['text']
        ]);
    }

    $mediaTypes = [
        'photo' => ['sendPhoto', 'photo'],
        'document' => ['sendDocument', 'document'],
        'video' => ['sendVideo', 'video']
    ];

    foreach ($mediaTypes as $field => [$method, $param]) {
        if (empty($reply[$field])) {
            continue;
        }

        $path = getMediaPath($reply[$field]);

        tg($method, [
            'chat_id' => $chatId,
            $param => new CURLFile($path)
        ]);
    }
}

function processUpdate(array $update): void
{
    // 用户发送命令
    if (isset($update['message'])) {
        $message = $update['message'];
        $text = $message['text'] ?? '';

        if (preg_match(
            '~^/(start|menu)(?:@\w+)?(?:\s|$)~',
            $text
        )) {
            showPage($message['chat']['id'], 'home');
        }

        if (preg_match(
            '~^/help(?:@\w+)?(?:\s|$)~',
            $text
        )) {
            tg('sendMessage', [
                'chat_id' => $message['chat']['id'],
                'text' => "🤖 Telegram 菜单助手\n\n发送 /start 打开主菜单。\n点击消息下方按钮获取服务。"
            ]);
        }
    }

    // 用户点击按钮
    if (isset($update['callback_query'])) {
        $callback = $update['callback_query'];

        tg('answerCallbackQuery', [
            'callback_query_id' => $callback['id']
        ]);

        $message = $callback['message'] ?? null;

        if (
            !isset($message['chat']['id']) ||
            !isset($message['message_id'])
        ) {
            return;
        }

        $chatId = $message['chat']['id'];
        $messageId = $message['message_id'];
        $data = $callback['data'] ?? '';

        if (str_starts_with($data, 'p:')) {
            showPage(
                $chatId,
                substr($data, 2),
                $messageId
            );
        } elseif (str_starts_with($data, 'r:')) {
            sendReply(
                $chatId,
                substr($data, 2)
            );
        }
    }
}

function readOffset(): int
{
    if (!is_file(OFFSET_FILE)) {
        return 0;
    }

    $data = json_decode(
        file_get_contents(OFFSET_FILE),
        true
    );

    return (int)($data['offset'] ?? 0);
}

function saveOffset(int $offset): void
{
    $temp = OFFSET_FILE . '.tmp';

    $json = json_encode(
        ['offset' => $offset],
        JSON_THROW_ON_ERROR
    );

    if (file_put_contents($temp, $json, LOCK_EX) === false) {
        throw new RuntimeException('保存 offset 失败');
    }

    if (!rename($temp, OFFSET_FILE)) {
        throw new RuntimeException('更新 offset 失败');
    }
}

if (!is_dir(BASE_DIR . '/data')) {
    mkdir(BASE_DIR . '/data', 0755, true);
}

echo "[START] Telegram 菜单助手启动成功\n";
echo "[INFO] Mihomo Proxy: " .
    ($proxy ?: '未配置') . "\n";

while (true) {
    try {
        $updates = tg('getUpdates', [
            'offset' => readOffset(),
            'timeout' => 25,
            'limit' => 50,
            'allowed_updates' => json_encode([
                'message',
                'callback_query'
            ])
        ]);

        foreach ($updates['result'] as $update) {
            try {
                processUpdate($update);
            } catch (Throwable $e) {
                error_log(
                    '[UPDATE ERROR] ' . $e->getMessage()
                );
            }

            // 记录已处理的更新，防止反复消费
            saveOffset($update['update_id'] + 1);
        }
    } catch (Throwable $e) {
        error_log(
            '[BOT ERROR] ' . $e->getMessage()
        );
        sleep(5);
    }
}
