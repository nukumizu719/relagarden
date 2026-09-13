# Instagram連携 API契約（Macアプリ向け）

Macアプリが保持する秘密は、既存のXserver端末トークンだけです。Meta App Secret、短期・長期InstagramアクセストークンはXserver内だけで扱い、API応答へ含めません。

## 接続

- `POST /api/instagram/oauth/start`（端末認証必須）
  - 応答: `authorizationUrl`, `expiresAt`
  - MacはURLを既定ブラウザで開きます。
- `GET /api/instagram/oauth/callback?code=...&state=...`（Metaからの戻り先）
  - Xserverがコード交換、長期化、投稿先確認、秘密領域への保存を行います。
  - MacへのURL貼り付けやMetaトークン入力は不要です。
  - ブラウザには「連携完了。アプリへ戻る」の案内だけを表示します。
- `GET /api/instagram/account`（端末認証必須）
  - 応答: `configured`, `connected`, `accountName`, `expiresAt`
- `POST /api/instagram/oauth/refresh`（端末認証必須）
- `POST /api/instagram/disconnect`（端末認証必須）

## 投稿

既存の二段階確認を維持します。

1. `POST /api/instagram/prepare` は下書きと一時画像だけを作り、投稿しません。
2. `GET /api/instagram/status?draftId=...` で準備完了を確認します。
3. 人間が投稿先、写真、本文を確認して「Instagramへ投稿」を押します。
4. `POST /api/instagram/publish` だけが実投稿を行います。
5. 不明な通信結果は成功・失敗へ決めつけず `publishing` のまま確認します。

## Mac側で廃止するもの

- App ID / App Secret / InstagramユーザーIDの入力欄
- コールバックURLや認可コードの貼り付け欄
- InstagramアクセストークンのKeychain保存
- MacからMeta Graph APIへの直接通信

これらを削除する前に、Xserver側の接続APIが本番で利用可能であることを確認してください。

## 本番の設定名

GitHub Actionsの暗号化Secretsに次を登録します。値はコード、PR、ログへ書きません。

- `RELAGARDEN_INSTAGRAM_APP_ID`
- `RELAGARDEN_INSTAGRAM_APP_SECRET`

Repository VariablesにはMeta画面で確認した現行値を登録します。

- `RELAGARDEN_INSTAGRAM_GRAPH_API_VERSION`

リダイレクトURIは `https://relagarden.jp/api/instagram/oauth/callback` に固定し、Meta開発者画面へ同じ文字列を登録します。
