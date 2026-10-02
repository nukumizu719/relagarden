# 公式LINEの自動登録：本番設定と引き継ぎ

このファイルは、**本番の設定をする人**（Codex・谷口さん）へ渡す手順書です。
サーバーは受信専用です。お客様への自動応答はLINE公式アカウント側で管理します。

関わるもの

| どこ | 何 |
| --- | --- |
| このリポジトリ | `api-line/`（LINE受信専用API）、`.github/workflows/deploy.yml` |
| iPhoneアプリ | `relagarden-iphone-app` の `feature/line-inbox` |
| Xserver | `public_html/api/line/`、`api-line-src/`、`private/line-config.php`、`private/line-storage/` |
| LINE Developers | Messaging APIチャネルの Webhook 設定 |

**掲載（施工事例）の経路には触れていません。** iPhone → GitHub → GitHub Actions →
Xserver のままです。`/publish` `/status` `/unpublish` `/pairing` はこのAPIにありません。

---

## いまの状態

| 段階 | 状態 |
| --- | --- |
| 1. 実装済み | 済み |
| 2. Fakeテスト済み | 済み |
| 3. Xserver設置済み | 済み |
| 4. 本番Webhook受信済み | 済み |
| 5. iPhone実機で自動登録済み | 確認時点の実機状態を別途確認する |

---

## 設置の手順（承認後に実施）

### ① ファイルを置く

```text
/home/<アカウント>/relagarden.jp/
├── public_html/
│   └── api/
│       └── line/          ← api-line/public/ の中身
│           ├── index.php
│           └── .htaccess
├── api-line-src/          ← api-line/src/ の中身（public_html の外）
└── private/
    ├── line-config.php    ← ②で作る（public_html の外）
    └── line-storage/      ← 自動で作られる
```

`public_html/api/line/` は、2段階のrsyncから保護します。
ホームページ更新では `api/` 全体を除外し、通常API更新では `line/` だけを
除外します。通常APIは更新・掃除しつつ、LINE受信口だけを残します。
除外が効いているかは手元で確かめられます。

```sh
sh scripts/deploy-exclude-test.sh
```

### ② 設定ファイルを作る

`api-line/private/line-config.example.php` をコピーして
`/home/<アカウント>/relagarden.jp/private/line-config.php` へ。

| 項目 | 入れる値 |
| --- | --- |
| `channel_secret` | LINE Developers のチャネルシークレット |
| `channel_access_token` | チャネルアクセストークン（長期）。空でも動く（表示名が空になる） |
| `inbox_token` | **`openssl rand -hex 32`**（64文字）で作る。iPhoneアプリへ同じ値を入れる |

本人限定のアプリ送信テストを始める場合だけ、GitHubに次を追加します。

| 種類 | 名前 | 値 |
| --- | --- | --- |
| Variable | `RELAGARDEN_LINE_MANUAL_SEND_ENABLED` | テスト開始直前だけ `true` |
| Variable | `RELAGARDEN_LINE_MANUAL_SEND_TEST_MODE` | 本人限定中は必ず `true` |
| Secret | `RELAGARDEN_LINE_MANUAL_SEND_ALLOWED_USER_ID` | よしさん本人のLINE userId 1件だけ |

3項目が揃わない場合、送信機能は有効になりません。送信OFFの間は、Secretが
残っていても生成する本番設定へuserIdを入れません。AI自動返信の設定は
この手順では常にOFFです。最初の本人限定テストは1日1通で停止します。

`inbox_token` は **64文字以上が必須** です。これより短いと起動を断り、
すべての入口が `503`（ただいま準備中です）を返します。
`openssl rand -hex 32` の出力がちょうど64文字なので、そのまま貼ってください。

GitHubのPAT・Xserverの管理パスワードは使わないこと。

### ③ 動作確認（LINEにつなぐ前）

```sh
curl -s -o /dev/null -w '%{http_code}\n' https://relagarden.jp/api/line/inbox
# → 401（合言葉が無い）が返れば設置できています
```

`503` は設定ファイルが読めていない、`404` は置き場所か .htaccess の問題です。

`403` が返る場合は、暗号化されていない通信として扱われています。
このAPIは `require_https` が `true` のとき、HTTPS以外を受け付けません。
**本番の設定は `true` のままにしてください。** `false` にして回避しないこと
（Webhookの本文にはお客様の文章が入るため、平文で流してはいけません）。

`403` のときに確かめるところ:

- そのURLへHTTPSでアクセスしているか（`http://` になっていないか）
- XserverでSSLが有効になっているか
- 前段でSSLを終端している場合、PHPへ `HTTPS` か `X-Forwarded-Proto: https`
  のどちらかが渡っているか（このAPIはその両方を見ます）

### ④ LINE Developers の設定

1. Messaging APIチャネル → Webhook URL に
   `https://relagarden.jp/api/line/webhook`
2. 「検証」を押す → 成功すること
3. 「Webhookの利用」をオン
4. あいさつ・営業時間内外の応答は、LINE Official Account Manager の応答設定で管理する

### ⑤ iPhone側

1. アプリを `feature/line-inbox` の版へ更新
2. 設定 →「公式LINEの受信」→ `inbox_token` と同じ合言葉を入れて保存
3. ホーム →「LINE新着を確認」

## 元に戻す手順

1. LINE Developers で「Webhookの利用」をオフ ← 受信を止める場合
2. `public_html/api/line/` を削除
3. `api-line-src/` と `private/line-config.php` を削除

掲載経路には影響しません。
アプリに取り込み済みのお客様と履歴は、iPhoneの中に残ります。

---

## Codexにお願いしたい実機確認

**サーバー**

- [ ] `GET /api/line/inbox`（合言葉なし）→ 401
- [ ] LINE Developers の「検証」→ 成功
- [ ] `private/line-config.php` と `private/line-storage/` がブラウザーから見えない
      （`https://relagarden.jp/private/line-config.php` が 403/404）
- [ ] ホームページを1回更新して、`public_html/api/line/` が消えないこと
- [ ] 施工事例11件・トップ・一覧が今までどおり表示されること

**LINE**

- [ ] 自分のスマホから公式LINEへ1通送る → 今までどおり自動返信が返る
- [ ] 同じ内容が二重に登録されない
- [ ] 写真だけ送っても、お客様が増えない（文字だけが対象）

**iPhoneアプリ**

- [ ] 「LINE新着を確認」で取り込める
- [ ] はじめての方が「自動登録・内容確認待ち」で入る
- [ ] 本名・電話番号・住所が空欄になっている
- [ ] アンケートに「自動入力・要確認」が出る
- [ ] 2通目が履歴に足され、お客様が増えない
- [ ] 名前を入れて保存すると「要確認」が外れる
- [ ] 機内モードで押しても、お客様と写真が減らない
- [ ] 更新前に入っていたお客様・施工事例・写真の件数が変わらない（更新前に控えを取る）

---

## 料金上の注意

- Xserver：いまの契約のまま。PHPのみ。データベースを使わない
- LINE：Webhookの受信は無料。サーバーからメッセージは送信しない
- 外部サービス：AI・OpenAI・ngrok・監視サービスのいずれも使わない

## LINE公式アカウント側の応答と共存できる理由

このAPIは受け取って控えるだけで、LINEの送信APIを呼びません。
Webhookの有効化は「届いた内容の写しを受け取る」設定で、応答設定とは別です。
