<?php

declare(strict_types=1);

namespace Relagarden\Line;

/**
 * 公式LINEに届いたメッセージを受け取って、受信箱へ入れる。
 *
 * 初期状態は受信専用。本人限定テストを明示的に有効にした場合だけ、
 * 本文の保存と二重処理防止を完了した後に、許可された1人へ
 * replyTokenで返信する。一斉配信・プッシュ・既読・画像取得は行わない。
 *
 * 受け取るのは1対1の文字と写真到着の印だけ。
 * 写真の中身は取得しない。スタンプ・友だち追加は、二度処理しない印だけ残す。
 *
 * ## 書く順番（ここを変えないこと）
 *
 * 1. 本文を受信箱へ書く
 * 2. 書けてから「二度処理しない印」を付ける
 *
 * 印を先に付けると、本文の保存で失敗したときに
 * 「処理済みだが本文はどこにも無い」問い合わせが生まれ、
 * 再送されても弾かれて永久に消える。
 *
 * 印だけ書けなかった場合は500を返す。LINEが再送してきたときに、
 * 受信箱に同じものがあることを見て印を付け直す（修復する）。
 * 受信箱の鍵は届いた内容から決まるので、何度再送されても増えない。
 */
final class LineWebhookService
{
    /** 1回の配信で受け取るイベントの上限。 */
    public const maxEventsPerRequest = 100;

    /** 受け取る本文の長さの上限。 */
    public const maxTextLength = 4000;

    public function __construct(
        private readonly LineConfig $config,
        private readonly LineStore $store,
        private readonly LineProfile $profile,
        private readonly ?LineOwnerAiReplyService $ownerAiReply = null,
    ) {
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,mixed>
     * @throws LineError
     */
    public function receive(string $rawBody, array $headers): array
    {
        if (strlen($rawBody) > $this->config->int('max_body_bytes')) {
            throw new LineError(413, '内容が大きすぎます', 'E_BODY_TOO_BIG');
        }
        if (!LineSignature::isValid(
            $this->config->str('channel_secret'),
            $rawBody,
            $headers['x-line-signature'] ?? null
        )) {
            // 中身は読まない。誰から来たかも記録しない。
            throw new LineError(400, '受け付けられない要求です', 'E_SIGNATURE');
        }

        $data = json_decode($rawBody === '' ? '{}' : $rawBody, true);
        if (!is_array($data) || (isset($data['events']) && !is_array($data['events']))) {
            throw new LineError(400, '内容を読み取れません', 'E_JSON');
        }
        /** @var list<mixed> $events */
        $events = is_array($data['events'] ?? null) ? $data['events'] : [];
        // 1回の配信に入るイベントは、実際には数件しかない。
        // 極端な数を送りつけられて保存が膨らむのを防ぐ。
        if (count($events) > self::maxEventsPerRequest) {
            throw new LineError(413, '内容が大きすぎます', 'E_TOO_MANY_EVENTS');
        }

        $stored = 0;
        $skipped = 0;
        $repaired = 0;
        /** @var array<string,string> $nameCache */
        $nameCache = [];

        foreach ($events as $event) {
            if (!is_array($event)) {
                $skipped++;
                continue;
            }
            $result = $this->handleEvent($event, $nameCache);
            match ($result) {
                self::stored => $stored++,
                self::repaired => $repaired++,
                default => $skipped++,
            };
        }

        $this->housekeeping();

        if ($stored > 0) {
            $this->store->log('I_STORED', $stored);
        }
        if ($repaired > 0) {
            $this->store->log('I_MARK_REPAIRED', $repaired);
        }

        // ここまで来たら、本文は確かに保存できている。
        return [
            'ok' => true,
            'received' => count($events),
            'stored' => $stored,
            'skipped' => $skipped,
            'repaired' => $repaired,
        ];
    }

    private const stored = 'stored';
    private const skipped = 'skipped';
    private const repaired = 'repaired';

    /**
     * @param array<string,mixed> $event
     * @param array<string,string> $nameCache
     */
    private function handleEvent(array $event, array &$nameCache): string
    {
        $eventId = is_string($event['webhookEventId'] ?? null) ? $event['webhookEventId'] : '';
        $message = is_array($event['message'] ?? null) ? $event['message'] : [];
        $messageId = is_string($message['id'] ?? null) ? $message['id'] : '';

        // どちらの番号も無い配信は、二度処理しない印を作れない。
        // 保存すると再送のたびに増えてしまうので、受け取らない。
        if ($eventId === '' && $messageId === '') {
            $this->store->log('E_NO_EVENT_ID', 1);
            return self::skipped;
        }
        // 長すぎる番号は、そもそもLINEのものではない。
        $maxId = $this->config->int('max_id_length');
        if (strlen($eventId) > $maxId || strlen($messageId) > $maxId) {
            $this->store->log('E_ID_TOO_LONG', 1);
            return self::skipped;
        }

        // 印の鍵は、届いた番号をそのまま使わずSHA-256を通す。
        // 記号だけが違う番号が同じ鍵に潰れたり、細工した文字で
        // フォルダーの外へ出られたりしないようにするため。
        $marks = [];
        if ($eventId !== '') {
            $marks[] = 'e' . LineStore::hashKey($eventId);
        }
        if ($messageId !== '') {
            $marks[] = 'm' . LineStore::hashKey($messageId);
        }
        // 印が「全部そろっている」ときだけ、見るまでもなく終わり。
        // 一部しか無いときは、前回どこかで失敗している。下で直す。
        $existingMarks = 0;
        foreach ($marks as $mark) {
            if ($this->store->exists('events', $mark)) {
                $existingMarks++;
            }
        }
        if ($existingMarks === count($marks)) {
            return self::skipped;
        }

        $isMessage = ($event['type'] ?? '') === 'message';
        $source = is_array($event['source'] ?? null) ? $event['source'] : [];
        $lineUserId = is_string($source['userId'] ?? null) ? $source['userId'] : '';
        $isDirect = ($source['type'] ?? '') === 'user' && $lineUserId !== '';
        $kind = is_string($message['type'] ?? null) ? $message['type'] : 'unknown';

        $isText = $kind === 'text' && is_string($message['text'] ?? null);
        $isImage = $kind === 'image';
        // 写真は「届いた」という情報だけを受け取り、中身は取りに行かない。
        // スタンプ・友だち追加・グループは印だけ残す。
        if (!$isMessage || !$isDirect || (!$isText && !$isImage)) {
            $this->writeMarks($marks);
            return self::skipped;
        }

        $timestamp = $event['timestamp'] ?? null;
        $receivedMs = is_int($timestamp) ? $timestamp : (int) (microtime(true) * 1000);
        // 届いた内容から決まる鍵。再送されても同じ鍵になるので、増えない。
        $key = sprintf('%014d', $receivedMs) . '-'
            . LineStore::hashKey($eventId . '|' . $messageId . '|' . $lineUserId);

        // 本文はあるのに印が無い＝前回、印を書く手前で失敗した。
        // ここで印を付け直す（本文は二重に保存しない）。
        if ($this->store->exists('inbox', $key)) {
            $this->writeMarks($marks);
            return self::repaired;
        }

        // 印は一部あるのに本文が無い＝前に文字以外として読み捨てた配信。
        // 足りない印だけ足して、本文は作らない（二重保存を防ぐ）。
        if ($existingMarks > 0) {
            $this->writeMarks($marks);
            return self::skipped;
        }

        // 本文は書き換えない。長すぎるものだけ切る。写真本文は保存しない。
        $text = $isText
            ? mb_substr((string) $message['text'], 0, self::maxTextLength)
            : '';

        if (!array_key_exists($lineUserId, $nameCache)) {
            try {
                $nameCache[$lineUserId] = $this->profile->displayNameOf($lineUserId);
            } catch (\Throwable $e) {
                // 表示名が取れなくても、本文は必ず受け取る。
                // 名前は空欄のままアプリへ渡し、画面では仮の呼び名を出す。
                $nameCache[$lineUserId] = '';
            }
        }

        // ── 1. 先に本文を確実に残す ──────────────────────────
        $record = [
            'id' => $key,
            'eventKey' => $eventId !== '' ? $eventId : $messageId,
            'messageId' => $messageId,
            'lineUserId' => $lineUserId,
            'lineDisplayName' => $nameCache[$lineUserId],
            'kind' => $isImage ? 'image' : 'text',
            'text' => $text,
            'receivedAt' => gmdate('c', (int) ($receivedMs / 1000)),
            'takenAt' => '',
        ] + LineReceptionStateService::manualMetadata();
        $saved = $this->store->put('inbox', $key, $record);
        if (!$saved) {
            // 保存できていないのに200を返さない。
            // 200を返すとLINEは再送してくれず、問い合わせが消えてしまう。
            throw new LineError(500, 'ただいま受け取れません', 'E_WRITE_INBOX');
        }

        // ── 2. 本文を残してから、本人限定受付の状態を更新する ────
        // 初期値はAI対象外。AIが明示的に引き継いだ場合だけneedsHumanをtrueにする。
        if ($this->ownerAiReply !== null) {
            $replyToken = is_string($event['replyToken'] ?? null)
                ? $event['replyToken']
                : '';
            $metadata = $this->ownerAiReply->replyIfAllowed(
                $lineUserId,
                $text,
                $replyToken,
                $isImage ? 'image' : 'text',
            );
            $record['needsHuman'] = $metadata['needsHuman'];
            $record['reasonCode'] = $metadata['reasonCode'];
            $record['collectedFields'] = $metadata['collectedFields'];
            if (!$this->store->put('inbox', $key, $record)) {
                // 元の記録はMANUAL_ONLYで残り、AI引継ぎとは区別される。
                throw new LineError(500, 'ただいま受け取れません', 'E_WRITE_RECEPTION_META');
            }
        }

        // ── 3. 状態と受信箱が残ってから、二度処理しない印を付ける ────
        $this->writeMarks($marks);
        return self::stored;
    }

    /**
     * 二度処理しない印を付ける。
     *
     * ここで失敗しても本文はもう残っているので、問い合わせは消えない。
     * 500を返してLINEに再送してもらい、次に来たときへ印を付け直す。
     *
     * @param list<string> $marks
     */
    private function writeMarks(array $marks): void
    {
        foreach ($marks as $mark) {
            if ($this->store->exists('events', $mark)) {
                continue;
            }
            if (!$this->store->put('events', $mark, ['at' => gmdate('c')])) {
                throw new LineError(500, 'ただいま受け取れません', 'E_WRITE_MARK');
            }
        }
    }

    /**
     * 1日に1回だけ、古い受け取り済みの控えを片付ける。
     *
     * 受け取っていないものは日数が経っても消さない。
     */
    private function housekeeping(): void
    {
        $today = gmdate('Y-m-d');
        $last = $this->store->get('rate', LineInboxService::housekeepingKey);
        if (is_array($last) && ($last['day'] ?? '') === $today) {
            return;
        }
        $this->store->put('rate', LineInboxService::housekeepingKey, ['day' => $today]);
        (new LineInboxService($this->config, $this->store))->prune();
    }
}
