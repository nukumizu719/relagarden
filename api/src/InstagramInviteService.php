<?php

declare(strict_types=1);

namespace Relagarden\Api;

/** 一度だけ使えるQR招待を作り、端末を独立した利用者領域へ結び付ける。 */
final class InstagramInviteService
{
    private const INVITES = 'iginvites';
    private const TENANTS = 'igtenants';
    private const TTL_SECONDS = 86400;

    public function __construct(
        private readonly Storage $storage,
        private readonly Auth $auth,
    ) {
    }

    /** @return array{inviteUri:string,workspaceName:string,expiresAt:string} */
    public function create(string $workspaceName, string $createdByDeviceId): array
    {
        $workspaceName = trim($workspaceName);
        if ($workspaceName === '' || mb_strlen($workspaceName) > 40) {
            throw new ApiError(400, '利用者名は40文字以内で入れてください');
        }

        $tenantId = 'tenant-' . bin2hex(random_bytes(8));
        $expiresAt = time() + self::TTL_SECONDS;
        $rawToken = bin2hex(random_bytes(32));
        $inviteKey = hash('sha256', $rawToken);

        $this->storage->put(self::TENANTS, $tenantId, [
            'name' => $workspaceName,
            'createdAt' => time(),
            'createdByDeviceIdHash' => substr(hash('sha256', $createdByDeviceId), 0, 16),
        ]);
        $this->storage->put(self::INVITES, $inviteKey, [
            'tenantId' => $tenantId,
            'workspaceName' => $workspaceName,
            'expiresAt' => $expiresAt,
            'used' => false,
        ]);
        $this->storage->log('instagram invite: created tenant=' . $tenantId);

        return [
            'inviteUri' => 'relagarden://instagram-invite?token=' . $rawToken,
            'workspaceName' => $workspaceName,
            'expiresAt' => gmdate('c', $expiresAt),
        ];
    }

    /**
     * QRを使わず、この端末自身の独立した利用先を用意する。
     * 通信が不明なまま再試行されても、既に移動済みなら同じ利用先を返す。
     *
     * @return array{workspaceName:string,canManageInstagram:bool}
     */
    public function createForCurrentDevice(string $workspaceName, string $deviceId): array
    {
        $currentTenantId = $this->auth->tenantId($deviceId);
        if ($currentTenantId !== 'legacy' || $this->auth->isAdmin($deviceId)) {
            return [
                'workspaceName' => $this->workspaceName($currentTenantId),
                'canManageInstagram' => $this->auth->isAdmin($deviceId),
            ];
        }

        $workspaceName = trim($workspaceName);
        if ($workspaceName === '' || mb_strlen($workspaceName) > 40) {
            throw new ApiError(400, '利用者名は40文字以内で入れてください');
        }
        $tenantId = 'tenant-' . bin2hex(random_bytes(8));
        $this->storage->put(self::TENANTS, $tenantId, [
            'name' => $workspaceName,
            'createdAt' => time(),
            'createdByDeviceIdHash' => substr(hash('sha256', $deviceId), 0, 16),
        ]);
        $this->auth->moveToTenantAsAdmin($deviceId, $tenantId);
        $this->storage->log('instagram workspace: created for current device tenant=' . $tenantId);

        return [
            'workspaceName' => $workspaceName,
            'canManageInstagram' => true,
        ];
    }

    /** @return array{token:string,deviceId:string,role:string,tenantId:string,workspaceName:string} */
    public function claim(string $rawToken, string $deviceName): array
    {
        $rawToken = trim($rawToken);
        if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
            throw new ApiError(400, 'この招待QRを読み取れません');
        }
        $key = hash('sha256', $rawToken);
        $invite = $this->storage->get(self::INVITES, $key);
        if ($invite === null || ($invite['used'] ?? true) === true) {
            throw new ApiError(409, 'この招待QRは使用済みです');
        }
        if ((int) ($invite['expiresAt'] ?? 0) < time()) {
            throw new ApiError(410, 'この招待QRの有効時間が切れています');
        }
        $tenantId = is_string($invite['tenantId'] ?? null)
            ? Storage::safeKey($invite['tenantId'])
            : '';
        if ($tenantId === '' || $this->storage->get(self::TENANTS, $tenantId) === null) {
            throw new ApiError(409, 'この招待QRの利用先を確認できません');
        }

        // 先に使用済みにして、同時に二台から使われても一台だけにする。
        $consumed = $this->storage->consumeUnused(self::INVITES, $key);
        if ($consumed === null) {
            throw new ApiError(409, 'この招待QRは使用済みです');
        }
        $invite = $consumed;

        $issued = $this->auth->issueDevice($deviceName, 'admin', $tenantId);
        $this->storage->log('instagram invite: claimed tenant=' . $tenantId);
        return $issued + [
            'workspaceName' => (string) ($invite['workspaceName'] ?? ''),
        ];
    }

    public function workspaceName(string $tenantId): string
    {
        if ($tenantId === 'legacy') {
            return 'これまでのリラガーデン';
        }
        $record = $this->storage->get(self::TENANTS, $tenantId);
        return is_string($record['name'] ?? null) ? $record['name'] : '';
    }
}
