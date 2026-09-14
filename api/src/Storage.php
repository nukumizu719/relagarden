<?php

declare(strict_types=1);

namespace Relagarden\Api;

/**
 * 端末トークン・投稿状況・記録の保存。
 *
 * データベースは使わない。Xserverの契約に左右されず、
 * ファイルだけで完結させるため。
 * 保存先は public_html の外を前提にしている。
 */
final class Storage
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');
        foreach (
            [
                '', '/devices', '/status', '/logs', '/rate', '/igdrafts', '/igtickets',
                '/igoauth', '/igconnection', '/iginvites', '/igtenants',
            ]
            as $sub
        ) {
            $path = $this->dir . $sub;
            if (!is_dir($path)) {
                @mkdir($path, 0700, true);
            }
        }
    }

    /**
     * 鍵に使ってよい文字だけに絞る。
     * `../` などでフォルダーの外へ出られないようにする。
     */
    public static function safeKey(string $raw): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $raw) ?? '';
        return substr($clean, 0, 128);
    }

    /** @param array<string,mixed> $data */
    public function put(string $bucket, string $key, array $data): void
    {
        $path = $this->pathFor($bucket, $key);
        if ($path === null) {
            return;
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        // 途中で落ちても壊れた内容が残らないよう、書いてから差し替える。
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            return;
        }
        @chmod($tmp, 0600);
        @rename($tmp, $path);
    }

    /** @return array<string,mixed>|null */
    public function get(string $bucket, string $key): ?array
    {
        $path = $this->pathFor($bucket, $key);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public function delete(string $bucket, string $key): void
    {
        $path = $this->pathFor($bucket, $key);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    public function exists(string $bucket, string $key): bool
    {
        $path = $this->pathFor($bucket, $key);
        return $path !== null && is_file($path);
    }

    /**
     * `used=false` の記録を、排他ロックの中で一度だけ使用済みにする。
     * 同じ招待を二台が同時に読んでも、片方だけが受け取れる。
     *
     * @return array<string,mixed>|null
     */
    public function consumeUnused(string $bucket, string $key): ?array
    {
        $path = $this->pathFor($bucket, $key);
        if ($path === null) {
            return null;
        }
        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            return null;
        }
        @chmod($path . '.lock', 0600);
        try {
            $record = $this->get($bucket, $key);
            if ($record === null || ($record['used'] ?? true) === true) {
                return null;
            }
            $record['used'] = true;
            $record['usedAt'] = time();
            $this->put($bucket, $key, $record);
            return $record;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * 記録を1行足す。
     *
     * 秘密情報は呼び出し側で外してから渡すこと。
     * ここでは念のため、トークンらしき文字列を伏せる。
     */
    public function log(string $message): void
    {
        $line = sprintf(
            "%s\t%s\n",
            gmdate('c'),
            self::maskSecrets($message)
        );
        @file_put_contents(
            $this->dir . '/logs/' . gmdate('Y-m') . '.log',
            $line,
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * 記録へ出す前に、トークンらしき文字列を伏せる。
     * 万が一の取り違えでも秘密が残らないようにする。
     */
    public static function maskSecrets(string $text): string
    {
        $patterns = [
            '/gh[pousr]_[A-Za-z0-9]{20,}/',
            '/github_pat_[A-Za-z0-9_]{20,}/',
            '/\b[A-Fa-f0-9]{40,}\b/',
            // Instagram / Meta のアクセストークン
            '/IG[A-Za-z0-9_-]{20,}/',
            '/EAA[A-Za-z0-9_-]{20,}/',
        ];
        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, '***', $text) ?? $text;
        }
        return $text;
    }

    private function pathFor(string $bucket, string $key): ?string
    {
        $safeBucket = self::safeKey($bucket);
        $safeKey = self::safeKey($key);
        if ($safeBucket === '' || $safeKey === '') {
            return null;
        }
        return $this->dir . '/' . $safeBucket . '/' . $safeKey . '.json';
    }
}
