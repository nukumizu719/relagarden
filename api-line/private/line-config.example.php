<?php
/**
 * LINE受信用の設定の見本。
 *
 * この見本をコピーして、Xserverの **public_html の外** へ置く。
 *   /home/<アカウント>/relagarden.jp/private/line-config.php
 *
 * 本物の line-config.php は絶対にGitへ入れない（.gitignore 済み）。
 * ここに書く値は、GitHubやXserverの管理パスワードではなく、
 * LINE Developersで発行するこの用途だけの値にすること。
 */

return [
    // ── LINE Developers（Messaging API チャネル）─────────────
    // チャネル基本設定の「チャネルシークレット」。
    // 届いた内容が本当にLINEからかを確かめるために使う。
    'channel_secret' => 'ここへチャネルシークレットを貼る',

    // 「チャネルアクセストークン（長期）」。通常は表示名の取得に使う。
    // 本人限定AI返信テストを有効にした場合だけreplyToken返信にも使う。
    // AI返信を使わない場合は空でもよい（表示名も空欄で届く）。
    'channel_access_token' => '',

    // ── iPhoneアプリとの合言葉 ────────────────────────────────
    // アプリが受信箱を読むときに使う。
    // **人が考えた言葉を使わないこと。** 次のどちらかで作った
    // 64文字以上のでたらめな文字列を貼る。
    //
    //   openssl rand -hex 32
    //   head -c 32 /dev/urandom | xxd -p -c 64
    //
    // 掲載用のPAT・Xserverの管理パスワードとは必ず別物にすること。
    // 作った値はiPhoneアプリの設定「公式LINEの受信」へ入れる。
    'inbox_token' => 'ここへ openssl rand -hex 32 の出力（64文字）を貼る',

    // ── 本人限定AI返信テスト ─────────────────────────────────
    // 初期状態は必ず両方 false。コードを置いただけでは誰にも返信しない。
    // 本人限定テストを始める直前にだけ両方 true にし、許可するuserIdは
    // よしさん本人の1件だけにする。表示名では判定しない。
    'ai_reply_enabled' => false,
    'ai_reply_test_mode' => false,
    // trueのまま使う。アプリ側もONにした場合だけ本人へ返信する。
    'ai_reply_runtime_control_required' => true,
    'ai_reply_allowed_user_id' => '',
    // 本人限定テストの識別子。同じ値の間は受付状態を引き継ぐ。
    // 再テスト時だけ別の値にする（APIキーやパスワードは入れない）。
    'ai_reply_session_id' => '',

    // ClaudeのAPIキーはここへ置かない。よし管理AI GatewayのURLと、
    // LINE受信サーバー専用にペアリングしたGatewayトークンだけを置く。
    'ai_gateway_base_url' => 'https://yoshi-ai-gateway.shakkin-diet-coach-api.workers.dev/v1',
    'ai_gateway_token' => '',
    // 地域・広さ・現状・写真・希望時期の5項目を確認する。
    // 最後の引継ぎを含む一連の受付を、本人限定で1日2回まで試せる。
    'ai_reception_max_questions' => 5,
    'ai_reply_daily_limit' => 12,
    'ai_gateway_timeout_seconds' => 8,
    'line_reply_timeout_seconds' => 5,

    // ── アプリで最終確認した後の本人限定送信 ───────────────
    // 自動返信とは別の安全設定。初期状態は必ず両方 false。
    // アプリの確認画面で宛先と本文を見て「送信する」を押した場合だけ使う。
    // 最初の実機テスト中は、許可するuserIdをよしさん本人の1件だけにする。
    'manual_send_enabled' => false,
    'manual_send_test_mode' => false,
    'manual_send_allowed_user_id' => '',
    // 本人への手動送信も1日20回で停止する。
    'manual_send_daily_limit' => 20,
    'manual_send_timeout_seconds' => 5,

    // ── 保存場所 ──────────────────────────────────────────────
    // 届いた問い合わせの置き場所。**public_html の外にすること。**
    // 公開領域（public_html / htdocs / www / public）の下を指していると、
    // 起動を断って「ただいま準備中です」を返す。
    // 作れない・読めない・書けない場所でも起動しない
    // （黙って問い合わせを捨てないため）。
    'storage_dir' => __DIR__ . '/line-storage',

    // ── 制限 ─────────────────────────────────────────────────
    // 受け取り済みの控えを残す日数（取り込めていないものは消さない）
    'keep_days' => 30,
    // 1回の受信で返す最大件数
    'inbox_limit' => 50,
    // 受信箱を読める回数（同じ回線から、この秒数の間に）
    'rate_window_seconds' => 3600,
    'rate_max_inbox' => 240,
    // Webhookの本文の上限（バイト）
    'max_body_bytes' => 512 * 1024,
    // 表示名を読みに行くときの待ち時間（秒）
    'profile_timeout' => 5,

    // ── 通信 ─────────────────────────────────────────────────
    // 本番は必ず true。HTTPS以外の接続を受け付けない。
    // 手元で確かめるときだけ false にする（本番の設定では変えないこと）。
    'require_https' => true,

    // /api/line/sync で受け取る上限
    'max_sync_bytes' => 64 * 1024,
    'max_sync_ids' => 200,
    'max_id_length' => 128,
    // /api/line/send の本文・返信文・二重送信防止番号の上限
    'max_send_body_bytes' => 16 * 1024,
    'max_send_text_length' => 2000,
    'max_send_request_id_length' => 128,
];
