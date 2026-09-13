<?php
/**
 * 本番用の設定の見本。
 *
 * この見本をコピーして、Xserverの **public_html の外** へ置く。
 *   /home/xs674757/relagarden.jp/private/config.php
 *
 * 本物の config.php は絶対にGitへ入れない（.gitignore 済み）。
 * ここに書く値は、GitHubやXserverの管理パスワードではなく、
 * この用途だけの限定キーにすること。
 */

return [
    // ── GitHub（ホームページ掲載。**使わないなら空のままでよい**）──
    //
    // 空にしておくと、掲載の入口（/publish /status /unpublish）だけが
    // 「ただいま準備中です」で止まる。
    // **端末の連携（/pairing）とInstagramの入口は、そのまま使える。**
    // Fine-grained personal access token
    //   対象リポジトリ: nukumizu719-cpu/relagarden のみ
    //   権限: Contents = Read and write だけ
    // 期限が切れたらここを差し替える。
    'github_token' => 'ここへFine-grained PATを貼る',
    'github_owner' => 'nukumizu719-cpu',
    'github_repo'  => 'relagarden',
    'github_branch' => 'main',

    // ── 端末の連携（**ここだけは必須**）────────────────────────
    // 投稿担当者のiPhoneアプリを初回に連携させるための合言葉。
    // 谷口さん・奥さまへ口頭など安全な方法で伝える。定期的に変えてよい。
    // 8文字以上。ここもGitHub/Xserverのパスワードとは別物にする。
    'pairing_code' => 'ここへ8文字以上の合言葉',

    // 管理者本人の端末だけに使う別の合言葉。
    // InstagramのOAuth開始・更新・連携解除は、この合言葉でつないだ端末だけが行える。
    // 投稿者用と同じ値にしない。未設定なら従来互換でpairing_codeが管理者になる。
    'admin_pairing_code' => 'ここへ8文字以上の管理者用合言葉',

    // ── 保存場所 ──────────────────────────────────────────────
    // 端末トークン・投稿状況・記録の置き場所。
    // public_html の外にすること（Webから読まれないため）。
    'storage_dir' => __DIR__ . '/storage',

    // ── 制限 ─────────────────────────────────────────────────
    // 1枚あたりの画像サイズ（バイト）
    'max_image_bytes' => 8 * 1024 * 1024,
    // 1回の投稿で受け取る画像の枚数
    'max_images' => 12,
    // 投稿全体のサイズ（バイト）
    'max_request_bytes' => 40 * 1024 * 1024,
    // 1端末あたり、この秒数の間に投稿できる回数
    'rate_window_seconds' => 3600,
    'rate_max_publishes' => 10,
    // 1端末あたり、この秒数の間に掲載削除できる回数
    'rate_max_unpublishes' => 5,
    // ペアリングの試行回数（同じIPから）
    'rate_max_pairings' => 5,

    // 公開されるサイトのURL。掲載後のリンクを組み立てるのに使う。
    // Instagramへ渡す一時画像のURLも、これを土台に作る。
    'site_base_url' => 'https://relagarden.jp',

    // ── Instagram実験（未設定なら Instagram の入口は動かない）──────
    //
    // **ここに書く値は、この見本ファイルには絶対に入れない。**
    // 本物は public_html の外の config.php にだけ書く。
    //
    // Meta開発者画面のInstagram App ID。
    'instagram_app_id' => '',

    // Meta開発者画面のApp Secret。Macアプリ・GitHub・公開領域へ置かない。
    'instagram_app_secret' => '',

    // Meta開発者画面にも同じ文字列を登録するコールバックURL。
    // 例: 'https://relagarden.jp/api/instagram/oauth/callback'
    'instagram_redirect_uri' => '',

    // 旧方式からの安全な移行用。新規連携では空のままにし、OAuth後に
    // public_html外へ自動保存された長期トークンを使う。
    'instagram_access_token' => '',

    // Instagramのユーザー番号（数字）。@から始まる名前ではない。
    'instagram_user_id' => '',

    // Graph APIのバージョン。例: 'v23.0'
    // **推測で入れないこと。** Metaの管理画面か公式資料で、
    // つなぐ直前に必ず現行のものを確認して入れる。
    // ここが空の間、Instagramの入口は「準備中」を返す。
    'instagram_graph_api_version' => '',

    // アプリの画面に出す投稿先の名前。取り違え防止のために表示する。
    // 例: 'your_instagram_name'（本物はここではなく config.php へ書く）
    'instagram_account_name' => '',

    // OAuth stateの有効期間と、1端末あたりの開始回数。
    'instagram_oauth_state_ttl_seconds' => 600,
    'rate_max_instagram_oauth_starts' => 5,

    // 1枚あたりの画像サイズ（バイト）
    'instagram_max_image_bytes' => 8 * 1024 * 1024,
    // 準備1回で受け取る全体のサイズ（バイト）
    'instagram_max_request_bytes' => 16 * 1024 * 1024,
    // 一時画像URLの有効期間（秒）。24時間より長くはできない。
    'instagram_media_ttl_seconds' => 3600,
    // 1端末あたり、rate_window_seconds の間に許す回数
    'rate_max_instagram_prepares' => 3,
    'rate_max_instagram_publishes' => 3,
    'rate_max_instagram_status' => 60,
    // 投稿先アカウントごとの上限。**端末を替えても超えられない。**
    'rate_max_instagram_account_prepares' => 3,
    'rate_max_instagram_account_publishes' => 3,
];
