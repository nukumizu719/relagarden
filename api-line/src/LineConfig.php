<?php

declare(strict_types=1);

namespace Relagarden\Line;

/**
 * LINE受信と、明示的に有効化する本人限定テスト送信の設定。
 *
 * 施工事例の掲載（api/ 側）とは別のファイルを読む。
 * 掲載はiPhoneからGitHubへ直接行う方式のままで、こちらは触らない。
 *
 * 秘密情報はコードに書かない。public_html の外に置いた
 * line-config.php から読む。無ければ、その旨だけを返す。
 */
final class LineConfig
{
    /** 受信箱の合言葉の最短の長さ。openssl rand -hex 32 で64文字になる。 */
    public const minInboxTokenLength = 64;

    /** @var array<string,mixed> */
    private array $values;

    /** @param array<string,mixed> $values */
    public function __construct(array $values)
    {
        $this->values = $values + self::defaults();
    }

    /**
     * 設定ファイルを読む。
     *
     * @throws LineConfigMissing 設定が見つからない・足りない場合
     */
    public static function load(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new LineConfigMissing('E_CONFIG_MISSING');
        }
        /** @var mixed $loaded */
        $loaded = require $path;
        if (!is_array($loaded)) {
            throw new LineConfigMissing('E_CONFIG_SHAPE');
        }

        // チャネルシークレットは署名の確認に必ず要る。
        if (!isset($loaded['channel_secret']) || !is_string($loaded['channel_secret']) || strlen($loaded['channel_secret']) < 16) {
            throw new LineConfigMissing('E_CONFIG_SECRET');
        }
        // 受信箱を読むための合言葉。iPhoneのKeychainへ入れる値。
        // 人が考えた言葉ではなく、機械で作ったでたらめな64文字以上にする。
        //   openssl rand -hex 32
        if (!isset($loaded['inbox_token']) || !is_string($loaded['inbox_token']) || strlen($loaded['inbox_token']) < self::minInboxTokenLength) {
            throw new LineConfigMissing('E_CONFIG_TOKEN');
        }

        // 受信データの置き場所が公開領域だと、ブラウザーから中身を読まれる。
        // 設定の書き間違いは起きるものなので、動く前に止める。
        $storageDir = isset($loaded['storage_dir']) && is_string($loaded['storage_dir'])
            ? $loaded['storage_dir']
            : (string) (self::defaults()['storage_dir']);
        if (self::looksPublic($storageDir)) {
            throw new LineConfigMissing('E_CONFIG_STORAGE_PUBLIC');
        }
        // チャネルアクセストークンは受信だけなら任意。無ければ表示名を
        // 取りに行かない。本人限定AI返信では必須だが、不足していても
        // 受信を止めず、返信機能だけが安全側で停止する。

        return new self($loaded);
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'channel_access_token' => '',
            'storage_dir' => sys_get_temp_dir() . '/relagarden-line',
            // LINEのWebhookは大きくない。念のための上限。
            'max_body_bytes' => 512 * 1024,
            // 受け取り済みの記録を残す日数。過ぎたものだけ片付ける。
            'keep_days' => 30,
            'rate_window_seconds' => 3600,
            'rate_max_inbox' => 240,
            // Webhookの上限。LINEからの正規の配信を落とさないよう十分に大きく取る。
            // ここを超えると429を返し、LINE側の再送に任せる。
            'rate_max_webhook' => 600,
            // 表示名を取りに行くときの待ち時間（秒）。
            'profile_timeout' => 5,
            // 本番はHTTPSでしか受けない。手元の確認のときだけ false にする。
            'require_https' => true,
            // /sync で受け取る本文と番号の上限。
            'max_sync_bytes' => 64 * 1024,
            'max_sync_ids' => 200,
            'max_id_length' => 128,
            // 1回の受信箱で返す最大件数。
            'inbox_limit' => 50,
            // 本人限定AI返信は、必要項目を明示して初めて動く。
            'ai_reply_enabled' => false,
            'ai_reply_test_mode' => false,
            'ai_reply_allowed_user_id' => '',
            'ai_gateway_base_url' => '',
            'ai_gateway_token' => '',
            'ai_reply_daily_limit' => 20,
            'ai_gateway_timeout_seconds' => 8,
            'line_reply_timeout_seconds' => 5,
            // アプリからの手動送信は、本人限定テストの設定を別に持つ。
            // コードを置いただけでは動かず、両方を明示的に有効にする必要がある。
            'manual_send_enabled' => false,
            'manual_send_test_mode' => false,
            'manual_send_allowed_user_id' => '',
            'manual_send_daily_limit' => 20,
            'manual_send_timeout_seconds' => 5,
            'max_send_body_bytes' => 16 * 1024,
            'max_send_text_length' => 2000,
            'max_send_request_id_length' => 128,
        ];
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

    public function bool(string $key): bool
    {
        // 文字列 'false' はPHPでは true 扱いになるため、設定の安全スイッチでは
        // 本物の真偽値 true だけをONとして認める。
        return ($this->values[$key] ?? null) === true;
    }

    /**
     * 公開領域（public_html / htdocs）の下を指していないか。
     *
     * ここに保存すると、URLを当てられただけで問い合わせを読まれてしまう。
     */
    public static function looksPublic(string $dir): bool
    {
        $normalized = str_replace('\\', '/', $dir);
        foreach (['/public_html/', '/htdocs/', '/www/', '/public/'] as $marker) {
            if (str_contains($normalized . '/', $marker)) {
                return true;
            }
        }
        return false;
    }
}

final class LineConfigMissing extends \RuntimeException
{
}
