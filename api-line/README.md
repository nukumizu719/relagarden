# 公式LINE 受信API／本人限定AI返信テスト

公式LINEに届いたお問い合わせを受け取り、iPhoneアプリへ渡すAPIです。
初期状態では受信専用です。明示的に2つの設定を有効にした場合だけ、
許可した本人1人へ、よし管理AI Gatewayで作った返信を返せます。
また、別の本人限定テスト設定を有効にした場合だけ、iPhoneアプリで
宛先と文面を最終確認した文字返信を本人1人へ送れます。

```text
お客様
  → 公式LINE
LINEプラットフォーム（Webhook）
  → https://relagarden.jp/api/line/webhook   ← このフォルダー
Xserver の private/line-storage（public_htmlの外）
  → https://relagarden.jp/api/line/inbox
iPhoneアプリ「リラガーデン」
```

## このAPIがしないこと

**施工事例のホームページ掲載には一切関わりません。**
掲載は今までどおり iPhoneアプリ → GitHub → GitHub Actions → Xserver の経路です。
このフォルダーには `/publish` `/status` `/unpublish` はありません
（無いことを `api-line/tests/run.php` で毎回確かめています）。

- **初期状態では返信を送りません。** 自動AI返信とアプリの確認後送信は別々に停止しています。
- **アプリの確認後送信は本人限定テストだけです。** 許可した1つのuserIdへ、
  アプリで宛先と本文を確認して送信を押した文字1件だけをプッシュ送信します。
  一斉配信・複数宛先・画像送信は行いません。
- **お客様の本名・電話番号・住所を推測しません。** 空欄のまま渡し、谷口さんが後から入れます。
- **写真・スタンプの中身を取りに行きません。** 1対1の写真は到着情報だけを受信箱へ残し、
  スタンプなどは「二度処理しない印」だけ残して読み捨てます。
- **既読を付けたり、一斉送信をしたりしません。**
- ClaudeのAPIキーを持ちません。AI利用時も、よし管理AI Gateway用の専用トークンだけを使います。

## 入口

| メソッド | 入口 | 用途 | 合言葉 |
| --- | --- | --- | --- |
| POST | `/api/line/webhook` | LINEからの配信を受ける | 不要（署名で確認） |
| GET | `/api/line/inbox` | まだ取り込んでいない問い合わせを渡す | 必要 |
| POST | `/api/line/sync` | 取り込めたものへ受け取り済みの印を付ける | 必要 |
| GET/POST | `/api/line/reception/mode` | 本人限定受付AIのON/OFFを確認・変更 | 必要 |
| POST | `/api/line/send` | アプリで最終確認した本人向け文字返信を1件送る | 必要 |

決めた入口以外、想定しないメソッドはすべて断ります。

## 動くために必要なもの

- PHP 8.1以上（`curl` `json` `mbstring`）
- Composerは不要、データベースも不要

## 設置のしかた（**まだ実施していません**）

⚠️ 本番へ置く操作は、谷口さんの承認を得てから行います。
この時点ではコードとテストだけが用意されている状態です。

### 1. ファイルを置く

```text
/home/<アカウント>/relagarden.jp/
├── public_html/
│   └── api/
│       └── line/          ← api-line/public/ の中身をここへ
│           ├── index.php
│           └── .htaccess
├── api-line-src/          ← api-line/src/ をここへ（public_html の外）
└── private/
    ├── line-config.php    ← 2で作る（public_html の外）
    └── line-storage/      ← 自動で作られる
```

⚠️ **Web更新のrsyncに注意。** `.github/workflows/deploy.yml` の
`--delete` は `public_html/` の中で、リポジトリに無いものを消します。
`public_html/api/` を除外していないと、次のホームページ更新で
この入口が消えます。設置の前に、除外の追加か、この2つのフォルダーを
リポジトリから配信する形にするかを決めてください。

### 2. 設定ファイルを作る

`api-line/private/line-config.example.php` をコピーして、
`/home/<アカウント>/relagarden.jp/private/line-config.php` として保存します。

| 項目 | 何を入れるか |
| --- | --- |
| `channel_secret` | LINE Developersの「チャネルシークレット」 |
| `channel_access_token` | 「チャネルアクセストークン（長期）」。空でも動く（表示名が空欄になる） |
| `inbox_token` | iPhoneアプリと共有する合言葉。**`openssl rand -hex 32`（64文字）** で作る |

本人限定AI返信テストを行う場合だけ、さらに次を設定します。

| 項目 | 何を入れるか |
| --- | --- |
| `ai_reply_enabled` | テスト開始直前だけ `true`。通常は `false` |
| `ai_reply_test_mode` | 本人限定中は必ず `true`。`false`なら返信しない |
| `ai_reply_runtime_control_required` | 必ず `true`。アプリ側のスイッチもONのときだけ返信 |
| `ai_reply_allowed_user_id` | よしさん本人のLINE userId 1件だけ |
| `ai_reply_session_id` | 本人限定テストの識別子。同じ値では受付状態を引き継ぐ |
| `ai_reception_max_questions` | 本人限定テストは `5`。受付5項目の確認後に担当者へ引継ぎ |
| `ai_gateway_base_url` | よし管理AI Gatewayの `/v1` URL |
| `ai_gateway_token` | LINE受信サーバー専用にペアリングしたGatewayトークン |

本人限定AI受付は最大5質問です。価格、日程確定、値引き、契約・解約・支払い、
クレーム・返金・損害・法律・安全・緊急に関する文はAIへ渡さず、担当者確認の
固定文を1回だけ返して停止します。通常の受付は地域・広さ・現状・写真・希望時期を
一連で確認した後、担当者へ引き継ぎます。
初回は短い挨拶か明白な庭仕事の相談だけ、質問中は地域・広さ・現状・写真可否・
希望時期それぞれの決まった回答形式だけをAIへ渡します。質問、依頼、施工可否、
修理、当日訪問など形式外の文はAIへ渡さず、固定文で担当者へ引き継ぎます。
受付状態は地域・広さ・現状・写真・希望時期を持ち、AIは未取得項目のうち
「次にどれを聞くか」だけを選びます。写真は到着情報だけを保存し、画像本体は
取得しません。受信箱には `needsHuman`、`reasonCode`、`collectedFields` を返します。
AI対象外のお客様は `reasonCode: MANUAL_ONLY`、`needsHuman: false` で、AIが担当者へ
引き継いだ状態とは区別します。
お客様へ送る文章は、サーバーに用意した短い受付定型文から選ぶため、AIが
自由に作った金額・約束・指示をそのまま送信することはありません。

担当者対応後に同じ本人の現在の受付だけを再開する場合は、認証済みの
`POST /api/line/reception/reset` へ `lineUserId` と `confirmed: true` を送ります。
受信箱と同じBearer合言葉が必要で、設定済みの本人userIdと完全一致した場合だけ、
現在の `ai_reply_session_id` の停止状態を初期化します。過去sessionや他のお客様、
受信箱の記録、日次回数は消しません。

会話途中から担当者が対応する場合は、認証済みの
`POST /api/line/reception/handoff` へ `lineUserId` と `confirmed: true` を送ります。
許可した本人userIdの現在の会話だけを停止し、それ以降はAIが自動返信しません。

テスト開始前に、LINE Official Account Managerの「応答メッセージ」と固定の
自動応答をOFFにしてください。ONのままでは、固定文とAI受付文が二重に届きます。
友だち追加時のあいさつを使う場合も、テスト用の文と重ならないことを確認します。

アプリで最終確認した後の本人限定送信を行う場合だけ、さらに次を設定します。

| 項目 | 何を入れるか |
| --- | --- |
| `manual_send_enabled` | テスト開始直前だけ `true`。通常は `false` |
| `manual_send_test_mode` | 本人限定中は必ず `true`。`false`なら送信しない |
| `manual_send_allowed_user_id` | よしさん本人のLINE userId 1件だけ |

`/send` は `confirmed: true`、許可userIdの完全一致、重複しないrequestIdを
すべて確認します。AI文章作成はこの入口では行いません。

⚠️ GitHubのPAT・Xserverの管理パスワードは使わないでください。
LINE用の値だけを入れます。

### 3. LINE側の設定（承認後に行う操作）

1. LINE Developers → Messaging APIチャネル → Webhook URL に
   `https://relagarden.jp/api/line/webhook` を入れる
2. 「検証」を押して成功することを確かめる
3. Webhookの利用をオンにする
4. あいさつ・営業時間内外の応答は、LINE Official Account Manager の応答設定で管理する

## 保存するもの・しないもの

| 保存する | 保存しない |
| --- | --- |
| webhookEventId / messageId（二重処理を防ぐ印） | チャネルシークレット |
| 手動送信requestIdのSHA-256と送信状態（二重送信を防ぐ印） | 手動送信した本文・送信先userId |
| lineUserId（お客様を結び付けるため。外へは出さない） | チャネルアクセストークン |
| LINE表示名 | 返信トークン（replyToken） |
| 問い合わせ本文 | プロフィールの表示名以外の項目 |
| 受付状態と収集済み5項目（private領域） | 写真・動画の中身 |
| 受信日時 | リクエストヘッダー |
| 取り込み状態・取り込み日時 | GitHubのPAT |

未取り込みの問い合わせは、日数が経っても消しません。
**取り込み済みのものだけ**、既定30日（`keep_days`）を過ぎたら片付けます。

## 元に戻す方法

1. LINE Developersで「Webhookの利用」をオフにする（これだけで受信は止まります）
2. `public_html/api/line/` を削除する
3. `api-line-src/` と `private/line-config.php` を削除する

LINE公式アカウント側の自動応答設定には影響しません。
ホームページの掲載経路にも影響しません。

## 安全のためにしていること

| 対策 | 内容 |
| --- | --- |
| なりすましを弾く | `X-Line-Signature` をチャネルシークレットで検証。合わない配信は中身を読まずに捨てる |
| 時間差での推測を防ぐ | 署名も合言葉も `hash_equals` で比べる |
| 二重処理を防ぐ | `webhookEventId` と `messageId` の両方に印を残し、再送を弾く |
| 既存顧客へAI返信しない | TEST_MODEと許可userIdの完全一致を両方満たしたときだけ動く |
| 既存顧客へアプリから誤送信しない | 手動送信専用TEST_MODE、許可userId、`confirmed: true`がすべて揃った場合だけ動く |
| AI障害時も問い合わせを残す | 先に受信箱へ保存し、GatewayやLINE返信が失敗しても200を返して手動対応へ戻す |
| 自動AI受付の誤送信を抑える | replyToken返信だけを使い、受付5項目＋引継ぎで停止。途中解除も本人完全一致と明示確認が必要 |
| 手動送信の二重送信を抑える | アプリ確認後の本人向け1件だけをpushし、requestIdを原子的に確保。本人も1日20回で停止 |
| 秘密を公開領域へ置かない | 設定も保存データも `public_html` の外 |
| 記録に個人情報を残さない | LINEのユーザーIDと本文は記録へ書かない。念のため伏せ字も掛ける |
| 総当たりを防ぐ | 受信箱の読み出しに回数制限。Webhookには掛けない（取りこぼしを防ぐため） |
| 取りこぼしを防ぐ | 消すのは「アプリが取り込み済み」のものだけ |
| 内部の事情を返さない | 外へは短い日本語だけ |
| 記録に何も残さない | 記録へ書くのは決まったコードと件数だけ。本文・ユーザーID・ファイルの場所・例外の文面・秘密は書かない |
| 握りつぶさない | 保存できなかったときは200を返さない（500）。LINEの再送に任せて、問い合わせを失わない |
| 先に本文を残す | 本文を保存してから「二度処理しない印」を付ける。印だけ失敗したら、再送時に印を付け直す |
| 鍵に生のIDを使わない | 保存の名前はSHA-256。記号だけ違うIDが同じ鍵に潰れない。細工した文字でフォルダーの外へ出られない |
| 途中の書き込みを見せない | 一時ファイルの名前は毎回変え、書けてから差し替える |
| 数え落とさない | 回数制限の読み書きは鍵をかけて一度に行う |
| 暗号化されていない通信を受けない | HTTPS以外は403（`require_https`） |
| 置き場所を確かめてから動く | 公開領域を指していたら起動しない。作れない・読めない・書けない場所でも起動しない |
| 取り込み済みの検証 | `/sync` は本文の大きさ・番号の数(200)・番号の長さを確かめる。印を書けなければ500 |
| 壊れた内容を弾く | JSONとして読めない・`events` が配列でない配信は400 |
| 大きすぎる本文を弾く | 既定512KBを超えたら413 |
| 異常な量を弾く | Webhookは既定600回/時。正規の配信を落とさない大きさにし、超えたら429（LINEが再送） |

## テスト

```sh
php api-line/tests/run.php
```

本物のLINEへはつながりません。差し替え可能な `FakeLineProfile` と
自前で作った署名で、受信から取り込みまでの流れを確かめられます。

手元のPHPで実際のHTTPを通して確かめることもできます。

```sh
php -S 127.0.0.1:8765 -t api-line/public
```

（`RELAGARDEN_LINE_CONFIG` に確認用の設定ファイルを、
`RELAGARDEN_LINE_SOURCE` に `api-line/src` の場所を渡してください。）
