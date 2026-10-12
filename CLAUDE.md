# リラガーデン（relagarden.jp） — Claude Code 作業ガイド

## このリポジトリについて

人工芝施工「リラガーデン」の公式ホームページ。本番URL: https://relagarden.jp/

Astro + Tailwind の静的サイト。main への push で GitHub Actions（`.github/workflows/deploy.yml`）が
ビルドし、Xserver へ rsync する。

**このリポジトリは Public で、共同開発者がいる。**

- brainyosi11-collab さん・桐生さんが `api/`（iPhoneアプリからの施工事例投稿API）と
  `api-line/`（公式LINEの受信API）を開発している
- **iPhoneアプリから事例を投稿すると、API が GitHub API 経由で main に直接コミットする。**
  そのため main は手元の知らないうちに進む。作業を始める前に必ず `git switch main && git pull` する

## 絶対原則

1. **施工事例に費用（cost）を載せない。** 事例ごとの金額は整合が取れておらず、公開すると
   見積りの基準にされてしまうため、2026年9月にスキーマから廃止した（`src/content/config.ts` 参照）。
   `.cursorrules` や `docs/BEFORE_AFTER_UPLOAD_GUIDE.md` には `cost` が残っているが古い記述。
2. **施工実績の数字（広さ・工期・日付）や、お客様の悩み・声を創作しない。**
   裏付けがないものは書かずにユーザーに確認する。`beforeConcerns` と `customerVoice` は
   材料がなければ省略してよい。
3. **お客様の氏名・住所・連絡先を載せない。** 事例は市区町村名までにとどめる。
   写真に顔・表札・車のナンバー・住所が写っていないか確認する。
4. **秘密情報をコミットしない。** `.env`（microCMS のキー）、`api/private/config.php`、
   `api-line/private/line-config.php` は gitignore 済み。本番の設定は GitHub Secrets から
   デプロイ時に組み立てている。
5. **iPhoneアプリ経由で入った事例（`case-YYYYMMDD-HHMM.md`）はリネームしない。**
   ファイル名がURLであり、本文の `relagarden-app-case-id` コメントでアプリ側と紐づいている。

## ディレクトリ

- `src/content/cases/*.md` … 施工事例。画像は `src/assets/works/`
- `src/content/news/*.md` … 新着情報（カテゴリーは `お知らせ` `イベント` `ブログ` のみ）
- `src/content/config.ts` … 上記2つの frontmatter スキーマ。正確な項目はここを見る
- `src/components/` … トップページの各セクション（`Top*.astro`）・ヘッダー・フッター
- `src/layouts/BaseLayout.astro` … 共通の head・OGP
- `src/pages/` … ページ（cases / news は一覧・ページ送り・詳細）
- `scripts/add-before-after-case.mjs` … `npm run case:add` で Before/After 事例を追加する
- `api/` `api-line/` … Xserver 上で動く PHP。共同開発者の担当範囲
- `scripts/detect-deploy-targets.sh` … 変更内容から API・LINE を置き直すかを判定する
- `docs/` … 事例追加・LINE受付の手順書

ブランチ `wip/microcms` に、施工事例・新着情報を microCMS へ移す作業の途中版を退避してある
（2026年10月12日）。iPhoneアプリの投稿（Markdown をコミットする方式）と方針がぶつかるため、
進めるときはユーザーに確認する。

## 作業手順

gh CLI が利用可能（アカウント nukumizu719-cpu）。PR作成までコマンドで実行する。

リポジトリは組織 `nukumizu719/relagarden` に移管済み。手元の origin が旧URL
（`nukumizu719-cpu/relagarden`）のままだと `gh pr create` が失敗するため、
gh の PR 操作には `--repo nukumizu719/relagarden` を付ける。

### すべての変更に共通

1. `git switch main && git pull` で最新にする
2. 内容が分かるブランチを切る（例: `content/news-2026-10-campaign` `fix/top-works-layout`）
3. 変更する
4. `npm run build` でビルドが通ることを確認する。見た目の変更はプレビュー（`.claude/launch.json` の
   `relagarden-dev`）で、スマホ幅とPC幅の両方を確認する
5. `git status` で `.env` や `private/` が含まれていないことを確認してコミット → push
6. `gh pr create` でPRを作成する
7. **PRのURLを報告して停止する。** 共同開発者がいるため、マージはユーザーの指示があってから行う

### マージの指示を受けたら

1. `gh pr merge <番号> --merge` でマージする（squash・rebase はしない）
2. `gh run watch` で GitHub Actions の「Deploy to Xserver」の完了を確認する
3. 本番URLを開き、変更が反映されていることを確認する
4. `git switch main && git pull` でローカルを同期する
5. 結果を報告する

### 共通の注意

- `.github/workflows/` `api/` `api-line/` `scripts/detect-deploy-targets.sh` を変更する場合は、
  必ず事前にユーザーに確認を取る。本番の API と公式LINEの受信に直結する
- 不具合が出た場合は修正PRを立てるか、GitHub上のRevertで戻す。強制pushはしない
- main に直接コミット・push しない

## やってはいけないこと

- 施工事例への費用の掲載
- 実際の記録に基づかない施工実績・お客様の声の作成
- 秘密情報（`.env`・`private/` 配下・トークン）のコミットや表示
- 確認なしでの `.github/workflows/` `api/` `api-line/` の変更
- main への直接 push・強制 push
