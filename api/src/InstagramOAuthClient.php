<?php

declare(strict_types=1);

namespace Relagarden\Api;

/** MetaとのOAuth通信だけを差し替え可能にする取り決め。 */
interface InstagramOAuthClient
{
    /** @return array{accessToken:string,userId:string} */
    public function exchangeCode(string $appId, string $appSecret, string $redirectUri, string $code): array;

    /** @return array{accessToken:string,expiresIn:int} */
    public function exchangeLongLived(string $appSecret, string $shortToken): array;

    /** @return array{accessToken:string,expiresIn:int} */
    public function refreshLongLived(string $accessToken): array;

    /** @return array{userId:string,username:string} */
    public function profile(string $apiVersion, string $userId, string $accessToken): array;
}
