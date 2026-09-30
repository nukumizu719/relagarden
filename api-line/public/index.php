<?php

declare(strict_types=1);

/**
 * 公式LINEの受信と、明示的に有効化した本人限定テスト送信の入口。
 *
 * Xserverでは public_html/api/line/ へ置く。
 * 施工事例の掲載はiPhoneからGitHubへ直接行う方式のままで、
 * この入口は掲載に一切関わらない（/publish /status /unpublish は無い）。
 *
 * 秘密情報はこのファイルにも、この下のどこにも書かない。
 * public_html の外にある private/line-config.php から読む。
 */

$sourceDir = getenv('RELAGARDEN_LINE_SOURCE') ?: '';
if ($sourceDir === '') {
    // リポジトリ内では api-line/src、本番では public_html の外の api-line-src を使う。
    $localSource = dirname(__DIR__) . '/src';
    $sourceDir = is_dir($localSource)
        ? $localSource
        : dirname(__DIR__, 3) . '/api-line-src';
}
$sourceDir = rtrim($sourceDir, '/');

/**
 * HTTPSで来ているか。
 *
 * Xserverのように前段で暗号化を解く作りでは、$_SERVER['HTTPS'] が
 * 立たずヘッダーだけで分かることがあるため、その両方を見る。
 */
function line_is_https(): bool
{
    $https = $_SERVER['HTTPS'] ?? '';
    if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
        return true;
    }
    $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    return is_string($forwarded) && strtolower($forwarded) === 'https';
}

require $sourceDir . '/LineError.php';
require $sourceDir . '/LineHeaders.php';
require $sourceDir . '/LineConfig.php';
require $sourceDir . '/LineStore.php';
require $sourceDir . '/LineSignature.php';
require $sourceDir . '/LineProfile.php';
require $sourceDir . '/LineRateLimiter.php';
require $sourceDir . '/LineInboxService.php';
require $sourceDir . '/LineOwnerAiReplyService.php';
require $sourceDir . '/LineManualSendService.php';
require $sourceDir . '/LineWebhookService.php';
require $sourceDir . '/LineRouter.php';

use Relagarden\Line\HttpLineProfile;
use Relagarden\Line\LineConfig;
use Relagarden\Line\LineConfigMissing;
use Relagarden\Line\LineHeaders;
use Relagarden\Line\LineRouter;
use Relagarden\Line\LineStore;
use Relagarden\Line\LineStorageUnavailable;
use Relagarden\Line\NoLineProfile;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
// ブラウザーからは使わないので、外部サイトへの許可は一切出さない
// （許可の書き方を間違えるより、何も書かないほうが安全）。

/**
 * 外へ返す短い返事。中身の事情は書かない。
 */
function line_fail(int $status, string $message, string $code): never
{
    // 記録へ出すのは決まったコードだけ。例外の文面もファイルの場所も出さない。
    error_log('[relagarden-line] ' . $code);
    http_response_code($status);
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// 設定ファイルの場所。public_html の外を指す。
// 置き場所を変える場合はここだけ直す。
$configPath = getenv('RELAGARDEN_LINE_CONFIG')
    ?: dirname(__DIR__, 3) . '/private/line-config.php';

try {
    $config = LineConfig::load($configPath);
} catch (LineConfigMissing $e) {
    // 何が足りないかはコードだけを記録し、外へは出さない。
    line_fail(503, 'ただいま準備中です', $e->getMessage());
}

// 暗号化されていない通信では受けない。
// Webhookの本文にはお客様の文章が入るため、平文で流れることを許さない。
if ($config->bool('require_https') && !line_is_https()) {
    line_fail(403, '受け付けられない要求です', 'E_NOT_HTTPS');
}

try {
    // 置き場所が使えないまま動くと、届いた問い合わせを黙って捨ててしまう。
    $store = new LineStore($config->str('storage_dir'));
} catch (LineStorageUnavailable $e) {
    line_fail(503, 'ただいま準備中です', $e->getMessage());
}
$token = $config->str('channel_access_token');
$profile = $token === ''
    ? new NoLineProfile()
    : new HttpLineProfile($token, $config->int('profile_timeout'));

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
// public_html/api/line/ の下に置く前提で、先頭の /api/line を落とす。
$path = preg_replace('#^/api/line#', '', $path) ?? $path;

// CGIとして動くサーバーでは Authorization が素通りしないことがある。
// 転送後の名前とApacheの持ち物も見る（LineHeaders を参照）。
$headers = LineHeaders::from(
    $_SERVER,
    function_exists('apache_request_headers') ? apache_request_headers() : null
);

$query = [];
foreach ($_GET as $key => $value) {
    if (is_string($key) && is_string($value)) {
        $query[$key] = $value;
    }
}

$router = new LineRouter($config, $store, $profile);
[$status, $payload] = $router->handle(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    $path,
    (string) file_get_contents('php://input'),
    $headers,
    (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
    $query,
);

http_response_code($status);
echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
