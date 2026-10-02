<?php

declare(strict_types=1);

namespace Relagarden\Line;

/**
 * 置き場所が使えないときに投げる。
 *
 * 書けない場所のまま動かすと、届いた問い合わせを黙って捨ててしまう。
 * それより「ただいま準備中です」で止まったほうが安全なので、
 * 開始時に確かめて、駄目ならここで止める。
 */
final class LineStorageUnavailable extends \RuntimeException
{
}

/**
 * LINEで届いた内容の置き場所。
 *
 * データベースは使わない。Xserverの契約に左右されず、
 * ファイルだけで完結させるため（掲載API側と同じ考え方だが、
 * 保存場所も中身も完全に別にしてある）。
 *
 * 置き場所は public_html の外を前提にしている。
 * LINEのユーザーIDはここにだけ残り、外へは出さない。
 */
final class LineStore
{
    private string $dir;

    /** @throws LineStorageUnavailable 作れない・読めない・書けないとき */
    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');
        if ($this->dir === '') {
            throw new LineStorageUnavailable('E_STORAGE_PATH');
        }
        foreach (['', '/events', '/inbox', '/rate', '/requests', '/reception', '/reception_stops', '/controls', '/locks', '/logs'] as $sub) {
            $path = $this->dir . $sub;
            if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
                throw new LineStorageUnavailable('E_STORAGE_MKDIR');
            }
            if (!is_readable($path) || !is_writable($path)) {
                throw new LineStorageUnavailable('E_STORAGE_PERM');
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

    /**
     * LINEから来た文字列を、そのまま鍵にしない。
     *
     * 記号だけが違うIDが同じ鍵に潰れたり、細工した文字で
     * フォルダーの外へ出られたりしないよう、必ずSHA-256を通す。
     */
    public static function hashKey(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /**
     * 保存する。**書けたかどうかを必ず返す。**
     *
     * 書けていないのに「受け取りました」と答えると、LINEは再送してくれず、
     * お客様の問い合わせがどこにも残らないまま消える。
     *
     * @param array<string,mixed> $data
     */
    public function put(string $bucket, string $key, array $data): bool
    {
        $path = $this->pathFor($bucket, $key);
        if ($path === null) {
            return false;
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        // 同じ相手から同時に届いても、途中の内容を読ませない。
        // 一時ファイルの名前は毎回変える。決め打ちの名前だと、
        // 同時に走った2つが同じ一時ファイルを奪い合って壊れる。
        $tmp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * 同じ鍵を最初の1回だけ保存する。
     *
     * 送信ボタンの二度押しや通信再試行が同時に来ても、同じrequestIdで
     * 外部送信へ進めるのは1つだけにする。`x` は既存ファイルを上書きしない。
     *
     * @param array<string,mixed> $data
     */
    public function claim(string $bucket, string $key, array $data): bool
    {
        $path = $this->pathFor($bucket, $key);
        if ($path === null) {
            return false;
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            return false;
        }
        $written = false;
        try {
            if (flock($handle, LOCK_EX) && fwrite($handle, $json) === strlen($json)) {
                fflush($handle);
                @chmod($path, 0600);
                $written = true;
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        if (!$written) {
            @unlink($path);
        }
        return $written;
    }

    /**
     * 同じ鍵の処理を1つずつ実行する。
     *
     * 外部通信を含む受付処理全体を囲むため、例外時もfinallyで必ず解除する。
     * 鍵にはuserIdそのものではなく、呼び出し側でSHA-256化した値を渡す。
     */
    public function synchronized(string $key, callable $work): mixed
    {
        $safeKey = self::safeKey($key);
        if ($safeKey === '') {
            throw new LineStorageUnavailable('E_LOCK_KEY');
        }
        $path = $this->dir . '/locks/' . $safeKey . '.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new LineStorageUnavailable('E_LOCK_OPEN');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new LineStorageUnavailable('E_LOCK_FLOCK');
            }
            @chmod($path, 0600);
            return $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array<string,mixed>|null */
    public function get(string $bucket, string $key): ?array
    {
        $path = $this->pathFor($bucket, $key);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * 読んで、書き換えて、書き戻すまでを一度に行う。
     *
     * 回数制限のように「読んだ値に足して書く」ものは、
     * 途中で別の呼び出しが割り込むと数が合わなくなる。
     * ファイルに鍵をかけて、割り込まれないようにする。
     *
     * @param callable(array<string,mixed>):array<string,mixed> $change
     */
    public function update(string $bucket, string $key, callable $change): bool
    {
        $path = $this->pathFor($bucket, $key);
        if ($path === null) {
            return false;
        }
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return false;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }
            $raw = (string) stream_get_contents($handle);
            $current = json_decode($raw, true);
            $next = $change(is_array($current) ? $current : []);
            $json = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return false;
            }
            rewind($handle);
            if (ftruncate($handle, 0) === false) {
                return false;
            }
            if (fwrite($handle, $json) === false) {
                return false;
            }
            fflush($handle);
            @chmod($path, 0600);
            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function exists(string $bucket, string $key): bool
    {
        $path = $this->pathFor($bucket, $key);
        return $path !== null && is_file($path);
    }

    public function delete(string $bucket, string $key): void
    {
        $path = $this->pathFor($bucket, $key);
        // 印やリンクを辿った先を消さない。ここで消してよいのは、
        // このフォルダーに置かれた本物のファイルだけ。
        if ($path !== null && is_file($path) && !is_link($path)) {
            @unlink($path);
        }
    }

    /**
     * 期限切れのものだけを、鍵をかけて片付ける。
     *
     * いま誰かが使っている（鍵が取れない）ファイルには触らない。
     * 消してよいかの判断は呼び出し側へ渡し、**鍵を持ったまま**確かめてから消す。
     * 確かめた後に書き込まれたものを消してしまわないようにするため。
     *
     * シンボリックリンクは相手にしない（リンクの先を消さない）。
     *
     * @param callable(array<string,mixed>):bool $isExpired
     * @return bool 消したかどうか
     */
    public function deleteIfExpired(string $bucket, string $key, callable $isExpired): bool
    {
        $path = $this->pathFor($bucket, $key);
        if ($path === null || is_link($path) || !is_file($path)) {
            return false;
        }
        $handle = @fopen($path, 'r+');
        if ($handle === false) {
            return false;
        }
        try {
            // 待たない。使われている最中なら、それは「今も使っている」ということ。
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return false;
            }
            $raw = (string) stream_get_contents($handle);
            $data = json_decode($raw, true);
            if (!is_array($data) || !$isExpired($data)) {
                return false;
            }
            return @unlink($path);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * 鍵を古い順に並べて返す。
     *
     * 受信箱の鍵は「受信時刻＋取り違えない印」で作るので、
     * 名前順が届いた順になる。
     *
     * @return list<string>
     */
    public function keys(string $bucket): array
    {
        $dir = $this->dir . '/' . self::safeKey($bucket);
        if (!is_dir($dir)) {
            return [];
        }
        $keys = [];
        foreach ((array) (scandir($dir) ?: []) as $name) {
            if (!is_string($name) || !str_ends_with($name, '.json')) {
                continue;
            }
            $keys[] = substr($name, 0, -5);
        }
        sort($keys, SORT_STRING);
        return $keys;
    }

    /**
     * 記録を1行足す。
     *
     * **書いてよいのは、決まったエラーコードと件数だけ。**
     * 本文・LINEのユーザーID・ファイルの場所・例外の文面・秘密は書かない。
     * 記録が漏れても、お客様のことが何も分からないようにしておく。
     */
    public function log(string $code, int $count = 0): void
    {
        // 決めた形以外は受け付けない（うっかり本文を渡しても残らない）。
        $safeCode = preg_replace('/[^A-Z0-9_]/', '', $code) ?? '';
        if ($safeCode === '') {
            $safeCode = 'E_UNKNOWN';
        }
        $line = sprintf("%s\t%s\t%d\n", gmdate('c'), substr($safeCode, 0, 40), $count);
        @file_put_contents(
            $this->dir . '/logs/' . gmdate('Y-m') . '.log',
            $line,
            FILE_APPEND | LOCK_EX
        );
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
