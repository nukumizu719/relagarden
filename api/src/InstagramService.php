<?php

declare(strict_types=1);

namespace Relagarden\Api;

/**
 * Instagram投稿の準備と公開。
 *
 * **いちばん大事な決まり**
 *   - 準備（prepare）から公開処理を絶対に呼ばない
 *   - 公開（publish）は、条件をすべて満たしたときだけ1回だけ呼ぶ
 *   - 結果が分からないときは、成功にも失敗にもしない（確認中で止める）
 *
 * 保存はデータベースを使わず、既存の [Storage]（ファイル）を使う。
 * 画像そのものだけは公開領域の外の別フォルダーへ置く。
 */
final class InstagramService
{
    /** 下書きの記録 */
    private const DRAFTS = 'igdrafts';

    /** 一時URLの合札 → 下書きID */
    private const TICKETS = 'igtickets';

    /** 一時画像の置き場所（storage_dir の下） */
    private const MEDIA_SUBDIR = '/igmedia';

    /** 一時URLの有効期間の上限。これを超える設定は受け付けない。 */
    private const MAX_TTL_SECONDS = 24 * 3600;

    public function __construct(
        private readonly Config $config,
        private readonly Storage $storage,
        private readonly InstagramClient $client,
        /**
         * 写真の作り直しを差し替える口。**テスト専用。**
         * 本番では null のままGDを使う。
         * 作り直せなかったときは null を返す取り決め。
         *
         * @var null|callable(string):?string
         */
        private $reencoder = null,
    ) {
    }

    // ── 設定 ────────────────────────────────────────────────

    /**
     * Instagram連携が設定されているか。
     *
     * **APIのバージョンに既定値を置かない。** 未設定なら動かさない。
     * 推測した値で本番へつなぐと、Metaが上げたときに黙って壊れる。
     */
    public static function isConfigured(Config $config): bool
    {
        foreach (
            ['instagram_user_id', 'instagram_graph_api_version', 'instagram_account_name']
            as $key
        ) {
            if ($config->str($key) === '') {
                return false;
            }
        }
        return true;
    }

    private function requireConfigured(): void
    {
        if (!self::isConfigured($this->config)) {
            throw new ApiError(503, 'Instagram連携はまだ設定されていません');
        }
    }

    /**
     * アカウント単位で回数を数えるための鍵。
     *
     * **アカウントIDも名前もそのまま使わない。** ハッシュにしてから使う。
     * 記録へ出ても、どのアカウントか分からないようにするため。
     */
    public static function accountRateKey(Config $config): string
    {
        $seed = $config->str('instagram_user_id') . '|' . $config->str('instagram_account_name');
        return 'igacc_' . substr(hash('sha256', $seed), 0, 32);
    }

    /** アプリへ見せる投稿先の名前。**アプリ側に固定値を持たせない。** */
    public function accountName(): string
    {
        return $this->config->str('instagram_account_name');
    }

    private function ttlSeconds(): int
    {
        $ttl = $this->config->int('instagram_media_ttl_seconds');
        if ($ttl <= 0) {
            $ttl = 3600;
        }
        return min($ttl, self::MAX_TTL_SECONDS);
    }

    private function mediaDir(): string
    {
        $dir = rtrim($this->config->str('storage_dir'), '/') . self::MEDIA_SUBDIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }

    // ── 準備 ────────────────────────────────────────────────

    /**
     * 投稿の準備だけを行う。**ここから公開処理は絶対に呼ばない。**
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function prepare(array $body, string $deviceId): array
    {
        $this->requireConfigured();

        $requestId = self::requireId($body['requestId'] ?? null, '要求番号');

        // 同じ要求が二度来ても、下書きを二重に作らない。
        $existing = $this->findByRequestId($deviceId, $requestId);
        if ($existing !== null) {
            return $this->publicView($existing);
        }

        // 掲載許可が無ければ、ここから先へ進まない。
        Validator::consent($body['consent'] ?? null);

        $caption = Validator::requiredText($body['caption'] ?? null, '投稿文', 2200);
        $image = $this->readJpeg($body['image'] ?? null);

        $draftId = bin2hex(random_bytes(16));
        $ticket = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $now = time();
        $expiresAt = $now + $this->ttlSeconds();

        // 画像は公開領域の外へ置く。名前は乱数だけ。
        // 元のファイル名・案件名・住所・氏名はどこにも使わない。
        $mediaPath = $this->mediaDir() . '/' . $draftId . '.jpg';
        if (file_put_contents($mediaPath, $image['bytes'], LOCK_EX) === false) {
            throw new ApiError(500, '画像を用意できませんでした');
        }
        @chmod($mediaPath, 0600);

        $device = $this->storage->get('devices', $deviceId);
        $actorName = is_string($device['name'] ?? null) ? (string) $device['name'] : '';
        $draft = [
            'draftId' => $draftId,
            'deviceId' => $deviceId,
            'actorName' => $actorName,
            'requestId' => $requestId,
            'accountName' => $this->accountName(),
            'igUserId' => $this->config->str('instagram_user_id'),
            'caption' => $caption,
            'captionHash' => hash('sha256', $caption),
            'imageHash' => hash('sha256', $image['bytes']),
            'reencoded' => $image['reencoded'],
            'ticket' => $ticket,
            'containerId' => '',
            'state' => 'preparing',
            'createdAt' => $now,
            'expiresAt' => $expiresAt,
            'publishNonce' => $nonce,
            'nonceUsed' => false,
            'publishRequestId' => '',
            'mediaId' => '',
            'permalink' => '',
            'publishedAt' => 0,
            'note' => '',
        ];
        $this->storage->put(self::DRAFTS, $draftId, $draft);
        $this->storage->put(self::TICKETS, $ticket, [
            'draftId' => $draftId,
            'expiresAt' => $expiresAt,
        ]);
        // 同じ要求番号で二度来たときに、同じ下書きを返すための目印。
        // 合札（64桁）とは形が違うので、鍵がぶつかることはない。
        $this->storage->put(self::TICKETS, $this->requestKey($deviceId, $requestId), [
            'draftId' => $draftId,
            'expiresAt' => $expiresAt,
        ]);

        // ここでMetaが取りに来るURLを作り、入れ物だけを作る。
        $imageUrl = $this->mediaUrl($ticket);
        try {
            $containerId = $this->client->createMediaContainer($imageUrl, $caption);
        } catch (InstagramUnknownResult $e) {
            $draft['state'] = 'failed';
            $draft['note'] = 'unknown-on-container';
            $this->storage->put(self::DRAFTS, $draftId, $draft);
            $this->storage->log('instagram: container result unknown draft=' . $draftId);
            throw new ApiError(502, 'Instagramの下書きを作れたか確認できませんでした');
        } catch (ApiError $e) {
            $draft['state'] = 'failed';
            $draft['note'] = 'container-failed';
            $this->storage->put(self::DRAFTS, $draftId, $draft);
            throw $e;
        }

        $draft['containerId'] = $containerId;
        // **ここでは ready にしない。** 入れ物ができただけで、
        // Instagram側の処理はまだ終わっていない。
        // FINISHED を確かめてから ready にする（status の仕事）。
        $draft['state'] = 'processing';
        $this->storage->put(self::DRAFTS, $draftId, $draft);
        $this->storage->log('instagram: container created draft=' . $draftId);

        return $this->publicView($draft);
    }

    // ── 状態 ────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function status(string $draftId, string $deviceId): array
    {
        $this->requireConfigured();
        $draft = $this->requireDraft($draftId, $deviceId);

        // すでに終わっているものは、Instagramへ聞きに行かない。
        if (in_array($draft['state'], ['published', 'discarded', 'expired'], true)) {
            return $this->publicView($draft);
        }

        if ($this->isExpired($draft) && $draft['state'] !== 'published') {
            $draft['state'] = 'expired';
            $this->storage->put(self::DRAFTS, $draftId, $draft);
            return $this->publicView($draft);
        }

        if (in_array($draft['state'], ['processing', 'ready'], true)
            && is_string($draft['containerId']) && $draft['containerId'] !== '') {
            try {
                $raw = $this->client->containerStatus((string) $draft['containerId']);
            } catch (\Throwable $e) {
                // 見られなかった。**readyへは上げない。** 処理中のまま返す。
                return $this->publicView($draft);
            }

            if ($raw === 'FINISHED') {
                // ここではじめて「公開してよい状態」になる。
                if ($draft['state'] !== 'ready') {
                    $draft['state'] = 'ready';
                    $this->storage->put(self::DRAFTS, $draftId, $draft);
                }
            } elseif ($raw === 'ERROR') {
                $draft['state'] = 'failed';
                $draft['note'] = 'container-error';
                $this->storage->put(self::DRAFTS, $draftId, $draft);
            } elseif ($raw === 'EXPIRED') {
                $draft['state'] = 'expired';
                $this->storage->put(self::DRAFTS, $draftId, $draft);
            } else {
                // IN_PROGRESS、または見覚えのない値。**readyへは上げない。**
                if ($draft['state'] !== 'processing') {
                    $draft['state'] = 'processing';
                    $this->storage->put(self::DRAFTS, $draftId, $draft);
                }
            }
        }

        return $this->publicView($draft);
    }

    // ── 公開 ────────────────────────────────────────────────

    /**
     * **ここだけが実際にInstagramへ公開する。**
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function publish(array $body, string $deviceId): array
    {
        $this->requireConfigured();

        $draftId = self::requireId($body['draftId'] ?? null, '下書き番号');
        $publishRequestId = self::requireId($body['publishRequestId'] ?? null, '公開要求番号');
        $nonce = is_string($body['confirmNonce'] ?? null) ? (string) $body['confirmNonce'] : '';
        $imageHash = is_string($body['imageHash'] ?? null) ? (string) $body['imageHash'] : '';
        $captionHash = is_string($body['captionHash'] ?? null) ? (string) $body['captionHash'] : '';

        $draft = $this->requireDraft($draftId, $deviceId);

        // すでに公開済みなら、同じ結果を返すだけ。**もう一度は投稿しない。**
        if ($draft['state'] === 'published') {
            return $this->publicView($draft);
        }
        // 同じ公開要求の再送も、投稿し直さない。
        if (($draft['publishRequestId'] ?? '') === $publishRequestId && $publishRequestId !== '') {
            return $this->publicView($draft);
        }

        if ($draft['state'] !== 'ready') {
            throw new ApiError(409, 'まだ公開できる状態ではありません');
        }
        if ($this->isExpired($draft)) {
            $draft['state'] = 'expired';
            $this->storage->put(self::DRAFTS, $draftId, $draft);
            throw new ApiError(410, '準備の期限が切れました。もう一度やり直してください');
        }
        if (($draft['accountName'] ?? '') !== $this->accountName()
            || ($draft['igUserId'] ?? '') !== $this->config->str('instagram_user_id')) {
            throw new ApiError(409, '投稿先のアカウントが変わっています。もう一度やり直してください');
        }
        if (!hash_equals((string) ($draft['publishNonce'] ?? ''), $nonce)
            || ($draft['nonceUsed'] ?? false) === true) {
            throw new ApiError(409, '確認のやり直しが必要です');
        }
        if (!hash_equals((string) ($draft['imageHash'] ?? ''), $imageHash)
            || !hash_equals((string) ($draft['captionHash'] ?? ''), $captionHash)) {
            throw new ApiError(409, '確認した内容と違います。もう一度確認してください');
        }

        // 二重に走らせない。ここは先に取れた1本だけが通る。
        $lock = $this->acquireLock($draftId);
        if ($lock === null) {
            throw new ApiError(409, '公開の処理中です。そのままお待ちください');
        }

        try {
            // 鍵を取ったあとに、もう一度読み直す（待っている間に終わっているかもしれない）。
            $fresh = $this->storage->get(self::DRAFTS, $draftId);
            if (is_array($fresh) && ($fresh['state'] ?? '') === 'published') {
                return $this->publicView($fresh);
            }

            // **公開の直前に、もう一度Instagram側の状態を確かめる。**
            // ここが FINISHED でなければ、絶対に公開処理を呼ばない。
            try {
                $containerState = $this->client->containerStatus((string) $draft['containerId']);
            } catch (\Throwable $e) {
                $this->storage->log('instagram: could not confirm container draft=' . $draftId);
                throw new ApiError(409, '公開できる状態か確認できませんでした。もう一度お試しください');
            }

            if ($containerState === 'PUBLISHED') {
                // すでに載っている。**送り直さない。**
                $draft['state'] = 'publishing';
                $draft['nonceUsed'] = true;
                $draft['publishRequestId'] = $publishRequestId;
                $draft['note'] = 'already-published-on-instagram';
                $this->storage->put(self::DRAFTS, $draftId, $draft);
                return $this->publicView($draft);
            }
            if ($containerState !== 'FINISHED') {
                if ($containerState === 'ERROR') {
                    $draft['state'] = 'failed';
                    $draft['note'] = 'container-error';
                    $this->storage->put(self::DRAFTS, $draftId, $draft);
                    throw new ApiError(409, 'Instagram側で写真を用意できませんでした');
                }
                if ($containerState === 'EXPIRED') {
                    $draft['state'] = 'expired';
                    $this->storage->put(self::DRAFTS, $draftId, $draft);
                    throw new ApiError(410, '準備の期限が切れました。もう一度やり直してください');
                }
                // IN_PROGRESS、または見覚えのない値。
                $draft['state'] = 'processing';
                $this->storage->put(self::DRAFTS, $draftId, $draft);
                throw new ApiError(409, 'まだ準備が終わっていません。少し待ってからお試しください');
            }

            $draft['state'] = 'publishing';
            $draft['nonceUsed'] = true;
            $draft['publishRequestId'] = $publishRequestId;
            $this->storage->put(self::DRAFTS, $draftId, $draft);

            try {
                $mediaId = $this->client->publishMedia((string) $draft['containerId']);
            } catch (InstagramUnknownResult $e) {
                // **成功にしない。失敗にもしない。**
                $draft['note'] = 'unknown-on-publish';
                $this->storage->put(self::DRAFTS, $draftId, $draft);
                $this->storage->log('instagram: publish result unknown draft=' . $draftId);
                return $this->publicView($draft);
            } catch (ApiError $e) {
                $draft['state'] = 'failed';
                $draft['note'] = 'publish-failed';
                $this->storage->put(self::DRAFTS, $draftId, $draft);
                throw $e;
            }

            $draft['state'] = 'published';
            $draft['mediaId'] = $mediaId;
            $draft['publishedAt'] = time();
            $draft['publishedBy'] = (string) ($draft['actorName'] ?? '');
            $draft['permalink'] = $this->client->mediaPermalink($mediaId);
            $this->storage->put(self::DRAFTS, $draftId, $draft);
            $this->storage->log('instagram: published draft=' . $draftId);

            // 公開できたので、一時画像はもう要らない。
            $this->removeMedia($draft);

            return $this->publicView($draft);
        } finally {
            $this->releaseLock($lock);
        }
    }

    // ── 破棄 ────────────────────────────────────────────────

    /**
     * まだ公開していない下書きと一時画像を片づける。
     *
     * **Instagramに載った投稿を消す機能ではない。**
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function discard(array $body, string $deviceId): array
    {
        $this->requireConfigured();
        $draftId = self::requireId($body['draftId'] ?? null, '下書き番号');
        $draft = $this->requireDraft($draftId, $deviceId);

        if ($draft['state'] === 'published') {
            throw new ApiError(409, 'すでに公開されています。Instagram側で操作してください');
        }
        if ($draft['state'] === 'publishing') {
            throw new ApiError(409, '公開の結果を確認中です。しばらくお待ちください');
        }

        $this->removeMedia($draft);
        $draft['state'] = 'discarded';
        $draft['publishNonce'] = '';
        $this->storage->put(self::DRAFTS, $draftId, $draft);
        $this->storage->log('instagram: discarded draft=' . $draftId);

        return $this->publicView($draft);
    }

    // ── 一時画像の配信 ──────────────────────────────────────

    /**
     * Metaが取りに来る一時URL。
     *
     * 端末の認証は無い（Metaは合言葉を持っていない）。
     * 代わりに、推測できない合札と短い期限で守る。
     *
     * @return array{0:int,1:string,2:string} status, contentType, body
     */
    public function serveMedia(string $ticket): array
    {
        if (preg_match('/^[0-9a-f]{64}$/', $ticket) !== 1) {
            return [404, 'text/plain; charset=utf-8', 'not found'];
        }
        $record = $this->storage->get(self::TICKETS, $ticket);
        if ($record === null) {
            return [404, 'text/plain; charset=utf-8', 'not found'];
        }
        $expiresAt = is_int($record['expiresAt'] ?? null) ? $record['expiresAt'] : 0;
        if ($expiresAt <= time()) {
            return [404, 'text/plain; charset=utf-8', 'not found'];
        }
        $draftId = is_string($record['draftId'] ?? null) ? $record['draftId'] : '';
        $path = $this->mediaDir() . '/' . Storage::safeKey($draftId) . '.jpg';
        if ($draftId === '' || !is_file($path)) {
            return [404, 'text/plain; charset=utf-8', 'not found'];
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            return [404, 'text/plain; charset=utf-8', 'not found'];
        }
        return [200, 'image/jpeg', $bytes];
    }

    /** 期限切れの一時画像と合札を片づける。 */
    public function cleanupExpired(): int
    {
        $removed = 0;
        $dir = $this->mediaDir();
        $entries = @scandir($dir);
        if ($entries === false) {
            return 0;
        }
        foreach ($entries as $name) {
            if (!str_ends_with($name, '.jpg')) {
                continue;
            }
            $draftId = substr($name, 0, -4);
            $draft = $this->storage->get(self::DRAFTS, $draftId);
            if ($draft === null || $this->isExpired($draft)) {
                @unlink($dir . '/' . $name);
                if (is_array($draft) && is_string($draft['ticket'] ?? null)) {
                    $this->storage->delete(self::TICKETS, (string) $draft['ticket']);
                }
                $removed++;
            }
        }
        return $removed;
    }

    // ── 中の処理 ────────────────────────────────────────────

    /**
     * 受け取った画像を確かめる。**JPEGだけを通す。**
     *
     * iPhone側でもEXIFを落としているが、それを信用しない。
     * ここで開き直して、位置情報の残らない形へ作り直す。
     *
     * @return array{bytes:string,reencoded:bool}
     */
    private function readJpeg(mixed $base64): array
    {
        $maxBytes = $this->config->int('instagram_max_image_bytes');
        if ($maxBytes <= 0) {
            $maxBytes = 8 * 1024 * 1024;
        }
        $checked = Validator::image($base64, $maxBytes, '写真');
        if ($checked['extension'] !== '.jpg') {
            throw new ApiError(400, 'Instagramへ送れるのはJPEGの写真だけです');
        }

        $bytes = $checked['bytes'];

        // 頭の2バイトがJPEGの印か（種類の偽装をもう一段はじく）
        if (substr($bytes, 0, 2) !== "\xFF\xD8") {
            throw new ApiError(400, '写真として読み取れません');
        }

        $size = @getimagesizefromstring($bytes);
        if ($size === false) {
            throw new ApiError(400, '写真として読み取れません');
        }
        [$width, $height] = [(int) $size[0], (int) $size[1]];
        if ($width < 320 || $height < 320) {
            throw new ApiError(400, '写真が小さすぎます（320ピクセル以上にしてください）');
        }
        if ($width > 8000 || $height > 8000) {
            throw new ApiError(400, '写真が大きすぎます');
        }

        // 開き直して書き出す。これでEXIF（位置情報を含む）は残らない。
        $rebuilt = $this->reencodeJpeg($bytes);
        if ($rebuilt === null) {
            // **作り直せなかったら、そのまま送らない。**
            // iPhone側で落としてあるはずでも、それを当てにしない。
            // 記録には理由だけを残す（写真の中身も置き場所も書かない）。
            $this->storage->log('instagram: could not re-encode jpeg; refused to send');
            throw new ApiError(503, '写真を安全な形に作り直せませんでした。時間をおいてお試しください');
        }
        return ['bytes' => $rebuilt, 'reencoded' => true];
    }

    /**
     * JPEGを開き直して書き出す。EXIFはここで落ちる。
     *
     * 作り直せなければ null。**元の写真をそのまま返さない。**
     */
    private function reencodeJpeg(string $bytes): ?string
    {
        $hook = $this->reencoder;
        if ($hook !== null) {
            $result = $hook($bytes);
            return is_string($result) && $result !== '' ? $result : null;
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return null;
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }
        ob_start();
        $ok = @imagejpeg($image, null, 90);
        $rebuilt = (string) ob_get_clean();
        // imagedestroy はPHP 8.0以降は何もしないので呼ばない（8.5で非推奨）。
        if (!$ok || $rebuilt === '' || substr($rebuilt, 0, 2) !== "\xFF\xD8") {
            return null;
        }
        return $rebuilt;
    }

    private function mediaUrl(string $ticket): string
    {
        $base = rtrim($this->config->str('site_base_url'), '/');
        return $base . '/api/instagram/media/' . $ticket;
    }

    /** @return array<string,mixed> */
    private function requireDraft(string $draftId, string $deviceId): array
    {
        $draft = $this->storage->get(self::DRAFTS, $draftId);
        if ($draft === null) {
            throw new ApiError(404, '準備した内容が見つかりません');
        }
        // 別の端末の下書きは触らせない。
        if (!hash_equals((string) ($draft['deviceId'] ?? ''), $deviceId)) {
            throw new ApiError(403, 'この端末では扱えません');
        }
        return $draft;
    }

    /** @return array<string,mixed>|null */
    private function findByRequestId(string $deviceId, string $requestId): ?array
    {
        $key = $this->requestKey($deviceId, $requestId);
        $pointer = $this->storage->get(self::TICKETS, $key);
        if ($pointer === null) {
            // 見つからなければ、この要求番号を記録して次回に備える。
            return null;
        }
        $draftId = is_string($pointer['draftId'] ?? null) ? $pointer['draftId'] : '';
        if ($draftId === '') {
            return null;
        }
        $draft = $this->storage->get(self::DRAFTS, $draftId);
        if ($draft === null) {
            return null;
        }
        if (!hash_equals((string) ($draft['deviceId'] ?? ''), $deviceId)) {
            return null;
        }
        return $draft;
    }

    private function requestKey(string $deviceId, string $requestId): string
    {
        return 'req-' . substr(hash('sha256', $deviceId . '|' . $requestId), 0, 40);
    }

    /** @param array<string,mixed> $draft */
    private function isExpired(array $draft): bool
    {
        $expiresAt = is_int($draft['expiresAt'] ?? null) ? $draft['expiresAt'] : 0;
        return $expiresAt > 0 && $expiresAt <= time();
    }

    /** @param array<string,mixed> $draft */
    private function removeMedia(array $draft): void
    {
        $draftId = is_string($draft['draftId'] ?? null) ? $draft['draftId'] : '';
        if ($draftId !== '') {
            $path = $this->mediaDir() . '/' . Storage::safeKey($draftId) . '.jpg';
            if (is_file($path)) {
                @unlink($path);
            }
        }
        if (is_string($draft['ticket'] ?? null) && $draft['ticket'] !== '') {
            $this->storage->delete(self::TICKETS, (string) $draft['ticket']);
        }
    }

    /**
     * アプリへ返してよい形。
     *
     * **アクセストークン・コンテナID・保存場所は返さない。**
     *
     * @param array<string,mixed> $draft
     * @return array<string,mixed>
     */
    private function publicView(array $draft): array
    {
        $state = is_string($draft['state'] ?? null) ? $draft['state'] : 'failed';
        $view = [
            'draftId' => (string) ($draft['draftId'] ?? ''),
            'state' => $state,
            'accountName' => (string) ($draft['accountName'] ?? ''),
            'expiresAt' => gmdate('c', is_int($draft['expiresAt'] ?? null) ? $draft['expiresAt'] : time()),
            'imageHash' => (string) ($draft['imageHash'] ?? ''),
            'captionHash' => (string) ($draft['captionHash'] ?? ''),
            'message' => self::messageFor($state),
        ];
        if (is_string($draft['actorName'] ?? null) && $draft['actorName'] !== '') {
            $view['preparedBy'] = $draft['actorName'];
        }
        // 確認用の合言葉は、まだ使っていないときだけ返す。
        if ($state === 'ready' && ($draft['nonceUsed'] ?? false) !== true) {
            $view['confirmNonce'] = (string) ($draft['publishNonce'] ?? '');
        }
        if ($state === 'published') {
            $view['mediaId'] = (string) ($draft['mediaId'] ?? '');
            $view['permalink'] = (string) ($draft['permalink'] ?? '');
            $view['publishedAt'] = gmdate('c', is_int($draft['publishedAt'] ?? null) ? $draft['publishedAt'] : time());
            if (is_string($draft['publishedBy'] ?? null) && $draft['publishedBy'] !== '') {
                $view['publishedBy'] = $draft['publishedBy'];
            }
        }
        return $view;
    }

    private static function messageFor(string $state): string
    {
        return match ($state) {
            'preparing' => '準備しています',
            'processing' => 'Instagram側で準備中です。もう少しお待ちください',
            'ready' => '準備ができました。まだ公開されていません',
            'publishing' => '公開の結果を確認中です',
            'published' => 'Instagramへ公開しました',
            'expired' => '期限が切れました。もう一度やり直してください',
            'discarded' => '取りやめました',
            default => 'うまくいきませんでした',
        };
    }

    private static function requireId(mixed $value, string $label): string
    {
        $text = is_string($value) ? trim($value) : '';
        if (preg_match('/^[A-Za-z0-9_-]{8,64}$/', $text) !== 1) {
            throw new ApiError(400, sprintf('%sが正しくありません', $label));
        }
        return $text;
    }

    /** @return resource|null */
    private function acquireLock(string $draftId)
    {
        $dir = rtrim($this->config->str('storage_dir'), '/') . '/iglocks';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $path = $dir . '/' . Storage::safeKey($draftId) . '.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    /** @param resource|null $handle */
    private function releaseLock($handle): void
    {
        if ($handle === null) {
            return;
        }
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}
