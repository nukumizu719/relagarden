<?php

declare(strict_types=1);

namespace Relagarden\Line;

/** アプリから切り替える、本人限定受付AIの追加安全スイッチ。 */
final class LineReceptionControlService
{
    private const namespace = 'controls';
    private const key = 'owner_reception_ai';

    public function __construct(
        private readonly LineConfig $config,
        private readonly LineStore $store,
    ) {
    }

    /** @return array{available:bool,enabled:bool,testOnly:bool} */
    public function status(): array
    {
        $available = $this->isConfigured();
        if (!$this->config->bool('ai_reply_runtime_control_required')) {
            return ['available' => $available, 'enabled' => $available, 'testOnly' => true];
        }

        $record = $this->store->get(self::namespace, self::key);
        $sessionHash = LineStore::hashKey($this->config->str('ai_reply_session_id'));
        $enabled = $available
            && is_array($record)
            && ($record['enabled'] ?? null) === true
            && is_string($record['sessionHash'] ?? null)
            && hash_equals($sessionHash, (string) $record['sessionHash']);
        return ['available' => $available, 'enabled' => $enabled, 'testOnly' => true];
    }

    /** @return array{available:bool,enabled:bool,testOnly:bool} */
    public function setEnabled(bool $enabled): array
    {
        if (!$this->config->bool('ai_reply_runtime_control_required')) {
            throw new LineError(503, '受付AIの切り替えはまだ準備中です', 'E_RECEPTION_CONTROL_DISABLED');
        }
        if ($enabled && !$this->isConfigured()) {
            throw new LineError(503, '受付AIはまだ準備中です', 'E_RECEPTION_CONFIG');
        }
        if (!$this->store->put(self::namespace, self::key, [
            'enabled' => $enabled,
            'sessionHash' => LineStore::hashKey($this->config->str('ai_reply_session_id')),
            'updatedAt' => time(),
        ])) {
            throw new LineError(500, '受付AIの設定を保存できません', 'E_RECEPTION_CONTROL_WRITE');
        }
        return $this->status();
    }

    public function isEnabled(): bool
    {
        return $this->status()['enabled'];
    }

    private function isConfigured(): bool
    {
        $allowed = $this->config->str('ai_reply_allowed_user_id');
        $sessionId = $this->config->str('ai_reply_session_id');
        $gateway = $this->config->str('ai_gateway_base_url');
        $maxQuestions = $this->config->int('ai_reception_max_questions');
        $dailyLimit = $this->config->int('ai_reply_daily_limit');
        return $this->config->bool('ai_reply_enabled')
            && $this->config->bool('ai_reply_test_mode')
            && $allowed !== ''
            && strlen($allowed) <= 64
            && $sessionId !== ''
            && strlen($sessionId) <= 128
            && filter_var($gateway, FILTER_VALIDATE_URL) !== false
            && str_starts_with(strtolower($gateway), 'https://')
            && strlen($this->config->str('ai_gateway_token')) >= 32
            && strlen($this->config->str('channel_access_token')) >= 16
            && $maxQuestions >= 1
            && $maxQuestions <= 5
            && $dailyLimit >= $maxQuestions + 1
            && $dailyLimit <= 20;
    }
}
