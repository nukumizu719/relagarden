<?php

declare(strict_types=1);

namespace Relagarden\Api;

/**
 * Instagram実験用の入口だけをまとめる。
 *
 * 既存の [Router] は施工事例の掲載を扱う。そちらへ手を入れずに済むよう、
 * `/instagram/...` はここへ渡す。**掲載・LINEの処理は一切含まない。**
 *
 * 認証・回数制限は既存の [Auth] と [RateLimiter] をそのまま使う。
 */
final class InstagramRouter
{
    public function __construct(
        private readonly Config $config,
        private readonly Storage $storage,
        private readonly ?InstagramClient $client,
        private readonly ?InstagramOAuthClient $oauthClient = null,
    ) {
    }

    /** この入口が扱う道か。 */
    public static function handles(string $route): bool
    {
        return $route === '/instagram' || str_starts_with($route, '/instagram/');
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,string> $query
     * @return array{0:int,1:array<string,mixed>}
     */
    public function handle(
        string $method,
        string $route,
        string $rawBody,
        array $headers,
        string $clientIp,
        array $query,
    ): array {
        $auth = new Auth($this->config, $this->storage);
        $limiter = new RateLimiter($this->storage, $this->config->int('rate_window_seconds'));
        $tail = substr($route, strlen('/instagram'));
        $tail = '/' . trim($tail, '/');

        if ($tail === '/workspaces/self') {
            $this->requireMethod($method, 'POST');
            $deviceId = $auth->requireDevice($headers['authorization'] ?? null);
            $limiter->hit(
                'igself_' . $deviceId,
                3,
                '連携操作が続いています。しばらく時間をおいてください'
            );
            $body = $this->json($rawBody);
            $name = is_string($body['workspaceName'] ?? null) ? $body['workspaceName'] : '';
            $workspaces = new InstagramInviteService($this->storage, $auth);
            return [200, ['ok' => true] + $workspaces->createForCurrentDevice($name, $deviceId)];
        }

        if ($tail === '/invites') {
            $this->requireMethod($method, 'POST');
            $deviceId = $auth->requireDevice($headers['authorization'] ?? null);
            $limiter->hit(
                'iginvite_' . $deviceId,
                5,
                '招待の作成が続いています。しばらく時間をおいてください'
            );
            $body = $this->json($rawBody);
            $name = is_string($body['workspaceName'] ?? null) ? $body['workspaceName'] : '';
            $invites = new InstagramInviteService($this->storage, $auth);
            return [200, ['ok' => true] + $invites->create($name, $deviceId)];
        }

        if ($tail === '/invites/claim') {
            $this->requireMethod($method, 'POST');
            $limiter->hit(
                'igclaim_' . $clientIp,
                $this->config->int('rate_max_pairings'),
                'しばらく時間をおいてからお試しください'
            );
            $body = $this->json($rawBody);
            $token = is_string($body['inviteToken'] ?? null) ? $body['inviteToken'] : '';
            $deviceName = is_string($body['deviceName'] ?? null) ? $body['deviceName'] : 'iPhone';
            $invites = new InstagramInviteService($this->storage, $auth);
            $result = $invites->claim($token, $deviceName);
            return [200, [
                'ok' => true,
                'token' => $result['deviceId'] . '.' . $result['token'],
                'role' => $result['role'],
                'workspaceName' => $result['workspaceName'],
            ]];
        }

        if ($tail === '/oauth/callback') {
            $this->requireMethod($method, 'GET');
            $result = $this->oauthService()->complete($query['state'] ?? '', $query['code'] ?? '');
            return [200, ['ok' => true, 'message' => 'Instagram連携が完了しました。アプリへ戻ってください。'] + $result];
        }

        if ($tail === '/oauth/start') {
            $this->requireMethod($method, 'POST');
            $deviceId = $auth->requireAdmin($headers['authorization'] ?? null);
            $tenantId = $auth->tenantId($deviceId);
            $limiter->hit(
                'igoauth_' . $deviceId,
                $this->config->int('rate_max_instagram_oauth_starts'),
                '連携操作が続いています。しばらく時間をおいてください'
            );
            return [200, ['ok' => true] + $this->oauthService()->start($deviceId, $tenantId)];
        }

        if ($tail === '/oauth/refresh') {
            $this->requireMethod($method, 'POST');
            $deviceId = $auth->requireAdmin($headers['authorization'] ?? null);
            $tenantId = $auth->tenantId($deviceId);
            return [200, ['ok' => true] + $this->oauthService()->refresh($deviceId, $tenantId)];
        }

        if ($tail === '/disconnect') {
            $this->requireMethod($method, 'POST');
            $deviceId = $auth->requireAdmin($headers['authorization'] ?? null);
            $tenantId = $auth->tenantId($deviceId);
            $this->oauthService()->disconnect($deviceId, $tenantId);
            return [200, ['ok' => true, 'connected' => false]];
        }

        if ($tail === '/account') {
            $this->requireMethod($method, 'GET');
            $deviceId = $auth->requireDevice($headers['authorization'] ?? null);
            $tenantId = $auth->tenantId($deviceId);
            $workspace = (new InstagramInviteService($this->storage, $auth))
                ->workspaceName($tenantId);
            if ($this->oauthClient !== null) {
                return [200, [
                    'ok' => true,
                    'workspaceName' => $workspace,
                    'canManageInstagram' => $auth->isAdmin($deviceId),
                ] + $this->oauthService()->status($tenantId)];
            }
            $scopedConfig = $this->configForTenant($tenantId);
            $ready = $this->client !== null && InstagramService::isConfigured($scopedConfig);
            return [200, [
                'ok' => true,
                'configured' => $ready,
                'connected' => $ready,
                'accountName' => $ready ? $this->service($tenantId)->accountName() : '',
                'expiresAt' => '',
                'workspaceName' => $workspace,
                'canManageInstagram' => $auth->isAdmin($deviceId),
            ]];
        }

        // OAuthも投稿クライアントも無い従来構成では、以前と同じく
        // 認証より先に「準備中」を返す。既存アプリの案内を変えない。
        if ($this->client === null && $this->oauthClient === null) {
            throw new ApiError(503, 'Instagramはまだ連携されていません');
        }

        if ($tail === '/prepare') {
            $this->requireMethod($method, 'POST');
            $deviceId = $auth->requireDevice($headers['authorization'] ?? null);
            $tenantId = $auth->tenantId($deviceId);
            $service = $this->service($tenantId);
            $scopedConfig = $this->configForTenant($tenantId);
            if (strlen($rawBody) > $this->config->int('instagram_max_request_bytes')) {
                throw new ApiError(413, '写真が大きすぎます');
            }
            // 端末ごとの制限。
            $limiter->hit(
                'igprep_' . $deviceId,
                $this->config->int('rate_max_instagram_prepares'),
                'しばらく時間をおいてからお試しください'
            );
            // **投稿先アカウントごとの制限。** 端末を替えても上限を超えられない。
            $limiter->hit(
                InstagramService::accountRateKey($scopedConfig) . '_prep',
                $this->config->int('rate_max_instagram_account_prepares'),
                'このアカウントへの準備が続いています。しばらく時間をおいてください'
            );
            return [200, ['ok' => true] + $service->prepare($this->json($rawBody), $deviceId)];
        }

        if ($tail === '/status') {
            $this->requireMethod($method, 'GET');
            $deviceId = $auth->requireDevice($headers['authorization'] ?? null);
            $service = $this->service($auth->tenantId($deviceId));
            $limiter->hit(
                'igstat_' . $deviceId,
                $this->config->int('rate_max_instagram_status'),
                'しばらく時間をおいてからお試しください'
            );
            $draftId = $query['draftId'] ?? '';
            return [200, ['ok' => true] + $service->status($draftId, $deviceId)];
        }

        if ($tail === '/publish') {
            $this->requireMethod($method, 'POST');
            $deviceId = $auth->requireDevice($headers['authorization'] ?? null);
            $tenantId = $auth->tenantId($deviceId);
            $service = $this->service($tenantId);
            $scopedConfig = $this->configForTenant($tenantId);
            // 端末ごとの制限。
            $limiter->hit(
                'igpub_' . $deviceId,
                $this->config->int('rate_max_instagram_publishes'),
                'しばらく時間をおいてからお試しください'
            );
            // **投稿先アカウントごとの制限。** 端末を替えても上限を超えられない。
            $limiter->hit(
                InstagramService::accountRateKey($scopedConfig) . '_pub',
                $this->config->int('rate_max_instagram_account_publishes'),
                'このアカウントへの投稿が続いています。しばらく時間をおいてください'
            );
            return [200, ['ok' => true] + $service->publish($this->json($rawBody), $deviceId)];
        }

        if ($tail === '/discard') {
            $this->requireMethod($method, 'POST');
            $deviceId = $auth->requireDevice($headers['authorization'] ?? null);
            $service = $this->service($auth->tenantId($deviceId));
            return [200, ['ok' => true] + $service->discard($this->json($rawBody), $deviceId)];
        }

        return [404, ['ok' => false, 'message' => '入口が見つかりません']];
    }

    private function service(string $tenantId): InstagramService
    {
        $config = $this->configForTenant($tenantId);
        $client = $this->client;
        if ($client === null && InstagramService::isConfigured($config)
            && $config->str('instagram_access_token') !== '') {
            $client = new CurlInstagramClient(
                $config->str('instagram_access_token'),
                $config->str('instagram_user_id'),
                $config->str('instagram_graph_api_version'),
                $this->storage,
            );
        }
        if ($client === null) {
            throw new ApiError(503, 'Instagramはまだ連携されていません');
        }
        return new InstagramService($config, $this->storage, $client);
    }

    private function configForTenant(string $tenantId): Config
    {
        $connection = InstagramOAuthService::activeConnection($this->storage, $tenantId);
        if ($connection === null) {
            // 既存環境の手入力設定は従来領域だけで使い、別利用者へ漏らさない。
            return $tenantId === 'legacy'
                ? $this->config
                : $this->config->with([
                    'instagram_access_token' => '',
                    'instagram_user_id' => '',
                    'instagram_account_name' => '',
                ]);
        }
        return $this->config->with([
            'instagram_access_token' => (string) ($connection['accessToken'] ?? ''),
            'instagram_user_id' => (string) ($connection['userId'] ?? ''),
            'instagram_account_name' => (string) ($connection['username'] ?? ''),
        ]);
    }

    private function oauthService(): InstagramOAuthService
    {
        if ($this->oauthClient === null) {
            throw new ApiError(503, 'Instagram連携はまだ設定されていません');
        }
        return new InstagramOAuthService($this->config, $this->storage, $this->oauthClient);
    }

    private function requireMethod(string $actual, string $expected): void
    {
        if (strtoupper($actual) !== $expected) {
            throw new ApiError(405, '受け付けられない要求です');
        }
    }

    /** @return array<string,mixed> */
    private function json(string $raw): array
    {
        if ($raw === '') {
            throw new ApiError(400, '内容が空です');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new ApiError(400, '内容を読み取れません');
        }
        return $data;
    }
}
