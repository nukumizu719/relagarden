import { createClient, type MicroCMSQueries, type MicroCMSImage, type MicroCMSListResponse } from "microcms-js-sdk";

// 1. クライアントの初期化
// .env から環境変数を読み込みます
export const client = createClient({
  serviceDomain: import.meta.env.MICROCMS_SERVICE_DOMAIN,
  apiKey: import.meta.env.MICROCMS_API_KEY,
});

// --- 型定義（Types） ---

/**
 * 施工事例（Cases）の型定義
 */
export type Case = {
  id: string;
  createdAt: string;
  updatedAt: string;
  publishedAt: string;
  revisedAt: string;
  title: string;
  area: string;
  pubDate: string; // 手動設定用の公開日
  image: MicroCMSImage;
  beforeImage?: MicroCMSImage;
  cost?: string;
  period?: string;
  beforeConcerns?: string;
  customerVoice?: string;
  content: string; // リッチエディタ
  tags?: {
    tagName: string;
  }[];
};

/**
 * 新着情報（News）の型定義
 */
export type News = {
  id: string;
  createdAt: string;
  updatedAt: string;
  publishedAt: string;
  revisedAt: string;
  title: string;
  pubDate: string; // 手動設定用の公開日
  category: string[]; // セレクトフィールド（複数選択を想定）
  description?: string;
  heroImage?: MicroCMSImage;
  content: string; // リッチエディタ
};

// --- ユーティリティ関数（Functions） ---

/**
 * 施工事例一覧を取得
 */
export const getCases = async (queries?: MicroCMSQueries): Promise<MicroCMSListResponse<Case>> => {
  return await client.get<MicroCMSListResponse<Case>>({
    endpoint: "cases",
    queries,
  });
};

/**
 * 施工事例の詳細を取得
 */
export const getCaseDetail = async (contentId: string, queries?: MicroCMSQueries): Promise<Case> => {
  return await client.get<Case>({
    endpoint: "cases",
    contentId,
    queries,
  });
};

/**
 * ニュース一覧を取得
 */
export const getNews = async (queries?: MicroCMSQueries): Promise<MicroCMSListResponse<News>> => {
  return await client.get<MicroCMSListResponse<News>>({
    endpoint: "news",
    queries,
  });
};

/**
 * ニュースの詳細を取得
 */
export const getNewsDetail = async (contentId: string, queries?: MicroCMSQueries): Promise<News> => {
  return await client.get<News>({
    endpoint: "news",
    contentId,
    queries,
  });
};