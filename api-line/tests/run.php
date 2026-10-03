<?php

declare(strict_types=1);

/**
 * LINE受信APIのテスト。
 *
 * Composerを使わない。Xserverに何が入っていても動かせるよう、
 * 素のPHPだけで完結させている。本物のLINEへはつながらない。
 *
 *   php api-line/tests/run.php
 */

require __DIR__ . '/../src/LineError.php';
require __DIR__ . '/../src/LineHeaders.php';
require __DIR__ . '/../src/LineConfig.php';
require __DIR__ . '/../src/LineStore.php';
require __DIR__ . '/../src/LineSignature.php';
require __DIR__ . '/../src/LineProfile.php';
require __DIR__ . '/../src/LineRateLimiter.php';
require __DIR__ . '/../src/LineInboxService.php';
require __DIR__ . '/../src/LineReceptionStateService.php';
require __DIR__ . '/../src/LineReceptionControlService.php';
require __DIR__ . '/../src/LineOwnerAiReplyService.php';
require __DIR__ . '/../src/LineManualSendService.php';
require __DIR__ . '/../src/LineWebhookService.php';
require __DIR__ . '/../src/LineRouter.php';

use Relagarden\Line\FakeLineProfile;
use Relagarden\Line\LineConfig;
use Relagarden\Line\LineConfigMissing;
use Relagarden\Line\LineHeaders;
use Relagarden\Line\LineStorageUnavailable;
use Relagarden\Line\LineInboxService;
use Relagarden\Line\LineManualSendService;
use Relagarden\Line\LineOwnerAiReplyService;
use Relagarden\Line\LineReceptionStateService;
use Relagarden\Line\LineReceptionControlService;
use Relagarden\Line\LineRouter;
use Relagarden\Line\LineSignature;
use Relagarden\Line\LineStore;

// ── ごく小さなテストの道具 ────────────────────────────────
$passed = 0;
$failed = 0;

function group(string $name): void
{
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

// ── 準備 ────────────────────────────────────────────────
const SECRET = 'test-channel-secret-0123456789';
// 本番と同じ長さ（openssl rand -hex 32 相当の64文字）で確かめる。
const INBOX_TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

function freshStore(): LineStore
{
    $dir = sys_get_temp_dir() . '/relagarden-line-test-' . bin2hex(random_bytes(6));
    return new LineStore($dir);
}

function testConfig(array $overrides = []): LineConfig
{
    return new LineConfig($overrides + [
        'channel_secret' => SECRET,
        'inbox_token' => INBOX_TOKEN,
        'storage_dir' => sys_get_temp_dir() . '/relagarden-line-test-unused',
        'ai_reply_runtime_control_required' => false,
        'ai_reception_max_questions' => 3,
        'ai_reply_daily_limit' => 4,
    ]);
}

/** 本物のLINEと同じ形の署名を作る。 */
function sign(string $body): string
{
    return base64_encode(hash_hmac('sha256', $body, SECRET, true));
}

/** 表示名を取りに行くと必ず失敗する版。取得失敗の確認に使う。 */
final class BrokenLineProfile implements \Relagarden\Line\LineProfile
{
    public function displayNameOf(string $lineUserId): string
    {
        throw new RuntimeException('プロフィールを取得できません');
    }
}

/** 保存フォルダーの場所を取り出す。 */
function storageDirOf(LineStore $store): string
{
    $reflection = new ReflectionProperty(LineStore::class, 'dir');
    return (string) $reflection->getValue($store);
}

/** その月の記録を読む。 */
function readLog(LineStore $store): string
{
    $path = storageDirOf($store) . '/logs/' . gmdate('Y-m') . '.log';
    return is_file($path) ? (string) file_get_contents($path) : '';
}

/** 決めたフォルダーを書けない状態にする。 */
function makeUnwritable(LineStore $store, string $sub): string
{
    $path = storageDirOf($store) . '/' . $sub;
    chmod($path, 0500);
    return $path;
}

function makeWritable(string $path): void
{
    @chmod($path, 0700);
}

/** テキストメッセージ1件分のWebhook本文を作る。 */
function textEvent(
    string $eventId,
    string $messageId,
    string $userId,
    string $text,
    int $timestampMs = 1756000000000,
): string {
    return json_encode([
        'destination' => 'Uffffffffffffffffffffffffffffffff',
        'events' => [[
            'type' => 'message',
            'webhookEventId' => $eventId,
            'timestamp' => $timestampMs,
            'mode' => 'active',
            'source' => ['type' => 'user', 'userId' => $userId],
            'replyToken' => 'dummy-reply-token',
            'message' => ['id' => $messageId, 'type' => 'text', 'text' => $text],
        ]],
    ], JSON_UNESCAPED_UNICODE);
}

/** 写真メッセージ1件分。写真本体はテストにも入れない。 */
function imageEvent(
    string $eventId,
    string $messageId,
    string $userId,
    int $timestampMs = 1756000000000,
): string {
    return json_encode([
        'destination' => 'Uffffffffffffffffffffffffffffffff',
        'events' => [[
            'type' => 'message',
            'webhookEventId' => $eventId,
            'timestamp' => $timestampMs,
            'mode' => 'active',
            'source' => ['type' => 'user', 'userId' => $userId],
            'replyToken' => 'dummy-reply-token',
            'message' => ['id' => $messageId, 'type' => 'image'],
        ]],
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Webhookを1回送る。返り値は [status, payload]。
 *
 * @return array{0:int,1:array<string,mixed>}
 */
function postWebhook(LineRouter $router, string $body, ?string $signature = null): array
{
    return $router->handle(
        'POST',
        '/webhook',
        $body,
        ['x-line-signature' => $signature ?? sign($body)],
        '203.0.113.10',
    );
}

function routerWith(
    LineStore $store,
    array $names = [],
    array $configOverrides = [],
    ?LineOwnerAiReplyService $ownerAiReply = null,
    ?LineManualSendService $manualSend = null,
): LineRouter
{
    return new LineRouter(
        testConfig($configOverrides),
        $store,
        new FakeLineProfile($names),
        $ownerAiReply,
        $manualSend,
    );
}

/** @return array{0:int,1:array<string,mixed>} */
function postSend(LineRouter $router, array $body, string $token = INBOX_TOKEN): array
{
    return $router->handle(
        'POST',
        '/send',
        json_encode($body, JSON_UNESCAPED_UNICODE),
        ['authorization' => 'Bearer ' . $token],
        '203.0.113.10',
    );
}

/** @return array{0:int,1:array<string,mixed>} */
function getInbox(LineRouter $router, string $token = INBOX_TOKEN): array
{
    return $router->handle('GET', '/inbox', '', ['authorization' => 'Bearer ' . $token], '203.0.113.10');
}

/** @return array{0:int,1:array<string,mixed>} */
function postSync(LineRouter $router, array $ids, string $token = INBOX_TOKEN): array
{
    return $router->handle(
        'POST',
        '/sync',
        json_encode(['ids' => $ids]),
        ['authorization' => 'Bearer ' . $token],
        '203.0.113.10',
    );
}

/** @return array{0:int,1:array<string,mixed>} */
function postReceptionReset(LineRouter $router, array $body, string $token = INBOX_TOKEN): array
{
    return $router->handle(
        'POST',
        '/reception/reset',
        json_encode($body, JSON_UNESCAPED_UNICODE),
        ['authorization' => 'Bearer ' . $token],
        '203.0.113.10',
    );
}

/** @return array{0:int,1:array<string,mixed>} */
function postReceptionHandoff(LineRouter $router, array $body, string $token = INBOX_TOKEN): array
{
    return $router->handle(
        'POST',
        '/reception/handoff',
        json_encode($body, JSON_UNESCAPED_UNICODE),
        ['authorization' => 'Bearer ' . $token],
        '203.0.113.10',
    );
}

/** @return array{0:int,1:array<string,mixed>} */
function getReceptionMode(LineRouter $router, string $token = INBOX_TOKEN): array
{
    return $router->handle(
        'GET',
        '/reception/mode',
        '',
        ['authorization' => 'Bearer ' . $token],
        '203.0.113.10',
    );
}

/** @return array{0:int,1:array<string,mixed>} */
function postReceptionMode(LineRouter $router, array $body, string $token = INBOX_TOKEN): array
{
    return $router->handle(
        'POST',
        '/reception/mode',
        json_encode($body, JSON_UNESCAPED_UNICODE),
        ['authorization' => 'Bearer ' . $token],
        '203.0.113.10',
    );
}

// ── 署名 ────────────────────────────────────────────────
group('署名の確認');

test('LINEが作る署名と同じものを正しいと判断する', function (): void {
    $body = '{"events":[]}';
    assertTrue(LineSignature::isValid(SECRET, $body, sign($body)));
});

test('本文が1文字でも違えば断る', function (): void {
    $body = '{"events":[]}';
    assertTrue(!LineSignature::isValid(SECRET, $body . ' ', sign($body)));
});

test('署名が無ければ断る', function (): void {
    assertTrue(!LineSignature::isValid(SECRET, '{}', null));
    assertTrue(!LineSignature::isValid(SECRET, '{}', ''));
});

// ── Webhook ─────────────────────────────────────────────
group('Webhookの受信');

test('署名が違う配信は受け取らず、受信箱にも入らない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $body = textEvent('EV1', 'MSG1', 'U11111111111111111111111111111111', '人工芝の見積りをお願いします');

    [$status, $payload] = postWebhook($router, $body, 'ちがう署名');
    assertSame(400, $status);
    assertSame(false, $payload['ok']);
    assertSame(0, count($store->keys('inbox')), '受信箱へ入ってしまった');
});

test('正しい配信は受信箱へ入る', function (): void {
    $store = freshStore();
    $router = routerWith($store, ['U11111111111111111111111111111111' => 'にわ好きたろう']);
    $body = textEvent('EV1', 'MSG1', 'U11111111111111111111111111111111', '庭の雑草がひどくて困っています');

    [$status, $payload] = postWebhook($router, $body);
    assertSame(200, $status);
    assertSame(1, $payload['stored']);

    [$s2, $inbox] = getInbox($router);
    assertSame(200, $s2);
    assertSame(1, count($inbox['items']));
    $item = $inbox['items'][0];
    assertSame('庭の雑草がひどくて困っています', $item['text']);
    assertSame('にわ好きたろう', $item['lineDisplayName']);
    assertSame('U11111111111111111111111111111111', $item['lineUserId']);
    assertSame('text', $item['kind']);
    assertSame('EV1', $item['eventKey']);
    assertSame('MSG1', $item['messageId']);
});

test('同じ webhookEventId は二度処理しない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $body = textEvent('EV-SAME', 'MSG-A', 'U22222222222222222222222222222222', 'こんにちは');

    [, $first] = postWebhook($router, $body);
    [, $second] = postWebhook($router, $body);
    assertSame(1, $first['stored']);
    assertSame(0, $second['stored'], '同じ配信が二重に入った');
    assertSame(1, count(getInbox($router)[1]['items']));
});

test('webhookEventIdが違っても、同じ messageId なら二度処理しない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $user = 'U33333333333333333333333333333333';

    postWebhook($router, textEvent('EV-A', 'MSG-SAME', $user, '見積りをお願いします'));
    [, $second] = postWebhook($router, textEvent('EV-B', 'MSG-SAME', $user, '見積りをお願いします'));

    assertSame(0, $second['stored'], '同じメッセージが二重に入った');
    assertSame(1, count(getInbox($router)[1]['items']));
});

test('LINEの「検証」ボタン（イベントなし）でも200を返す', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    [$status, $payload] = postWebhook($router, '{"destination":"U0","events":[]}');
    assertSame(200, $status);
    assertSame(true, $payload['ok']);
    assertSame(0, count($store->keys('inbox')));
});

test('写真は到着情報だけ受け取り、ほかの添付は受け取らない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    foreach (['image', 'sticker', 'video', 'location', 'file'] as $kind) {
        $body = json_encode([
            'events' => [[
                'type' => 'message',
                'webhookEventId' => 'EV-' . $kind,
                'timestamp' => 1756000000000,
                'source' => ['type' => 'user', 'userId' => 'U44444444444444444444444444444444'],
                'message' => ['id' => 'MSG-' . $kind, 'type' => $kind],
            ]],
        ]);
        [$status, $payload] = postWebhook($router, $body);
        assertSame(200, $status, $kind);
        assertSame($kind === 'image' ? 1 : 0, $payload['stored'], $kind);
    }
    $items = getInbox($router)[1]['items'];
    assertSame(1, count($items));
    assertSame('image', $items[0]['kind']);
    assertSame('', $items[0]['text'], '写真本文を保存している');
});

test('文字以外を読み捨てても、印は残って二度処理しない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $body = json_encode([
        'events' => [[
            'type' => 'message',
            'webhookEventId' => 'EV-IMG2',
            'timestamp' => 1756000000000,
            'source' => ['type' => 'user', 'userId' => 'U44444444444444444444444444444444'],
            'message' => ['id' => 'MSG-IMG2', 'type' => 'image'],
        ]],
    ]);
    postWebhook($router, $body);
    assertTrue(
        $store->exists('events', 'e' . LineStore::hashKey('EV-IMG2')),
        '印が残っていない'
    );
});

test('本文が大きすぎる配信は断る', function (): void {
    $store = freshStore();
    $router = routerWith($store, [], ['max_body_bytes' => 1024]);
    $body = textEvent('EV-BIG', 'MSG-BIG', 'U12121212121212121212121212121212', str_repeat('あ', 2000));
    [$status, $payload] = postWebhook($router, $body);
    assertSame(413, $status);
    assertSame(false, $payload['ok']);
    assertSame(0, count($store->keys('inbox')));
});

test('極端な数のイベントを送りつけられても受け取らない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $events = [];
    for ($i = 0; $i < 200; $i++) {
        $events[] = [
            'type' => 'message',
            'webhookEventId' => 'EV-MANY-' . $i,
            'timestamp' => 1756000000000,
            'source' => ['type' => 'user', 'userId' => 'U17171717171717171717171717171717'],
            'message' => ['id' => 'MSG-MANY-' . $i, 'type' => 'text', 'text' => '連投'],
        ];
    }
    [$status] = postWebhook($router, json_encode(['events' => $events]));
    assertSame(413, $status);
    assertSame(0, count($store->keys('inbox')));
});

test('署名は正しくても、壊れた内容は断る', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    foreach (['これはJSONではありません', '{"events": "配列ではない"}', '{壊れている'] as $body) {
        [$status] = postWebhook($router, $body);
        assertSame(400, $status, $body);
    }
    assertSame(0, count($store->keys('inbox')));
});

test('表示名の取得が失敗しても、本文は必ず受け取る', function (): void {
    $store = freshStore();
    $router = new LineRouter(testConfig(), $store, new BrokenLineProfile());
    $body = textEvent('EV-BROKEN', 'MSG-BROKEN', 'U13131313131313131313131313131313', '見積りをお願いします');

    [$status, $payload] = postWebhook($router, $body);
    assertSame(200, $status);
    assertSame(1, $payload['stored'], '表示名が取れないと本文まで捨ててしまった');

    $items = getInbox($router)[1]['items'];
    assertSame('見積りをお願いします', $items[0]['text']);
    assertSame('', $items[0]['lineDisplayName']);
});

test('保存できないときは200を返さない（LINEの再送に任せる）', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $inbox = makeUnwritable($store, 'inbox');

    try {
        $body = textEvent('EV-RO', 'MSG-RO', 'U14141414141414141414141414141414', '保存できないはず');
        [$status, $payload] = postWebhook($router, $body);
        assertSame(500, $status, '書けていないのに受け取ったと答えた');
        assertSame(false, $payload['ok']);
        // 印も残っていない＝再送されたときに、もう一度きちんと試せる。
        assertSame(0, count($store->keys('events')), '本文が無いのに処理済みの印が残った');
    } finally {
        makeWritable($inbox);
    }
});

test('異常な量の配信は断る（LINEは再送する）', function (): void {
    $store = freshStore();
    $router = routerWith($store, [], ['rate_max_webhook' => 3]);
    $user = 'U15151515151515151515151515151515';

    for ($i = 0; $i < 3; $i++) {
        [$status] = postWebhook($router, textEvent('EV-R' . $i, 'MSG-R' . $i, $user, '連続' . $i));
        assertSame(200, $status);
    }
    [$status] = postWebhook($router, textEvent('EV-R9', 'MSG-R9', $user, '4通目'));
    assertSame(429, $status);
});

test('グループからのメッセージは受信箱へ入れない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $body = json_encode([
        'events' => [[
            'type' => 'message',
            'webhookEventId' => 'EV-GROUP',
            'timestamp' => 1756000000000,
            'source' => ['type' => 'group', 'groupId' => 'C0000', 'userId' => 'U55555555555555555555555555555555'],
            'message' => ['id' => 'MSG-GROUP', 'type' => 'text', 'text' => 'グループの発言'],
        ]],
    ]);
    [, $payload] = postWebhook($router, $body);
    assertSame(0, $payload['stored']);
});

test('友だち追加だけでは受信箱へ入れない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $body = json_encode([
        'events' => [[
            'type' => 'follow',
            'webhookEventId' => 'EV-FOLLOW',
            'timestamp' => 1756000000000,
            'source' => ['type' => 'user', 'userId' => 'U66666666666666666666666666666666'],
        ]],
    ]);
    [$status, $payload] = postWebhook($router, $body);
    assertSame(200, $status);
    assertSame(0, $payload['stored']);
});

test('表示名が取れなくても、問い合わせは受け取る', function (): void {
    $store = freshStore();
    // 名前を1件も知らない＝チャネルアクセストークン未設定と同じ状態
    $router = routerWith($store, []);
    postWebhook($router, textEvent('EV-NONAME', 'MSG-NONAME', 'U77777777777777777777777777777777', '相談したいです'));

    $items = getInbox($router)[1]['items'];
    assertSame(1, count($items));
    assertSame('', $items[0]['lineDisplayName']);
    assertSame('相談したいです', $items[0]['text']);
});

test('GETでWebhookを叩いても受け付けない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    [$status] = $router->handle('GET', '/webhook', '', [], '203.0.113.10');
    assertSame(405, $status);
});

// ── 途中で失敗したとき ──────────────────────────────────
group('書いている途中で失敗したとき');

test('本文は残ったが印を書けなかったとき、再送で印を付け直す（本文は増えない）', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $body = textEvent('EV-MARK', 'MSG-MARK', 'U19191919191919191919191919191919', '消えては困る問い合わせ');

    $events = makeUnwritable($store, 'events');
    try {
        [$status] = postWebhook($router, $body);
        assertSame(500, $status, '印を書けていないのに受け取ったと答えた');
    } finally {
        makeWritable($events);
    }

    // 本文は残っている（ここが消えると問い合わせが失われる）。
    assertSame(1, count($store->keys('inbox')), '本文が残っていない');
    assertSame(0, count($store->keys('events')), '書けないはずの印が残っている');

    // LINEが再送してきた。印を付け直し、本文は増やさない。
    [$status2, $payload2] = postWebhook($router, $body);
    assertSame(200, $status2);
    assertSame(0, $payload2['stored'], '本文を二重に保存した');
    assertSame(1, $payload2['repaired'], '印を付け直していない');
    assertSame(1, count($store->keys('inbox')), '本文が増えた');
    assertSame(2, count($store->keys('events')), '印が揃っていない');

    // 3回目以降は何もしない。
    [, $payload3] = postWebhook($router, $body);
    assertSame(0, $payload3['stored']);
    assertSame(0, $payload3['repaired']);
    assertSame(1, count($store->keys('inbox')));
});

test('印の1つ目は書けて2つ目が書けないときも、再送で揃う', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $body = textEvent('EV-HALF', 'MSG-HALF', 'U20202020202020202020202020202020', '半分だけ書けた');

    // 2つ目の印（messageId 側）の置き場所を、書き込めない形でふさぐ。
    $blocked = storageDirOf($store) . '/events/m' . LineStore::hashKey('MSG-HALF') . '.json';
    mkdir($blocked, 0700, true);

    [$status] = postWebhook($router, $body);
    assertSame(500, $status);
    assertSame(1, count($store->keys('inbox')), '本文が残っていない');
    assertTrue($store->exists('events', 'e' . LineStore::hashKey('EV-HALF')), '1つ目の印が無い');

    rmdir($blocked);

    [, $payload] = postWebhook($router, $body);
    assertSame(0, $payload['stored'], '本文を二重に保存した');
    assertSame(1, $payload['repaired']);
    assertTrue($store->exists('events', 'm' . LineStore::hashKey('MSG-HALF')), '2つ目の印が無い');
    assertSame(1, count($store->keys('inbox')));
});

test('同じ配信を10回続けて送っても、受信箱は1件のまま', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $body = textEvent('EV-10', 'MSG-10', 'U21212121212121212121212121212121', '同じ配信');

    for ($i = 0; $i < 10; $i++) {
        [$status] = postWebhook($router, $body);
        assertSame(200, $status);
    }
    assertSame(1, count($store->keys('inbox')));
    assertSame(1, count(getInbox($router)[1]['items']));
});

test('同じお客様からの別のメッセージは、どちらも残る', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $user = 'U22222222222222222222222222222299';

    postWebhook($router, textEvent('EV-A1', 'MSG-A1', $user, '1通目', 1756000000000));
    postWebhook($router, textEvent('EV-A2', 'MSG-A2', $user, '2通目', 1756000000000));

    $items = getInbox($router)[1]['items'];
    assertSame(2, count($items), '同じ時刻でも別のメッセージなら2件残る');
});

// ── 番号の扱い ──────────────────────────────────────────
group('番号（ID）の扱い');

test('webhookEventIdだけでも受け取る', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $body = json_encode([
        'events' => [[
            'type' => 'message',
            'webhookEventId' => 'EV-ONLY',
            'timestamp' => 1756000000000,
            'source' => ['type' => 'user', 'userId' => 'U23232323232323232323232323232323'],
            'message' => ['type' => 'text', 'text' => '番号は片方だけ'],
        ]],
    ]);
    [, $payload] = postWebhook($router, $body);
    assertSame(1, $payload['stored']);
    // 再送しても増えない。
    postWebhook($router, $body);
    assertSame(1, count($store->keys('inbox')));
});

test('messageIdだけでも受け取る', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $body = json_encode([
        'events' => [[
            'type' => 'message',
            'timestamp' => 1756000000000,
            'source' => ['type' => 'user', 'userId' => 'U24242424242424242424242424242424'],
            'message' => ['id' => 'MSG-ONLY', 'type' => 'text', 'text' => '番号は片方だけ'],
        ]],
    ]);
    [, $payload] = postWebhook($router, $body);
    assertSame(1, $payload['stored']);
    postWebhook($router, $body);
    assertSame(1, count($store->keys('inbox')));
});

test('番号がどちらも無い配信は保存しない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $body = json_encode([
        'events' => [[
            'type' => 'message',
            'timestamp' => 1756000000000,
            'source' => ['type' => 'user', 'userId' => 'U25252525252525252525252525252525'],
            'message' => ['type' => 'text', 'text' => '番号が無い'],
        ]],
    ]);
    [$status, $payload] = postWebhook($router, $body);
    assertSame(200, $status, 'LINEへは200を返す（再送させても同じため）');
    assertSame(0, $payload['stored'], '二度と見分けられないものを保存した');
    assertSame(0, count($store->keys('inbox')));
});

test('記号だけが違う番号を、同じものとして扱わない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $user = 'U26262626262626262626262626262626';

    // 記号を落とすと同じ文字列になる番号たち。
    foreach (['EV-1', 'EV/1', 'EV.1', 'EV_1'] as $i => $eventId) {
        [, $payload] = postWebhook(
            $router,
            textEvent($eventId, 'MSG-SYM-' . $i, $user, '記号ちがい ' . $i, 1756000000000 + $i * 1000)
        );
        assertSame(1, $payload['stored'], $eventId . ' が別のものとして扱われていない');
    }
    assertSame(4, count($store->keys('inbox')));
});

test('フォルダーの外へ出ようとする番号でも壊れない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $dir = storageDirOf($store);

    [, $payload] = postWebhook($router, textEvent(
        '../../../../etc/passwd',
        '../../evil',
        'U27272727272727272727272727272727',
        'パストラバーサル'
    ));
    assertSame(1, $payload['stored']);
    // 置き場所の外にファイルが作られていない。
    assertTrue(!file_exists($dir . '/../evil.json'), '外へ書き出している');
    foreach ($store->keys('events') as $key) {
        assertTrue(!str_contains($key, '.'), '鍵に危ない文字が残っている: ' . $key);
    }
});

test('長すぎる番号は受け取らない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $long = str_repeat('E', 200);
    [$status, $payload] = postWebhook($router, textEvent(
        $long,
        'MSG-LONG',
        'U28282828282828282828282828282828',
        '長すぎる番号'
    ));
    assertSame(200, $status);
    assertSame(0, $payload['stored']);
    assertSame(0, count($store->keys('inbox')));
});

// ── 受信箱 ──────────────────────────────────────────────
group('受信箱と取り込み');

test('合言葉が無いと受信箱を読めない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    [$status] = $router->handle('GET', '/inbox', '', [], '203.0.113.10');
    assertSame(401, $status);
});

test('合言葉が違うと受信箱を読めない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    [$status] = getInbox($router, 'ちがう合言葉');
    assertSame(401, $status);
});

test('合言葉が無いと取り込み済みの印を付けられない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    [$noToken] = $router->handle('POST', '/sync', '{"ids":["x"]}', [], '203.0.113.10');
    assertSame(401, $noToken);
    [$wrongToken] = postSync($router, ['x'], 'ちがう合言葉');
    assertSame(401, $wrongToken);
});

test('取り込み済みの印を付けると、次からは渡さない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    postWebhook($router, textEvent('EV-1', 'MSG-1', 'U88888888888888888888888888888888', '1件目'));
    postWebhook($router, textEvent('EV-2', 'MSG-2', 'U88888888888888888888888888888888', '2件目', 1756000060000));

    $items = getInbox($router)[1]['items'];
    assertSame(2, count($items));
    assertSame('1件目', $items[0]['text'], '古い順に並んでいない');

    [$status, $acked] = postSync($router, [$items[0]['id']]);
    assertSame(200, $status);
    assertSame(1, $acked['marked']);

    $left = getInbox($router)[1]['items'];
    assertSame(1, count($left));
    assertSame('2件目', $left[0]['text']);
});

test('同じ取り込み済みの印を二度送っても壊れない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    postWebhook($router, textEvent('EV-9', 'MSG-9', 'U99999999999999999999999999999999', '重ねて送る'));
    $id = getInbox($router)[1]['items'][0]['id'];

    postSync($router, [$id]);
    [, $again] = postSync($router, [$id]);
    assertSame(0, $again['marked']);
    assertSame(0, count(getInbox($router)[1]['items']));
});

test('知らないidを送られても何も起きない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    [$status, $payload] = postSync($router, ['../../etc/passwd', '', 'いない番号']);
    assertSame(200, $status);
    assertSame(0, $payload['marked']);
});

test('取り込めていない問い合わせは、日数が経っても消さない', function (): void {
    $store = freshStore();
    $config = testConfig(['keep_days' => 1]);
    $old = gmdate('c', time() - (10 * 86400));

    // 取り込み済みで古いもの
    $store->put('inbox', '00000000000001-aaaaaaaaaaaa', [
        'id' => '00000000000001-aaaaaaaaaaaa', 'text' => '古い・取り込み済み', 'takenAt' => $old,
    ]);
    // まだ取り込んでいない古いもの
    $store->put('inbox', '00000000000002-bbbbbbbbbbbb', [
        'id' => '00000000000002-bbbbbbbbbbbb', 'text' => '古い・未取り込み', 'takenAt' => '',
    ]);

    $removed = (new LineInboxService($config, $store))->prune();
    assertSame(1, $removed);
    assertTrue(!$store->exists('inbox', '00000000000001-aaaaaaaaaaaa'), '取り込み済みが残っている');
    assertTrue($store->exists('inbox', '00000000000002-bbbbbbbbbbbb'), '未取り込みを消してしまった');
});

test('一度に送れる取り込み済みの数を超えたら断る', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $ids = [];
    for ($i = 0; $i < 201; $i++) {
        $ids[] = 'id-' . $i;
    }
    [$status] = postSync($router, $ids);
    assertSame(413, $status);
});

test('長すぎる番号や大きすぎる本文の取り込み済みは断る', function (): void {
    $store = freshStore();
    $router = routerWith($store);

    [$longId] = postSync($router, [str_repeat('x', 200)]);
    assertSame(400, $longId);

    $huge = json_encode(['ids' => ['x'], 'padding' => str_repeat('a', 100000)]);
    [$bigBody] = $router->handle(
        'POST',
        '/sync',
        (string) $huge,
        ['authorization' => 'Bearer ' . INBOX_TOKEN],
        '203.0.113.10'
    );
    assertSame(413, $bigBody);

    [$shape] = $router->handle(
        'POST',
        '/sync',
        '{"ids":"配列ではない"}',
        ['authorization' => 'Bearer ' . INBOX_TOKEN],
        '203.0.113.10'
    );
    assertSame(400, $shape);
});

test('取り込み済みの印を書けなかったら、成功と答えない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    postWebhook($router, textEvent('EV-ACK', 'MSG-ACK', 'U29292929292929292929292929292929', '印を書けない'));
    $id = getInbox($router)[1]['items'][0]['id'];

    $inbox = makeUnwritable($store, 'inbox');
    try {
        [$status, $payload] = postSync($router, [$id]);
        assertSame(500, $status, '書けていないのに済んだと答えた');
        assertSame(false, $payload['ok']);
    } finally {
        makeWritable($inbox);
    }

    // まだ取り込み済みになっていない＝アプリが次にもう一度知らせられる。
    assertSame(1, count(getInbox($router)[1]['items']), '未確認のまま残っていない');

    // 書ける状態に戻れば、やり直せる。
    [$retry, $ok] = postSync($router, [$id]);
    assertSame(200, $retry);
    assertSame(1, $ok['marked']);
    assertSame(0, count(getInbox($router)[1]['items']));
});

// ── 回数制限の記録の片付け ──────────────────────────────
group('回数制限の記録の片付け');

test('窓を過ぎた記録だけを片付け、いま数えている分は残す', function (): void {
    $store = freshStore();
    $config = testConfig(['keep_days' => 1, 'rate_window_seconds' => 3600]);
    $now = time();

    // 窓を過ぎたもの（消える）
    $store->put('rate', 'inbox_198_51_100_1', ['times' => [$now - 7200, $now - 7300]]);
    // 窓の中に1回でもあるもの（残る）
    $store->put('rate', 'inbox_198_51_100_2', ['times' => [$now - 7200, $now - 60]]);
    // 片付けの印（残る）。
    // 万一この印が回数制限と同じ形になっても消さないよう、
    // わざと「窓を過ぎた形」で置いて、名前だけで守れているかを見る。
    $store->put('rate', LineInboxService::housekeepingKey, [
        'day' => gmdate('Y-m-d'),
        'times' => [$now - 7200],
    ]);
    // 見覚えのない形のもの（残す。回数制限の記録ではない）
    $store->put('rate', 'unknown_shape', ['day' => '2026-01-01']);

    (new LineInboxService($config, $store))->prune();

    assertTrue(!$store->exists('rate', 'inbox_198_51_100_1'), '古い記録が残っている');
    assertTrue($store->exists('rate', 'inbox_198_51_100_2'), '数えている最中の記録を消した');
    assertTrue(
        $store->exists('rate', LineInboxService::housekeepingKey),
        '片付けの印を消した'
    );
    assertTrue($store->exists('rate', 'unknown_shape'), '見覚えのない形のものを消した');
});

test('片付けても、未取り込みの問い合わせと印には触れない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $config = testConfig(['keep_days' => 1, 'rate_window_seconds' => 3600]);
    $now = time();

    postWebhook($router, textEvent('EV-PRUNE', 'MSG-PRUNE', 'U30303030303030303030303030303030', '残っていてほしい'));
    $store->put('rate', 'inbox_old', ['times' => [$now - 7200]]);

    $inboxBefore = count($store->keys('inbox'));
    $eventsBefore = count($store->keys('events'));

    (new LineInboxService($config, $store))->prune();

    assertSame($inboxBefore, count($store->keys('inbox')), '未取り込みの問い合わせが減った');
    assertSame($eventsBefore, count($store->keys('events')), '二度処理しない印が減った');
    assertTrue(!$store->exists('rate', 'inbox_old'), '古い回数制限の記録が残っている');
    assertSame(1, count(getInbox($router)[1]['items']), 'アプリへ渡せなくなった');
});

test('いま使われている記録は、片付けの対象にしない', function (): void {
    $store = freshStore();
    $config = testConfig(['keep_days' => 1, 'rate_window_seconds' => 3600]);
    $now = time();
    $store->put('rate', 'inbox_busy', ['times' => [$now - 7200]]);

    // 別の処理が同じファイルを開いて鍵をかけている状態を作る。
    $path = storageDirOf($store) . '/rate/inbox_busy.json';
    $busy = fopen($path, 'r+');
    assertTrue($busy !== false, 'ファイルを開けない');
    assertTrue(flock($busy, LOCK_EX | LOCK_NB), '鍵をかけられない');

    try {
        (new LineInboxService($config, $store))->prune();
        assertTrue($store->exists('rate', 'inbox_busy'), '使われている最中のファイルを消した');
    } finally {
        flock($busy, LOCK_UN);
        fclose($busy);
    }

    // 使い終われば、次の片付けで消える。
    (new LineInboxService($config, $store))->prune();
    assertTrue(!$store->exists('rate', 'inbox_busy'), '使い終わっても消えない');
});

test('印（シンボリックリンク）の先は消さない', function (): void {
    $store = freshStore();
    $config = testConfig(['keep_days' => 1, 'rate_window_seconds' => 3600]);
    $dir = storageDirOf($store);

    // 置き場所の外にある、消されては困るファイル。
    $outside = $dir . '/../大事なファイル-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($outside, json_encode(['times' => [1]]));
    // それを指す印を、回数制限のフォルダーへ置く。
    symlink($outside, $dir . '/rate/inbox_link.json');

    try {
        (new LineInboxService($config, $store))->prune();
        assertTrue(file_exists($outside), '印の先のファイルを消した');
        assertTrue(is_link($dir . '/rate/inbox_link.json'), '印そのものを消した');
    } finally {
        @unlink($dir . '/rate/inbox_link.json');
        @unlink($outside);
    }
});

// ── 設定と置き場所 ──────────────────────────────────────
group('設定と置き場所の守り');

test('合言葉が64文字未満なら起動しない', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'linecfg') . '.php';
    file_put_contents($path, '<?php return ' . var_export([
        'channel_secret' => SECRET,
        'inbox_token' => 'みじかい合言葉',
        'storage_dir' => sys_get_temp_dir() . '/relagarden-line-cfg',
    ], true) . ';');

    try {
        LineConfig::load($path);
        throw new RuntimeException('短い合言葉で起動してしまった');
    } catch (LineConfigMissing $e) {
        assertSame('E_CONFIG_TOKEN', $e->getMessage());
    } finally {
        @unlink($path);
    }
});

test('置き場所が公開領域なら起動しない', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'linecfg') . '.php';
    file_put_contents($path, '<?php return ' . var_export([
        'channel_secret' => SECRET,
        'inbox_token' => INBOX_TOKEN,
        'storage_dir' => '/home/example/relagarden.jp/public_html/api/line/data',
    ], true) . ';');

    try {
        LineConfig::load($path);
        throw new RuntimeException('公開領域を置き場所にして起動してしまった');
    } catch (LineConfigMissing $e) {
        assertSame('E_CONFIG_STORAGE_PUBLIC', $e->getMessage());
    } finally {
        @unlink($path);
    }
});

test('設定ファイルが無いときも、事情を外へ出さない', function (): void {
    try {
        LineConfig::load('/存在しない/line-config.php');
        throw new RuntimeException('設定が無いのに起動してしまった');
    } catch (LineConfigMissing $e) {
        assertSame('E_CONFIG_MISSING', $e->getMessage());
    }
});

test('置き場所が書けないなら起動しない', function (): void {
    $base = sys_get_temp_dir() . '/relagarden-line-ro-' . bin2hex(random_bytes(4));
    mkdir($base, 0500, true);
    try {
        new LineStore($base . '/data');
        throw new RuntimeException('書けない場所で起動してしまった');
    } catch (LineStorageUnavailable $e) {
        assertTrue(str_starts_with($e->getMessage(), 'E_STORAGE'), $e->getMessage());
    } finally {
        @chmod($base, 0700);
        @rmdir($base);
    }
});

// ── 掲載とは分かれていること ────────────────────────────
group('掲載の入口を持たないこと');

foreach (['/publish', '/status', '/unpublish', '/pairing'] as $route) {
    test('この入口には ' . $route . ' が無い', function () use ($route): void {
        $store = freshStore();
        $router = routerWith($store);
        [$status] = $router->handle('POST', $route, '{}', ['authorization' => 'Bearer ' . INBOX_TOKEN], '203.0.113.10');
        assertSame(404, $status);
    });
}

// ── 本人限定AI返信 ──────────────────────────────────────
group('本人限定AI返信');

test('自動返信の入口が無い', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    [$status] = $router->handle(
        'POST',
        '/auto-reply/replied',
        '{}',
        ['authorization' => 'Bearer ' . INBOX_TOKEN],
        '203.0.113.10'
    );
    assertSame(404, $status);
});

test('自動返信には本人限定の代替プッシュ以外の一斉送信・画像取得が無い', function (): void {
    $forbidden = [
        '/v2/bot/message/multicast',
        '/v2/bot/message/broadcast',
        '/v2/bot/message/narrowcast',
        // 画像や動画の中身を取りに行く入口
        '/content',
    ];
    foreach (glob(__DIR__ . '/../src/*.php') ?: [] as $file) {
        if (basename($file) === 'LineManualSendService.php') {
            continue;
        }
        $code = (string) file_get_contents($file);
        foreach ($forbidden as $needle) {
            assertTrue(
                !str_contains($code, $needle),
                basename($file) . ' に ' . $needle . ' が入っている'
            );
        }
    }
    $ownerReplyCode = (string) file_get_contents(__DIR__ . '/../src/LineOwnerAiReplyService.php');
    assertSame(1, substr_count($ownerReplyCode, '/v2/bot/message/push'));
});

test('手動送信にも一斉配信・複数配信の入口が無い', function (): void {
    $code = (string) file_get_contents(__DIR__ . '/../src/LineManualSendService.php');
    assertSame(1, substr_count($code, '/v2/bot/message/push'));
    foreach (['/multicast', '/broadcast', '/narrowcast', '/content'] as $needle) {
        assertTrue(!str_contains($code, $needle), $needle . ' が入っている');
    }
});

test('初期状態では誰から届いても外部通信しない', function (): void {
    $store = freshStore();
    $calls = [];
    $config = testConfig();
    $reply = new LineOwnerAiReplyService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            return ['status' => 500, 'body' => ''];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), $reply);
    [$status] = postWebhook(
        $router,
        textEvent('EV-AI-OFF', 'MSG-AI-OFF', 'UOWNER00000000000000000000000000', '相談です')
    );

    assertSame(200, $status);
    assertSame(0, count($calls));
    assertSame(1, count($store->keys('inbox')), '受信箱への保存は止めない');
});

test('TEST_MODEがOFFならenabledでも外部通信しない', function (): void {
    $store = freshStore();
    $calls = [];
    $config = testConfig([
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => false,
        'ai_reply_allowed_user_id' => 'UOWNER00000000000000000000000000',
    ]);
    $reply = new LineOwnerAiReplyService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            return ['status' => 500, 'body' => ''];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), $reply);
    [$status] = postWebhook(
        $router,
        textEvent('EV-AI-NOTEST', 'MSG-AI-NOTEST', 'UOWNER00000000000000000000000000', '相談です')
    );

    assertSame(200, $status);
    assertSame(0, count($calls));
    assertTrue(str_contains(readLog($store), 'E_AI_TEST_MODE_OFF'));
});

test('許可した本人以外はGatewayにもLINE返信にも送らない', function (): void {
    $store = freshStore();
    $calls = [];
    $config = testConfig([
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => true,
        'ai_reply_allowed_user_id' => 'UOWNER00000000000000000000000000',
        'ai_reply_session_id' => 'owner-test-session-other',
        'ai_gateway_base_url' => 'https://gateway.example/v1',
        'ai_gateway_token' => str_repeat('g', 64),
        'channel_access_token' => str_repeat('l', 64),
    ]);
    $reply = new LineOwnerAiReplyService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            return ['status' => 200, 'body' => '{"reply":"返事"}'];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), $reply);
    [$status] = postWebhook(
        $router,
        textEvent('EV-AI-OTHER', 'MSG-AI-OTHER', 'UCUSTOMER000000000000000000000000', 'お客様です')
    );

    assertSame(200, $status);
    assertSame(0, count($calls));
    assertSame(1, count($store->keys('inbox')), '既存のお客様の本文は受信箱へ残す');
});

test('許可した本人だけGateway経由でreplyTokenへ返信する', function (): void {
    $store = freshStore();
    $calls = [];
    $owner = 'UOWNER00000000000000000000000000';
    $config = testConfig([
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => true,
        'ai_reply_allowed_user_id' => $owner,
        'ai_reply_session_id' => 'owner-test-session-safe',
        'ai_gateway_base_url' => 'https://gateway.example/v1',
        'ai_gateway_token' => str_repeat('g', 64),
        'channel_access_token' => str_repeat('l', 64),
    ]);
    $reply = new LineOwnerAiReplyService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            if ($url === 'https://gateway.example/v1/ask') {
                return ['status' => 200, 'body' => '{"reply":"ASK_AREA"}'];
            }
            return ['status' => 200, 'body' => '{}'];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), $reply);
    $body = textEvent('EV-AI-OWNER', 'MSG-AI-OWNER', $owner, '人工芝の相談です');
    [$status] = postWebhook($router, $body);
    [$retryStatus] = postWebhook($router, $body);

    assertSame(200, $status);
    assertSame(200, $retryStatus);
    assertSame(2, count($calls));
    assertSame('https://gateway.example/v1/ask', $calls[0]['url']);
    assertSame('line_reception', $calls[0]['body']['feature'] ?? null);
    assertTrue(str_contains($calls[0]['body']['prompt'] ?? '', '人工芝の相談です'));
    assertTrue(str_contains($calls[0]['body']['prompt'] ?? '', '受付分類'));
    assertTrue(!str_contains(json_encode($calls[0]['body']) ?: '', $owner), 'userIdをGatewayへ送っている');
    assertSame('https://api.line.me/v2/bot/message/reply', $calls[1]['url']);
    assertSame('dummy-reply-token', $calls[1]['body']['replyToken'] ?? null);
    assertSame(
        'お問い合わせありがとうございます。お庭のおおよその広さを教えていただけますか。',
        $calls[1]['body']['messages'][0]['text'] ?? null,
    );
    assertTrue(!str_contains($calls[1]['url'], '/push'), 'プッシュ送信を使っている');
    assertSame(1, count($store->keys('inbox')), '再送で本文が増えている');
});

test('replyTokenが明確に拒否された場合だけ許可した本人へプッシュで補う', function (): void {
    $store = freshStore();
    $calls = [];
    $owner = 'UOWNER00000000000000000000000000';
    $config = testConfig([
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => true,
        'ai_reply_allowed_user_id' => $owner,
        'ai_reply_session_id' => 'owner-reply-push-fallback',
        'ai_gateway_base_url' => 'https://gateway.example/v1',
        'ai_gateway_token' => str_repeat('g', 64),
        'channel_access_token' => str_repeat('l', 64),
    ]);
    $reply = new LineOwnerAiReplyService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            if (str_contains($url, '/ask')) {
                return ['status' => 200, 'body' => '{"reply":"ASK_LOCATION"}'];
            }
            if (str_contains($url, '/reply')) {
                return ['status' => 400, 'body' => '{}'];
            }
            return ['status' => 200, 'body' => '{}'];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), $reply);
    postWebhook(
        $router,
        textEvent('EV-AI-PUSH-FALLBACK', 'MSG-AI-PUSH-FALLBACK', $owner, '人工芝を検討しています')
    );

    assertSame(3, count($calls));
    assertSame('https://api.line.me/v2/bot/message/reply', $calls[1]['url']);
    assertSame('https://api.line.me/v2/bot/message/push', $calls[2]['url']);
    assertSame($owner, $calls[2]['body']['to'] ?? null);
    assertSame(
        'お問い合わせありがとうございます。施工場所の市区町村を教えていただけますか。',
        $calls[2]['body']['messages'][0]['text'] ?? null,
    );
    $log = readLog($store);
    assertTrue(str_contains($log, 'E_AI_LINE_REPLY_400'));
    assertTrue(str_contains($log, 'I_AI_OWNER_PUSH_FALLBACK'));
    [, $inbox] = getInbox($router);
    assertSame(false, $inbox['items'][0]['needsHuman']);
});

test('replyToken送信が不確実な場合は二重送信防止のためプッシュしない', function (): void {
    $store = freshStore();
    $calls = [];
    $owner = 'UOWNER00000000000000000000000000';
    $config = testConfig([
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => true,
        'ai_reply_allowed_user_id' => $owner,
        'ai_reply_session_id' => 'owner-reply-uncertain',
        'ai_gateway_base_url' => 'https://gateway.example/v1',
        'ai_gateway_token' => str_repeat('g', 64),
        'channel_access_token' => str_repeat('l', 64),
    ]);
    $reply = new LineOwnerAiReplyService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            if (str_contains($url, '/ask')) {
                return ['status' => 200, 'body' => '{"reply":"ASK_LOCATION"}'];
            }
            throw new RuntimeException('timeout');
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), $reply);
    postWebhook(
        $router,
        textEvent('EV-AI-REPLY-UNCERTAIN', 'MSG-AI-REPLY-UNCERTAIN', $owner, '人工芝を検討しています')
    );

    assertSame(2, count($calls));
    assertTrue(!array_filter(
        $calls,
        static fn (array $call): bool => str_contains((string) ($call['url'] ?? ''), '/push'),
    ));
    assertTrue(str_contains(readLog($store), 'E_AI_LINE_REPLY_UNCERTAIN'));
});

test('replyTokenが欠けている場合は本人にもプッシュせず停止する', function (): void {
    $store = freshStore();
    $calls = [];
    $owner = 'UOWNER00000000000000000000000000';
    $config = testConfig([
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => true,
        'ai_reply_allowed_user_id' => $owner,
        'ai_reply_session_id' => 'owner-missing-reply-token',
        'ai_gateway_base_url' => 'https://gateway.example/v1',
        'ai_gateway_token' => str_repeat('g', 64),
        'channel_access_token' => str_repeat('l', 64),
    ]);
    $reply = new LineOwnerAiReplyService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            return ['status' => 200, 'body' => '{"reply":"ASK_LOCATION"}'];
        },
    );

    $result = $reply->replyIfAllowed($owner, '人工芝を検討しています', '');

    assertSame(1, count($calls));
    assertSame(true, $result['needsHuman']);
    assertSame('REPLY_FAILED', $result['reasonCode']);
    assertTrue(str_contains(readLog($store), 'E_AI_REPLY_TOKEN'));
});

test('通常の人工芝相談はClaudeが引継ぎを選んでも最低限の受付質問を返す', function (): void {
    $store = freshStore();
    $calls = [];
    $owner = 'UOWNER00000000000000000000000000';
    $config = testConfig([
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => true,
        'ai_reply_allowed_user_id' => $owner,
        'ai_reply_session_id' => 'owner-minimum-response',
        'ai_gateway_base_url' => 'https://gateway.example/v1',
        'ai_gateway_token' => str_repeat('g', 64),
        'channel_access_token' => str_repeat('l', 64),
    ]);
    $reply = new LineOwnerAiReplyService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            return str_contains($url, '/ask')
                ? ['status' => 200, 'body' => '{"reply":"ACK_HANDOFF"}']
                : ['status' => 200, 'body' => '{}'];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), $reply);
    postWebhook(
        $router,
        textEvent('EV-MINIMUM-RESPONSE', 'MSG-MINIMUM-RESPONSE', $owner, '人工芝を検討しています。')
    );

    assertSame(2, count($calls));
    assertSame('https://gateway.example/v1/ask', $calls[0]['url']);
    assertSame('https://api.line.me/v2/bot/message/reply', $calls[1]['url']);
    assertSame(
        'お問い合わせありがとうございます。施工場所の市区町村を教えていただけますか。',
        $calls[1]['body']['messages'][0]['text'] ?? null,
    );
    [, $inbox] = getInbox($router);
    assertSame(false, $inbox['items'][0]['needsHuman']);
    assertSame('', $inbox['items'][0]['reasonCode']);
});

test('設定が不足しても外部通信せず、受信箱は止めない', function (): void {
    $store = freshStore();
    $calls = [];
    $owner = 'UOWNER00000000000000000000000000';
    $config = testConfig([
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => true,
        'ai_reply_allowed_user_id' => $owner,
        // GatewayトークンとLINEトークンは意図的に空欄。
    ]);
    $reply = new LineOwnerAiReplyService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            return ['status' => 200, 'body' => '{}'];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), $reply);
    [$status] = postWebhook(
        $router,
        textEvent('EV-AI-CONFIG', 'MSG-AI-CONFIG', $owner, '設定不足の確認')
    );

    assertSame(200, $status);
    assertSame(0, count($calls));
    assertSame(1, count($store->keys('inbox')));
    assertTrue(str_contains(readLog($store), 'E_AI_CONFIG'));
});

test('Gatewayが失敗しても受信箱へ残し、安全な固定質問で最低限の受付を続ける', function (): void {
    $store = freshStore();
    $calls = [];
    $owner = 'UOWNER00000000000000000000000000';
    $config = testConfig([
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => true,
        'ai_reply_allowed_user_id' => $owner,
        'ai_reply_session_id' => 'owner-test-session-fail',
        'ai_gateway_base_url' => 'https://gateway.example/v1',
        'ai_gateway_token' => str_repeat('g', 64),
        'channel_access_token' => str_repeat('l', 64),
    ]);
    $reply = new LineOwnerAiReplyService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            if (str_contains($url, '/ask')) {
                return ['status' => 503, 'body' => '{"ok":false}'];
            }
            return str_contains($url, '/reply')
                ? ['status' => 400, 'body' => '{}']
                : ['status' => 200, 'body' => '{}'];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), $reply);
    [$status] = postWebhook(
        $router,
        textEvent('EV-AI-FAIL', 'MSG-AI-FAIL', $owner, '人工芝について相談です')
    );

    assertSame(200, $status);
    assertSame(3, count($calls), 'Gateway失敗後の固定質問を試していない');
    assertSame('https://api.line.me/v2/bot/message/reply', $calls[1]['url']);
    assertSame('https://api.line.me/v2/bot/message/push', $calls[2]['url']);
    assertSame(
        'お問い合わせありがとうございます。施工場所の市区町村を教えていただけますか。',
        $calls[1]['body']['messages'][0]['text'] ?? null,
    );
    assertSame(1, count($store->keys('inbox')), '手動返信用の本文が残っていない');
    assertTrue(str_contains(readLog($store), 'E_AI_GATEWAY'));
});

test('AIが選択肢外を返しても安全な固定質問で最低限の受付を続ける', function (): void {
    $store = freshStore();
    $calls = [];
    $owner = 'UOWNER00000000000000000000000000';
    $config = testConfig([
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => true,
        'ai_reply_allowed_user_id' => $owner,
        'ai_reply_session_id' => 'owner-test-session-once',
        'ai_gateway_base_url' => 'https://gateway.example/v1',
        'ai_gateway_token' => str_repeat('g', 64),
        'channel_access_token' => str_repeat('l', 64),
        'ai_reply_daily_limit' => 4,
    ]);
    $reply = new LineOwnerAiReplyService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            return str_contains($url, '/ask')
                ? ['status' => 200, 'body' => '{"reply":"返事"}']
                : ['status' => 200, 'body' => '{}'];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), $reply);
    postWebhook($router, textEvent('EV-AI-LIMIT-1', 'MSG-AI-LIMIT-1', $owner, '人工芝について相談です'));
    assertSame(2, count($calls));
    assertSame('https://api.line.me/v2/bot/message/reply', $calls[1]['url']);
    assertSame(
        'お問い合わせありがとうございます。施工場所の市区町村を教えていただけますか。',
        $calls[1]['body']['messages'][0]['text'] ?? null,
    );
    assertTrue(str_contains(readLog($store), 'E_AI_RESPONSE'));
});

test('料金や日程の相談はAIへ送らず固定文で担当者へ引き継ぐ', function (): void {
    $store = freshStore();
    $calls = [];
    $owner = 'UOWNER00000000000000000000000000';
    $config = testConfig([
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => true,
        'ai_reply_allowed_user_id' => $owner,
        'ai_reply_session_id' => 'owner-test-session-handoff',
        'ai_gateway_base_url' => 'https://gateway.example/v1',
        'ai_gateway_token' => str_repeat('g', 64),
        'channel_access_token' => str_repeat('l', 64),
        'ai_reply_daily_limit' => 4,
    ]);
    $reply = new LineOwnerAiReplyService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            return ['status' => 200, 'body' => '{}'];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), $reply);
    postWebhook(
        $router,
        textEvent('EV-AI-HANDOFF', 'MSG-AI-HANDOFF', $owner, '料金はいくらですか')
    );

    assertSame(1, count($calls));
    assertSame('https://api.line.me/v2/bot/message/reply', $calls[0]['url']);
    assertSame(
        'お問い合わせありがとうございます。内容を確認し、担当者からご連絡いたします。少々お待ちください。',
        $calls[0]['body']['messages'][0]['text'] ?? null,
    );
    assertTrue(str_contains(readLog($store), 'I_AI_RECEPTION_HANDOFF'));
});

test('危険な相談表現の違いもAIへ渡さない', function (): void {
    $owner = 'UOWNER00000000000000000000000000';
    $messages = ['見積をください', '費用を知りたい', '来週できますか', '保証はありますか', '弁償してください'];
    foreach ($messages as $index => $message) {
        $store = freshStore();
        $calls = [];
        $config = testConfig([
            'ai_reply_enabled' => true,
            'ai_reply_test_mode' => true,
            'ai_reply_allowed_user_id' => $owner,
            'ai_reply_session_id' => 'owner-risk-variant-' . $index,
            'ai_gateway_base_url' => 'https://gateway.example/v1',
            'ai_gateway_token' => str_repeat('g', 64),
            'channel_access_token' => str_repeat('l', 64),
            'ai_reply_daily_limit' => 4,
        ]);
        $reply = new LineOwnerAiReplyService(
            $config,
            $store,
            function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
                $calls[] = compact('url', 'headers', 'body', 'timeout');
                return ['status' => 200, 'body' => '{}'];
            },
        );
        $router = new LineRouter($config, $store, new FakeLineProfile(), $reply);
        postWebhook(
            $router,
            textEvent('EV-AI-RISK-' . $index, 'MSG-AI-RISK-' . $index, $owner, $message)
        );

        assertSame(1, count($calls), $message . ' がGatewayへ送られている');
        assertSame('https://api.line.me/v2/bot/message/reply', $calls[0]['url']);
    }
});

test('いつもありがとうございますは日程相談と誤判定しない', function (): void {
    $store = freshStore();
    $calls = [];
    $owner = 'UOWNER00000000000000000000000000';
    $config = testConfig([
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => true,
        'ai_reply_allowed_user_id' => $owner,
        'ai_reply_session_id' => 'owner-thanks-not-schedule',
        'ai_gateway_base_url' => 'https://gateway.example/v1',
        'ai_gateway_token' => str_repeat('g', 64),
        'channel_access_token' => str_repeat('l', 64),
        'ai_reply_daily_limit' => 4,
    ]);
    $reply = new LineOwnerAiReplyService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            return str_contains($url, '/ask')
                ? ['status' => 200, 'body' => '{"reply":"ACK_HANDOFF"}']
                : ['status' => 200, 'body' => '{}'];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), $reply);
    postWebhook(
        $router,
        textEvent('EV-AI-THANKS', 'MSG-AI-THANKS', $owner, 'いつもありがとうございます')
    );

    assertSame('https://gateway.example/v1/ask', $calls[0]['url'] ?? null);
});

test('受付形式外の質問・依頼・施工可否はAIへ渡さず固定引継ぎにする', function (): void {
    $owner = 'UOWNER00000000000000000000000000';
    $messages = [
        'これは何ですか？',
        '人工芝を施工できますか？',
        '庭を修理してください',
        '今日来られますか？',
        '人工芝について教えて',
    ];
    foreach ($messages as $index => $message) {
        $store = freshStore();
        $calls = [];
        $config = testConfig([
            'ai_reply_enabled' => true,
            'ai_reply_test_mode' => true,
            'ai_reply_allowed_user_id' => $owner,
            'ai_reply_session_id' => 'owner-unsafe-initial-' . $index,
            'ai_gateway_base_url' => 'https://gateway.example/v1',
            'ai_gateway_token' => str_repeat('g', 64),
            'channel_access_token' => str_repeat('l', 64),
            'ai_reply_daily_limit' => 4,
        ]);
        $reply = new LineOwnerAiReplyService(
            $config,
            $store,
            function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
                $calls[] = compact('url', 'headers', 'body', 'timeout');
                return ['status' => 200, 'body' => '{}'];
            },
        );
        $router = new LineRouter($config, $store, new FakeLineProfile(), $reply);
        postWebhook($router, textEvent('EV-UNSAFE-' . $index, 'MSG-UNSAFE-' . $index, $owner, $message));

        assertSame(1, count($calls), $message . ' で固定引継ぎ以外の通信がある');
        assertSame('https://api.line.me/v2/bot/message/reply', $calls[0]['url']);
        [, $inbox] = getInbox($router);
        assertSame(true, $inbox['items'][0]['needsHuman']);
        assertSame('UNSAFE_INPUT', $inbox['items'][0]['reasonCode']);
    }
});

test('質問中も項目ごとの形式外回答をAIへ渡さない', function (): void {
    $owner = 'UOWNER00000000000000000000000000';
    $cases = [
        ['ASK_LOCATION', 'どこまで来られますか？'],
        ['ASK_AREA', '広さは多分です'],
        ['ASK_CONDITION', '砂利ですが今日来られますか？'],
        ['ASK_PHOTO', '写真送れますか？'],
        ['ASK_TIMING', '今日来られますか？'],
    ];
    foreach ($cases as $index => [$decision, $answer]) {
        $store = freshStore();
        $calls = [];
        $config = testConfig([
            'ai_reply_enabled' => true,
            'ai_reply_test_mode' => true,
            'ai_reply_allowed_user_id' => $owner,
            'ai_reply_session_id' => 'owner-unsafe-answer-' . $index,
            'ai_gateway_base_url' => 'https://gateway.example/v1',
            'ai_gateway_token' => str_repeat('g', 64),
            'channel_access_token' => str_repeat('l', 64),
            'ai_reply_daily_limit' => 4,
        ]);
        $reply = new LineOwnerAiReplyService(
            $config,
            $store,
            function (string $url, array $headers, array $body, int $timeout) use (&$calls, $decision): array {
                $calls[] = compact('url', 'headers', 'body', 'timeout');
                return str_contains($url, '/ask')
                    ? ['status' => 200, 'body' => json_encode(['reply' => $decision])]
                    : ['status' => 200, 'body' => '{}'];
            },
        );
        $router = new LineRouter($config, $store, new FakeLineProfile(), $reply);
        postWebhook($router, textEvent('EV-SAFE-START-' . $index, 'MSG-SAFE-START-' . $index, $owner, '人工芝について相談です'));
        postWebhook($router, textEvent('EV-UNSAFE-ANSWER-' . $index, 'MSG-UNSAFE-ANSWER-' . $index, $owner, $answer, 1756000001000));

        $gatewayCalls = array_values(array_filter($calls, static fn (array $call): bool => str_contains($call['url'], '/ask')));
        assertSame(1, count($gatewayCalls), $answer . ' がGatewayへ送られている');
        [, $inbox] = getInbox($router);
        assertSame(true, $inbox['items'][1]['needsHuman']);
        assertSame('UNSAFE_INPUT', $inbox['items'][1]['reasonCode']);
    }
});

test('AI文に金額や確約が混ざっても送らず安全な固定質問へ置き換える', function (): void {
    $store = freshStore();
    $calls = [];
    $owner = 'UOWNER00000000000000000000000000';
    $config = testConfig([
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => true,
        'ai_reply_allowed_user_id' => $owner,
        'ai_reply_session_id' => 'owner-test-session-output-guard',
        'ai_gateway_base_url' => 'https://gateway.example/v1',
        'ai_gateway_token' => str_repeat('g', 64),
        'channel_access_token' => str_repeat('l', 64),
        'ai_reply_daily_limit' => 4,
    ]);
    $reply = new LineOwnerAiReplyService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            return str_contains($url, '/ask')
                ? ['status' => 200, 'body' => '{"reply":"施工できます。料金は5000円です。"}']
                : ['status' => 200, 'body' => '{}'];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), $reply);
    postWebhook(
        $router,
        textEvent('EV-AI-OUTPUT-GUARD', 'MSG-AI-OUTPUT-GUARD', $owner, '人工芝について相談したいです')
    );

    assertSame(2, count($calls));
    assertSame(
        'お問い合わせありがとうございます。施工場所の市区町村を教えていただけますか。',
        $calls[1]['body']['messages'][0]['text'] ?? null,
    );
    assertTrue(str_contains(readLog($store), 'E_AI_RESPONSE'));
});

test('最大3質問で地域・広さ・現状を集め、担当者へ引き継ぐ', function (): void {
    $store = freshStore();
    $calls = [];
    $gatewayReplies = ['ASK_LOCATION', 'ASK_AREA', 'ASK_PHOTO'];
    $owner = 'UOWNER00000000000000000000000000';
    $config = testConfig([
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => true,
        'ai_reply_allowed_user_id' => $owner,
        'ai_reply_session_id' => 'owner-multiturn-three-questions',
        'ai_gateway_base_url' => 'https://gateway.example/v1',
        'ai_gateway_token' => str_repeat('g', 64),
        'channel_access_token' => str_repeat('l', 64),
        'ai_reception_max_questions' => 3,
        'ai_reply_daily_limit' => 4,
    ]);
    $reply = new LineOwnerAiReplyService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls, &$gatewayReplies): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            if (str_contains($url, '/ask')) {
                $decision = array_shift($gatewayReplies) ?? 'ACK_HANDOFF';
                return ['status' => 200, 'body' => json_encode(['reply' => $decision])];
            }
            return ['status' => 200, 'body' => '{}'];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), $reply);

    postWebhook($router, textEvent('EV-MT-1', 'MSG-MT-1', $owner, '人工芝について相談です', 1756000000000));
    postWebhook($router, textEvent('EV-MT-2', 'MSG-MT-2', $owner, '岡崎市です', 1756000001000));
    postWebhook($router, textEvent('EV-MT-3', 'MSG-MT-3', $owner, '約30㎡です', 1756000002000));
    postWebhook($router, textEvent('EV-MT-4', 'MSG-MT-4', $owner, '砂利です', 1756000003000));

    $gatewayCalls = array_values(array_filter($calls, static fn (array $call): bool => str_contains($call['url'], '/ask')));
    $lineCalls = array_values(array_filter($calls, static fn (array $call): bool => str_contains($call['url'], '/message/reply')));
    assertSame(1, count($gatewayCalls), '一連受付の途中でもGateway待ちが発生している');
    assertSame(4, count($lineCalls), '質問3回と引継ぎ1回以外を送っている');
    foreach ($calls as $call) {
        assertTrue(!str_contains($call['url'], '/push'), 'Push送信を使っている');
        assertTrue(!str_contains($call['url'], '/content'), '写真本体を取得している');
    }

    [, $inbox] = getInbox($router);
    assertSame(4, count($inbox['items']));
    $latest = $inbox['items'][3];
    assertSame('text', $latest['kind']);
    assertSame('砂利です', $latest['text']);
    assertSame(true, $latest['needsHuman']);
    assertSame('MAX_QUESTIONS', $latest['reasonCode']);
    assertSame('岡崎市です', $latest['collectedFields']['region']);
    assertSame('約30㎡です', $latest['collectedFields']['area']);
    assertSame('', $latest['collectedFields']['photo']);
    assertSame('砂利です', $latest['collectedFields']['condition']);
    assertSame('', $latest['collectedFields']['preferredTiming']);
});

test('最大5質問なら受付5項目を一連で確認してから担当者へ引き継ぐ', function (): void {
    $store = freshStore();
    $calls = [];
    $gatewayReplies = ['ASK_LOCATION', 'ASK_AREA', 'ASK_CONDITION', 'ASK_PHOTO', 'ASK_TIMING'];
    $owner = 'UOWNER00000000000000000000000000';
    $config = testConfig([
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => true,
        'ai_reply_allowed_user_id' => $owner,
        'ai_reply_session_id' => 'owner-multiturn-five-questions',
        'ai_gateway_base_url' => 'https://gateway.example/v1',
        'ai_gateway_token' => str_repeat('g', 64),
        'channel_access_token' => str_repeat('l', 64),
        'ai_reception_max_questions' => 5,
        'ai_reply_daily_limit' => 12,
    ]);
    $reply = new LineOwnerAiReplyService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls, &$gatewayReplies): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            if (str_contains($url, '/ask')) {
                return ['status' => 200, 'body' => json_encode(['reply' => array_shift($gatewayReplies)])];
            }
            return ['status' => 200, 'body' => '{}'];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), $reply);

    postWebhook($router, textEvent('EV-FIVE-1', 'MSG-FIVE-1', $owner, '人工芝について相談です', 1756000000000));
    postWebhook($router, textEvent('EV-FIVE-2', 'MSG-FIVE-2', $owner, '愛知県刈谷市です', 1756000001000));
    postWebhook($router, textEvent('EV-FIVE-3', 'MSG-FIVE-3', $owner, '約20㎡です', 1756000002000));
    postWebhook($router, textEvent('EV-FIVE-4', 'MSG-FIVE-4', $owner, '今は雑草が生えています', 1756000003000));
    postWebhook($router, textEvent('EV-FIVE-5', 'MSG-FIVE-5', $owner, '写真は送ります', 1756000004000));
    postWebhook($router, textEvent('EV-FIVE-6', 'MSG-FIVE-6', $owner, '未定です', 1756000005000));

    $gatewayCalls = array_values(array_filter($calls, static fn (array $call): bool => str_contains($call['url'], '/ask')));
    $lineCalls = array_values(array_filter($calls, static fn (array $call): bool => str_contains($call['url'], '/message/reply')));
    assertSame(1, count($gatewayCalls), '一連受付の途中でもGateway待ちが発生している');
    assertSame(6, count($lineCalls), '5質問と完了引継ぎ以外を送っている');
    assertTrue(
        str_contains((string) ($lineCalls[1]['body']['messages'][0]['text'] ?? ''), '広さ'),
        '場所回答後に広さの質問へ進んでいない',
    );

    [, $inbox] = getInbox($router);
    $latest = $inbox['items'][5];
    assertSame(true, $latest['needsHuman']);
    assertSame('INTAKE_COMPLETE', $latest['reasonCode']);
    assertSame('愛知県刈谷市です', $latest['collectedFields']['region']);
    assertSame('約20㎡です', $latest['collectedFields']['area']);
    assertSame('今は雑草が生えています', $latest['collectedFields']['condition']);
    assertSame('promised', $latest['collectedFields']['photo']);
    assertSame('未定です', $latest['collectedFields']['preferredTiming']);
});

test('同じ本人の並行受付でも質問数は3回を超えない', function (): void {
    if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
        // 本番コードのfinally解放だけは、pcntlが無いPHPでも確認する。
        $store = freshStore();
        try {
            $store->synchronized('fallback-lock-test', static function (): void {
                throw new RuntimeException('expected');
            });
        } catch (RuntimeException $e) {
            assertSame('expected', $e->getMessage());
        }
        assertSame('released', $store->synchronized('fallback-lock-test', static fn (): string => 'released'));
        return;
    }

    $store = freshStore();
    $owner = 'UOWNER00000000000000000000000000';
    $sessionId = 'owner-concurrent-question-limit';
    $states = new LineReceptionStateService($store, 3);
    $state = $states->load($owner, $sessionId);
    $state = $states->capture($states->ask($state, 'condition'), 'text', '砂利です');
    $state = $states->capture($states->ask($state, 'area'), 'text', '30㎡です');
    assertTrue($states->save($owner, $sessionId, $state));

    $config = testConfig([
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => true,
        'ai_reply_allowed_user_id' => $owner,
        'ai_reply_session_id' => $sessionId,
        'ai_gateway_base_url' => 'https://gateway.example/v1',
        'ai_gateway_token' => str_repeat('g', 64),
        'channel_access_token' => str_repeat('l', 64),
        'ai_reception_max_questions' => 3,
        'ai_reply_daily_limit' => 4,
    ]);
    $callFile = storageDirOf($store) . '/parallel-calls.log';
    $children = [];
    for ($index = 0; $index < 4; $index++) {
        $pid = pcntl_fork();
        assertTrue($pid >= 0, '子プロセスを開始できない');
        if ($pid === 0) {
            $reply = new LineOwnerAiReplyService(
                $config,
                $store,
                static function (string $url, array $headers, array $body, int $timeout) use ($callFile): array {
                    if (str_contains($url, '/ask')) {
                        file_put_contents($callFile, "G\n", FILE_APPEND | LOCK_EX);
                        usleep(150000);
                        return ['status' => 200, 'body' => '{"reply":"ASK_LOCATION"}'];
                    }
                    file_put_contents($callFile, "L\n", FILE_APPEND | LOCK_EX);
                    return ['status' => 200, 'body' => '{}'];
                },
            );
            $reply->replyIfAllowed($owner, '人工芝について相談です', 'parallel-reply-' . $index);
            exit(0);
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) {
        $status = 0;
        pcntl_waitpid($pid, $status);
        assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, '並行受付が異常終了した');
    }

    $calls = is_file($callFile) ? file($callFile, FILE_IGNORE_NEW_LINES) : [];
    assertSame(0, count(array_filter($calls, static fn (string $call): bool => $call === 'G')));
    $saved = $states->load($owner, $sessionId);
    assertSame(3, $saved['questionsAsked']);
    assertSame('handoff', $saved['status']);
    assertSame('UNSAFE_INPUT', $saved['reasonCode']);
});

test('引継ぎ後の新着は保存するがGatewayにもLINEにも送らない', function (): void {
    $store = freshStore();
    $calls = [];
    $owner = 'UOWNER00000000000000000000000000';
    $config = testConfig([
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => true,
        'ai_reply_allowed_user_id' => $owner,
        'ai_reply_session_id' => 'owner-handoff-stays-stopped',
        'ai_gateway_base_url' => 'https://gateway.example/v1',
        'ai_gateway_token' => str_repeat('g', 64),
        'channel_access_token' => str_repeat('l', 64),
        'ai_reception_max_questions' => 3,
        'ai_reply_daily_limit' => 4,
    ]);
    $reply = new LineOwnerAiReplyService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            return ['status' => 200, 'body' => '{}'];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), $reply);
    postWebhook($router, textEvent('EV-STOP-1', 'MSG-STOP-1', $owner, '料金はいくらですか'));
    $afterHandoff = count($calls);
    postWebhook($router, textEvent('EV-STOP-2', 'MSG-STOP-2', $owner, '岡崎市です', 1756000001000));

    assertSame(1, $afterHandoff);
    assertSame($afterHandoff, count($calls), '引継ぎ後も外部通信している');
    [, $inbox] = getInbox($router);
    assertSame(2, count($inbox['items']));
    assertSame(true, $inbox['items'][1]['needsHuman']);
    assertSame('PRICE', $inbox['items'][1]['reasonCode']);
});

test('AI対象外の通常受信はAI引継ぎにせず固定5項目を返す', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    postWebhook($router, textEvent('EV-MANUAL-META', 'MSG-MANUAL-META', 'U10101010101010101010101010101010', '相談です'));
    [, $inbox] = getInbox($router);
    $item = $inbox['items'][0];
    assertSame(false, $item['needsHuman']);
    assertSame('MANUAL_ONLY', $item['reasonCode']);
    assertSame(
        ['region', 'area', 'condition', 'photo', 'preferredTiming'],
        array_keys($item['collectedFields']),
    );
});

test('受付状態にはreplyTokenと生のuserIdを保存しない', function (): void {
    $store = freshStore();
    $owner = 'UOWNER00000000000000000000000000';
    $config = testConfig([
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => true,
        'ai_reply_allowed_user_id' => $owner,
        'ai_reply_session_id' => 'owner-private-state',
        'ai_gateway_base_url' => 'https://gateway.example/v1',
        'ai_gateway_token' => str_repeat('g', 64),
        'channel_access_token' => str_repeat('l', 64),
        'ai_reception_max_questions' => 3,
        'ai_reply_daily_limit' => 4,
    ]);
    $reply = new LineOwnerAiReplyService(
        $config,
        $store,
        static function (string $url, array $headers, array $body, int $timeout): array {
            return str_contains($url, '/ask')
                ? ['status' => 200, 'body' => '{"reply":"ASK_LOCATION"}']
                : ['status' => 200, 'body' => '{}'];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), $reply);
    postWebhook($router, textEvent('EV-PRIVATE', 'MSG-PRIVATE', $owner, '人工芝について相談です'));

    $keys = $store->keys('reception');
    assertSame(1, count($keys));
    assertTrue(!str_contains($keys[0], $owner), 'userIdを状態の鍵へ出している');
    $saved = json_encode($store->get('reception', $keys[0]), JSON_UNESCAPED_UNICODE) ?: '';
    assertTrue(!str_contains($saved, $owner), 'userIdを状態本文へ保存している');
    assertTrue(!str_contains($saved, 'dummy-reply-token'), 'replyTokenを状態へ保存している');
});

test('現状・希望時期・写真なしも固定5項目へ安全に保存できる', function (): void {
    $states = new LineReceptionStateService(freshStore(), 3);
    $state = $states->load('U-state-unit', 'session-state-unit');
    $state = $states->ask($state, 'condition');
    $state = $states->capture($state, 'text', '砂利が敷いてあります');
    $state = $states->ask($state, 'preferredTiming');
    $state = $states->capture($state, 'text', '11月ごろ希望です');
    $state = $states->ask($state, 'photo');
    $state = $states->capture($state, 'text', '写真はありません');
    $meta = $states->metadata($state);

    assertSame('砂利が敷いてあります', $meta['collectedFields']['condition']);
    assertSame('11月ごろ希望です', $meta['collectedFields']['preferredTiming']);
    assertSame('unavailable', $meta['collectedFields']['photo']);
    assertTrue($states->reachedQuestionLimit($state));
});

test('Reply APIが失敗したら人対応へ切り替え、次の新着へ自動返信しない', function (): void {
    $store = freshStore();
    $calls = [];
    $owner = 'UOWNER00000000000000000000000000';
    $config = testConfig([
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => true,
        'ai_reply_allowed_user_id' => $owner,
        'ai_reply_session_id' => 'owner-line-reply-failure',
        'ai_gateway_base_url' => 'https://gateway.example/v1',
        'ai_gateway_token' => str_repeat('g', 64),
        'channel_access_token' => str_repeat('l', 64),
        'ai_reception_max_questions' => 3,
        'ai_reply_daily_limit' => 4,
    ]);
    $reply = new LineOwnerAiReplyService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            return str_contains($url, '/ask')
                ? ['status' => 200, 'body' => '{"reply":"ASK_LOCATION"}']
                : ['status' => 500, 'body' => '{}'];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), $reply);
    postWebhook($router, textEvent('EV-LINE-FAIL-1', 'MSG-LINE-FAIL-1', $owner, '人工芝について相談です'));
    $afterFailure = count($calls);
    postWebhook($router, textEvent('EV-LINE-FAIL-2', 'MSG-LINE-FAIL-2', $owner, '岡崎市です', 1756000001000));

    assertSame(2, $afterFailure);
    assertSame($afterFailure, count($calls), '返信失敗後も外部通信している');
    [, $inbox] = getInbox($router);
    assertSame(true, $inbox['items'][0]['needsHuman']);
    assertSame('REPLY_FAILED', $inbox['items'][0]['reasonCode']);
    assertSame('REPLY_FAILED', $inbox['items'][1]['reasonCode']);
});

test('受付状態を書けないと別の停止印を残し、次の新着でAIを再開しない', function (): void {
    $store = freshStore();
    $calls = [];
    $owner = 'UOWNER00000000000000000000000000';
    $config = testConfig([
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => true,
        'ai_reply_allowed_user_id' => $owner,
        'ai_reply_session_id' => 'owner-state-write-fail-stop',
        'ai_gateway_base_url' => 'https://gateway.example/v1',
        'ai_gateway_token' => str_repeat('g', 64),
        'channel_access_token' => str_repeat('l', 64),
        'ai_reception_max_questions' => 3,
        'ai_reply_daily_limit' => 4,
    ]);
    $reply = new LineOwnerAiReplyService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            return ['status' => 200, 'body' => '{"reply":"ASK_LOCATION"}'];
        },
    );
    $receptionDir = storageDirOf($store) . '/reception';
    chmod($receptionDir, 0500);
    try {
        $first = $reply->replyIfAllowed($owner, '人工芝について相談です', 'state-fail-1');
    } finally {
        chmod($receptionDir, 0700);
    }
    $second = $reply->replyIfAllowed($owner, '人工芝について相談です', 'state-fail-2');

    assertSame(true, $first['needsHuman']);
    assertSame('STATE_WRITE_FAILED', $first['reasonCode']);
    assertSame(true, $second['needsHuman']);
    assertSame('STATE_WRITE_FAILED', $second['reasonCode']);
    assertSame(0, count($calls), '状態保存失敗後に外部通信している');
});

test('受付ロックを作れないと別の停止印を残し、次の新着でAIを再開しない', function (): void {
    $store = freshStore();
    $calls = [];
    $owner = 'UOWNER00000000000000000000000000';
    $config = testConfig([
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => true,
        'ai_reply_allowed_user_id' => $owner,
        'ai_reply_session_id' => 'owner-lock-fail-stop',
        'ai_gateway_base_url' => 'https://gateway.example/v1',
        'ai_gateway_token' => str_repeat('g', 64),
        'channel_access_token' => str_repeat('l', 64),
        'ai_reception_max_questions' => 3,
        'ai_reply_daily_limit' => 4,
    ]);
    $reply = new LineOwnerAiReplyService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            return ['status' => 200, 'body' => '{"reply":"ASK_LOCATION"}'];
        },
    );
    $locksDir = storageDirOf($store) . '/locks';
    chmod($locksDir, 0500);
    try {
        $first = $reply->replyIfAllowed($owner, '人工芝について相談です', 'lock-fail-1');
    } finally {
        chmod($locksDir, 0700);
    }
    $second = $reply->replyIfAllowed($owner, '人工芝について相談です', 'lock-fail-2');

    assertSame(true, $first['needsHuman']);
    assertSame('LOCK_FAILED', $first['reasonCode']);
    assertSame(true, $second['needsHuman']);
    assertSame('LOCK_FAILED', $second['reasonCode']);
    assertSame(0, count($calls), 'ロック失敗後に外部通信している');
});

test('replyToken を受け取っても保存しない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    // textEvent() は本物と同じく replyToken を含む。
    postWebhook($router, textEvent('EV-RT', 'MSG-RT', 'U16161616161616161616161616161616', '返信トークン確認'));

    foreach ($store->keys('inbox') as $key) {
        $record = $store->get('inbox', $key) ?? [];
        assertTrue(!array_key_exists('replyToken', $record), 'replyTokenを保存している');
        assertTrue(
            !str_contains(json_encode($record, JSON_UNESCAPED_UNICODE) ?: '', 'dummy-reply-token'),
            'replyTokenが保存内容に混ざっている'
        );
    }
});

// ── 本人限定AI受付の停止解除 ────────────────────────────
group('本人限定AI受付の停止解除');

test('合言葉・本人完全一致・明示確認が無ければ停止解除できない', function (): void {
    $store = freshStore();
    $owner = 'UOWNER00000000000000000000000000';
    $config = testConfig([
        'ai_reply_allowed_user_id' => $owner,
        'ai_reply_session_id' => 'owner-reset-auth',
        'ai_reception_max_questions' => 3,
    ]);
    $states = new LineReceptionStateService($store, 3);
    $state = $states->handoff($states->load($owner, 'owner-reset-auth'), 'PRICE');
    assertTrue($states->save($owner, 'owner-reset-auth', $state));
    $router = new LineRouter($config, $store, new FakeLineProfile());

    [$missingToken] = postReceptionReset($router, ['lineUserId' => $owner, 'confirmed' => true], '');
    [$wrongOwner] = postReceptionReset($router, ['lineUserId' => 'UOTHER00000000000000000000000000', 'confirmed' => true]);
    [$notConfirmed] = postReceptionReset($router, ['lineUserId' => $owner, 'confirmed' => false]);

    assertSame(401, $missingToken);
    assertSame(403, $wrongOwner);
    assertSame(400, $notConfirmed);
    assertSame('handoff', $states->load($owner, 'owner-reset-auth')['status']);
});

test('停止解除は許可本人の現在sessionだけを初期化する', function (): void {
    $store = freshStore();
    $calls = [];
    $owner = 'UOWNER00000000000000000000000000';
    $currentSession = 'owner-reset-current';
    $oldSession = 'owner-reset-old';
    $config = testConfig([
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => true,
        'ai_reply_allowed_user_id' => $owner,
        'ai_reply_session_id' => $currentSession,
        'ai_reception_max_questions' => 3,
        'ai_reply_daily_limit' => 4,
        'ai_gateway_base_url' => 'https://gateway.example/v1',
        'ai_gateway_token' => str_repeat('g', 64),
        'channel_access_token' => str_repeat('l', 64),
    ]);
    $states = new LineReceptionStateService($store, 3);
    assertTrue($states->save(
        $owner,
        $currentSession,
        $states->handoff($states->load($owner, $currentSession), 'PRICE'),
    ));
    assertTrue($states->save(
        $owner,
        $oldSession,
        $states->handoff($states->load($owner, $oldSession), 'COMPLAINT'),
    ));
    $reply = new LineOwnerAiReplyService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            return str_contains($url, '/ask')
                ? ['status' => 200, 'body' => '{"reply":"ASK_LOCATION"}']
                : ['status' => 200, 'body' => '{}'];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), $reply);

    [$status, $payload] = postReceptionReset($router, [
        'lineUserId' => $owner,
        'confirmed' => true,
    ]);

    assertSame(200, $status);
    assertSame(true, $payload['reset']);
    assertSame('collecting', $states->load($owner, $currentSession)['status']);
    assertSame('', $states->load($owner, $currentSession)['reasonCode']);
    assertSame('handoff', $states->load($owner, $oldSession)['status']);
    assertSame('COMPLAINT', $states->load($owner, $oldSession)['reasonCode']);
    assertSame(1, count($store->keys('reception')), '過去sessionまで消している');

    postWebhook($router, textEvent('EV-RESET-RESUME', 'MSG-RESET-RESUME', $owner, '人工芝について相談です'));
    assertSame(2, count($calls), '停止解除後にGatewayとReply APIの受付が再開していない');
    assertSame('collecting', $states->load($owner, $currentSession)['status']);
    assertSame(1, $states->load($owner, $currentSession)['questionsAsked']);
});

test('本人限定テストを確認付きで再開すると本人の受付回数だけ初期化する', function (): void {
    $store = freshStore();
    $owner = 'UOWNER00000000000000000000000000';
    $sessionId = 'owner-reset-keeps-daily-limit';
    $calls = [];
    $config = testConfig([
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => true,
        'ai_reply_allowed_user_id' => $owner,
        'ai_reply_session_id' => $sessionId,
        'ai_reception_max_questions' => 3,
        'ai_reply_daily_limit' => 4,
        'ai_gateway_base_url' => 'https://gateway.example/v1',
        'ai_gateway_token' => str_repeat('g', 64),
        'channel_access_token' => str_repeat('l', 64),
    ]);
    $states = new LineReceptionStateService($store, 3);
    assertTrue($states->save(
        $owner,
        $sessionId,
        $states->handoff($states->load($owner, $sessionId), 'MAX_QUESTIONS'),
    ));
    $limiter = new \Relagarden\Line\LineRateLimiter($store, 86400);
    for ($i = 0; $i < 4; $i++) {
        $limiter->hit('ai_owner_' . LineStore::hashKey($owner), 4, '上限');
    }
    $reply = new LineOwnerAiReplyService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            return ['status' => 200, 'body' => '{"reply":"ASK_LOCATION"}'];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), $reply);
    [$status] = postReceptionReset($router, [
        'lineUserId' => $owner,
        'confirmed' => true,
    ]);
    assertSame(200, $status);

    postWebhook($router, textEvent('EV-RESET-LIMIT', 'MSG-RESET-LIMIT', $owner, '人工芝について相談です'));
    assertSame(2, count($calls), '本人限定テストのGatewayとLINE返信が再開していない');
    assertSame('collecting', $states->load($owner, $sessionId)['status']);
    assertSame(1, $states->load($owner, $sessionId)['questionsAsked']);
    assertTrue(str_contains(readLog($store), 'I_AI_RECEPTION_TEST_RESTART'));
});

// ── 会話途中のAI受付解除 ────────────────────────────────
group('会話途中のAI受付解除');

test('合言葉・本人完全一致・明示確認が無ければAI受付を解除できない', function (): void {
    $store = freshStore();
    $owner = 'UOWNER00000000000000000000000000';
    $sessionId = 'owner-manual-handoff-auth';
    $config = testConfig([
        'ai_reply_allowed_user_id' => $owner,
        'ai_reply_session_id' => $sessionId,
        'ai_reception_max_questions' => 5,
    ]);
    $states = new LineReceptionStateService($store, 5);
    assertTrue($states->save($owner, $sessionId, $states->load($owner, $sessionId)));
    $router = new LineRouter($config, $store, new FakeLineProfile());

    [$missingToken] = postReceptionHandoff($router, ['lineUserId' => $owner, 'confirmed' => true], '');
    [$wrongOwner] = postReceptionHandoff($router, ['lineUserId' => 'UOTHER00000000000000000000000000', 'confirmed' => true]);
    [$notConfirmed] = postReceptionHandoff($router, ['lineUserId' => $owner, 'confirmed' => false]);

    assertSame(401, $missingToken);
    assertSame(403, $wrongOwner);
    assertSame(400, $notConfirmed);
    assertSame('collecting', $states->load($owner, $sessionId)['status']);
});

test('確認後はその本人の会話だけ人対応に切り替え、その後は自動返信しない', function (): void {
    $store = freshStore();
    $calls = [];
    $owner = 'UOWNER00000000000000000000000000';
    $sessionId = 'owner-manual-handoff';
    $config = testConfig([
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => true,
        'ai_reply_allowed_user_id' => $owner,
        'ai_reply_session_id' => $sessionId,
        'ai_gateway_base_url' => 'https://gateway.example/v1',
        'ai_gateway_token' => str_repeat('g', 64),
        'channel_access_token' => str_repeat('l', 64),
        'ai_reception_max_questions' => 5,
        'ai_reply_daily_limit' => 12,
    ]);
    $reply = new LineOwnerAiReplyService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            return str_contains($url, '/ask')
                ? ['status' => 200, 'body' => '{"reply":"ASK_LOCATION"}']
                : ['status' => 200, 'body' => '{}'];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), $reply);

    postWebhook($router, textEvent('EV-TAKEOVER-1', 'MSG-TAKEOVER-1', $owner, '人工芝について相談です'));
    assertSame(2, count($calls));

    [$status, $payload] = postReceptionHandoff($router, [
        'lineUserId' => $owner,
        'confirmed' => true,
    ]);
    assertSame(200, $status);
    assertSame(true, $payload['handoff']);

    $states = new LineReceptionStateService($store, 5);
    assertSame('handoff', $states->load($owner, $sessionId)['status']);
    assertSame('MANUAL_TAKEOVER', $states->load($owner, $sessionId)['reasonCode']);

    postWebhook($router, textEvent('EV-TAKEOVER-2', 'MSG-TAKEOVER-2', $owner, '愛知県刈谷市です', 1756000001000));
    assertSame(2, count($calls), '解除後にもGatewayまたはLINEへ自動返信している');
    [, $inbox] = getInbox($router);
    assertSame(true, $inbox['items'][1]['needsHuman']);
    assertSame('MANUAL_TAKEOVER', $inbox['items'][1]['reasonCode']);
});

// ── アプリの受付AIスイッチ ──────────────────────────────
group('アプリの受付AIスイッチ');

test('受付AIは設定がそろっていても初期状態OFF', function (): void {
    $store = freshStore();
    $owner = 'UOWNER00000000000000000000000000';
    $config = testConfig([
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => true,
        'ai_reply_runtime_control_required' => true,
        'ai_reply_allowed_user_id' => $owner,
        'ai_reply_session_id' => 'app-switch-session',
        'ai_gateway_base_url' => 'https://gateway.example/v1',
        'ai_gateway_token' => str_repeat('g', 64),
        'channel_access_token' => str_repeat('l', 64),
        'ai_reception_max_questions' => 3,
        'ai_reply_daily_limit' => 4,
    ]);
    $router = new LineRouter($config, $store, new FakeLineProfile());
    [$status, $payload] = getReceptionMode($router);
    assertSame(200, $status);
    assertSame(true, $payload['available']);
    assertSame(false, $payload['enabled']);
    assertSame(true, $payload['testOnly']);
});

test('明示確認してON、OFFは即時に反映しsession変更でOFFへ戻る', function (): void {
    $store = freshStore();
    $owner = 'UOWNER00000000000000000000000000';
    $base = [
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => true,
        'ai_reply_runtime_control_required' => true,
        'ai_reply_allowed_user_id' => $owner,
        'ai_reply_session_id' => 'app-switch-current',
        'ai_gateway_base_url' => 'https://gateway.example/v1',
        'ai_gateway_token' => str_repeat('g', 64),
        'channel_access_token' => str_repeat('l', 64),
        'ai_reception_max_questions' => 3,
        'ai_reply_daily_limit' => 4,
    ];
    $router = new LineRouter(testConfig($base), $store, new FakeLineProfile());
    [$notConfirmed] = postReceptionMode($router, ['enabled' => true, 'confirmed' => false]);
    [$onStatus, $on] = postReceptionMode($router, ['enabled' => true, 'confirmed' => true]);
    assertSame(400, $notConfirmed);
    assertSame(200, $onStatus);
    assertSame(true, $on['enabled']);

    $changed = new LineRouter(
        testConfig(['ai_reply_session_id' => 'app-switch-new'] + $base),
        $store,
        new FakeLineProfile(),
    );
    assertSame(false, getReceptionMode($changed)[1]['enabled']);

    [$offStatus, $off] = postReceptionMode($router, ['enabled' => false, 'confirmed' => true]);
    assertSame(200, $offStatus);
    assertSame(false, $off['enabled']);
});

test('アプリ側OFFでは許可本人から届いてもAI外部通信しない', function (): void {
    $store = freshStore();
    $calls = [];
    $owner = 'UOWNER00000000000000000000000000';
    $config = testConfig([
        'ai_reply_enabled' => true,
        'ai_reply_test_mode' => true,
        'ai_reply_runtime_control_required' => true,
        'ai_reply_allowed_user_id' => $owner,
        'ai_reply_session_id' => 'app-switch-webhook',
        'ai_gateway_base_url' => 'https://gateway.example/v1',
        'ai_gateway_token' => str_repeat('g', 64),
        'channel_access_token' => str_repeat('l', 64),
        'ai_reception_max_questions' => 3,
        'ai_reply_daily_limit' => 4,
    ]);
    $reply = new LineOwnerAiReplyService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            return ['status' => 200, 'body' => '{"reply":"ASK_LOCATION"}'];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), $reply);
    postWebhook($router, textEvent('EV-SWITCH-OFF', 'MSG-SWITCH-OFF', $owner, '人工芝について相談です'));
    assertSame(0, count($calls));

    postReceptionMode($router, ['enabled' => true, 'confirmed' => true]);
    postWebhook($router, textEvent('EV-SWITCH-ON', 'MSG-SWITCH-ON', $owner, '人工芝について相談です'));
    assertSame(2, count($calls), 'ON後にGatewayとLINE reply APIへつながっていない');
});

// ── アプリで確認した後の本人限定送信 ────────────────────
group('確認後の本人限定LINE送信');

test('合言葉が無ければ送信入口を使えない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    [$status] = $router->handle(
        'POST',
        '/send',
        '{}',
        [],
        '203.0.113.10',
    );
    assertSame(401, $status);
});

test('初期状態では手動送信も止まっている', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    [$status] = postSend($router, [
        'confirmed' => true,
        'lineUserId' => 'UOWNER00000000000000000000000000',
        'text' => 'テスト返信',
        'requestId' => 'request_manual_off_001',
    ]);
    assertSame(503, $status);
    assertSame(0, count($store->keys('requests')));
});

test('安全スイッチはPHPの真偽値trueだけをONとして扱う', function (): void {
    assertSame(true, testConfig(['manual_send_enabled' => true])->bool('manual_send_enabled'));
    assertSame(false, testConfig(['manual_send_enabled' => false])->bool('manual_send_enabled'));
    assertSame(false, testConfig(['manual_send_enabled' => 'true'])->bool('manual_send_enabled'));
    assertSame(false, testConfig(['manual_send_enabled' => 'false'])->bool('manual_send_enabled'));
    assertSame(false, testConfig(['manual_send_enabled' => 1])->bool('manual_send_enabled'));
    assertSame(false, testConfig(['manual_send_enabled' => 0])->bool('manual_send_enabled'));
});

test('TEST_MODEがOFFなら手動送信しない', function (): void {
    $store = freshStore();
    $owner = 'UOWNER00000000000000000000000000';
    $calls = [];
    $config = testConfig([
        'manual_send_enabled' => true,
        'manual_send_test_mode' => false,
        'manual_send_allowed_user_id' => $owner,
        'channel_access_token' => str_repeat('l', 64),
    ]);
    $service = new LineManualSendService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            return ['status' => 200, 'body' => '{}'];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), null, $service);
    [$status] = postSend($router, [
        'confirmed' => true,
        'lineUserId' => $owner,
        'text' => 'テスト返信',
        'requestId' => 'request_manual_notest_001',
    ]);
    assertSame(403, $status);
    assertSame(0, count($calls));
});

test('confirmedが真でなければ送信しない', function (): void {
    $store = freshStore();
    $owner = 'UOWNER00000000000000000000000000';
    $calls = [];
    $config = testConfig([
        'manual_send_enabled' => true,
        'manual_send_test_mode' => true,
        'manual_send_allowed_user_id' => $owner,
        'channel_access_token' => str_repeat('l', 64),
    ]);
    $service = new LineManualSendService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            return ['status' => 200, 'body' => '{}'];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), null, $service);
    [$missingStatus] = postSend($router, [
        'lineUserId' => $owner,
        'text' => '未確認',
        'requestId' => 'request_manual_unconfirmed_001',
    ]);
    [$stringStatus] = postSend($router, [
        'confirmed' => 'true',
        'lineUserId' => $owner,
        'text' => '文字列は不可',
        'requestId' => 'request_manual_unconfirmed_002',
    ]);
    assertSame(400, $missingStatus);
    assertSame(400, $stringStatus);
    assertSame(0, count($calls));
});

test('許可した本人以外には送信しない', function (): void {
    $store = freshStore();
    $owner = 'UOWNER00000000000000000000000000';
    $calls = [];
    $config = testConfig([
        'manual_send_enabled' => true,
        'manual_send_test_mode' => true,
        'manual_send_allowed_user_id' => $owner,
        'channel_access_token' => str_repeat('l', 64),
    ]);
    $service = new LineManualSendService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            return ['status' => 200, 'body' => '{}'];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), null, $service);
    [$status] = postSend($router, [
        'confirmed' => true,
        'lineUserId' => 'UCUSTOMER000000000000000000000000',
        'text' => '誤送信してはいけない文面',
        'requestId' => 'request_manual_other_001',
    ]);
    assertSame(403, $status);
    assertSame(0, count($calls));
});

test('確認済みの本人向け文字を1件だけpush送信する', function (): void {
    $store = freshStore();
    $owner = 'UOWNER00000000000000000000000000';
    $calls = [];
    $config = testConfig([
        'manual_send_enabled' => true,
        'manual_send_test_mode' => true,
        'manual_send_allowed_user_id' => $owner,
        'channel_access_token' => str_repeat('l', 64),
    ]);
    $service = new LineManualSendService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            return ['status' => 200, 'body' => '{}'];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), null, $service);
    [$status, $payload] = postSend($router, [
        'confirmed' => true,
        'lineUserId' => $owner,
        'text' => '承知しました。よろしくお願いいたします。',
        'requestId' => 'request_manual_success_001',
    ]);

    assertSame(200, $status);
    assertSame(true, $payload['sent'] ?? null);
    assertSame(false, $payload['duplicate'] ?? null);
    assertSame(false, $payload['uncertain'] ?? null);
    assertSame(1, count($calls));
    assertSame('https://api.line.me/v2/bot/message/push', $calls[0]['url']);
    assertSame($owner, $calls[0]['body']['to'] ?? null);
    assertSame('text', $calls[0]['body']['messages'][0]['type'] ?? null);
    assertSame('承知しました。よろしくお願いいたします。', $calls[0]['body']['messages'][0]['text'] ?? null);
    assertSame(1, count($calls[0]['body']['messages'] ?? []));

    $requestRecords = $store->keys('requests');
    assertSame(1, count($requestRecords));
    $saved = json_encode($store->get('requests', $requestRecords[0]), JSON_UNESCAPED_UNICODE) ?: '';
    assertTrue(!str_contains($saved, $owner), '送信先を送信記録へ残している');
    assertTrue(!str_contains($saved, '承知しました'), '本文を送信記録へ残している');
});

test('同じrequestIdを再送してもLINEへは1回しか送らない', function (): void {
    $store = freshStore();
    $owner = 'UOWNER00000000000000000000000000';
    $calls = [];
    $config = testConfig([
        'manual_send_enabled' => true,
        'manual_send_test_mode' => true,
        'manual_send_allowed_user_id' => $owner,
        'channel_access_token' => str_repeat('l', 64),
    ]);
    $service = new LineManualSendService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            return ['status' => 200, 'body' => '{}'];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), null, $service);
    $body = [
        'confirmed' => true,
        'lineUserId' => $owner,
        'text' => '二重送信しない',
        'requestId' => 'request_manual_duplicate_001',
    ];
    [$firstStatus, $first] = postSend($router, $body);
    [$secondStatus, $second] = postSend($router, $body);
    assertSame(200, $firstStatus);
    assertSame(true, $first['sent'] ?? null);
    assertSame(200, $secondStatus);
    assertSame(true, $second['duplicate'] ?? null);
    assertSame(true, $second['sent'] ?? null);
    assertSame(false, $second['uncertain'] ?? null);
    assertSame(1, count($calls));
});

test('LINEが失敗を返した場合は同じrequestIdで再試行できる', function (): void {
    $store = freshStore();
    $owner = 'UOWNER00000000000000000000000000';
    $calls = [];
    $config = testConfig([
        'manual_send_enabled' => true,
        'manual_send_test_mode' => true,
        'manual_send_allowed_user_id' => $owner,
        'channel_access_token' => str_repeat('l', 64),
    ]);
    $service = new LineManualSendService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'timeout');
            return count($calls) === 1
                ? ['status' => 503, 'body' => '{}']
                : ['status' => 200, 'body' => '{}'];
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), null, $service);
    $body = [
        'confirmed' => true,
        'lineUserId' => $owner,
        'text' => '失敗後の再試行',
        'requestId' => 'request_manual_retry_001',
    ];
    [$failedStatus] = postSend($router, $body);
    [$retryStatus, $retry] = postSend($router, $body);
    assertSame(502, $failedStatus);
    assertSame(200, $retryStatus);
    assertSame(true, $retry['sent'] ?? null);
    assertSame(2, count($calls));
});

test('通信結果が不明な場合は同じrequestIdの二重送信を止める', function (): void {
    $store = freshStore();
    $owner = 'UOWNER00000000000000000000000000';
    $calls = 0;
    $config = testConfig([
        'manual_send_enabled' => true,
        'manual_send_test_mode' => true,
        'manual_send_allowed_user_id' => $owner,
        'channel_access_token' => str_repeat('l', 64),
    ]);
    $service = new LineManualSendService(
        $config,
        $store,
        function (string $url, array $headers, array $body, int $timeout) use (&$calls): array {
            $calls++;
            throw new RuntimeException('通信が途中で切れた想定');
        },
    );
    $router = new LineRouter($config, $store, new FakeLineProfile(), null, $service);
    $body = [
        'confirmed' => true,
        'lineUserId' => $owner,
        'text' => '結果不明時の二重送信防止',
        'requestId' => 'request_manual_uncertain_001',
    ];
    [$firstStatus] = postSend($router, $body);
    [$secondStatus, $second] = postSend($router, $body);
    assertSame(502, $firstStatus);
    assertSame(200, $secondStatus);
    assertSame(true, $second['duplicate'] ?? null);
    assertSame(false, $second['sent'] ?? null);
    assertSame(true, $second['uncertain'] ?? null);
    assertSame(1, $calls, '結果不明なのに同じ要求を再送した');
});

// ── 合言葉の受け取り ────────────────────────────────────
group('合言葉の見出しを受け取れること');

test('そのまま渡ってきた合言葉を読む', function (): void {
    $headers = LineHeaders::from(['HTTP_AUTHORIZATION' => 'Bearer abc']);
    assertSame('Bearer abc', $headers['authorization'] ?? '');
});

test('内部で転送された場合（REDIRECT_付き）も読む', function (): void {
    // CGIとして動くサーバーでは、この名前で渡ってくることがある。
    // ここを読み落とすと、正しい合言葉でも401になる。
    $headers = LineHeaders::from(['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer abc']);
    assertSame('Bearer abc', $headers['authorization'] ?? '');
});

test('Apacheが持っている見出しからも読む', function (): void {
    $headers = LineHeaders::from([], ['Authorization' => 'Bearer abc']);
    assertSame('Bearer abc', $headers['authorization'] ?? '');
});

test('そのまま渡ってきたものを、転送後のもので上書きしない', function (): void {
    $headers = LineHeaders::from([
        'HTTP_AUTHORIZATION' => 'Bearer 本物',
        'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer 古い',
    ]);
    assertSame('Bearer 本物', $headers['authorization'] ?? '');
});

test('ほかの見出しもこれまでどおり読める', function (): void {
    $headers = LineHeaders::from([
        'HTTP_X_LINE_SIGNATURE' => 'sig',
        'HTTP_CONTENT_TYPE' => 'application/json',
        'REQUEST_METHOD' => 'POST',
    ]);
    assertSame('sig', $headers['x-line-signature'] ?? '');
    assertSame('application/json', $headers['content-type'] ?? '');
    assertTrue(!isset($headers['request-method']), '見出し以外まで拾っている');
});

test('読み取った合言葉で、受信箱を読める', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    // CGIのサーバーから来た形（REDIRECT_付き）をそのまま通す。
    $headers = LineHeaders::from([
        'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . INBOX_TOKEN,
    ]);
    [$status] = $router->handle('GET', '/inbox', '', $headers, '203.0.113.10');
    assertSame(200, $status, 'CGIのサーバーで受信箱を読めない');
});

// ── 記録に何を書くか ────────────────────────────────────
group('記録');

test('記録には決まったコードと件数しか書かない', function (): void {
    $store = freshStore();
    $router = routerWith($store, ['U18181818181818181818181818181818' => 'にわ好きたろう']);
    $secretText = '雑草がひどいです。電話は0564-00-0000、住所は岡崎市○○町です';

    // ふつうに受け取る／署名を間違える／壊れた内容を送る、を一通り行う。
    postWebhook($router, textEvent('EV-LOG', 'MSG-LOG', 'U18181818181818181818181818181818', $secretText));
    postWebhook($router, textEvent('EV-LOG2', 'MSG-LOG2', 'U18181818181818181818181818181818', $secretText), 'ちがう署名');
    postWebhook($router, '{壊れている');
    getInbox($router, 'ちがう合言葉');

    $log = readLog($store);
    assertTrue($log !== '', '記録が1行も無い');
    foreach ([
        $secretText,
        '0564-00-0000',
        'U18181818181818181818181818181818',
        'にわ好きたろう',
        SECRET,
        INBOX_TOKEN,
        'EV-LOG',
        'MSG-LOG',
        sys_get_temp_dir(),
        '/api-line/',
        '.php',
    ] as $forbidden) {
        assertTrue(
            !str_contains($log, $forbidden),
            '記録に「' . mb_substr($forbidden, 0, 20) . '」が混ざっている'
        );
    }
    // 中身は「日時・コード・件数」だけ。
    foreach (explode("\n", trim($log)) as $line) {
        assertTrue(
            (bool) preg_match('/^[0-9T:+\-]+\t[A-Z0-9_]+\t\d+$/', $line),
            '決まった形になっていない行がある: ' . $line
        );
    }
});

// ── 結果 ────────────────────────────────────────────────
echo "\n";
echo sprintf("成功 %d / 失敗 %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
