<?php

declare(strict_types=1);

namespace Relagarden\Line;

/**
 * LINE受信と、明示的に有効化した本人限定AI返信テストの受け口。
 *
 * ここには施工事例の掲載（/publish /status /unpublish）は無い。
 * 掲載はiPhoneからGitHubへ直接行う方式のままで、こちらは触らない。
 *
 * | メソッド | 入口              | 用途                                   |
 * | POST     | /api/line/webhook | LINEからの配信を受ける（署名を確認）    |
 * | GET      | /api/line/inbox   | まだ取り込んでいない問い合わせを渡す    |
 * | POST     | /api/line/sync    | 取り込めたものへ受け取り済みの印を付ける |
 * | POST     | /api/line/reception/reset | 本人限定受付の停止状態を解除する   |
 * | POST     | /api/line/reception/handoff | 本人限定受付を人対応へ切り替える |
 * | GET/POST | /api/line/reception/mode  | 本人限定受付AIを確認・切り替える   |
 * | POST     | /api/line/send    | 確認済みの本人向け文字返信を1件送る       |
 */
final class LineRouter
{
    public function __construct(
        private readonly LineConfig $config,
        private readonly LineStore $store,
        private readonly LineProfile $profile,
        private readonly ?LineOwnerAiReplyService $ownerAiReply = null,
        private readonly ?LineManualSendService $manualSend = null,
    ) {
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,string> $query
     * @return array{0:int,1:array<string,mixed>}
     */
    public function handle(
        string $method,
        string $path,
        string $rawBody,
        array $headers,
        string $clientIp,
        array $query = [],
    ): array {
        try {
            $route = '/' . trim($path, '/');

            if ($route === '/webhook') {
                $this->requireMethod($method, 'POST');
                // 上限は十分に大きく取る。正規の配信を落とすと問い合わせが消えるため、
                // ここで断るのは明らかに異常な量のときだけ。断っても LINE が再送する。
                $limiter = new LineRateLimiter($this->store, $this->config->int('rate_window_seconds'));
                $limiter->hit(
                    'hook_' . $clientIp,
                    $this->config->int('rate_max_webhook'),
                    'ただいま受け取れません'
                );
                $ownerAiReply = $this->ownerAiReply
                    ?? new LineOwnerAiReplyService($this->config, $this->store);
                $service = new LineWebhookService(
                    $this->config,
                    $this->store,
                    $this->profile,
                    $ownerAiReply,
                );
                return [200, $service->receive($rawBody, $headers)];
            }

            if ($route === '/inbox') {
                $this->requireMethod($method, 'GET');
                $this->requireInboxToken($headers, $clientIp);
                $limit = isset($query['limit']) ? (int) $query['limit'] : 0;
                $service = new LineInboxService($this->config, $this->store);
                return [200, ['ok' => true] + $service->items($limit)];
            }

            if ($route === '/sync') {
                $this->requireMethod($method, 'POST');
                $this->requireInboxToken($headers, $clientIp);
                if (strlen($rawBody) > $this->config->int('max_sync_bytes')) {
                    throw new LineError(413, '内容が大きすぎます', 'E_SYNC_BODY_TOO_BIG');
                }
                $body = $this->json($rawBody);
                $ids = $this->validIds($body['ids'] ?? null);
                $service = new LineInboxService($this->config, $this->store);
                $result = $service->ack($ids);
                if (($result['failed'] ?? 0) > 0) {
                    // 印を付けられていないのに「済んだ」と答えない。
                    // アプリは取り込み済み・確認待ちとして持ち越し、次に付け直す。
                    $this->store->log('E_ACK_WRITE', (int) $result['failed']);
                    return [500, ['ok' => false, 'message' => 'ただいま記録できません']];
                }
                return [200, ['ok' => true] + $result];
            }

            if ($route === '/send') {
                $this->requireMethod($method, 'POST');
                $this->requireInboxToken($headers, $clientIp);
                if (strlen($rawBody) > $this->config->int('max_send_body_bytes')) {
                    throw new LineError(413, '内容が大きすぎます', 'E_SEND_BODY_TOO_BIG');
                }
                $body = $this->json($rawBody);
                $service = $this->manualSend
                    ?? new LineManualSendService($this->config, $this->store);
                return [200, ['ok' => true] + $service->send($body)];
            }

            if ($route === '/reception/reset') {
                $this->requireMethod($method, 'POST');
                $this->requireInboxToken($headers, $clientIp);
                if (strlen($rawBody) > $this->config->int('max_sync_bytes')) {
                    throw new LineError(413, '内容が大きすぎます', 'E_RESET_BODY_TOO_BIG');
                }
                $body = $this->json($rawBody);
                if (($body['confirmed'] ?? null) !== true) {
                    throw new LineError(400, '停止解除の確認が必要です', 'E_RESET_NOT_CONFIRMED');
                }
                $lineUserId = is_string($body['lineUserId'] ?? null)
                    ? trim($body['lineUserId'])
                    : '';
                $allowed = $this->config->str('ai_reply_allowed_user_id');
                $sessionId = $this->config->str('ai_reply_session_id');
                if ($lineUserId === '' || $allowed === '' || !hash_equals($allowed, $lineUserId)) {
                    throw new LineError(403, 'この受付状態は解除できません', 'E_RESET_TARGET');
                }
                if ($sessionId === '' || strlen($sessionId) > 128) {
                    throw new LineError(503, 'AI受付はまだ準備中です', 'E_RESET_CONFIG');
                }
                $lockKey = 'reception_' . LineStore::hashKey($lineUserId . "\0" . $sessionId);
                $reset = $this->store->synchronized(
                    $lockKey,
                    fn (): bool => (new LineReceptionStateService(
                        $this->store,
                        $this->config->int('ai_reception_max_questions'),
                    ))->reset($lineUserId, $sessionId)
                );
                if (!$reset) {
                    throw new LineError(500, '停止状態を解除できません', 'E_RESET_WRITE');
                }
                return [200, ['ok' => true, 'reset' => true]];
            }

            if ($route === '/reception/handoff') {
                $this->requireMethod($method, 'POST');
                $this->requireInboxToken($headers, $clientIp);
                if (strlen($rawBody) > $this->config->int('max_sync_bytes')) {
                    throw new LineError(413, '内容が大きすぎます', 'E_HANDOFF_BODY_TOO_BIG');
                }
                $body = $this->json($rawBody);
                if (($body['confirmed'] ?? null) !== true) {
                    throw new LineError(400, 'AI受付を解除する確認が必要です', 'E_HANDOFF_NOT_CONFIRMED');
                }
                $lineUserId = is_string($body['lineUserId'] ?? null)
                    ? trim($body['lineUserId'])
                    : '';
                $allowed = $this->config->str('ai_reply_allowed_user_id');
                $sessionId = $this->config->str('ai_reply_session_id');
                if ($lineUserId === '' || $allowed === '' || !hash_equals($allowed, $lineUserId)) {
                    throw new LineError(403, 'この受付は解除できません', 'E_HANDOFF_TARGET');
                }
                if ($sessionId === '' || strlen($sessionId) > 128) {
                    throw new LineError(503, 'AI受付はまだ準備中です', 'E_HANDOFF_CONFIG');
                }
                $lockKey = 'reception_' . LineStore::hashKey($lineUserId . "\0" . $sessionId);
                $handoff = $this->store->synchronized(
                    $lockKey,
                    function () use ($lineUserId, $sessionId): bool {
                        $states = new LineReceptionStateService(
                            $this->store,
                            $this->config->int('ai_reception_max_questions'),
                        );
                        $state = $states->handoff(
                            $states->load($lineUserId, $sessionId),
                            'MANUAL_TAKEOVER',
                        );
                        return $states->save($lineUserId, $sessionId, $state);
                    }
                );
                if (!$handoff) {
                    throw new LineError(500, 'AI受付を解除できません', 'E_HANDOFF_WRITE');
                }
                return [200, ['ok' => true, 'handoff' => true]];
            }

            if ($route === '/reception/mode') {
                $this->requireInboxToken($headers, $clientIp);
                $service = new LineReceptionControlService($this->config, $this->store);
                if (strtoupper($method) === 'GET') {
                    return [200, ['ok' => true] + $service->status()];
                }
                $this->requireMethod($method, 'POST');
                if (strlen($rawBody) > $this->config->int('max_sync_bytes')) {
                    throw new LineError(413, '内容が大きすぎます', 'E_RECEPTION_MODE_BODY_TOO_BIG');
                }
                $body = $this->json($rawBody);
                if (!is_bool($body['enabled'] ?? null)) {
                    throw new LineError(400, '受付AIの設定を確認してください', 'E_RECEPTION_MODE_VALUE');
                }
                if ($body['enabled'] === true && ($body['confirmed'] ?? null) !== true) {
                    throw new LineError(400, '受付AIをONにする確認が必要です', 'E_RECEPTION_MODE_CONFIRM');
                }
                return [200, ['ok' => true] + $service->setEnabled($body['enabled'])];
            }

            return [404, ['ok' => false, 'message' => '入口が見つかりません']];
        } catch (LineError $e) {
            if ($e->detailForLog !== null) {
                $this->store->log($e->detailForLog);
            }
            return [$e->status, ['ok' => false, 'message' => $e->getMessage()]];
        } catch (\Throwable $e) {
            // 内部の事情は返さない。記録にも文面を残さない
            // （例外の文面にはファイルの場所や値が入ることがあるため）。
            $this->store->log('E_UNEXPECTED', 1);
            return [500, ['ok' => false, 'message' => 'ただいま処理できません。時間をおいてお試しください']];
        }
    }

    /**
     * 取り込み済みとして送られてきた番号を確かめる。
     *
     * 数も長さも決めておく。決めておかないと、極端な数や長い文字列で
     * 保存を探し回らせることができてしまう。
     *
     * @return list<string>
     */
    private function validIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new LineError(400, '内容を読み取れません', 'E_SYNC_IDS_SHAPE');
        }
        if (count($raw) > $this->config->int('max_sync_ids')) {
            throw new LineError(413, '一度に送れる件数を超えています', 'E_SYNC_IDS_COUNT');
        }
        $maxLength = $this->config->int('max_id_length');
        $ids = [];
        foreach ($raw as $id) {
            if (!is_string($id) || $id === '') {
                continue;
            }
            if (strlen($id) > $maxLength) {
                throw new LineError(400, '受け付けられない要求です', 'E_SYNC_ID_LENGTH');
            }
            $ids[] = $id;
        }
        return $ids;
    }

    private function requireMethod(string $actual, string $expected): void
    {
        if (strtoupper($actual) !== $expected) {
            throw new LineError(405, '受け付けられない要求です');
        }
    }

    /**
     * 受信箱の合言葉を確かめる。
     *
     * この合言葉はiPhoneのKeychainにだけ入れる。掲載用のPATとは別物。
     *
     * @param array<string,string> $headers
     */
    private function requireInboxToken(array $headers, string $clientIp): void
    {
        $limiter = new LineRateLimiter($this->store, $this->config->int('rate_window_seconds'));
        $limiter->hit('inbox_' . $clientIp, $this->config->int('rate_max_inbox'));

        $header = trim((string) ($headers['authorization'] ?? ''));
        if (!preg_match('/^Bearer\s+(\S+)$/', $header, $m)) {
            throw new LineError(401, 'LINE受信の設定が済んでいません');
        }
        if (!hash_equals($this->config->str('inbox_token'), $m[1])) {
            throw new LineError(401, 'LINE受信の設定が済んでいません', 'E_TOKEN');
        }
    }

    /** @return array<string,mixed> */
    private function json(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new LineError(400, '内容を読み取れません');
        }
        return $data;
    }
}
