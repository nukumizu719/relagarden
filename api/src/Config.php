<?php

declare(strict_types=1);

namespace Relagarden\Api;

/**
 * 設定の読み込み。
 *
 * 秘密情報はコードに書かない。public_html の外に置いた
 * config.php から読む。ファイルが無ければ、その旨だけを返す
 * （中身や場所を利用者へ見せない）。
 */
final class Config
{
    /** @var array<string,mixed> */
    private array $values;

    /** @param array<string,mixed> $values */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * 設定ファイルを読む。
     *
     * @throws ConfigMissing 設定が見つからない・壊れている場合
     */
    public static function load(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ConfigMissing('設定ファイルがありません');
        }
        /** @var mixed $loaded */
        $loaded = require $path;
        if (!is_array($loaded)) {
            throw new ConfigMissing('設定ファイルの形式が正しくありません');
        }

        // **必須はこれだけ。** どの機能を使うにも要る、端末との合言葉。
        //
        // GitHub（ホームページ掲載）の設定は必須にしない。
        // 掲載を使わずInstagramだけ動かしたいことがあるため。
        // 掲載の入口は、設定が無ければ入口ごとに「準備中」で止める。
        if (!isset($loaded['pairing_code'])
            || !is_string($loaded['pairing_code'])
            || $loaded['pairing_code'] === '') {
            throw new ConfigMissing('設定 pairing_code が未設定です');
        }
        if (strlen((string) $loaded['pairing_code']) < 8) {
            throw new ConfigMissing('pairing_code は8文字以上にしてください');
        }

        return new self($loaded + self::defaults());
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'github_branch' => 'main',
            'storage_dir' => sys_get_temp_dir() . '/relagarden-api',
            'max_image_bytes' => 8 * 1024 * 1024,
            'max_images' => 12,
            'max_request_bytes' => 40 * 1024 * 1024,
            'rate_window_seconds' => 3600,
            'rate_max_publishes' => 10,
            'rate_max_unpublishes' => 5,
            'rate_max_pairings' => 5,
            'site_base_url' => 'https://relagarden.jp',

            // ── Instagram実験 ────────────────────────────────
            // instagram_graph_api_version には既定値を置かない。
            // 推測した値で本番へつなぐと、Metaが上げたときに黙って壊れる。
            // 未設定のときは Instagram の入口を動かさない。
            'instagram_max_image_bytes' => 8 * 1024 * 1024,
            'instagram_max_request_bytes' => 16 * 1024 * 1024,
            'instagram_media_ttl_seconds' => 3600,
            'rate_max_instagram_prepares' => 3,
            'rate_max_instagram_publishes' => 3,
            'rate_max_instagram_status' => 60,
            // 投稿先アカウントごとの上限。端末を替えても超えられない。
            'rate_max_instagram_account_prepares' => 3,
            'rate_max_instagram_account_publishes' => 3,
            'rate_max_instagram_oauth_starts' => 5,
            'instagram_oauth_state_ttl_seconds' => 600,
        ];
    }

    /**
     * ホームページ掲載（GitHub直接方式）の設定がそろっているか。
     *
     * そろっていなければ、掲載の入口だけを「準備中」で止める。
     * **Instagramの入口は止めない。**
     */
    public function hasGitHub(): bool
    {
        foreach (['github_token', 'github_owner', 'github_repo'] as $key) {
            if ($this->str($key) === '') {
                return false;
            }
        }
        return true;
    }

    public function str(string $key): string
    {
        $value = $this->values[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    public function int(string $key): int
    {
        $value = $this->values[$key] ?? 0;
        return is_int($value) ? $value : (int) $value;
    }

    /**
     * いま持っている設定をそのまま返す。
     *
     * テストで一部だけを差し替えた設定を作るために使う。
     * **本番の処理からは呼ばない**（秘密情報をまとめて持ち回さないため）。
     *
     * @return array<string,mixed>
     */
    public function raw(): array
    {
        return $this->values;
    }

    /** 秘密の設定全体を外へ出さず、実行時の値だけを重ねた設定を作る。 */
    public function with(array $overrides): self
    {
        return new self($overrides + $this->values);
    }
}

final class ConfigMissing extends \RuntimeException
{
}
