<?php

declare(strict_types=1);

namespace Relagarden\Line;

/**
 * 本人限定テストのときだけ、よし管理AI Gatewayで返信文を作ってLINEへ返す。
 *
 * AI ProviderのAPIキーは持たない。Gateway用トークンとLINEチャネル
 * アクセストークンは、public_html外の設定ファイルからだけ受け取る。
 * プッシュ送信は使わず、届いたイベントのreplyTokenへ1回だけ返信する。
 */
final class LineOwnerAiReplyService
{
    /** LINEの文字メッセージ上限より少し余裕を持たせる。 */
    private const maxReplyLength = 4800;

    /** Gateway側の上限と合わせる。 */
    private const maxPromptLength = 2000;

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
     * 返信対象でなければ何もしない。失敗しても受信自体は成功させ、
     * iPhone受信箱から従来どおり手動返信できる状態を残す。
     */
    public function replyIfAllowed(
        string $lineUserId,
        string $messageText,
        string $replyToken,
    ): void {
        if (!$this->config->bool('ai_reply_enabled')) {
            return;
        }

        // 現段階では本人限定テスト以外の動作を実装しない。
        if (!$this->config->bool('ai_reply_test_mode')) {
            $this->store->log('E_AI_TEST_MODE_OFF', 1);
            return;
        }

        $allowedUserId = $this->config->str('ai_reply_allowed_user_id');
        if ($allowedUserId === '' || !hash_equals($allowedUserId, $lineUserId)) {
            return;
        }

        $gatewayBase = rtrim($this->config->str('ai_gateway_base_url'), '/');
        $gatewayToken = $this->config->str('ai_gateway_token');
        $lineToken = $this->config->str('channel_access_token');
        $dailyLimit = $this->config->int('ai_reply_daily_limit');
        if (!$this->isHttpsUrl($gatewayBase)
            || strlen($gatewayToken) < 32
            || strlen($lineToken) < 16
            || $dailyLimit < 1
            || $replyToken === ''
            || strlen($replyToken) > 512
        ) {
            $this->store->log('E_AI_CONFIG', 1);
            return;
        }

        try {
            $limiter = new LineRateLimiter($this->store, 86400);
            $limiter->hit(
                'ai_owner_' . LineStore::hashKey($lineUserId),
                $dailyLimit,
                '本人限定テストの上限に達しました'
            );
        } catch (\Throwable $e) {
            $this->store->log('E_AI_LOCAL_LIMIT', 1);
            return;
        }

        try {
            $gateway = ($this->postJson)(
                $gatewayBase . '/ask',
                [
                    'Authorization' => 'Bearer ' . $gatewayToken,
                    'Content-Type' => 'application/json',
                ],
                [
                    'prompt' => mb_substr($messageText, 0, self::maxPromptLength),
                    'feature' => 'line_reply',
                ],
                $this->positiveTimeout('ai_gateway_timeout_seconds', 8),
            );
        } catch (\Throwable $e) {
            $this->store->log('E_AI_GATEWAY', 1);
            return;
        }

        if ($gateway['status'] !== 200) {
            $this->store->log('E_AI_GATEWAY', 1);
            return;
        }
        $gatewayBody = json_decode($gateway['body'], true);
        $reply = is_array($gatewayBody) && is_string($gatewayBody['reply'] ?? null)
            ? trim($gatewayBody['reply'])
            : '';
        if ($reply === '') {
            $this->store->log('E_AI_RESPONSE', 1);
            return;
        }

        try {
            $line = ($this->postJson)(
                'https://api.line.me/v2/bot/message/reply',
                [
                    'Authorization' => 'Bearer ' . $lineToken,
                    'Content-Type' => 'application/json',
                ],
                [
                    'replyToken' => $replyToken,
                    'messages' => [[
                        'type' => 'text',
                        'text' => mb_substr($reply, 0, self::maxReplyLength),
                    ]],
                ],
                $this->positiveTimeout('line_reply_timeout_seconds', 5),
            );
        } catch (\Throwable $e) {
            $this->store->log('E_AI_LINE_REPLY', 1);
            return;
        }

        if ($line['status'] < 200 || $line['status'] >= 300) {
            $this->store->log('E_AI_LINE_REPLY', 1);
            return;
        }
        $this->store->log('I_AI_OWNER_REPLIED', 1);
    }

    private function positiveTimeout(string $key, int $fallback): int
    {
        $value = $this->config->int($key);
        return $value > 0 && $value <= 30 ? $value : $fallback;
    }

    private function isHttpsUrl(string $url): bool
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
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
            // PHP 8.3以前では、file_get_contentsがローカルスコープへ
            // http_response_headerを追加する。変数を直接参照すると新しいPHPで
            // 非推奨警告になるため、旧版だけget_defined_vars経由で読む。
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
