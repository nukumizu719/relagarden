<?php

declare(strict_types=1);

namespace Relagarden\Line;

/**
 * 本人限定AI受付の会話状態。
 *
 * LINEのuserIdは鍵にも本文にも置かず、userIdとテストsessionIdを
 * SHA-256へ通した鍵でだけ結び付ける。replyTokenは保存しない。
 */
final class LineReceptionStateService
{
    /** @var list<string> */
    public const fields = ['region', 'area', 'condition', 'photo', 'preferredTiming'];

    /** @var list<string> */
    private const reasons = [
        '',
        'MANUAL_ONLY',
        'MANUAL_TAKEOVER',
        'PRICE',
        'CAPABILITY',
        'SCHEDULE_CONFIRMATION',
        'DISCOUNT',
        'CONTRACT',
        'COMPLAINT',
        'SAFETY',
        'UNSAFE_INPUT',
        'AI_HANDOFF',
        'AI_GATEWAY',
        'AI_INVALID',
        'MAX_QUESTIONS',
        'INTAKE_COMPLETE',
        'LOCAL_LIMIT',
        'REPLY_FAILED',
        'STATE_INVALID',
        'STATE_WRITE_FAILED',
        'LOCK_FAILED',
    ];

    /** @var list<string> */
    private const flexibleReasons = ['PRICE', 'CAPABILITY', 'SCHEDULE_CONFIRMATION', 'INTAKE_COMPLETE'];

    public function __construct(
        private readonly LineStore $store,
        private readonly int $maxQuestions,
    ) {
    }

    /** @return array<string,mixed> */
    public function load(string $lineUserId, string $sessionId): array
    {
        $stop = $this->store->get('reception_stops', $this->key($lineUserId, $sessionId));
        if ($stop !== null) {
            $reason = is_string($stop['reasonCode'] ?? null)
                ? $stop['reasonCode']
                : 'STATE_WRITE_FAILED';
            return $this->handoff($this->initial(), $reason);
        }
        $stored = $this->store->get('reception', $this->key($lineUserId, $sessionId));
        if ($stored === null) {
            return $this->initial();
        }
        return $this->normalize($stored);
    }

    /** @param array<string,mixed> $state */
    public function save(string $lineUserId, string $sessionId, array $state): bool
    {
        $state = $this->normalize($state);
        $state['updatedAt'] = gmdate('c');
        return $this->store->put('reception', $this->key($lineUserId, $sessionId), $state);
    }

    /** 現在のテストsessionだけを初期状態へ戻す。 */
    public function reset(string $lineUserId, string $sessionId): bool
    {
        $key = $this->key($lineUserId, $sessionId);
        $this->store->delete('reception', $key);
        $this->store->delete('reception_stops', $key);
        return !$this->store->exists('reception', $key)
            && !$this->store->exists('reception_stops', $key);
    }

    /**
     * 通常の会話状態を書けない場合でも、別の停止印で後続の自動受付を止める。
     *
     * @return array{needsHuman:bool,reasonCode:string,collectedFields:array<string,string>}
     */
    public function failStop(string $lineUserId, string $sessionId, string $reasonCode): array
    {
        $state = $this->handoff($this->initial(), $reasonCode);
        $reason = (string) $state['reasonCode'];
        $this->store->put('reception_stops', $this->key($lineUserId, $sessionId), [
            'reasonCode' => $reason,
            'at' => gmdate('c'),
        ]);
        return $this->metadata($state);
    }

    /**
     * 待っていた回答だけを保存する。初回メッセージを勝手に項目へ割り当てない。
     * 写真は本文を取得せず「届いた」という状態だけを持つ。
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    public function capture(array $state, string $kind, string $text): array
    {
        $state = $this->normalize($state);
        if (($state['status'] ?? '') === 'handoff') {
            return $state;
        }

        /** @var array<string,string> $fields */
        $fields = $state['collectedFields'];
        $awaiting = (string) ($state['awaiting'] ?? '');
        if ($kind === 'image') {
            $fields['photo'] = 'received';
            if ($awaiting === 'photo') {
                $state['awaiting'] = '';
            }
        } elseif ($kind === 'text' && $awaiting !== '' && in_array($awaiting, self::fields, true)) {
            $value = trim($text);
            if ($awaiting === 'photo') {
                if (preg_match('/(?:写真.*(?:ない|ありません|送れない)|画像.*(?:ない|ありません|送れない)|撮れない)/u', $value) === 1) {
                    $fields['photo'] = 'unavailable';
                    $state['awaiting'] = '';
                } elseif (preg_match('/(?:写真|画像).*(?:送ります|送れます|あとで送)/u', $value) === 1) {
                    $fields['photo'] = 'promised';
                    $state['awaiting'] = '';
                }
            } elseif ($value !== '') {
                $limits = [
                    'region' => 100,
                    'area' => 100,
                    'condition' => 300,
                    'preferredTiming' => 100,
                ];
                $fields[$awaiting] = mb_substr($value, 0, $limits[$awaiting] ?? 100);
                $state['awaiting'] = '';
            }
        }
        $state['collectedFields'] = $fields;
        $state['turnCount'] = min(99, ((int) ($state['turnCount'] ?? 0)) + 1);
        return $state;
    }

    /**
     * 1通に複数の受付項目が書かれていたとき、未入力の項目だけをまとめて保存する。
     * 値の抽出と安全確認は呼び出し側で済ませ、ここでは既存回答を上書きしない。
     *
     * @param array<string,mixed> $state
     * @param array<string,string> $provided
     * @return array<string,mixed>
     */
    public function captureProvided(array $state, array $provided): array
    {
        $state = $this->normalize($state);
        if (($state['status'] ?? '') === 'handoff') {
            return $state;
        }

        /** @var array<string,string> $fields */
        $fields = $state['collectedFields'];
        $limits = [
            'region' => 100,
            'area' => 100,
            'condition' => 300,
            'preferredTiming' => 100,
        ];
        foreach (self::fields as $field) {
            if (($fields[$field] ?? '') !== '') {
                continue;
            }
            $value = is_string($provided[$field] ?? null)
                ? trim($provided[$field])
                : '';
            if ($value === '') {
                continue;
            }
            if ($field === 'photo') {
                if (!in_array($value, ['received', 'unavailable', 'promised'], true)) {
                    continue;
                }
                $fields[$field] = $value;
                continue;
            }
            $fields[$field] = mb_substr($value, 0, $limits[$field] ?? 100);
        }

        $awaiting = (string) ($state['awaiting'] ?? '');
        if ($awaiting !== '' && ($fields[$awaiting] ?? '') !== '') {
            $state['awaiting'] = '';
        }
        $state['collectedFields'] = $fields;
        $state['turnCount'] = min(99, ((int) ($state['turnCount'] ?? 0)) + 1);
        return $state;
    }

    /**
     * 突然の庭仕事相談は、確約せず「場所・写真」だけを受付してから人へ渡す。
     * 元の相談は既存の問い合わせ本文にも残るが、アプリの確認画面でも分かるよう
     * conditionへ最大300文字だけ控える。
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    public function beginFlexible(array $state, string $reasonCode, string $inquiry): array
    {
        $state = $this->normalize($state);
        if (($state['status'] ?? '') === 'handoff'
            || !in_array($reasonCode, self::flexibleReasons, true)
        ) {
            return $this->handoff($state, 'AI_INVALID');
        }
        /** @var array<string,string> $fields */
        $fields = $state['collectedFields'];
        if (($fields['condition'] ?? '') === '') {
            $fields['condition'] = mb_substr(trim($inquiry), 0, 300);
        }
        $state['collectedFields'] = $fields;
        $state['reasonCode'] = $reasonCode;
        $state['turnCount'] = min(99, ((int) ($state['turnCount'] ?? 0)) + 1);
        return $state;
    }

    /** @param array<string,mixed> $state */
    public function isFlexible(array $state): bool
    {
        $state = $this->normalize($state);
        return ($state['status'] ?? '') === 'collecting'
            && in_array($state['reasonCode'] ?? '', self::flexibleReasons, true);
    }

    /** @param array<string,mixed> $state */
    public function canBeginFlexible(array $state): bool
    {
        $state = $this->normalize($state);
        return ($state['status'] ?? '') === 'collecting'
            && ($state['reasonCode'] ?? '') === '';
    }

    /** @param array<string,mixed> $state @return list<string> */
    public function flexibleMissing(array $state): array
    {
        $state = $this->normalize($state);
        /** @var array<string,string> $fields */
        $fields = $state['collectedFields'];
        return array_values(array_filter(
            ['region', 'photo'],
            static fn (string $field): bool => ($fields[$field] ?? '') === '',
        ));
    }

    /** @param array<string,mixed> $state */
    public function flexibleReason(array $state): string
    {
        $state = $this->normalize($state);
        $reason = is_string($state['reasonCode'] ?? null) ? $state['reasonCode'] : '';
        return in_array($reason, self::flexibleReasons, true) ? $reason : 'INTAKE_COMPLETE';
    }

    /** @param array<string,mixed> $state @return list<string> */
    public function missing(array $state): array
    {
        $state = $this->normalize($state);
        /** @var array<string,string> $fields */
        $fields = $state['collectedFields'];
        return array_values(array_filter(
            self::fields,
            static fn (string $field): bool => ($fields[$field] ?? '') === ''
        ));
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    public function ask(array $state, string $field): array
    {
        $state = $this->normalize($state);
        if (!in_array($field, $this->missing($state), true)) {
            return $this->handoff($state, 'AI_INVALID');
        }
        $state['awaiting'] = $field;
        $state['questionsAsked'] = ((int) $state['questionsAsked']) + 1;
        return $state;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    public function handoff(array $state, string $reasonCode): array
    {
        $state = $this->normalize($state);
        $state['status'] = 'handoff';
        $state['awaiting'] = '';
        $state['reasonCode'] = in_array($reasonCode, self::reasons, true)
            ? $reasonCode
            : 'STATE_INVALID';
        return $state;
    }

    /** @param array<string,mixed> $state */
    public function reachedQuestionLimit(array $state): bool
    {
        return (int) ($this->normalize($state)['questionsAsked'] ?? 0) >= max(1, min(5, $this->maxQuestions));
    }

    /** @param array<string,mixed> $state @return array{needsHuman:bool,reasonCode:string,collectedFields:array<string,string>} */
    public function metadata(array $state): array
    {
        $state = $this->normalize($state);
        return [
            'needsHuman' => ($state['status'] ?? '') === 'handoff',
            'reasonCode' => (string) ($state['reasonCode'] ?? ''),
            'collectedFields' => $state['collectedFields'],
        ];
    }

    /** @return array{needsHuman:bool,reasonCode:string,collectedFields:array<string,string>} */
    public static function manualMetadata(): array
    {
        return [
            // AI対象外であることと「AIが担当者へ引き継いだ」ことは別。
            'needsHuman' => false,
            'reasonCode' => 'MANUAL_ONLY',
            'collectedFields' => self::emptyFields(),
        ];
    }

    /** @return array<string,mixed> */
    private function initial(): array
    {
        return [
            'version' => 1,
            'status' => 'collecting',
            'awaiting' => '',
            'turnCount' => 0,
            'questionsAsked' => 0,
            'collectedFields' => self::emptyFields(),
            'reasonCode' => '',
            'updatedAt' => '',
        ];
    }

    /** @param array<string,mixed> $raw @return array<string,mixed> */
    private function normalize(array $raw): array
    {
        $state = $this->initial();
        if (($raw['version'] ?? null) !== 1
            || !in_array($raw['status'] ?? null, ['collecting', 'handoff'], true)
        ) {
            return $this->handoffWithoutNormalize($state, 'STATE_INVALID');
        }

        $status = (string) $raw['status'];
        $awaiting = is_string($raw['awaiting'] ?? null) ? $raw['awaiting'] : '';
        if ($awaiting !== '' && !in_array($awaiting, self::fields, true)) {
            return $this->handoffWithoutNormalize($state, 'STATE_INVALID');
        }
        $reason = is_string($raw['reasonCode'] ?? null) ? $raw['reasonCode'] : '';
        if (!in_array($reason, self::reasons, true)) {
            return $this->handoffWithoutNormalize($state, 'STATE_INVALID');
        }

        $fields = self::emptyFields();
        $rawFields = is_array($raw['collectedFields'] ?? null) ? $raw['collectedFields'] : [];
        foreach (self::fields as $field) {
            $value = is_string($rawFields[$field] ?? null) ? $rawFields[$field] : '';
            if ($field === 'photo' && !in_array($value, ['', 'received', 'unavailable', 'promised'], true)) {
                return $this->handoffWithoutNormalize($state, 'STATE_INVALID');
            }
            $fields[$field] = mb_substr($value, 0, $field === 'condition' ? 300 : 100);
        }

        $state['status'] = $status;
        $state['awaiting'] = $awaiting;
        $state['turnCount'] = max(0, min(99, (int) ($raw['turnCount'] ?? 0)));
        $state['questionsAsked'] = max(0, min(5, (int) ($raw['questionsAsked'] ?? 0)));
        $state['collectedFields'] = $fields;
        $state['reasonCode'] = $reason;
        $state['updatedAt'] = is_string($raw['updatedAt'] ?? null) ? $raw['updatedAt'] : '';
        return $state;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function handoffWithoutNormalize(array $state, string $reason): array
    {
        $state['status'] = 'handoff';
        $state['awaiting'] = '';
        $state['reasonCode'] = $reason;
        return $state;
    }

    /** @return array<string,string> */
    private static function emptyFields(): array
    {
        return [
            'region' => '',
            'area' => '',
            'condition' => '',
            'photo' => '',
            'preferredTiming' => '',
        ];
    }

    private function key(string $lineUserId, string $sessionId): string
    {
        return 'reception_' . LineStore::hashKey($lineUserId . "\0" . $sessionId);
    }
}
