import { useAuthState, type SiteInfo, type UserInfo } from './useAuthState'

/** 认证逻辑：初始化 / 登录 / 注册 / 登出 */
export const useAuth = () => {
  const { user, ready, site, csrf, registrationOpen } = useAuthState()
  const api = useApi()

  const setSession = (data: { user: UserInfo | null; csrf: string }) => {
    user.value = data.user
    csrf.value = data.csrf
  }

  /** 初始化（应用启动时调用一次） */
  const init = async () => {
    if (ready.value) return
    const r = await api.get<{
      user: UserInfo | null
      csrf: string
      registration_open: boolean
      site: SiteInfo
    }>('auth.me')
    if (r.ok && r.data) {
      user.value = r.data.user
      csrf.value = r.data.csrf
      site.value = r.data.site
      registrationOpen.value = r.data.registration_open
    }
    ready.value = true
  }

  const login = async (username: string, password: string) => {
    const r = await api.post<{ user: UserInfo; csrf: string }>('auth.login', { username, password })
    if (r.ok && r.data) setSession(r.data)
    return r
  }

  const register = async (username: string, password: string, confirm: string, regcode: string) => {
    const r = await api.post<{ user: UserInfo; csrf: string }>('auth.register', { username, password, confirm, regcode })
    if (r.ok && r.data) setSession(r.data)
    return r
  }

  const logout = async () => {
    await api.post('auth.logout', {})
    user.value = null
    csrf.value = ''
  }

  return { user, ready, site, csrf, registrationOpen, init, login, register, logout }
}
