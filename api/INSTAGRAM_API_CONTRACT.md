# Instagram連携 API契約（アプリ向け）

Macアプリが保持する秘密は、既存のXserver端末トークンだけです。Meta App Secret、短期・長期InstagramアクセストークンはXserver内だけで扱い、API応答へ含めません。

## 接続

- `POST /api/instagram/workspaces/self`（端末認証必須）
  - 投稿者端末が自分のInstagramを使う場合、QRなしで独立した利用先へ移します。
  - 再試行しても同じ利用先を返し、従来利用者の接続情報とは混ざりません。
- `POST /api/instagram/oauth/start`（管理者端末の認証必須）
  - 応答: `authorizationUrl`, `expiresAt`
  - MacはURLを既定ブラウザで開きます。
- `GET /api/instagram/oauth/callback?code=...&state=...`（Metaからの戻り先）
  - Xserverがコード交換、長期化、投稿先確認、秘密領域への保存を行います。
  - MacへのURL貼り付けやMetaトークン入力は不要です。
  - ブラウザには「連携完了。アプリへ戻る」の案内だけを表示します。
- `GET /api/instagram/account`（端末認証必須）
  - 応答: `configured`, `connected`, `accountName`, `expiresAt`
- `POST /api/instagram/oauth/refresh`（管理者端末の認証必須）
- `POST /api/instagram/disconnect`（管理者端末の認証必須）

## 3人で同じ公式アカウントを使う

- 管理者本人は管理者用合言葉で端末を登録し、連携設定と投稿の両方を行います。
- 谷口さんと奥さまは投稿者用合言葉で、それぞれの端末を別々に登録します。
- 投稿者端末は準備・確認・投稿ができますが、OAuthの開始・更新・解除はできません。
- 下書きと投稿結果には端末登録時の名前を `preparedBy` / `publishedBy` として返します。

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

- `RELAGARDEN_API_PAIRING_CODE`（投稿者用。16文字以上）
- `RELAGARDEN_API_ADMIN_PAIRING_CODE`（管理者用。16文字以上、投稿者用とは別）
- `RELAGARDEN_INSTAGRAM_APP_ID`
- `RELAGARDEN_INSTAGRAM_APP_SECRET`

Repository VariablesにはMeta画面で確認した現行値を登録します。

- `RELAGARDEN_INSTAGRAM_GRAPH_API_VERSION`

リダイレクトURIは `https://relagarden.jp/api/instagram/oauth/callback` に固定し、Meta開発者画面へ同じ文字列を登録します。
