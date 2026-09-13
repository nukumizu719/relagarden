<?php

declare(strict_types=1);

namespace Relagarden\Api;

/** 通信しないOAuthテスト用。 */
final class FakeInstagramOAuthClient implements InstagramOAuthClient
{
    public int $exchangeCalls = 0;
    public int $refreshCalls = 0;

    public function exchangeCode(string $appId, string $appSecret, string $redirectUri, string $code): array
    {
        $this->exchangeCalls++;
        return ['accessToken' => 'IG_SHORT_TEST_TOKEN_1234567890', 'userId' => '123456789'];
    }

    public function exchangeLongLived(string $appSecret, string $shortToken): array
    {
        return ['accessToken' => 'IG_LONG_TEST_TOKEN_1234567890', 'expiresIn' => 5184000];
    }

    public function refreshLongLived(string $accessToken): array
    {
        $this->refreshCalls++;
        return ['accessToken' => 'IG_REFRESHED_TEST_TOKEN_1234567890', 'expiresIn' => 5184000];
    }

    public function profile(string $apiVersion, string $userId, string $accessToken): array
    {
        return ['userId' => $userId, 'username' => 'relagarden_test'];
    }
}
