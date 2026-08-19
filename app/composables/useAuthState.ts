export interface SiteInfo {
  name: string
  description: string
  home_tag: string
  site_footer: string
  language: string
}

export interface UserInfo {
  id: number
  username: string
  nickname: string
  avatar: string
  bio: string
  socials: Record<string, string>
  level: number
  is_admin: boolean
  created_at: string
}

/** 共享认证状态（useApi 与 useAuth 均依赖，避免循环） */
export const useAuthState = () => {
  const user = useState<UserInfo | null>('auth.user', () => null)
  const ready = useState<boolean>('auth.ready', () => false)
  const site = useState<SiteInfo | null>('auth.site', () => null)
  const csrf = useState<string>('auth.csrf', () => '')
  const registrationOpen = useState<boolean>('auth.registrationOpen', () => true)

  return { user, ready, site, csrf, registrationOpen }
}
