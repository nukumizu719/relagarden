<?php

declare(strict_types=1);

/**
 * APIのテスト。
 *
 * Composerを使わない。Xserverに何が入っていても動かせるよう、
 * 素のPHPだけで完結させている。
 *
 *   php api/tests/run.php
 */

require __DIR__ . '/../src/ApiError.php';
require __DIR__ . '/../src/Config.php';
require __DIR__ . '/../src/Storage.php';
require __DIR__ . '/../src/Auth.php';
require __DIR__ . '/../src/RateLimiter.php';
require __DIR__ . '/../src/Validator.php';
require __DIR__ . '/../src/CaseMarkdown.php';
require __DIR__ . '/../src/GitHubClient.php';
require __DIR__ . '/../src/FakeGitHubClient.php';
require __DIR__ . '/../src/PublishService.php';
require __DIR__ . '/../src/InstagramClient.php';
require __DIR__ . '/../src/FakeInstagramClient.php';
require __DIR__ . '/../src/InstagramOAuthClient.php';
require __DIR__ . '/../src/FakeInstagramOAuthClient.php';
require __DIR__ . '/../src/InstagramOAuthService.php';
require __DIR__ . '/../src/InstagramInviteService.php';
require __DIR__ . '/../src/InstagramService.php';
require __DIR__ . '/../src/InstagramRouter.php';
require __DIR__ . '/../src/Router.php';

use Relagarden\Api\ApiError;
use Relagarden\Api\Auth;
use Relagarden\Api\CaseMarkdown;
use Relagarden\Api\Config;
use Relagarden\Api\FakeGitHubClient;
use Relagarden\Api\FakeInstagramClient;
use Relagarden\Api\FakeInstagramOAuthClient;
use Relagarden\Api\InstagramOAuthService;
use Relagarden\Api\InstagramService;
use Relagarden\Api\PublishService;
use Relagarden\Api\RateLimiter;
use Relagarden\Api\Router;
use Relagarden\Api\Storage;
use Relagarden\Api\Validator;

// ── ごく小さなテストの道具 ────────────────────────────────
$passed = 0;
$failed = 0;
$currentGroup = '';

function group(string $name): void
{
    global $currentGroup;
    $currentGroup = $name;
    echo "\n" . $name . "\n";
}

function test(string $name, callable $body): void
{
    global $passed, $failed;
    try {
        $body();
        $passed++;
        echo "  ✓ " . $name . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ " . $name . "\n";
        echo "      " . $e->getMessage() . "\n";
    }
}

function assertTrue(bool $value, string $message = ''): void
{
    if (!$value) {
        throw new RuntimeException($message !== '' ? $message : '真であるはずが偽');
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s 期待=%s 実際=%s',
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertThrows(int $status, callable $body, string $message = ''): void
{
    try {
        $body();
    } catch (ApiError $e) {
        if ($e->status !== $status) {
            throw new RuntimeException(sprintf(
                '%s 期待した状態=%d 実際=%d (%s)',
                $message,
                $status,
                $e->status,
                $e->getMessage()
            ));
        }
        return;
    }
    throw new RuntimeException($message !== '' ? $message : '断られるはずが通った');
}

/** テスト用の作業場所。毎回まっさらにする。 */
function freshStorage(): Storage
{
    $dir = sys_get_temp_dir() . '/relagarden-api-test-' . bin2hex(random_bytes(4));
    return new Storage($dir);
}

function testConfig(): Config
{
    return new Config([
        'github_token' => 'dummy-not-a-real-token',
        'github_owner' => 'nukumizu719-cpu',
        'github_repo' => 'relagarden',
        'pairing_code' => 'test-pairing-code',
        'storage_dir' => sys_get_temp_dir() . '/relagarden-api-test-cfg',
    ] + Config::defaults());
}

/** 本物のJPEGを1枚作る（中身まで見る検証を通すため） */
function makeJpeg(int $w = 40, int $h = 30): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 160, 80));
    ob_start();
    imagejpeg($im, null, 80);
    $bytes = (string) ob_get_clean();

    return $bytes;
}

function makePng(): string
{
    $im = imagecreatetruecolor(20, 20);
    ob_start();
    imagepng($im);
    $bytes = (string) ob_get_clean();

    return $bytes;
}

/** @return array<string,mixed> */
function validPayload(string $slug = 'case-20260825-1430'): array
{
    return [
        'caseId' => 'local-1',
        'slug' => $slug,
        'title' => 'ワンちゃんが走れるお庭へ',
        'area' => '愛知県岡崎市中町1-2-3',
        'cost' => '約15万円',
        'size' => '30',
        'period' => '2日間',
        'body' => "岡崎市のお客様より、雑草のご相談をいただきました。\n人工芝を施工しました。",
        'tags' => ['人工芝', '雑草対策'],
        'date' => '2026-08-25',
        'beforeImages' => [base64_encode(makeJpeg())],
        'afterImages' => [base64_encode(makeJpeg())],
        'consent' => true,
    ];
}

group('Instagram：OAuthはXserver内だけで完了する');

/** @return array{Config,Storage,Router,FakeInstagramOAuthClient,string} */
function igOAuthWorkspace(): array
{
    $dir = sys_get_temp_dir() . '/relagarden-ig-oauth-' . bin2hex(random_bytes(6));
    $config = new Config([
        'pairing_code' => 'pairing-code-1234',
        'admin_pairing_code' => 'admin-code-56789',
        'storage_dir' => $dir,
        'instagram_app_id' => '1234567890',
        'instagram_app_secret' => 'APP_SECRET_MUST_NOT_LEAK',
        'instagram_redirect_uri' => 'https://relagarden.jp/api/instagram/oauth/callback',
        'instagram_graph_api_version' => 'v0.0-test',
    ] + Config::defaults());
    $storage = new Storage($dir);
    $oauth = new FakeInstagramOAuthClient();
    $router = new Router($config, $storage, new FakeGitHubClient(), null, $oauth);
    [, $paired] = $router->handle('POST', '/pairing', json_encode([
        'pairingCode' => 'admin-code-56789',
        'deviceName' => 'Mac',
    ]), [], '203.0.113.1');
    return [$config, $storage, $router, $oauth, 'Bearer ' . $paired['token']];
}

test('投稿者端末はOAuth連携設定を変更できない', function (): void {
    [, , $router] = igOAuthWorkspace();
    [, $poster] = $router->handle('POST', '/pairing', json_encode([
        'pairingCode' => 'pairing-code-1234',
        'deviceName' => '谷口さん',
    ]), [], '203.0.113.2');
    $headers = ['authorization' => 'Bearer ' . $poster['token']];

    foreach (['/instagram/oauth/start', '/instagram/oauth/refresh', '/instagram/disconnect'] as $route) {
        [$status, $payload] = $router->handle('POST', $route, '', $headers, '203.0.113.2');
        assertSame(403, $status, $route . ' は管理者だけ');
        assertTrue(($payload['ok'] ?? true) === false);
    }
});

test('開始URLは必要最小限の2権限とstateを持つ', function (): void {
    [, , $router, , $token] = igOAuthWorkspace();
    [$status, $payload] = $router->handle('POST', '/instagram/oauth/start', '', ['authorization' => $token], '203.0.113.1');
    assertSame(200, $status);
    $url = (string) ($payload['authorizationUrl'] ?? '');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    assertSame('instagram_business_basic,instagram_business_content_publish', $query['scope'] ?? '');
    assertSame(64, strlen((string) ($query['state'] ?? '')));
    assertTrue(!str_contains($url, 'APP_SECRET_MUST_NOT_LEAK'), 'App SecretがURLへ出た');
});

test('プロフィール確認はMeta仕様のidとusernameを取得する', function (): void {
    $source = (string) file_get_contents(__DIR__ . '/../src/CurlInstagramOAuthClient.php');
    assertTrue(str_contains($source, '?fields=id,username'), 'プロフィール取得項目がMeta仕様と違う');
    assertTrue(str_contains($source, "requiredString(\$result, 'id')"), '投稿先IDをidから読んでいない');
    assertTrue(!str_contains($source, '?fields=user_id,username'), '廃止したuser_id取得が残っている');
});

test('認証完了後も応答へApp Secretとアクセストークンを返さない', function (): void {
    [, $storage, $router, $oauth, $token] = igOAuthWorkspace();
    [, $start] = $router->handle('POST', '/instagram/oauth/start', '', ['authorization' => $token], '203.0.113.1');
    parse_str((string) parse_url((string) $start['authorizationUrl'], PHP_URL_QUERY), $query);
    $_GET = ['state' => (string) $query['state'], 'code' => 'AUTH_CODE_TEST'];
    [$status, $payload] = $router->handle('GET', '/instagram/oauth/callback', '', [], '203.0.113.1');
    $_GET = [];
    assertSame(200, $status);
    assertSame(true, $payload['connected'] ?? false);
    assertSame('relagarden_test', $payload['accountName'] ?? '');
    assertSame(1, $oauth->exchangeCalls);
    $encoded = json_encode($payload);
    assertTrue(!str_contains((string) $encoded, 'APP_SECRET_MUST_NOT_LEAK'));
    assertTrue(!str_contains((string) $encoded, 'IG_LONG_TEST_TOKEN'));
    $saved = InstagramOAuthService::activeConnection($storage);
    assertSame('IG_LONG_TEST_TOKEN_1234567890', $saved['accessToken'] ?? '');
});

test('同じstateとcodeは二度使えない', function (): void {
    [, , $router, $oauth, $token] = igOAuthWorkspace();
    [, $start] = $router->handle('POST', '/instagram/oauth/start', '', ['authorization' => $token], '203.0.113.1');
    parse_str((string) parse_url((string) $start['authorizationUrl'], PHP_URL_QUERY), $query);
    $_GET = ['state' => (string) $query['state'], 'code' => 'AUTH_CODE_TEST'];
    [$first] = $router->handle('GET', '/instagram/oauth/callback', '', [], '203.0.113.1');
    [$second] = $router->handle('GET', '/instagram/oauth/callback', '', [], '203.0.113.1');
    $_GET = [];
    assertSame(200, $first);
    assertSame(400, $second);
    assertSame(1, $oauth->exchangeCalls);
});

test('接続確認・更新・解除は端末認証が必要', function (): void {
    [, , $router, $oauth, $token] = igOAuthWorkspace();
    [, $start] = $router->handle('POST', '/instagram/oauth/start', '', ['authorization' => $token], '203.0.113.1');
    parse_str((string) parse_url((string) $start['authorizationUrl'], PHP_URL_QUERY), $query);
    $_GET = ['state' => (string) $query['state'], 'code' => 'AUTH_CODE_TEST'];
    $router->handle('GET', '/instagram/oauth/callback', '', [], '203.0.113.1');
    $_GET = [];
    [$unauthorized] = $router->handle('GET', '/instagram/account', '', [], '203.0.113.1');
    [$account, $accountPayload] = $router->handle('GET', '/instagram/account', '', ['authorization' => $token], '203.0.113.1');
    [$refresh] = $router->handle('POST', '/instagram/oauth/refresh', '', ['authorization' => $token], '203.0.113.1');
    [$disconnect] = $router->handle('POST', '/instagram/disconnect', '', ['authorization' => $token], '203.0.113.1');
    [, $afterPayload] = $router->handle('GET', '/instagram/account', '', ['authorization' => $token], '203.0.113.1');
    assertSame(401, $unauthorized);
    assertSame(200, $account);
    assertSame(true, $accountPayload['connected'] ?? false);
    assertSame(200, $refresh);
    assertSame(1, $oauth->refreshCalls);
    assertSame(200, $disconnect);
    assertSame(false, $afterPayload['connected'] ?? true);
});

// ══════════════════════════════════════════════════════════
group('Instagram：招待QRと利用者別の投稿先');

test('招待QRは一度だけ使え、受取端末を新しい利用者領域へ固定する', function (): void {
    [, , $router, , $legacyToken] = igOAuthWorkspace();
    [$createStatus, $created] = $router->handle(
        'POST',
        '/instagram/invites',
        json_encode(['workspaceName' => '谷口さんのリラガーデン']),
        ['authorization' => $legacyToken],
        '203.0.113.20'
    );
    assertSame(200, $createStatus);
    $uri = (string) ($created['inviteUri'] ?? '');
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $inviteQuery);
    $inviteToken = (string) ($inviteQuery['token'] ?? '');
    assertSame(64, strlen($inviteToken));

    [$claimStatus, $claimed] = $router->handle(
        'POST',
        '/instagram/invites/claim',
        json_encode(['inviteToken' => $inviteToken, 'deviceName' => '谷口さんのiPhone']),
        [],
        '203.0.113.21'
    );
    assertSame(200, $claimStatus);
    assertSame('admin', $claimed['role'] ?? '');
    assertSame('谷口さんのリラガーデン', $claimed['workspaceName'] ?? '');
    assertTrue(is_string($claimed['token'] ?? null) && str_contains($claimed['token'], '.'));

    [$againStatus] = $router->handle(
        'POST',
        '/instagram/invites/claim',
        json_encode(['inviteToken' => $inviteToken, 'deviceName' => '別のiPhone']),
        [],
        '203.0.113.22'
    );
    assertSame(409, $againStatus);
});

test('招待された利用者のInstagram連携は従来利用者へ漏れない', function (): void {
    [, , $router, , $legacyToken] = igOAuthWorkspace();
    [, $created] = $router->handle(
        'POST',
        '/instagram/invites',
        json_encode(['workspaceName' => '谷口さん']),
        ['authorization' => $legacyToken],
        '203.0.113.30'
    );
    parse_str((string) parse_url((string) $created['inviteUri'], PHP_URL_QUERY), $inviteQuery);
    [, $claimed] = $router->handle(
        'POST',
        '/instagram/invites/claim',
        json_encode(['inviteToken' => (string) $inviteQuery['token'], 'deviceName' => '谷口さんのiPhone']),
        [],
        '203.0.113.31'
    );
    $tenantToken = 'Bearer ' . (string) $claimed['token'];

    [, $start] = $router->handle(
        'POST', '/instagram/oauth/start', '', ['authorization' => $tenantToken], '203.0.113.31'
    );
    parse_str((string) parse_url((string) $start['authorizationUrl'], PHP_URL_QUERY), $oauthQuery);
    $_GET = ['state' => (string) $oauthQuery['state'], 'code' => 'AUTH_CODE_TENANT'];
    [$callbackStatus] = $router->handle('GET', '/instagram/oauth/callback', '', [], '203.0.113.31');
    $_GET = [];
    assertSame(200, $callbackStatus);

    [, $tenantAccount] = $router->handle(
        'GET', '/instagram/account', '', ['authorization' => $tenantToken], '203.0.113.31'
    );
    [, $legacyAccount] = $router->handle(
        'GET', '/instagram/account', '', ['authorization' => $legacyToken], '203.0.113.30'
    );
    assertSame(true, $tenantAccount['connected'] ?? false);
    assertSame('谷口さん', $tenantAccount['workspaceName'] ?? '');
    assertSame(false, $legacyAccount['connected'] ?? true);
});

group('入力の検証');

test('記事の名前は決めた形だけ通す', function (): void {
    assertSame('case-20260825-1430', Validator::slug('case-20260825-1430'));
    assertThrows(400, fn() => Validator::slug('大文字ダメ'), '日本語が通った');
    assertThrows(400, fn() => Validator::slug('UPPER'), '大文字が通った');
    assertThrows(400, fn() => Validator::slug(''), '空が通った');
    assertThrows(400, fn() => Validator::slug('a b'), '空白が通った');
});

test('パストラバーサルを弾く', function (): void {
    assertThrows(400, fn() => Validator::slug('../../etc/passwd'));
    assertThrows(400, fn() => Validator::slug('..%2Fetc'));
    assertThrows(400, fn() => Validator::slug('a/../b'));
});

test('番地は必ず落とす', function (): void {
    assertSame('岡崎市', Validator::area('愛知県岡崎市中町1-2-3'));
    assertSame('岡崎市', Validator::area('岡崎市'));
    assertSame('豊田市', Validator::area('愛知県豊田市'));
    $result = Validator::cityOf('どこかの場所 12-3');
    assertTrue(!str_contains($result, '12'), '数字が残った: ' . $result);
});

test('制御文字を落とす', function (): void {
    $text = Validator::requiredText("あ\x00い\x07う", 'タイトル');
    assertSame('あいう', $text);
});

test('長すぎる文字を断る', function (): void {
    assertThrows(400, fn() => Validator::requiredText(str_repeat('あ', 300), 'タイトル', 100));
});

test('日付の形を確かめる', function (): void {
    assertSame('2026-08-25', Validator::date('2026-08-25'));
    assertThrows(400, fn() => Validator::date('2026/08/25'));
    assertThrows(400, fn() => Validator::date('2026-02-30'), 'ありえない日付が通った');
    assertThrows(400, fn() => Validator::date(''));
});

test('掲載許可がないと断る', function (): void {
    assertThrows(400, fn() => Validator::consent(false));
    assertThrows(400, fn() => Validator::consent('true'));
    assertThrows(400, fn() => Validator::consent(null));
    Validator::consent(true);
});

// ══════════════════════════════════════════════════════════
group('画像の検証');

test('本物のJPEGは通る', function (): void {
    $result = Validator::image(base64_encode(makeJpeg()), 1024 * 1024, '写真');
    assertSame('.jpg', $result['extension']);
});

test('PNGも通る', function (): void {
    $result = Validator::image(base64_encode(makePng()), 1024 * 1024, '写真');
    assertSame('.png', $result['extension']);
});

test('種類を偽っても中身で見抜く', function (): void {
    // JPEGのふりをしたPHP
    $fake = base64_encode('<?php system($_GET["c"]); ?>');
    assertThrows(400, fn() => Validator::image($fake, 1024 * 1024, '写真'));
});

test('画像の先頭だけ真似た細工も断る', function (): void {
    // JPEGの魔法の番号だけ付けた中身
    $fake = base64_encode("\xFF\xD8\xFF\xE0" . str_repeat('A', 100));
    assertThrows(400, fn() => Validator::image($fake, 1024 * 1024, '写真'));
});

test('大きすぎる画像を断る', function (): void {
    $big = base64_encode(makeJpeg(800, 800));
    assertThrows(413, fn() => Validator::image($big, 100, '写真'));
});

test('空や壊れた値を断る', function (): void {
    assertThrows(400, fn() => Validator::image('', 1024, '写真'));
    assertThrows(400, fn() => Validator::image('!!!not-base64!!!', 1024, '写真'));
    assertThrows(400, fn() => Validator::image(null, 1024, '写真'));
});

test('置き先の名前はこちらで作る（送られた名前を使わない）', function (): void {
    $name = Validator::assetName('case-20260825-1430', 'before', 1, '.jpg');
    assertSame('case-20260825-1430-before-01.jpg', $name);
    // 危ない役割名を渡しても before/after にしかならない
    $name2 = Validator::assetName('case-20260825-1430', '../../evil', 2, '.png');
    assertSame('case-20260825-1430-before-02.png', $name2);
});

// ══════════════════════════════════════════════════════════
group('連携と認証');

test('正しい合言葉でトークンを発行する', function (): void {
    $auth = new Auth(testConfig(), freshStorage());
    $result = $auth->pair('test-pairing-code', 'よしさんのiPhone');
    assertTrue(strlen($result['token']) === 64, 'トークンの長さが違う');
    assertTrue(strlen($result['deviceId']) === 16, '端末IDの長さが違う');
    assertSame('admin', $result['role'], '既存設定は管理者として維持する');
});

test('管理者用と投稿者用の合言葉で役割を分ける', function (): void {
    $storage = freshStorage();
    $config = new Config([
        'pairing_code' => 'poster-code-1234',
        'admin_pairing_code' => 'admin-code-56789',
        'storage_dir' => sys_get_temp_dir() . '/relagarden-api-role-test',
    ] + Config::defaults());
    $auth = new Auth($config, $storage);

    $admin = $auth->pair('admin-code-56789', '管理者');
    $poster = $auth->pair('poster-code-1234', '谷口さん');

    assertSame('admin', $admin['role']);
    assertSame('poster', $poster['role']);
    assertSame('admin', $storage->get('devices', $admin['deviceId'])['role'] ?? '');
    assertSame('poster', $storage->get('devices', $poster['deviceId'])['role'] ?? '');
    $auth->requireAdmin('Bearer ' . $admin['deviceId'] . '.' . $admin['token']);
    assertThrows(
        403,
        fn() => $auth->requireAdmin('Bearer ' . $poster['deviceId'] . '.' . $poster['token'])
    );
});

test('合言葉が違えば断る', function (): void {
    $auth = new Auth(testConfig(), freshStorage());
    assertThrows(401, fn() => $auth->pair('wrong-code', 'iPhone'));
});

test('サーバーにはトークンそのものを残さない', function (): void {
    $storage = freshStorage();
    $auth = new Auth(testConfig(), $storage);
    $result = $auth->pair('test-pairing-code', 'iPhone');
    $record = $storage->get('devices', $result['deviceId']);
    assertTrue($record !== null, '端末の記録がない');
    assertTrue(!isset($record['token']), 'トークンが平文で残っている');
    assertTrue(
        !in_array($result['token'], array_values($record ?? []), true),
        'トークンがどこかに残っている'
    );
});

test('発行したトークンで通る', function (): void {
    $storage = freshStorage();
    $auth = new Auth(testConfig(), $storage);
    $r = $auth->pair('test-pairing-code', 'iPhone');
    $deviceId = $auth->requireDevice('Bearer ' . $r['deviceId'] . '.' . $r['token']);
    assertSame($r['deviceId'], $deviceId);
});

test('形の違うトークンを断る', function (): void {
    $auth = new Auth(testConfig(), freshStorage());
    assertThrows(401, fn() => $auth->requireDevice(null));
    assertThrows(401, fn() => $auth->requireDevice(''));
    assertThrows(401, fn() => $auth->requireDevice('Bearer abc'));
    assertThrows(401, fn() => $auth->requireDevice('Basic user:pass'));
});

test('連携を解除すると通らなくなる', function (): void {
    $storage = freshStorage();
    $auth = new Auth(testConfig(), $storage);
    $r = $auth->pair('test-pairing-code', 'iPhone');
    $header = 'Bearer ' . $r['deviceId'] . '.' . $r['token'];
    $auth->requireDevice($header);
    $auth->revoke($r['deviceId']);
    assertThrows(403, fn() => $auth->requireDevice($header));
});

// ══════════════════════════════════════════════════════════
group('回数の制限');

test('上限を超えたら断る', function (): void {
    $limiter = new RateLimiter(freshStorage(), 3600);
    for ($i = 0; $i < 3; $i++) {
        $limiter->hit('k', 3, 'だめ');
    }
    assertThrows(429, fn() => $limiter->hit('k', 3, 'だめ'));
});

test('別の鍵なら別に数える', function (): void {
    $storage = freshStorage();
    $limiter = new RateLimiter($storage, 3600);
    $limiter->hit('a', 1, 'だめ');
    $limiter->hit('b', 1, 'だめ');
    assertThrows(429, fn() => $limiter->hit('a', 1, 'だめ'));
});

// ══════════════════════════════════════════════════════════
group('記事の組み立て');

test('既存の形と同じ項目が並ぶ', function (): void {
    $md = CaseMarkdown::build([
        'slug' => 'case-1',
        'title' => 'テスト',
        'description' => '説明',
        'date' => '2026-08-25',
        'area' => '岡崎市',
        'cost' => '約15万円',
        'period' => '2日間',
        'size' => '30',
        'body' => '本文です。',
        'tags' => ['人工芝'],
        'beforeAsset' => 'case-1-before-01.jpg',
        'afterAsset' => 'case-1-after-01.jpg',
    ]);
    foreach (['title:', 'description:', 'pubDate:', 'image:', 'beforeImage:', 'area:', 'cost:', 'period:', 'tags:'] as $key) {
        assertTrue(str_contains($md, $key), $key . ' がない');
    }
    assertTrue(str_contains($md, '"../../assets/works/case-1-after-01.jpg"'), 'After画像の場所が違う');
    assertTrue(str_contains($md, '"../../assets/works/case-1-before-01.jpg"'), 'Before画像の場所が違う');
    assertTrue(str_contains($md, '広さ：約30㎡'), '広さがない');
});

test('入力がない項目は出さない', function (): void {
    $md = CaseMarkdown::build([
        'slug' => 'case-1', 'title' => 'テスト', 'description' => '説明',
        'date' => '2026-08-25', 'area' => '岡崎市', 'cost' => '', 'period' => '',
        'size' => '', 'body' => '本文', 'tags' => [],
        'beforeAsset' => 'b.jpg', 'afterAsset' => 'a.jpg',
    ]);
    assertTrue(!str_contains($md, 'cost:'), 'costが出た');
    assertTrue(!str_contains($md, 'period:'), 'periodが出た');
    assertTrue(!str_contains($md, '広さ'), '広さが出た');
});

test('引用符を含む題名でも壊れない', function (): void {
    $md = CaseMarkdown::build([
        'slug' => 'case-1', 'title' => 'あ"い\\う', 'description' => '説明',
        'date' => '2026-08-25', 'area' => '岡崎市', 'cost' => '', 'period' => '',
        'size' => '', 'body' => '本文', 'tags' => [],
        'beforeAsset' => 'b.jpg', 'afterAsset' => 'a.jpg',
    ]);
    // frontmatter として読み直せる形になっている
    preg_match('/^title: (.+)$/m', $md, $m);
    assertSame('あ"い\\う', json_decode($m[1], true));
});

// ══════════════════════════════════════════════════════════
group('投稿');

test('正しい内容なら記事と画像が置かれる', function (): void {
    $github = new FakeGitHubClient();
    $service = new PublishService(testConfig(), freshStorage(), $github);
    $result = $service->publish(validPayload(), 'dev1');

    assertSame('published', $result['status']);
    assertTrue(str_contains($result['url'], '/cases/case-20260825-1430/'), 'URLが違う: ' . $result['url']);
    assertTrue(isset($github->files['src/content/cases/case-20260825-1430.md']), '記事が置かれていない');
    assertTrue(
        isset($github->files['src/assets/works/case-20260825-1430-before-01.jpg']),
        'Before画像が置かれていない'
    );
    assertTrue(
        isset($github->files['src/assets/works/case-20260825-1430-after-01.jpg']),
        'After画像が置かれていない'
    );
});

test('番地はGitHubへ送る内容に入らない', function (): void {
    $github = new FakeGitHubClient();
    $service = new PublishService(testConfig(), freshStorage(), $github);
    $service->publish(validPayload(), 'dev1');
    $md = $github->files['src/content/cases/case-20260825-1430.md'];
    assertTrue(str_contains($md, '岡崎市'), '市区町村がない');
    assertTrue(!str_contains($md, '中町'), '番地の町名が残っている');
    assertTrue(!str_contains($md, '1-2-3'), '番地が残っている');
});

test('複数枚の写真を扱える', function (): void {
    $payload = validPayload('case-multi-1');
    $payload['beforeImages'] = [base64_encode(makeJpeg()), base64_encode(makeJpeg())];
    $payload['afterImages'] = [base64_encode(makeJpeg()), base64_encode(makeJpeg()), base64_encode(makeJpeg())];
    $github = new FakeGitHubClient();
    $service = new PublishService(testConfig(), freshStorage(), $github);
    $service->publish($payload, 'dev1');

    assertTrue(isset($github->files['src/assets/works/case-multi-1-before-02.jpg']), 'Before2枚目がない');
    assertTrue(isset($github->files['src/assets/works/case-multi-1-after-03.jpg']), 'After3枚目がない');
});

test('掲載許可がなければ投稿しない', function (): void {
    $payload = validPayload('case-noconsent');
    $payload['consent'] = false;
    $github = new FakeGitHubClient();
    $service = new PublishService(testConfig(), freshStorage(), $github);
    assertThrows(400, fn() => $service->publish($payload, 'dev1'));
    assertSame([], $github->files, '断ったのに置かれた');
});

test('施工前の写真がなければ投稿しない', function (): void {
    $payload = validPayload('case-nobefore');
    $payload['beforeImages'] = [];
    $service = new PublishService(testConfig(), freshStorage(), new FakeGitHubClient());
    assertThrows(400, fn() => $service->publish($payload, 'dev1'));
});

test('施工後の写真がなければ投稿しない', function (): void {
    $payload = validPayload('case-noafter');
    $payload['afterImages'] = [];
    $service = new PublishService(testConfig(), freshStorage(), new FakeGitHubClient());
    assertThrows(400, fn() => $service->publish($payload, 'dev1'));
});

test('同じ記事名は二重に置かない', function (): void {
    $github = new FakeGitHubClient();
    $storage = freshStorage();
    $service = new PublishService(testConfig(), $storage, $github);
    $service->publish(validPayload('case-dup'), 'dev1');
    assertThrows(409, fn() => $service->publish(validPayload('case-dup'), 'dev1'), '二重に置けてしまった');
});

test('同じ事例IDを二度掲載しない', function (): void {
    $storage = freshStorage();
    $service = new PublishService(testConfig(), $storage, new FakeGitHubClient());
    $service->publish(validPayload('case-a'), 'dev1');
    // 別の記事名でも、同じ事例IDなら断る
    assertThrows(409, function () use ($storage): void {
        $s = new PublishService(testConfig(), $storage, new FakeGitHubClient());
        $s->publish(validPayload('case-b'), 'dev1');
    });
});

test('GitHubが失敗したら失敗として記録する', function (): void {
    $storage = freshStorage();
    $github = new FakeGitHubClient(failAfter: 0);
    $service = new PublishService(testConfig(), $storage, $github);
    assertThrows(502, fn() => $service->publish(validPayload('case-fail'), 'dev1'));
    assertSame('failed', $service->status('local-1')['status']);
});

test('状態を後から確かめられる', function (): void {
    $storage = freshStorage();
    $service = new PublishService(testConfig(), $storage, new FakeGitHubClient());
    assertSame('unknown', $service->status('まだない')['status']);
    $service->publish(validPayload('case-status'), 'dev1');
    $status = $service->status('local-1');
    assertSame('published', $status['status']);
    assertSame('case-status', $status['slug']);
});

test('掲載削除で記事と専用画像だけが消える', function (): void {
    $storage = freshStorage();
    $github = new FakeGitHubClient();
    $service = new PublishService(testConfig(), $storage, $github);
    $service->publish(validPayload('case-remove'), 'dev1');
    $github->files['src/content/cases/keep.md'] = '残す';

    $result = $service->unpublish([
        'caseId' => 'local-1',
        'slug' => 'case-remove',
    ], 'dev1');

    assertSame('draft', $result['status']);
    assertTrue(!isset($github->files['src/content/cases/case-remove.md']), '記事が残っている');
    assertTrue(!isset($github->files['src/assets/works/case-remove-before-01.jpg']), 'Before画像が残っている');
    assertTrue(!isset($github->files['src/assets/works/case-remove-after-01.jpg']), 'After画像が残っている');
    assertTrue(isset($github->files['src/content/cases/keep.md']), '関係ない記事を消した');
    assertSame('draft', $service->status('local-1')['status']);
});

test('違う記事名では掲載削除できない', function (): void {
    $storage = freshStorage();
    $github = new FakeGitHubClient();
    $service = new PublishService(testConfig(), $storage, $github);
    $service->publish(validPayload('case-remove-check'), 'dev1');
    assertThrows(409, fn() => $service->unpublish([
        'caseId' => 'local-1',
        'slug' => 'case-other',
    ], 'dev1'));
    assertTrue(isset($github->files['src/content/cases/case-remove-check.md']), '記事が消えた');
});

test('掲載時に記録していないパスは削除しない', function (): void {
    $storage = freshStorage();
    $github = new FakeGitHubClient();
    $service = new PublishService(testConfig(), $storage, $github);
    $service->publish(validPayload('case-safe-remove'), 'dev1');
    $record = $storage->get('status', 'local-1') ?? [];
    $record['files'][] = 'src/content/cases/important.md';
    $storage->put('status', 'local-1', $record);
    $github->files['src/content/cases/important.md'] = '重要';

    $service->unpublish(['caseId' => 'local-1', 'slug' => 'case-safe-remove'], 'dev1');
    assertTrue(isset($github->files['src/content/cases/important.md']), '任意ファイルを消せてしまった');
});

test('古い記録だけでは安全確認できないため削除しない', function (): void {
    $storage = freshStorage();
    $storage->put('status', 'local-1', [
        'status' => 'published',
        'slug' => 'case-old',
        'url' => 'https://relagarden.jp/cases/case-old/',
    ]);
    $service = new PublishService(testConfig(), $storage, new FakeGitHubClient());
    assertThrows(409, fn() => $service->unpublish([
        'caseId' => 'local-1',
        'slug' => 'case-old',
    ], 'dev1'));
});

test('掲載削除が失敗したら状態を残す', function (): void {
    $storage = freshStorage();
    // Before、After、記事の3作成後、最初の削除で失敗させる。
    $github = new FakeGitHubClient(failAfter: 3);
    $service = new PublishService(testConfig(), $storage, $github);
    $service->publish(validPayload('case-remove-fail'), 'dev1');
    assertThrows(502, fn() => $service->unpublish([
        'caseId' => 'local-1',
        'slug' => 'case-remove-fail',
    ], 'dev1'));
    assertSame('delete_failed', $service->status('local-1')['status']);
});

// ══════════════════════════════════════════════════════════
group('受け口');

test('決めていない入口は404', function (): void {
    $router = new Router(testConfig(), freshStorage(), new FakeGitHubClient());
    [$status] = $router->handle('GET', '/nope', '', [], '127.0.0.1');
    assertSame(404, $status);
});

test('想定しないメソッドを断る', function (): void {
    $router = new Router(testConfig(), freshStorage(), new FakeGitHubClient());
    [$status] = $router->handle('GET', '/publish', '', [], '127.0.0.1');
    assertSame(405, $status);
    [$status2] = $router->handle('DELETE', '/pairing', '', [], '127.0.0.1');
    assertSame(405, $status2);
});

test('壊れたJSONを断る', function (): void {
    $router = new Router(testConfig(), freshStorage(), new FakeGitHubClient());
    [$status] = $router->handle('POST', '/pairing', '{壊れています', [], '127.0.0.1');
    assertSame(400, $status);
    [$status2] = $router->handle('POST', '/pairing', '', [], '127.0.0.1');
    assertSame(400, $status2);
});

test('認証なしの投稿を断る', function (): void {
    $router = new Router(testConfig(), freshStorage(), new FakeGitHubClient());
    [$status] = $router->handle('POST', '/publish', '{}', [], '127.0.0.1');
    assertSame(401, $status);
    [$deleteStatus] = $router->handle('POST', '/unpublish', '{}', [], '127.0.0.1');
    assertSame(401, $deleteStatus);
});

test('連携から投稿まで通しで動く', function (): void {
    $storage = freshStorage();
    $github = new FakeGitHubClient();
    $router = new Router(testConfig(), $storage, $github);

    [$s1, $b1] = $router->handle(
        'POST',
        '/pairing',
        json_encode(['pairingCode' => 'test-pairing-code', 'deviceName' => 'iPhone']),
        [],
        '127.0.0.1'
    );
    assertSame(200, $s1);
    $token = $b1['token'];

    [$s2, $b2] = $router->handle(
        'POST',
        '/publish',
        json_encode(validPayload('case-e2e')),
        ['authorization' => 'Bearer ' . $token],
        '127.0.0.1'
    );
    assertSame(200, $s2, '投稿が通らなかった: ' . ($b2['message'] ?? ''));
    assertSame('published', $b2['status']);
    assertTrue(isset($github->files['src/content/cases/case-e2e.md']), '記事が置かれていない');

    [$s3, $b3] = $router->handle(
        'POST',
        '/unpublish',
        json_encode(['caseId' => 'local-1', 'slug' => 'case-e2e']),
        ['authorization' => 'Bearer ' . $token],
        '127.0.0.1'
    );
    assertSame(200, $s3, '掲載削除が通らなかった: ' . ($b3['message'] ?? ''));
    assertSame('draft', $b3['status']);
    assertTrue(!isset($github->files['src/content/cases/case-e2e.md']), '記事が残っている');
});

test('合言葉の総当たりを止める', function (): void {
    $router = new Router(testConfig(), freshStorage(), new FakeGitHubClient());
    $body = json_encode(['pairingCode' => 'wrong', 'deviceName' => 'x']);
    $lastStatus = 0;
    for ($i = 0; $i < 8; $i++) {
        [$lastStatus] = $router->handle('POST', '/pairing', $body, [], '10.0.0.1');
    }
    assertSame(429, $lastStatus, '何度でも試せてしまう');
});

// ══════════════════════════════════════════════════════════
group('秘密情報');

test('返す内容に秘密が混ざらない', function (): void {
    $router = new Router(testConfig(), freshStorage(), new FakeGitHubClient());
    [, $body] = $router->handle('POST', '/publish', '{}', [], '127.0.0.1');
    $json = json_encode($body, JSON_UNESCAPED_UNICODE);
    foreach (['dummy-not-a-real-token', 'test-pairing-code', 'github_token'] as $secret) {
        assertTrue(!str_contains((string) $json, $secret), $secret . ' が漏れている');
    }
});

test('記録へ出す前にトークンらしき文字を伏せる', function (): void {
    $masked = Storage::maskSecrets('token=ghp_abcdefghijklmnopqrstuvwxyz012345');
    assertTrue(!str_contains($masked, 'ghp_abcdefghij'), '伏せられていない: ' . $masked);
    assertTrue(str_contains($masked, '***'), '印がない');
});

test('設定が足りなければ読み込みを断る', function (): void {
    $path = sys_get_temp_dir() . '/relagarden-bad-config.php';
    file_put_contents($path, '<?php return ["github_token" => "x"];');
    try {
        Config::load($path);
        throw new RuntimeException('足りない設定が通った');
    } catch (\Relagarden\Api\ConfigMissing $e) {
        assertTrue(true);
    } finally {
        @unlink($path);
    }
});

test('合言葉が短すぎると断る', function (): void {
    $path = sys_get_temp_dir() . '/relagarden-short-config.php';
    file_put_contents($path, '<?php return ' . var_export([
        'github_token' => 'x', 'github_owner' => 'o', 'github_repo' => 'r',
        'pairing_code' => 'short',
    ], true) . ';');
    try {
        Config::load($path);
        throw new RuntimeException('短い合言葉が通った');
    } catch (\Relagarden\Api\ConfigMissing $e) {
        assertTrue(true);
    } finally {
        @unlink($path);
    }
});

test('管理者用と投稿者用の合言葉が同じなら断る', function (): void {
    $path = sys_get_temp_dir() . '/relagarden-same-role-config.php';
    file_put_contents($path, '<?php return ' . var_export([
        'pairing_code' => 'same-pairing-code',
        'admin_pairing_code' => 'same-pairing-code',
    ], true) . ';');
    try {
        Config::load($path);
        throw new RuntimeException('同じ合言葉が通った');
    } catch (\Relagarden\Api\ConfigMissing $e) {
        assertTrue(str_contains($e->getMessage(), '別々'));
    } finally {
        @unlink($path);
    }
});

test('本番APIはサイト直下のprivate設定を読む', function (): void {
    $source = (string) file_get_contents(__DIR__ . '/../public/index.php');
    assertTrue(
        str_contains($source, "dirname(__DIR__, 2) . '/private/config.php'"),
        'public_html/api から relagarden.jp/private を参照する'
    );
    assertTrue(
        !str_contains($source, "dirname(__DIR__, 3) . '/private/config.php'"),
        'サーバー利用者ホーム直下を参照しない'
    );
});

test('XserverでもBearer認証ヘッダーをPHPへ渡す', function (): void {
    $rules = (string) file_get_contents(__DIR__ . '/../public/.htaccess');
    assertTrue(str_contains($rules, 'HTTP:Authorization'), '受信したAuthorizationを見る');
    assertTrue(str_contains($rules, 'HTTP_AUTHORIZATION'), 'PHPへAuthorizationを渡す');
});


// ══════════════════════════════════════════════════════════
// Instagram実験
//
// **本物のInstagramへは1件も送らない。** すべて FakeInstagramClient を使う。
// ここで確かめたいのは「準備で公開されないこと」と「二重に投稿しないこと」。
// ══════════════════════════════════════════════════════════

/** テスト用の作業場所を1つ作る。 */
function igWorkspace(): array
{
    $dir = sys_get_temp_dir() . '/relagarden-ig-' . bin2hex(random_bytes(6));
    @mkdir($dir, 0700, true);
    $config = new Config([
        'github_token' => 'x',
        'github_owner' => 'o',
        'github_repo' => 'r',
        'pairing_code' => 'pairing-code-1234',
        'storage_dir' => $dir,
        'site_base_url' => 'https://relagarden.jp',
        'instagram_user_id' => '17841400000000000',
        'instagram_graph_api_version' => 'v0.0-test',
        'instagram_account_name' => 'test_account',
    ] + Config::defaults());
    $storage = new Storage($dir);
    return [$config, $storage, $dir];
}

/** 本物のJPEGを1枚作る（GDが無い環境では中身だけの簡易JPEG）。 */
function igJpegBase64(int $width = 640, int $height = 640): string
{
    if (function_exists('imagecreatetruecolor') && function_exists('imagejpeg')) {
        $im = imagecreatetruecolor($width, $height);
        imagefill($im, 0, 0, imagecolorallocate($im, 120, 180, 120));
        ob_start();
        imagejpeg($im, null, 85);
        $bytes = (string) ob_get_clean();
        return base64_encode($bytes);
    }
    return '';
}

function igPrepareBody(array $overrides = []): array
{
    return $overrides + [
        'requestId' => 'req-' . bin2hex(random_bytes(6)),
        'consent' => true,
        'caption' => 'Instagram API 接続テストです',
        'image' => igJpegBase64(),
    ];
}

$igJpeg = igJpegBase64();
if ($igJpeg === '') {
    group('Instagram実験');
    test('GDが無いため画像を作れず、Instagramのテストを飛ばしました', function (): void {
        throw new RuntimeException('GD拡張が必要です');
    });
} else {

group('Instagram：準備は公開しない');

test('prepareを呼んでも、公開は一度も呼ばれない', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient();
    $service = new InstagramService($config, $storage, $fake);

    $result = $service->prepare(igPrepareBody(), 'device01');

    assertSame('processing', $result['state'], '準備直後はまだreadyにしない');
    assertTrue(!isset($result['confirmNonce']), '確認用の合言葉はまだ渡さない');
    assertSame(1, $fake->containerCalls, '入れ物は1回作る');
    assertSame(0, $fake->publishCalls, '**公開は呼ばれてはいけない**');
});

test('準備と公開の結果に操作した端末名を残す', function (): void {
    [$config, $storage] = igWorkspace();
    $auth = new Auth($config, $storage);
    $paired = $auth->pair('pairing-code-1234', '谷口さん');
    $fake = new FakeInstagramClient();
    $service = new InstagramService($config, $storage, $fake);

    $prepared = $service->prepare(igPrepareBody(), $paired['deviceId']);
    assertSame('谷口さん', $prepared['preparedBy'] ?? '');
    $ready = $service->status($prepared['draftId'], $paired['deviceId']);
    $published = $service->publish([
        'draftId' => $ready['draftId'],
        'publishRequestId' => 'pub-' . bin2hex(random_bytes(6)),
        'confirmNonce' => $ready['confirmNonce'],
        'imageHash' => $ready['imageHash'],
        'captionHash' => $ready['captionHash'],
    ], $paired['deviceId']);
    assertSame('谷口さん', $published['preparedBy'] ?? '');
    assertSame('谷口さん', $published['publishedBy'] ?? '');
});

test('prepareを何度繰り返しても公開されない', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient();
    $service = new InstagramService($config, $storage, $fake);
    for ($i = 0; $i < 3; $i++) {
        $service->prepare(igPrepareBody(), 'device01');
    }
    assertSame(0, $fake->publishCalls, '公開は0回');
});

test('同じ要求番号なら下書きを二重に作らない', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient();
    $service = new InstagramService($config, $storage, $fake);

    $body = igPrepareBody();
    $first = $service->prepare($body, 'device01');
    $second = $service->prepare($body, 'device01');

    assertSame($first['draftId'], $second['draftId'], '同じ下書き');
    assertSame(1, $fake->containerCalls, '入れ物は1回だけ');
});

group('Instagram：受け取る内容の確認');

test('掲載許可がなければ準備できない', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient();
    $service = new InstagramService($config, $storage, $fake);
    try {
        $service->prepare(igPrepareBody(['consent' => false]), 'device01');
        throw new RuntimeException('許可なしが通った');
    } catch (ApiError $e) {
        assertSame(400, $e->status);
        assertSame(0, $fake->containerCalls, '入れ物も作らない');
    }
});

test('JPEG以外は断る', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient();
    $service = new InstagramService($config, $storage, $fake);

    $im = imagecreatetruecolor(640, 640);
    ob_start();
    imagepng($im);
    $png = (string) ob_get_clean();

    try {
        $service->prepare(igPrepareBody(['image' => base64_encode($png)]), 'device01');
        throw new RuntimeException('PNGが通った');
    } catch (ApiError $e) {
        assertSame(400, $e->status);
        assertSame(0, $fake->publishCalls);
    }
});

test('壊れた画像は断る', function (): void {
    [$config, $storage] = igWorkspace();
    $service = new InstagramService($config, $storage, new FakeInstagramClient());
    try {
        $service->prepare(igPrepareBody(['image' => base64_encode('これは画像ではありません')]), 'device01');
        throw new RuntimeException('壊れた画像が通った');
    } catch (ApiError $e) {
        assertTrue($e->status === 400, '400で断る');
    }
});

test('大きすぎる画像は断る', function (): void {
    [$config, $storage, $dir] = igWorkspace();
    $small = new Config(['instagram_max_image_bytes' => 100] + $config->raw());
    $service = new InstagramService($small, $storage, new FakeInstagramClient());
    try {
        $service->prepare(igPrepareBody(), 'device01');
        throw new RuntimeException('大きすぎる画像が通った');
    } catch (ApiError $e) {
        assertSame(413, $e->status);
    }
});

test('小さすぎる画像は断る', function (): void {
    [$config, $storage] = igWorkspace();
    $service = new InstagramService($config, $storage, new FakeInstagramClient());
    try {
        $service->prepare(igPrepareBody(['image' => igJpegBase64(100, 100)]), 'device01');
        throw new RuntimeException('小さすぎる画像が通った');
    } catch (ApiError $e) {
        assertSame(400, $e->status);
    }
});

group('Instagram：一時画像のURL');

test('URLに元のファイル名も案件名も個人情報も入らない', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient();
    $service = new InstagramService($config, $storage, $fake);
    $service->prepare(igPrepareBody(['caption' => '岡崎市明大寺町1-2-3 山田様']), 'device01');

    $url = $fake->containers[0]['imageUrl'];
    assertTrue(str_starts_with($url, 'https://'), 'HTTPSであること');
    foreach (['岡崎', '明大寺', '山田', '1-2-3', 'before', 'after', '.jpeg'] as $ng) {
        assertTrue(!str_contains($url, $ng), 'URLに ' . $ng . ' が入っている');
    }
    assertTrue(
        preg_match('#/api/instagram/media/[0-9a-f]{64}$#', $url) === 1,
        '合札は64桁の乱数'
    );
});

test('期限が切れた一時URLは404', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient();
    $service = new InstagramService($config, $storage, $fake);
    $service->prepare(igPrepareBody(), 'device01');

    $url = $fake->containers[0]['imageUrl'];
    $ticket = substr($url, -64);

    [$status] = $service->serveMedia($ticket);
    assertSame(200, $status, '期限内は取得できる');

    $record = $storage->get('igtickets', $ticket);
    $record['expiresAt'] = time() - 10;
    $storage->put('igtickets', $ticket, $record);

    [$expiredStatus] = $service->serveMedia($ticket);
    assertSame(404, $expiredStatus, '期限切れは404');
});

test('でたらめな合札は404', function (): void {
    [$config, $storage] = igWorkspace();
    $service = new InstagramService($config, $storage, new FakeInstagramClient());
    assertSame(404, $service->serveMedia(str_repeat('a', 64))[0]);
    assertSame(404, $service->serveMedia('../../etc/passwd')[0]);
    assertSame(404, $service->serveMedia('')[0]);
});

test('配信する中身はJPEGで、EXIFが残っていない', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient();
    $service = new InstagramService($config, $storage, $fake);
    $service->prepare(igPrepareBody(), 'device01');
    $ticket = substr($fake->containers[0]['imageUrl'], -64);

    [$status, $type, $body] = $service->serveMedia($ticket);
    assertSame(200, $status);
    assertSame('image/jpeg', $type);
    assertSame("\xFF\xD8", substr($body, 0, 2), 'JPEGの印');
    assertTrue(!str_contains($body, 'Exif'), 'EXIFが残っている');
});

group('Instagram：公開の条件');

/**
 * 準備まで済ませて、下書きと確認用の合言葉を返す。
 *
 * prepare だけでは ready にならない（Instagram側がまだ処理中のため）。
 * status で FINISHED を確かめてはじめて公開できる状態になる。
 */
function igReady(InstagramService $service, string $device = 'device01'): array
{
    $prepared = $service->prepare(igPrepareBody(), $device);
    return $service->status($prepared['draftId'], $device);
}

test('確認用の合言葉が無ければ公開しない', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient();
    $service = new InstagramService($config, $storage, $fake);
    $ready = igReady($service);
    try {
        $service->publish([
            'draftId' => $ready['draftId'],
            'publishRequestId' => 'pub-' . bin2hex(random_bytes(6)),
            'confirmNonce' => '',
            'imageHash' => $ready['imageHash'],
            'captionHash' => $ready['captionHash'],
        ], 'device01');
        throw new RuntimeException('合言葉なしで公開された');
    } catch (ApiError $e) {
        assertSame(409, $e->status);
        assertSame(0, $fake->publishCalls, '**公開は呼ばれてはいけない**');
    }
});

test('合言葉が違えば公開しない', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient();
    $service = new InstagramService($config, $storage, $fake);
    $ready = igReady($service);
    try {
        $service->publish([
            'draftId' => $ready['draftId'],
            'publishRequestId' => 'pub-' . bin2hex(random_bytes(6)),
            'confirmNonce' => str_repeat('f', 64),
            'imageHash' => $ready['imageHash'],
            'captionHash' => $ready['captionHash'],
        ], 'device01');
        throw new RuntimeException('違う合言葉で公開された');
    } catch (ApiError $e) {
        assertSame(0, $fake->publishCalls);
    }
});

test('画像や本文が入れ替わっていたら公開しない', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient();
    $service = new InstagramService($config, $storage, $fake);
    $ready = igReady($service);
    try {
        $service->publish([
            'draftId' => $ready['draftId'],
            'publishRequestId' => 'pub-' . bin2hex(random_bytes(6)),
            'confirmNonce' => $ready['confirmNonce'],
            'imageHash' => str_repeat('0', 64),
            'captionHash' => $ready['captionHash'],
        ], 'device01');
        throw new RuntimeException('中身が違うのに公開された');
    } catch (ApiError $e) {
        assertSame(409, $e->status);
        assertSame(0, $fake->publishCalls);
    }
});

test('別の端末からは公開できない', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient();
    $service = new InstagramService($config, $storage, $fake);
    $ready = igReady($service, 'device01');
    try {
        $service->publish([
            'draftId' => $ready['draftId'],
            'publishRequestId' => 'pub-' . bin2hex(random_bytes(6)),
            'confirmNonce' => $ready['confirmNonce'],
            'imageHash' => $ready['imageHash'],
            'captionHash' => $ready['captionHash'],
        ], 'device02');
        throw new RuntimeException('別端末が公開できた');
    } catch (ApiError $e) {
        assertSame(403, $e->status);
        assertSame(0, $fake->publishCalls);
    }
});

test('期限が切れていたら公開しない', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient();
    $service = new InstagramService($config, $storage, $fake);
    $ready = igReady($service);

    $draft = $storage->get('igdrafts', $ready['draftId']);
    $draft['expiresAt'] = time() - 10;
    $storage->put('igdrafts', $ready['draftId'], $draft);

    try {
        $service->publish([
            'draftId' => $ready['draftId'],
            'publishRequestId' => 'pub-' . bin2hex(random_bytes(6)),
            'confirmNonce' => $ready['confirmNonce'],
            'imageHash' => $ready['imageHash'],
            'captionHash' => $ready['captionHash'],
        ], 'device01');
        throw new RuntimeException('期限切れで公開された');
    } catch (ApiError $e) {
        assertSame(410, $e->status);
        assertSame(0, $fake->publishCalls);
    }
});

test('投稿先のアカウントが変わっていたら公開しない', function (): void {
    [$config, $storage, $dir] = igWorkspace();
    $fake = new FakeInstagramClient();
    // ready まで進めてから確かめる（状態のせいで断られたのでは意味がない）
    $ready = igReady(new InstagramService($config, $storage, $fake), 'device01');
    assertSame('ready', $ready['state'], '前提: readyになっていること');

    // 設定だけ別アカウントに差し替える
    $other = new Config(['instagram_account_name' => 'someone_else'] + $config->raw());
    try {
        (new InstagramService($other, $storage, $fake))->publish([
            'draftId' => $ready['draftId'],
            'publishRequestId' => 'pub-' . bin2hex(random_bytes(6)),
            'confirmNonce' => $ready['confirmNonce'],
            'imageHash' => $ready['imageHash'],
            'captionHash' => $ready['captionHash'],
        ], 'device01');
        throw new RuntimeException('別アカウントへ公開された');
    } catch (ApiError $e) {
        assertSame(409, $e->status);
        assertSame(0, $fake->publishCalls);
    }
});

group('Instagram：公開は1回だけ');

test('条件がそろえば公開でき、投稿IDが返る', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient();
    $service = new InstagramService($config, $storage, $fake);
    $ready = igReady($service);

    $result = $service->publish([
        'draftId' => $ready['draftId'],
        'publishRequestId' => 'pub-abcdef123456',
        'confirmNonce' => $ready['confirmNonce'],
        'imageHash' => $ready['imageHash'],
        'captionHash' => $ready['captionHash'],
    ], 'device01');

    assertSame('published', $result['state']);
    assertSame(1, $fake->publishCalls, '公開は1回');
    assertTrue(($result['mediaId'] ?? '') !== '', '投稿IDが返る');
    assertTrue(($result['permalink'] ?? '') !== '', '投稿URLが返る');
    assertSame('test_account', $result['accountName'], '投稿先が返る');
});

test('同じ公開要求を送り直しても、公開は1回だけ', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient();
    $service = new InstagramService($config, $storage, $fake);
    $ready = igReady($service);

    $request = [
        'draftId' => $ready['draftId'],
        'publishRequestId' => 'pub-abcdef123456',
        'confirmNonce' => $ready['confirmNonce'],
        'imageHash' => $ready['imageHash'],
        'captionHash' => $ready['captionHash'],
    ];
    $first = $service->publish($request, 'device01');
    $second = $service->publish($request, 'device01');
    $third = $service->publish($request, 'device01');

    assertSame(1, $fake->publishCalls, '**何度送っても公開は1回**');
    assertSame($first['mediaId'], $second['mediaId'], '同じ結果が返る');
    assertSame($first['mediaId'], $third['mediaId'], '同じ結果が返る');
});

test('確認用の合言葉は一度きり', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient();
    $service = new InstagramService($config, $storage, $fake);
    $ready = igReady($service);

    $service->publish([
        'draftId' => $ready['draftId'],
        'publishRequestId' => 'pub-firstrequest1',
        'confirmNonce' => $ready['confirmNonce'],
        'imageHash' => $ready['imageHash'],
        'captionHash' => $ready['captionHash'],
    ], 'device01');

    // 別の公開要求番号で、同じ合言葉を使い回す
    try {
        $service->publish([
            'draftId' => $ready['draftId'],
            'publishRequestId' => 'pub-secondrequest',
            'confirmNonce' => $ready['confirmNonce'],
            'imageHash' => $ready['imageHash'],
            'captionHash' => $ready['captionHash'],
        ], 'device01');
    } catch (ApiError $e) {
        // 断られてもよい
    }
    assertSame(1, $fake->publishCalls, '公開は1回のまま');
});

test('準備前（readyでない）状態では公開しない', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient();
    $service = new InstagramService($config, $storage, $fake);
    $ready = igReady($service);

    $draft = $storage->get('igdrafts', $ready['draftId']);
    $draft['state'] = 'processing';
    $storage->put('igdrafts', $ready['draftId'], $draft);

    try {
        $service->publish([
            'draftId' => $ready['draftId'],
            'publishRequestId' => 'pub-abcdef123456',
            'confirmNonce' => $ready['confirmNonce'],
            'imageHash' => $ready['imageHash'],
            'captionHash' => $ready['captionHash'],
        ], 'device01');
        throw new RuntimeException('準備前に公開された');
    } catch (ApiError $e) {
        assertSame(409, $e->status);
        assertSame(0, $fake->publishCalls);
    }
});

group('Instagram：結果が分からないとき');

test('応答を読めなかったら、成功にしない', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient(unknownOnPublish: true);
    $service = new InstagramService($config, $storage, $fake);
    $ready = igReady($service);

    $result = $service->publish([
        'draftId' => $ready['draftId'],
        'publishRequestId' => 'pub-abcdef123456',
        'confirmNonce' => $ready['confirmNonce'],
        'imageHash' => $ready['imageHash'],
        'captionHash' => $ready['captionHash'],
    ], 'device01');

    assertSame('publishing', $result['state'], '**成功にしてはいけない**');
    assertSame('', $result['mediaId'] ?? '', '投稿IDは返さない');
    assertTrue(str_contains($result['message'], '確認中'), '確認中と伝える');
});

test('結果が分からないまま、勝手に送り直さない', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient(unknownOnPublish: true);
    $service = new InstagramService($config, $storage, $fake);
    $ready = igReady($service);
    $request = [
        'draftId' => $ready['draftId'],
        'publishRequestId' => 'pub-abcdef123456',
        'confirmNonce' => $ready['confirmNonce'],
        'imageHash' => $ready['imageHash'],
        'captionHash' => $ready['captionHash'],
    ];
    $service->publish($request, 'device01');
    $service->publish($request, 'device01');
    assertSame(1, $fake->publishCalls, '送り直さない');
});

group('Instagram：取りやめ');

test('公開前なら取りやめられ、一時画像も消える', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient();
    $service = new InstagramService($config, $storage, $fake);
    $ready = igReady($service);
    $ticket = substr($fake->containers[0]['imageUrl'], -64);

    $result = $service->discard(['draftId' => $ready['draftId']], 'device01');

    assertSame('discarded', $result['state']);
    assertSame(404, $service->serveMedia($ticket)[0], '一時画像は消えている');
    assertSame(0, $fake->publishCalls);
});

test('公開済みは取りやめられない（Instagram側は消さない）', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient();
    $service = new InstagramService($config, $storage, $fake);
    $ready = igReady($service);
    $service->publish([
        'draftId' => $ready['draftId'],
        'publishRequestId' => 'pub-abcdef123456',
        'confirmNonce' => $ready['confirmNonce'],
        'imageHash' => $ready['imageHash'],
        'captionHash' => $ready['captionHash'],
    ], 'device01');

    try {
        $service->discard(['draftId' => $ready['draftId']], 'device01');
        throw new RuntimeException('公開済みを取りやめられた');
    } catch (ApiError $e) {
        assertSame(409, $e->status);
    }
});

test('公開すると一時画像は片づけられる', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient();
    $service = new InstagramService($config, $storage, $fake);
    $ready = igReady($service);
    $ticket = substr($fake->containers[0]['imageUrl'], -64);

    assertSame(200, $service->serveMedia($ticket)[0], '公開前は取得できる');
    $service->publish([
        'draftId' => $ready['draftId'],
        'publishRequestId' => 'pub-abcdef123456',
        'confirmNonce' => $ready['confirmNonce'],
        'imageHash' => $ready['imageHash'],
        'captionHash' => $ready['captionHash'],
    ], 'device01');
    assertSame(404, $service->serveMedia($ticket)[0], '公開後は消えている');
});

group('Instagram：秘密情報と設定');

test('返す内容にトークンもコンテナIDも入らない', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient();
    $service = new InstagramService($config, $storage, $fake);
    $ready = igReady($service);
    $result = $service->publish([
        'draftId' => $ready['draftId'],
        'publishRequestId' => 'pub-abcdef123456',
        'confirmNonce' => $ready['confirmNonce'],
        'imageHash' => $ready['imageHash'],
        'captionHash' => $ready['captionHash'],
    ], 'device01');

    $json = json_encode([$ready, $result], JSON_UNESCAPED_UNICODE);
    foreach (['accessToken', 'access_token', 'containerId', 'container-', 'app_secret', 'storage_dir'] as $ng) {
        assertTrue(!str_contains($json, $ng), '応答に ' . $ng . ' が入っている');
    }
});

test('記録にアクセストークンらしき文字列を残さない', function (): void {
    [$config, $storage, $dir] = igWorkspace();
    $storage->log('instagram: token=IGFAKETOKENFORTESTONLY000000000000');
    $log = (string) @file_get_contents($dir . '/logs/' . gmdate('Y-m') . '.log');
    assertTrue(!str_contains($log, 'IGFAKETOKENFORTESTONLY000000000000'), 'トークンが記録に残っている');
    assertTrue(str_contains($log, '***'), '伏せ字になっている');
});

test('APIのバージョンが未設定なら動かさない', function (): void {
    [$config, $storage] = igWorkspace();
    $noVersion = new Config(['instagram_graph_api_version' => ''] + $config->raw());
    assertTrue(!InstagramService::isConfigured($noVersion), '未設定と判定する');

    $service = new InstagramService($noVersion, $storage, new FakeInstagramClient());
    try {
        $service->prepare(igPrepareBody(), 'device01');
        throw new RuntimeException('未設定なのに動いた');
    } catch (ApiError $e) {
        assertSame(503, $e->status);
    }
});

test('コメント・DM・インサイトの入口を持たない', function (): void {
    $source = (string) file_get_contents(__DIR__ . '/../src/CurlInstagramClient.php');
    foreach (['comments', 'messages', 'insights', 'subscribed_apps', 'conversations'] as $ng) {
        assertTrue(!str_contains($source, $ng), $ng . ' を呼ぶコードがある');
    }
});

test('アクセストークンをURLへ入れない', function (): void {
    $source = (string) file_get_contents(__DIR__ . '/../src/CurlInstagramClient.php');
    assertTrue(!str_contains($source, 'access_token='), 'URLにトークンを入れている');
    assertTrue(str_contains($source, 'Authorization: Bearer'), 'ヘッダーで送っていない');
});

group('Instagram：回数の制限');

test('決めた回数を超えたら断る', function (): void {
    [$config, $storage] = igWorkspace();
    $limiter = new RateLimiter($storage, 3600);
    for ($i = 0; $i < 3; $i++) {
        $limiter->hit('igprep_device01', 3, 'だめ');
    }
    try {
        $limiter->hit('igprep_device01', 3, 'だめ');
        throw new RuntimeException('4回目が通った');
    } catch (ApiError $e) {
        assertSame(429, $e->status);
    }
});

test('既定の回数は控えめ（1時間に3回まで）', function (): void {
    $defaults = Config::defaults();
    assertSame(3, $defaults['rate_max_instagram_prepares']);
    assertSame(3, $defaults['rate_max_instagram_publishes']);
});


group('Instagram：FINISHEDを確かめるまで公開しない');

/** 公開要求を1つ組み立てる。 */
function igPublishRequest(array $ready, string $publishRequestId = 'pub-abcdef123456'): array
{
    return [
        'draftId' => $ready['draftId'],
        'publishRequestId' => $publishRequestId,
        'confirmNonce' => $ready['confirmNonce'] ?? '',
        'imageHash' => $ready['imageHash'],
        'captionHash' => $ready['captionHash'],
    ];
}

test('IN_PROGRESSのままなら ready にならない', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient(status: 'IN_PROGRESS');
    $service = new InstagramService($config, $storage, $fake);

    $prepared = $service->prepare(igPrepareBody(), 'device01');
    $status = $service->status($prepared['draftId'], 'device01');

    assertSame('processing', $status['state'], 'readyにしてはいけない');
    assertTrue(!isset($status['confirmNonce']), '合言葉を渡さない');
});

test('**IN_PROGRESSのときは publishCalls === 0**', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient(status: 'IN_PROGRESS');
    $service = new InstagramService($config, $storage, $fake);

    $prepared = $service->prepare(igPrepareBody(), 'device01');
    $service->status($prepared['draftId'], 'device01');

    // 合言葉が手に入らないので、それでも無理に公開を試す。
    $draft = $storage->get('igdrafts', $prepared['draftId']);
    try {
        $service->publish([
            'draftId' => $prepared['draftId'],
            'publishRequestId' => 'pub-abcdef123456',
            'confirmNonce' => $draft['publishNonce'],
            'imageHash' => $draft['imageHash'],
            'captionHash' => $draft['captionHash'],
        ], 'device01');
        throw new RuntimeException('処理中なのに公開された');
    } catch (ApiError $e) {
        assertSame(409, $e->status);
        assertSame(0, $fake->publishCalls, '**公開は0回**');
    }
});

test('**FINISHEDのときだけ publishCalls === 1**', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient(status: 'FINISHED');
    $service = new InstagramService($config, $storage, $fake);

    $ready = igReady($service);
    assertSame('ready', $ready['state'], 'FINISHEDならready');

    $result = $service->publish(igPublishRequest($ready), 'device01');
    assertSame('published', $result['state']);
    assertSame(1, $fake->publishCalls, '**公開はちょうど1回**');
});

test('readyになったあとにIN_PROGRESSへ戻ったら公開しない', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient(status: 'FINISHED');
    $service = new InstagramService($config, $storage, $fake);
    $ready = igReady($service);

    // 公開の直前に、Instagram側がまだ処理中になった場合。
    $fake->setStatus('IN_PROGRESS');
    try {
        $service->publish(igPublishRequest($ready), 'device01');
        throw new RuntimeException('処理中なのに公開された');
    } catch (ApiError $e) {
        assertSame(409, $e->status);
        assertSame(0, $fake->publishCalls, '**公開は0回**');
    }
});

test('ERRORなら公開せず、失敗として扱う', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient(status: 'FINISHED');
    $service = new InstagramService($config, $storage, $fake);
    $ready = igReady($service);

    $fake->setStatus('ERROR');
    try {
        $service->publish(igPublishRequest($ready), 'device01');
        throw new RuntimeException('ERRORなのに公開された');
    } catch (ApiError $e) {
        assertSame(409, $e->status);
        assertSame(0, $fake->publishCalls, '**公開は0回**');
    }
    assertSame('failed', $storage->get('igdrafts', $ready['draftId'])['state']);
});

test('EXPIREDなら公開せず、期限切れとして扱う', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient(status: 'FINISHED');
    $service = new InstagramService($config, $storage, $fake);
    $ready = igReady($service);

    $fake->setStatus('EXPIRED');
    try {
        $service->publish(igPublishRequest($ready), 'device01');
        throw new RuntimeException('EXPIREDなのに公開された');
    } catch (ApiError $e) {
        assertSame(410, $e->status);
        assertSame(0, $fake->publishCalls, '**公開は0回**');
    }
});

test('状態を確かめられなかったら公開しない', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient(status: 'FINISHED');
    $service = new InstagramService($config, $storage, $fake);
    $ready = igReady($service);

    // 状態の問い合わせだけが失敗する客を用意する。
    $broken = new class implements \Relagarden\Api\InstagramClient {
        public int $publishCalls = 0;
        public function createMediaContainer(string $imageUrl, string $caption): string
        {
            return 'container-x';
        }
        public function containerStatus(string $containerId): string
        {
            throw new \Relagarden\Api\InstagramUnknownResult('確認できません');
        }
        public function publishMedia(string $containerId): string
        {
            $this->publishCalls++;
            return 'media-x';
        }
        public function mediaPermalink(string $mediaId): string
        {
            return '';
        }
    };
    $service2 = new InstagramService($config, $storage, $broken);
    try {
        $service2->publish(igPublishRequest($ready), 'device01');
        throw new RuntimeException('確認できないのに公開された');
    } catch (ApiError $e) {
        assertSame(409, $e->status);
        assertSame(0, $broken->publishCalls, '**公開は0回**');
    }
});

test('見覚えのない状態でも公開しない', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient(status: 'FINISHED');
    $service = new InstagramService($config, $storage, $fake);
    $ready = igReady($service);

    $fake->setStatus('なにかの新しい状態');
    try {
        $service->publish(igPublishRequest($ready), 'device01');
        throw new RuntimeException('未知の状態で公開された');
    } catch (ApiError $e) {
        assertSame(0, $fake->publishCalls, '**公開は0回**');
    }
});

test('Instagram側がPUBLISHEDなら、送り直さない', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient(status: 'FINISHED');
    $service = new InstagramService($config, $storage, $fake);
    $ready = igReady($service);

    $fake->setStatus('PUBLISHED');
    $result = $service->publish(igPublishRequest($ready), 'device01');

    assertSame('publishing', $result['state'], '確認中で止める');
    assertSame(0, $fake->publishCalls, '**送り直さない**');
});

group('Instagram：EXIF除去に失敗したら送らない');

test('正常時はEXIFが落ちる', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient();
    $service = new InstagramService($config, $storage, $fake);
    $service->prepare(igPrepareBody(), 'device01');
    $ticket = substr($fake->containers[0]['imageUrl'], -64);

    [, , $body] = $service->serveMedia($ticket);
    assertSame("\xFF\xD8", substr($body, 0, 2), 'JPEGの印');
    assertTrue(!str_contains($body, 'Exif'), 'EXIFが残っている');
    assertTrue(!str_contains($body, 'GPS'), 'GPSが残っている');
});

test('**作り直せなければ createMediaContainer を呼ばない**', function (): void {
    [$config, $storage] = igWorkspace();
    $fake = new FakeInstagramClient();
    // 作り直しに必ず失敗する状況（GDが無い環境と同じ）
    $service = new InstagramService($config, $storage, $fake, fn(string $b): ?string => null);

    try {
        $service->prepare(igPrepareBody(), 'device01');
        throw new RuntimeException('作り直せないのに進んだ');
    } catch (ApiError $e) {
        assertSame(503, $e->status);
        assertSame(0, $fake->containerCalls, '**入れ物も作らない**');
        assertSame(0, $fake->publishCalls, '公開もしない');
    }
});

test('作り直せなかったとき、下書きも一時画像も残らない', function (): void {
    [$config, $storage, $dir] = igWorkspace();
    $fake = new FakeInstagramClient();
    $service = new InstagramService($config, $storage, $fake, fn(string $b): ?string => null);

    try {
        $service->prepare(igPrepareBody(), 'device01');
    } catch (ApiError $e) {
        // 想定どおり
    }

    $drafts = @scandir($dir . '/igdrafts') ?: [];
    $media = @scandir($dir . '/igmedia') ?: [];
    assertSame([], array_values(array_diff($drafts, ['.', '..'])), '下書きが残っている');
    assertSame([], array_values(array_diff($media, ['.', '..'])), '一時画像が残っている');
});

test('作り直せなかったときも、記録に写真の中身や置き場所を書かない', function (): void {
    [$config, $storage, $dir] = igWorkspace();
    $service = new InstagramService($config, $storage, new FakeInstagramClient(), fn(string $b): ?string => null);
    try {
        $service->prepare(igPrepareBody(), 'device01');
    } catch (ApiError $e) {
        assertTrue(!str_contains($e->getMessage(), $dir), '応答に保存場所が入っている');
    }
    $log = (string) @file_get_contents($dir . '/logs/' . gmdate('Y-m') . '.log');
    assertTrue(!str_contains($log, $dir), '記録に保存場所が入っている');
    assertTrue(!str_contains($log, "\xFF\xD8"), '記録に写真の中身が入っている');
});

group('Instagram：アカウント単位の回数制限');

test('端末を替えても、アカウントの上限を超えられない', function (): void {
    [$config, $storage] = igWorkspace();
    $limited = new Config([
        'rate_max_instagram_prepares' => 10,          // 端末ごとは緩くしておく
        'rate_max_instagram_account_prepares' => 3,   // アカウントごとは3回まで
    ] + $config->raw());

    $fake = new FakeInstagramClient();
    $router = new Router($limited, $storage, new FakeGitHubClient(), $fake);

    // 端末を2台つなぐ
    $tokens = [];
    foreach (['iPhone-A', 'iPhone-B'] as $name) {
        [, $paired] = $router->handle('POST', '/pairing', json_encode([
            'pairingCode' => 'pairing-code-1234',
            'deviceName' => $name,
        ]), [], '203.0.113.1');
        $tokens[] = 'Bearer ' . $paired['token'];
    }

    // A で2回、B で2回。合計4回目が断られること。
    $statuses = [];
    foreach ([0, 0, 1, 1] as $i) {
        [$status] = $router->handle(
            'POST',
            '/instagram/prepare',
            json_encode(igPrepareBody()),
            ['authorization' => $tokens[$i]],
            '203.0.113.9'
        );
        $statuses[] = $status;
    }

    assertSame([200, 200, 200, 429], $statuses, '4回目はアカウント上限で断る');
    assertSame(3, $fake->containerCalls, '入れ物は3回まで');
    assertSame(0, $fake->publishCalls, '公開は0回');
});

test('端末ごとの制限も残っている', function (): void {
    [$config, $storage] = igWorkspace();
    $limited = new Config([
        'rate_max_instagram_prepares' => 2,           // 端末ごとは2回
        'rate_max_instagram_account_prepares' => 99,  // アカウントごとは緩く
    ] + $config->raw());

    $fake = new FakeInstagramClient();
    $router = new Router($limited, $storage, new FakeGitHubClient(), $fake);
    [, $paired] = $router->handle('POST', '/pairing', json_encode([
        'pairingCode' => 'pairing-code-1234',
        'deviceName' => 'iPhone-A',
    ]), [], '203.0.113.1');
    $token = 'Bearer ' . $paired['token'];

    $statuses = [];
    for ($i = 0; $i < 3; $i++) {
        [$status] = $router->handle(
            'POST',
            '/instagram/prepare',
            json_encode(igPrepareBody()),
            ['authorization' => $token],
            '203.0.113.9'
        );
        $statuses[] = $status;
    }
    assertSame([200, 200, 429], $statuses, '3回目は端末上限で断る');
});

test('数える鍵にアカウントの名前も番号もそのまま出ない', function (): void {
    [$config] = igWorkspace();
    $key = InstagramService::accountRateKey($config);
    assertTrue(str_starts_with($key, 'igacc_'), '決まった形');
    assertTrue(!str_contains($key, 'test_account'), '名前が入っている');
    assertTrue(!str_contains($key, '17841400000000000'), '番号が入っている');
    assertSame(38, strlen($key), 'ハッシュの長さ');
});

group('Instagram：既存の掲載に影響しない');

test('既存の掲載APIの入口は今までどおり', function (): void {
    [$config, $storage] = igWorkspace();
    $github = new FakeGitHubClient();
    $router = new Router($config, $storage, $github);

    [$status, $payload] = $router->handle('POST', '/pairing', json_encode([
        'pairingCode' => 'pairing-code-1234',
        'deviceName' => 'iPhone',
    ]), [], '203.0.113.1');
    assertSame(200, $status, 'ペアリングは今までどおり');
    assertTrue(($payload['ok'] ?? false) === true);
});

test('Instagramを渡していないときは、その入口だけが準備中を返す', function (): void {
    [$config, $storage] = igWorkspace();
    $router = new Router($config, $storage, new FakeGitHubClient());
    [$status, $payload] = $router->handle('POST', '/instagram/prepare', '{}', [], '203.0.113.1');
    assertSame(503, $status);
    assertTrue(($payload['ok'] ?? true) === false);
});

}


// ══════════════════════════════════════════════════════════
// 機能ごとの設定（GitHubが無くてもInstagramは使える）
// ══════════════════════════════════════════════════════════

group('設定：機能ごとに分けて止める');

/** GitHubの設定を持たない作業場。 */
function igOnlyWorkspace(bool $withInstagram = true): array
{
    $dir = sys_get_temp_dir() . '/relagarden-igonly-' . bin2hex(random_bytes(6));
    @mkdir($dir, 0700, true);
    $values = [
        'pairing_code' => 'pairing-code-1234',
        'storage_dir' => $dir,
        'site_base_url' => 'https://relagarden.jp',
    ];
    if ($withInstagram) {
        $values += [
            'instagram_user_id' => '17841400000000000',
            'instagram_graph_api_version' => 'v0.0-test',
            'instagram_account_name' => 'test_account',
        ];
    }
    $config = new Config($values + Config::defaults());
    return [$config, new Storage($dir), $dir];
}

test('GitHubの設定が無くても、設定ファイルは読める', function (): void {
    $path = sys_get_temp_dir() . '/relagarden-igonly-config.php';
    file_put_contents($path, '<?php return ' . var_export([
        'pairing_code' => 'pairing-code-1234',
        'instagram_user_id' => '17841400000000000',
        'instagram_graph_api_version' => 'v0.0-test',
        'instagram_account_name' => 'test_account',
    ], true) . ';');
    try {
        $config = Config::load($path);
        assertTrue(!$config->hasGitHub(), 'GitHubは未設定と判定されるはず');
    } finally {
        @unlink($path);
    }
});

test('合言葉が無ければ、やはり読み込みを断る', function (): void {
    $path = sys_get_temp_dir() . '/relagarden-nopair-config.php';
    file_put_contents($path, '<?php return ' . var_export([
        'instagram_user_id' => '1', 'instagram_account_name' => 'x',
    ], true) . ';');
    try {
        Config::load($path);
        throw new RuntimeException('合言葉なしが通った');
    } catch (\Relagarden\Api\ConfigMissing $e) {
        assertTrue(true);
    } finally {
        @unlink($path);
    }
});

test('GitHub設定なし＋Instagram設定ありで、Instagramの入口が使える', function (): void {
    [$config, $storage] = igOnlyWorkspace();
    $fake = new FakeInstagramClient();
    $router = new Router($config, $storage, null, $fake);

    [$pairStatus, $paired] = $router->handle('POST', '/pairing', json_encode([
        'pairingCode' => 'pairing-code-1234',
        'deviceName' => 'iPhone',
    ]), [], '203.0.113.1');
    assertSame(200, $pairStatus, '端末の連携は使えるはず');

    $auth = ['authorization' => 'Bearer ' . $paired['token']];
    [$status, $payload] = $router->handle('GET', '/instagram/account', '', $auth, '203.0.113.1');
    assertSame(200, $status, 'Instagramの入口が使えるはず');
    assertTrue(($payload['configured'] ?? false) === true, '設定済みと返るはず');
    assertSame('test_account', $payload['accountName'] ?? '');
});

test('GitHub設定が無いとき、掲載の入口だけが準備中で止まる', function (): void {
    [$config, $storage] = igOnlyWorkspace();
    $router = new Router($config, $storage, null, new FakeInstagramClient());

    [, $paired] = $router->handle('POST', '/pairing', json_encode([
        'pairingCode' => 'pairing-code-1234',
        'deviceName' => 'iPhone',
    ]), [], '203.0.113.2');
    $auth = ['authorization' => 'Bearer ' . $paired['token']];

    foreach ([['POST', '/publish'], ['GET', '/status'], ['POST', '/unpublish']] as [$method, $route]) {
        [$status, $payload] = $router->handle($method, $route, '{}', $auth, '203.0.113.2');
        assertSame(503, $status, $route . ' は準備中で止まるはず');
        assertTrue(($payload['ok'] ?? true) === false);
    }

    // 連携の解除は掲載に関係しないので使える。
    [$unpairStatus] = $router->handle('POST', '/unpair', '{}', $auth, '203.0.113.2');
    assertSame(200, $unpairStatus, '連携解除は使えるはず');
});

test('Instagramの設定が無ければ、Instagramの入口が準備中で止まる', function (): void {
    [$config, $storage] = igOnlyWorkspace(withInstagram: false);
    $router = new Router($config, $storage, null, new FakeInstagramClient());

    [, $paired] = $router->handle('POST', '/pairing', json_encode([
        'pairingCode' => 'pairing-code-1234',
        'deviceName' => 'iPhone',
    ]), [], '203.0.113.3');
    $auth = ['authorization' => 'Bearer ' . $paired['token']];

    [$status, $payload] = $router->handle('POST', '/instagram/prepare', '{}', $auth, '203.0.113.3');
    assertSame(503, $status);
    assertTrue(($payload['ok'] ?? true) === false);
});

test('GitHubの設定がそろえば、掲載の入口は今までどおり', function (): void {
    [$config, $storage, $dir] = igOnlyWorkspace();
    $withGitHub = new Config([
        'github_token' => 'x', 'github_owner' => 'o', 'github_repo' => 'r',
    ] + $config->raw());
    assertTrue($withGitHub->hasGitHub(), 'GitHubは設定済みと判定されるはず');

    $router = new Router($withGitHub, $storage, new FakeGitHubClient(), new FakeInstagramClient());
    [, $paired] = $router->handle('POST', '/pairing', json_encode([
        'pairingCode' => 'pairing-code-1234',
        'deviceName' => 'iPhone',
    ]), [], '203.0.113.4');
    $auth = ['authorization' => 'Bearer ' . $paired['token']];

    [$status] = $router->handle('GET', '/status', '', $auth, '203.0.113.4');
    assertTrue($status !== 503, '掲載の入口が準備中で止まってはいけない');
});

test('設定が欠けても、返す内容に秘密情報を出さない', function (): void {
    [$config, $storage] = igOnlyWorkspace();
    $router = new Router($config, $storage, null, new FakeInstagramClient());
    [, $paired] = $router->handle('POST', '/pairing', json_encode([
        'pairingCode' => 'pairing-code-1234',
        'deviceName' => 'iPhone',
    ]), [], '203.0.113.5');
    $auth = ['authorization' => 'Bearer ' . $paired['token']];
    [, $payload] = $router->handle('POST', '/publish', '{}', $auth, '203.0.113.5');

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    foreach (['github_token', 'pairing_code', 'access_token', 'storage_dir'] as $ng) {
        assertTrue(!str_contains($json, $ng), '応答に ' . $ng . ' が出ている');
    }
});

// ══════════════════════════════════════════════════════════
echo "\n";
echo str_repeat('─', 50) . "\n";
printf("合格 %d 件 / 失敗 %d 件\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
