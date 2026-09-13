<?php

declare(strict_types=1);

/**
 * 施工事例の投稿を受ける入口。
 *
 * Xserverでは public_html/api/ へ置く。
 * 秘密情報はこのファイルにも、この下のどこにも書かない。
 * public_html の外にある private/config.php から読む。
 */

$sourceDir = getenv('RELAGARDEN_API_SOURCE') ?: '';
if ($sourceDir === '') {
    // リポジトリ内では api/src、本番では public_html の外の api-src を使う。
    $localSource = dirname(__DIR__) . '/src';
    $sourceDir = is_dir($localSource)
        ? $localSource
        : dirname(__DIR__, 2) . '/api-src';
}
$sourceDir = rtrim($sourceDir, '/');

require $sourceDir . '/ApiError.php';
require $sourceDir . '/Config.php';
require $sourceDir . '/Storage.php';
require $sourceDir . '/Auth.php';
require $sourceDir . '/RateLimiter.php';
require $sourceDir . '/Validator.php';
require $sourceDir . '/CaseMarkdown.php';
require $sourceDir . '/GitHubClient.php';
require $sourceDir . '/GitHubApiClient.php';
require $sourceDir . '/FakeGitHubClient.php';
require $sourceDir . '/PublishService.php';
require $sourceDir . '/InstagramClient.php';
require $sourceDir . '/FakeInstagramClient.php';
require $sourceDir . '/CurlInstagramClient.php';
require $sourceDir . '/InstagramOAuthClient.php';
require $sourceDir . '/CurlInstagramOAuthClient.php';
require $sourceDir . '/InstagramOAuthService.php';
require $sourceDir . '/InstagramService.php';
require $sourceDir . '/InstagramRouter.php';
require $sourceDir . '/Router.php';

use Relagarden\Api\Config;
use Relagarden\Api\ConfigMissing;
use Relagarden\Api\CurlInstagramClient;
use Relagarden\Api\CurlInstagramOAuthClient;
use Relagarden\Api\GitHubApiClient;
use Relagarden\Api\InstagramOAuthService;
use Relagarden\Api\InstagramService;
use Relagarden\Api\Router;
use Relagarden\Api\Storage;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
// ブラウザーからは使わないため、外部サイトからの利用を許さない。
header('Access-Control-Allow-Origin: null');

// 設定ファイルの場所。public_html の外を指す。
// 置き場所を変える場合はここだけ直す。
$configPath = getenv('RELAGARDEN_API_CONFIG')
    ?: dirname(__DIR__, 3) . '/private/config.php';

try {
    $config = Config::load($configPath);
} catch (ConfigMissing $e) {
    // 何が足りないかは記録にだけ残し、外へは出さない。
    error_log('[relagarden-api] ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(
        ['ok' => false, 'message' => 'ただいま準備中です'],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

$storage = new Storage($config->str('storage_dir'));

// ホームページ掲載（GitHub直接方式）。設定が無ければ組み立てない。
// 掲載の入口だけが「準備中」になり、Instagramと端末の連携は使える。
$github = null;
if ($config->hasGitHub()) {
    $github = new GitHubApiClient(
        $config->str('github_token'),
        $config->str('github_owner'),
        $config->str('github_repo'),
        $config->str('github_branch'),
        $storage,
    );
}

// OAuthトークンはpublic_html外の保存領域から読む。Macへは返さない。
$oauthClient = new CurlInstagramOAuthClient($storage);
$connection = InstagramOAuthService::activeConnection($storage);
if ($connection !== null) {
    $config = $config->with([
        'instagram_access_token' => (string) ($connection['accessToken'] ?? ''),
        'instagram_user_id' => (string) ($connection['userId'] ?? ''),
        'instagram_account_name' => (string) ($connection['username'] ?? ''),
    ]);
}

// 移行期間だけ、従来の手入力トークン設定も利用可能にする。
$instagram = null;
if (InstagramService::isConfigured($config) && $config->str('instagram_access_token') !== '') {
    $instagram = new CurlInstagramClient(
        $config->str('instagram_access_token'),
        $config->str('instagram_user_id'),
        $config->str('instagram_graph_api_version'),
        $storage,
    );
}

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
// public_html/api/ の下に置く前提で、先頭の /api を落とす
$path = preg_replace('#^/api#', '', $path) ?? $path;

// Metaが取りに来る一時画像だけは、JSONではなく画像そのものを返す。
// 端末の合言葉は要らない（Metaは持っていない）。合札と期限で守る。
if (preg_match('#^/instagram/media/([0-9a-f]{64})$#', $path, $mediaMatch) === 1) {
    $service = new InstagramService($config, $storage, new \Relagarden\Api\FakeInstagramClient());
    [$mediaStatus, $mediaType, $mediaBody] = $service->serveMedia($mediaMatch[1]);
    header('Content-Type: ' . $mediaType);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    http_response_code($mediaStatus);
    echo $mediaBody;
    exit;
}

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with((string) $key, 'HTTP_') && is_string($value)) {
        $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
        $headers[$name] = $value;
    }
}

$router = new Router($config, $storage, $github, $instagram, $oauthClient);
[$status, $payload] = $router->handle(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    $path,
    (string) file_get_contents('php://input'),
    $headers,
    (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
);

http_response_code($status);
if ($path === '/instagram/oauth/callback') {
    header('Content-Type: text/html; charset=utf-8');
    $ok = $status >= 200 && $status < 300 && ($payload['ok'] ?? false) === true;
    $title = $ok ? 'Instagram連携が完了しました' : 'Instagram連携を完了できませんでした';
    $message = $ok
        ? 'この画面を閉じて、リラガーデンOSへ戻ってください。'
        : (string) ($payload['message'] ?? 'アプリへ戻り、最初からやり直してください。');
    echo '<!doctype html><html lang="ja"><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
        . '<body style="font-family:-apple-system,sans-serif;padding:32px;line-height:1.7">'
        . '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
    exit;
}
echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
