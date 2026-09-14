<?php

declare(strict_types=1);

namespace Relagarden\Api;

/** OAuthのstateと長期トークンをpublic_html外で管理する。 */
final class InstagramOAuthService
{
    private const STATE_BUCKET = 'igoauth';
    private const CONNECTION_BUCKET = 'igconnection';
    private const LEGACY_CONNECTION = 'active';

    public function __construct(
        private readonly Config $config,
        private readonly Storage $storage,
        private readonly InstagramOAuthClient $client,
    ) {
    }

    /** @return array{authorizationUrl:string,expiresAt:string} */
    public function start(string $deviceId, string $tenantId = 'legacy'): array
    {
        $this->requireSettings();
        $state = bin2hex(random_bytes(32));
        $ttl = max(300, min(1800, $this->config->int('instagram_oauth_state_ttl_seconds')));
        $expires = time() + $ttl;
        $this->storage->put(self::STATE_BUCKET, $state, [
            'deviceId' => $deviceId,
            'tenantId' => $this->connectionKey($tenantId),
            'expiresAt' => $expires,
            'used' => false,
        ]);
        $url = 'https://www.instagram.com/oauth/authorize?' . http_build_query([
            'client_id' => $this->config->str('instagram_app_id'),
            'redirect_uri' => $this->config->str('instagram_redirect_uri'),
            'response_type' => 'code',
            'scope' => 'instagram_business_basic,instagram_business_content_publish',
            'state' => $state,
        ]);
        return ['authorizationUrl' => $url, 'expiresAt' => gmdate('c', $expires)];
    }

    /** @return array{connected:bool,accountName:string,expiresAt:string} */
    public function complete(string $state, string $code): array
    {
        $this->requireSettings();
        if (!preg_match('/^[a-f0-9]{64}$/', $state) || $code === '') {
            throw new ApiError(400, 'Instagram連携の確認情報が正しくありません');
        }
        $saved = $this->storage->get(self::STATE_BUCKET, $state);
        if ($saved === null || ($saved['used'] ?? true) === true || (int) ($saved['expiresAt'] ?? 0) < time()) {
            throw new ApiError(400, 'Instagram連携の有効時間が切れています。最初からやり直してください');
        }
        $saved['used'] = true;
        $this->storage->put(self::STATE_BUCKET, $state, $saved);
        $short = $this->client->exchangeCode(
            $this->config->str('instagram_app_id'),
            $this->config->str('instagram_app_secret'),
            $this->config->str('instagram_redirect_uri'),
            $code,
        );
        $long = $this->client->exchangeLongLived($this->config->str('instagram_app_secret'), $short['accessToken']);
        $profile = $this->client->profile(
            $this->config->str('instagram_graph_api_version'),
            $short['userId'],
            $long['accessToken'],
        );
        if ($profile['userId'] !== $short['userId']) {
            throw new ApiError(502, 'Instagramの投稿先を確認できませんでした');
        }
        $expires = time() + $long['expiresIn'];
        $tenantId = is_string($saved['tenantId'] ?? null)
            ? $this->connectionKey($saved['tenantId'])
            : self::LEGACY_CONNECTION;
        $this->storage->put(self::CONNECTION_BUCKET, $tenantId, [
            'accessToken' => $long['accessToken'],
            'userId' => $profile['userId'],
            'username' => $profile['username'],
            'connectedByDeviceId' => (string) ($saved['deviceId'] ?? ''),
            'connectedAt' => time(),
            'expiresAt' => $expires,
        ]);
        $this->storage->delete(self::STATE_BUCKET, $state);
        $this->storage->log('instagram oauth: connected account=' . substr(hash('sha256', $profile['userId']), 0, 12));
        return ['connected' => true, 'accountName' => $profile['username'], 'expiresAt' => gmdate('c', $expires)];
    }

    /** @return array{configured:bool,connected:bool,accountName:string,expiresAt:string} */
    public function status(string $tenantId = 'legacy'): array
    {
        $record = self::activeConnection($this->storage, $tenantId);
        $expires = (int) ($record['expiresAt'] ?? 0);
        return [
            'configured' => $this->settingsReady(),
            'connected' => $record !== null && $expires > time(),
            'accountName' => $record !== null ? (string) ($record['username'] ?? '') : '',
            'expiresAt' => $expires > 0 ? gmdate('c', $expires) : '',
        ];
    }

    /** @return array{connected:bool,accountName:string,expiresAt:string} */
    public function refresh(string $deviceId, string $tenantId = 'legacy'): array
    {
        $record = self::activeConnection($this->storage, $tenantId);
        if ($record === null || (string) ($record['accessToken'] ?? '') === '') {
            throw new ApiError(409, 'Instagramはまだ連携されていません');
        }
        $renewed = $this->client->refreshLongLived((string) $record['accessToken']);
        $record['accessToken'] = $renewed['accessToken'];
        $record['expiresAt'] = time() + $renewed['expiresIn'];
        $record['refreshedAt'] = time();
        $record['refreshedByDeviceId'] = $deviceId;
        $this->storage->put(self::CONNECTION_BUCKET, $this->connectionKey($tenantId), $record);
        return [
            'connected' => true,
            'accountName' => (string) ($record['username'] ?? ''),
            'expiresAt' => gmdate('c', (int) $record['expiresAt']),
        ];
    }

    public function disconnect(string $deviceId, string $tenantId = 'legacy'): void
    {
        $this->storage->delete(self::CONNECTION_BUCKET, $this->connectionKey($tenantId));
        $this->storage->log('instagram oauth: disconnected by device=' . substr(hash('sha256', $deviceId), 0, 12));
    }

    /** @return array<string,mixed>|null */
    public static function activeConnection(Storage $storage, string $tenantId = 'legacy'): ?array
    {
        $key = $tenantId === 'legacy' ? self::LEGACY_CONNECTION : Storage::safeKey($tenantId);
        $record = $storage->get(self::CONNECTION_BUCKET, $key);
        if ($record === null || (int) ($record['expiresAt'] ?? 0) <= time()) {
            return null;
        }
        return $record;
    }

    private function connectionKey(string $tenantId): string
    {
        $key = $tenantId === 'legacy' ? self::LEGACY_CONNECTION : Storage::safeKey($tenantId);
        if ($key === '') {
            throw new ApiError(400, 'Instagramの利用先を確認できません');
        }
        return $key;
    }

    private function settingsReady(): bool
    {
        foreach (['instagram_app_id', 'instagram_app_secret', 'instagram_redirect_uri', 'instagram_graph_api_version'] as $key) {
            if ($this->config->str($key) === '') {
                return false;
            }
        }
        return true;
    }

    private function requireSettings(): void
    {
        if (!$this->settingsReady()) {
            throw new ApiError(503, 'Instagram連携はまだ設定されていません');
        }
    }
}
