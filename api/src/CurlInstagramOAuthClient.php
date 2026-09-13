<?php

declare(strict_types=1);

namespace Relagarden\Api;

/** Instagram Loginの認可コードをXserver内だけでトークンへ交換する。 */
final class CurlInstagramOAuthClient implements InstagramOAuthClient
{
    public function __construct(
        private readonly Storage $storage,
        private readonly int $timeoutSeconds = 20,
    ) {
    }

    public function exchangeCode(string $appId, string $appSecret, string $redirectUri, string $code): array
    {
        $result = $this->request('POST', 'https://api.instagram.com/oauth/access_token', [
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);
        return [
            'accessToken' => $this->requiredString($result, 'access_token'),
            'userId' => $this->requiredString($result, 'user_id'),
        ];
    }

    public function exchangeLongLived(string $appSecret, string $shortToken): array
    {
        $url = 'https://graph.instagram.com/access_token?' . http_build_query([
            'grant_type' => 'ig_exchange_token',
            'client_secret' => $appSecret,
            'access_token' => $shortToken,
        ]);
        return $this->tokenResult($this->request('GET', $url));
    }

    public function refreshLongLived(string $accessToken): array
    {
        $url = 'https://graph.instagram.com/refresh_access_token?' . http_build_query([
            'grant_type' => 'ig_refresh_token',
            'access_token' => $accessToken,
        ]);
        return $this->tokenResult($this->request('GET', $url));
    }

    public function profile(string $apiVersion, string $userId, string $accessToken): array
    {
        $url = 'https://graph.instagram.com/' . rawurlencode($apiVersion) . '/'
            . rawurlencode($userId) . '?fields=user_id,username';
        $result = $this->request('GET', $url, [], $accessToken);
        return [
            'userId' => $this->requiredString($result, 'user_id'),
            'username' => $this->requiredString($result, 'username'),
        ];
    }

    /** @param array<string,string> $form @return array<string,mixed> */
    private function request(string $method, string $url, array $form = [], string $bearer = ''): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new ApiError(502, 'Instagramへつなげませんでした');
        }
        $headers = ['Accept: application/json'];
        if ($bearer !== '') {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($form));
        }
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if (!is_string($body) || $body === '') {
            throw new ApiError(502, 'Instagramから応答がありませんでした');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || $status >= 400) {
            $this->storage->log('instagram oauth: rejected status=' . $status);
            throw new ApiError(502, 'Instagram連携を完了できませんでした');
        }
        return $decoded;
    }

    /** @param array<string,mixed> $data */
    private function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        if ((!is_string($value) && !is_int($value)) || (string) $value === '') {
            throw new ApiError(502, 'Instagram連携の応答を確認できませんでした');
        }
        return (string) $value;
    }

    /** @param array<string,mixed> $data @return array{accessToken:string,expiresIn:int} */
    private function tokenResult(array $data): array
    {
        $expires = (int) ($data['expires_in'] ?? 0);
        if ($expires <= 0) {
            $expires = 60 * 24 * 3600;
        }
        return ['accessToken' => $this->requiredString($data, 'access_token'), 'expiresIn' => $expires];
    }
}
