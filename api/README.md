# 施工事例 投稿API

iPhoneアプリ「リラガーデン」から施工事例を受け取り、
このリポジトリへ追加するためのAPIです。

```text
iPhoneアプリ
  → HTTPS
Xserver の PHP API（このフォルダー）
  → GitHub API
このリポジトリ（src/content/cases/ と src/assets/works/）
  → GitHub Actions（既存の deploy.yml）
Xserver public_html
  → ホームページ公開
```

**秘密情報はこのフォルダーのどこにもありません。**
GitHubのトークンも合言葉も、Xserverの `public_html` の外に置いた
`private/config.php` から読みます。

## 動くために必要なもの

- PHP 8.1以上（`curl` `json` `mbstring` `fileinfo` `gd`）
- Composer は不要です

## 設置のしかた

### 1. ファイルを置く

```text
/home/xs674757/relagarden.jp/
├── public_html/
│   └── api/              ← api/public/ の中身をここへ
│       ├── index.php
│       └── .htaccess
├── api-src/              ← api/src/ をここへ（public_html の外）
└── private/
    ├── config.php        ← 2で作る
    └── storage/          ← 自動で作られる
```

`main` へ反映された後は既存のGitHub Actionsが、`api/public/` を
`public_html/api/` へ、`api/src/` を公開領域外の `api-src/` へ配置します。
Webサイトの同期では `api/` を削除対象から除外するため、次回のWeb更新でも
APIが消えることはありません。

`private/config.php` と `private/storage/` は自動配信・削除の対象外です。
秘密情報はXserver側で一度だけ設定してください。

### 2. 設定ファイルを作る

`api/private/config.example.php` をコピーして、
`/home/xs674757/relagarden.jp/private/config.php` として保存し、
次の2つを書き込みます。

| 項目 | 何を入れるか |
| --- | --- |
| `github_token` | GitHubのFine-grained PAT（このリポジトリのContents書き込みだけ） |
| `pairing_code` | 投稿者のiPhoneと連携するときの合言葉（8文字以上） |
| `admin_pairing_code` | 管理者本人のiPhoneと連携するときの別の合言葉（8文字以上） |

⚠️ **GitHubやXserverの管理パスワードは使わないでください。**
この用途だけの限定キーにしてください。

### 3. 動くか確かめる

```sh
curl -sS -X POST https://relagarden.jp/api/pairing \
  -H 'Content-Type: application/json' \
  -d '{"pairingCode":"（合言葉）","deviceName":"test"}'
```

`{"ok":true,"token":"..."}` が返れば設置できています。
設定ファイルが無い場合は `{"ok":false,"message":"ただいま準備中です"}` を返します。

## 入口

| メソッド | 入口 | 用途 |
| --- | --- | --- |
| POST | `/api/pairing` | 端末の初回連携。合言葉と引き換えに端末専用トークンを返す |
| POST | `/api/publish` | 施工事例の投稿。要トークン |
| POST | `/api/unpublish` | このAPIで掲載した施工事例と専用画像の削除。要トークン |
| GET | `/api/status?caseId=...` | 投稿の状態確認。要トークン |
| POST | `/api/unpair` | 連携の解除。要トークン |

決めた入口以外、想定しないメソッドはすべて断ります。

## 安全のためにしていること

| 対策 | 内容 |
| --- | --- |
| アプリに秘密を置かない | 初回だけ合言葉を入れ、端末専用トークンを発行。アプリ本体には何も埋め込まない |
| トークンを平文で持たない | サーバーにはハッシュだけを保存。ファイルが漏れても元に戻せない |
| 時間差での推測を防ぐ | 合言葉とトークンの照合は `hash_equals` |
| 総当たりを防ぐ | 連携の試行と投稿に回数制限 |
| 写真の偽装を見抜く | 申告ではなく中身を読んで判定。画像として開けるかも確認 |
| ファイル名の細工を防ぐ | 送られてきた名前は使わず、こちらで組み立てる |
| 番地を出さない | サーバー側でも市区町村までに丸める。アプリ任せにしない |
| 二重投稿を防ぐ | 同じ記事名・同じ事例IDを断る。上書きもしない |
| 削除対象を限定 | APIが掲載時に記録した記事と、その記事名で始まる専用画像だけを削除 |
| 秘密を記録に残さない | 記録へ書く前にトークンらしき文字を伏せる |
| 内部の事情を返さない | 利用者へは短い日本語だけ。詳細は記録の側 |

## テスト

```sh
php api/tests/run.php
```

本物のGitHubへはつながりません。差し替え可能な `FakeGitHubClient` を使うので、
トークンが無くても投稿の流れ全体を確かめられます。

## 記事の形について

`CaseMarkdown::build()` が作る記事の形は、
`scripts/add-before-after-case.mjs` と同じです。
**片方だけ変えないでください。** 形を変えるときは両方を直し、
`api/tests/run.php` で確かめてください。
