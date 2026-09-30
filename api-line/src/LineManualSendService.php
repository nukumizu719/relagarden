<?php

declare(strict_types=1);

namespace Relagarden\Line;

/**
 * iPhoneアプリで宛先と文面を確認した後だけ、本人へ文字を1件送る。
 *
 * AI文章作成はここでは行わない。受け取るのは完成済みの文面だけ。
 * 自動返信、一斉送信、複数宛先、画像送信は実装しない。
 */
final class LineManualSendService
{
    /**
     * @var \Closure(string,array<string,string>,array<string,mixed>,int):array{status:int,body:string}
     */
    private readonly \Closure $postJson;

    /**
     * @param null|callable(string,array<string,string>,array<string,mixed>,int):array{status:int,body:string} $postJson
     */
    public function __construct(
        private readonly LineConfig $config,
        private readonly LineStore $store,
        ?callable $postJson = null,
    ) {
        $this->postJson = $postJson !== null
            ? \Closure::fromCallable($postJson)
            : \Closure::fromCallable([$this, 'sendJson']);
    }

    /**
     * @param array<string,mixed> $body
     * @return array{sent:bool,duplicate:bool,uncertain:bool}
     */
    public function send(array $body): array
    {
        if (!$this->config->bool('manual_send_enabled')) {
            throw new LineError(503, 'LINE送信はまだ準備中です', 'E_SEND_DISABLED');
        }
        if (!$this->config->bool('manual_send_test_mode')) {
            throw new LineError(403, 'LINE送信は安全設定により停止しています', 'E_SEND_TEST_MODE_OFF');
        }
        // trueというJSONの真偽値だけを受ける。文字列 "true" は認めない。
        if (($body['confirmed'] ?? null) !== true) {
            throw new LineError(400, '送信前の確認が必要です', 'E_SEND_NOT_CONFIRMED');
        }

        $lineUserId = is_string($body['lineUserId'] ?? null)
            ? trim($body['lineUserId'])
            : '';
        $text = is_string($body['text'] ?? null)
            ? trim($body['text'])
            : '';
        $requestId = is_string($body['requestId'] ?? null)
            ? trim($body['requestId'])
            : '';

        $allowedUserId = $this->config->str('manual_send_allowed_user_id');
        if ($lineUserId === '' || $allowedUserId === '' || !hash_equals($allowedUserId, $lineUserId)) {
            throw new LineError(403, 'この送信先には送れません', 'E_SEND_TARGET');
        }
        $maxTextLength = $this->positiveInt('max_send_text_length', 2000, 5000);
        if ($text === '' || mb_strlen($text) > $maxTextLength) {
            throw new LineError(400, '返信文を確認してください', 'E_SEND_TEXT');
        }
        $maxRequestIdLength = $this->positiveInt('max_send_request_id_length', 128, 128);
        if ($requestId === ''
            || strlen($requestId) > $maxRequestIdLength
            || preg_match('/^[A-Za-z0-9_-]+$/', $requestId) !== 1
        ) {
            throw new LineError(400, '送信要求を確認できません', 'E_SEND_REQUEST_ID');
        }

        $lineToken = $this->config->str('channel_access_token');
        $dailyLimit = $this->config->int('manual_send_daily_limit');
        if (strlen($lineToken) < 16 || $dailyLimit < 1) {
            throw new LineError(503, 'LINE送信はまだ準備中です', 'E_SEND_CONFIG');
        }

        // 本文やuserIdは保存しない。requestIdもSHA-256の印だけを残す。
        $requestKey = LineStore::hashKey($requestId);
        if ($this->store->exists('requests', $requestKey)) {
            return $this->duplicateResult($requestKey);
        }
        if (!$this->store->claim('requests', $requestKey, [
            'status' => 'sending',
            'createdAt' => time(),
        ])) {
            // 同時に来た同じrequestIdなら、もう片方が印を作っている。
            if ($this->store->exists('requests', $requestKey)) {
                return $this->duplicateResult($requestKey);
            }
            throw new LineError(500, 'ただいま送信を記録できません', 'E_SEND_CLAIM');
        }

        try {
            $limiter = new LineRateLimiter($this->store, 86400);
            $limiter->hit(
                'manual_send_' . LineStore::hashKey($lineUserId),
                $dailyLimit,
                '本人限定テストの送信上限に達しました'
            );
        } catch (LineError $e) {
            $this->store->delete('requests', $requestKey);
            throw $e;
        }

        try {
            $line = ($this->postJson)(
                'https://api.line.me/v2/bot/message/push',
                [
                    'Authorization' => 'Bearer ' . $lineToken,
                    'Content-Type' => 'application/json',
                ],
                [
                    'to' => $lineUserId,
                    'messages' => [[
                        'type' => 'text',
                        'text' => $text,
                    ]],
                ],
                $this->positiveInt('manual_send_timeout_seconds', 5, 30),
            );
        } catch (\Throwable $e) {
            // 通信が途中で切れた場合は、LINE側で送信済みか判断できない。
            // 印を残して同じrequestIdの再送を止め、公式アカウントでの確認を促す。
            $this->store->put('requests', $requestKey, [
                'status' => 'uncertain',
                'updatedAt' => time(),
            ]);
            $this->store->log('E_MANUAL_LINE_SEND', 1);
            throw new LineError(502, '送信結果を確認できません。LINE公式アカウントで確認してください');
        }
        if ($line['status'] < 200 || $line['status'] >= 300) {
            // LINEから明確な失敗応答を受けた場合だけ、同じrequestIdで再試行できる。
            $this->store->delete('requests', $requestKey);
            $this->store->log('E_MANUAL_LINE_SEND', 1);
            throw new LineError(502, '送信できませんでした。時間をおいてお試しください');
        }

        if (!$this->store->put('requests', $requestKey, [
            'status' => 'sent',
            'sentAt' => time(),
        ])) {
            // LINE送信は成功済みなので再送させない。claimのファイルは残っている。
            $this->store->log('E_SEND_RECEIPT_WRITE', 1);
        }
        $this->store->log('I_MANUAL_OWNER_SENT', 1);
        return ['sent' => true, 'duplicate' => false, 'uncertain' => false];
    }

    /** @return array{sent:bool,duplicate:bool,uncertain:bool} */
    private function duplicateResult(string $requestKey): array
    {
        $record = $this->store->get('requests', $requestKey);
        $status = is_array($record) ? ($record['status'] ?? null) : null;
        if ($status === 'sent') {
            return ['sent' => true, 'duplicate' => true, 'uncertain' => false];
        }
        // sending / uncertain / 読み取り失敗は「送信済み」にしない。
        return ['sent' => false, 'duplicate' => true, 'uncertain' => true];
    }

    private function positiveInt(string $key, int $fallback, int $maximum): int
    {
        $value = $this->config->int($key);
        return $value > 0 && $value <= $maximum ? $value : $fallback;
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed> $body
     * @return array{status:int,body:string}
     */
    private function sendJson(
        string $url,
        array $headers,
        array $body,
        int $timeoutSeconds,
    ): array {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('E_JSON_ENCODE');
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $json,
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if (function_exists('http_get_last_response_headers')) {
            $lastHeaders = http_get_last_response_headers();
            $responseHeaders = is_array($lastHeaders) ? $lastHeaders : [];
        } else {
            $scope = get_defined_vars();
            $legacyHeaders = $scope['http_response_header'] ?? [];
            $responseHeaders = is_array($legacyHeaders) ? $legacyHeaders : [];
        }
        $status = 0;
        if (isset($responseHeaders[0])
            && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $matches) === 1
        ) {
            $status = (int) $matches[1];
        }
        if ($response === false && $status === 0) {
            throw new \RuntimeException('E_HTTP');
        }
        return ['status' => $status, 'body' => is_string($response) ? $response : ''];
    }
}
