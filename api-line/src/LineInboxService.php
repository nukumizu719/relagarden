<?php

declare(strict_types=1);

namespace Relagarden\Line;

/**
 * 受信箱。アプリが取りに来て、取れたものに印を付ける。
 *
 * - `items()` … まだ取っていないものを、届いた順に返す
 * - `ack()`   … アプリが取り込めたものへ「受け取り済み」の印を付ける
 * - `prune()` … 受け取り済みで日数が経ったものだけ片付ける
 *
 * 消すのは受け取り済みのものだけ。取り込めていないお客様の
 * 問い合わせは、日数が経っても残す。
 */
final class LineInboxService
{
    /** 片付けを1日1回にするための印。**これは消さない。** */
    public const housekeepingKey = 'housekeeping';

    public function __construct(
        private readonly LineConfig $config,
        private readonly LineStore $store,
    ) {
    }

    /**
     * まだ取り込んでいない問い合わせを、古い順に返す。
     *
     * @return array<string,mixed>
     */
    public function items(int $limit = 0): array
    {
        $max = $limit > 0 ? $limit : $this->config->int('inbox_limit');
        $max = max(1, min($max, 200));

        $items = [];
        $remaining = 0;
        foreach ($this->store->keys('inbox') as $key) {
            $record = $this->store->get('inbox', $key);
            if ($record === null) {
                continue;
            }
            if (($record['takenAt'] ?? '') !== '') {
                continue;
            }
            if (count($items) >= $max) {
                $remaining++;
                continue;
            }
            $items[] = [
                'id' => (string) ($record['id'] ?? $key),
                'eventKey' => (string) ($record['eventKey'] ?? ''),
                'messageId' => (string) ($record['messageId'] ?? ''),
                'lineUserId' => (string) ($record['lineUserId'] ?? ''),
                'lineDisplayName' => (string) ($record['lineDisplayName'] ?? ''),
                'kind' => (string) ($record['kind'] ?? 'text'),
                'text' => (string) ($record['text'] ?? ''),
                'receivedAt' => (string) ($record['receivedAt'] ?? ''),
                'needsHuman' => ($record['needsHuman'] ?? false) === true,
                'reasonCode' => is_string($record['reasonCode'] ?? null)
                    ? $record['reasonCode']
                    : 'MANUAL_ONLY',
                'collectedFields' => $this->collectedFields($record['collectedFields'] ?? null),
            ];
        }

        return ['items' => $items, 'remaining' => $remaining];
    }

    /** @return array<string,string> */
    private function collectedFields(mixed $raw): array
    {
        $source = is_array($raw) ? $raw : [];
        $result = [];
        foreach (LineReceptionStateService::fields as $field) {
            $result[$field] = is_string($source[$field] ?? null)
                ? (string) $source[$field]
                : '';
        }
        return $result;
    }

    /**
     * アプリが取り込めたものへ印を付ける。
     *
     * 同じidを何度送られても結果は変わらない。
     * **書けなかったものは数えて返す。** 呼び出し側は成功として扱わない
     * （印が付いていないのに「済んだ」と答えると、アプリは次に取りに来ず、
     * サーバー側にはいつまでも残る）。
     *
     * @param list<mixed> $ids
     * @return array{marked:int,failed:int}
     */
    public function ack(array $ids): array
    {
        $marked = 0;
        $failed = 0;
        foreach ($ids as $id) {
            if (!is_string($id) || $id === '') {
                continue;
            }
            $record = $this->store->get('inbox', $id);
            if ($record === null) {
                continue;
            }
            if (($record['takenAt'] ?? '') !== '') {
                continue;
            }
            $record['takenAt'] = gmdate('c');
            if ($this->store->put('inbox', $id, $record)) {
                $marked++;
            } else {
                $failed++;
            }
        }
        return ['marked' => $marked, 'failed' => $failed];
    }

    /** 受け取り済みで、決めた日数を過ぎたものだけ片付ける。 */
    public function prune(): int
    {
        $days = max(1, $this->config->int('keep_days'));
        $limit = time() - ($days * 86400);
        $removed = 0;

        foreach ($this->store->keys('inbox') as $key) {
            $record = $this->store->get('inbox', $key);
            if ($record === null) {
                continue;
            }
            $takenAt = (string) ($record['takenAt'] ?? '');
            if ($takenAt === '') {
                // まだアプリへ渡っていない。残す。
                continue;
            }
            $taken = strtotime($takenAt);
            if ($taken !== false && $taken < $limit) {
                $this->store->delete('inbox', $key);
                $removed++;
            }
        }

        // 二重処理を防ぐ印も、同じ日数だけ残してから片付ける。
        foreach ($this->store->keys('events') as $key) {
            $record = $this->store->get('events', $key);
            $at = is_array($record) ? strtotime((string) ($record['at'] ?? '')) : false;
            if ($at !== false && $at < $limit) {
                $this->store->delete('events', $key);
            }
        }

        $this->pruneRate();

        return $removed;
    }

    /**
     * 回数制限の記録のうち、**時間の窓を過ぎたものだけ**を片付ける。
     *
     * 接続元ごとに1つずつ増えるため、放っておくと溜まり続ける。
     * 窓の中に1回でも記録が残っているものは、まだ数えている最中なので消さない。
     * 片付けの印（[housekeepingKey]）も消さない。
     * 問い合わせ（inbox）と二度処理しない印（events）には触れない。
     */
    private function pruneRate(): int
    {
        $window = max(1, $this->config->int('rate_window_seconds'));
        $now = time();
        $removed = 0;

        foreach ($this->store->keys('rate') as $key) {
            if ($key === self::housekeepingKey) {
                continue;
            }
            $done = $this->store->deleteIfExpired(
                'rate',
                $key,
                static function (array $record) use ($now, $window): bool {
                    // 数えた時刻の一覧を持たないものは、回数制限の記録ではない。
                    // 見覚えのない形のものは消さない。
                    if (!isset($record['times']) || !is_array($record['times'])) {
                        return false;
                    }
                    foreach ($record['times'] as $t) {
                        if (is_int($t) && $t > $now - $window) {
                            // まだ窓の中。数えている最中なので残す。
                            return false;
                        }
                    }
                    return true;
                }
            );
            if ($done) {
                $removed++;
            }
        }

        return $removed;
    }
}
