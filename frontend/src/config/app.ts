export interface AppConfig {
  companyName: string
  systemName: string
  systemShortName: string
  browserTitle: string
  loginSubtitle: string
}

export const appConfig = {
  companyName: '中古車行',
  systemName: '中古車行內部營運系統',
  systemShortName: '中古車行系統',
  browserTitle: '中古車行內部營運系統',
  loginSubtitle: '請登入以繼續',
} satisfies AppConfig
