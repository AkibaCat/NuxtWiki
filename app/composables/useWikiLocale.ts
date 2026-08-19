/**
 * 站点显示语言管理。
 *
 * 优先级：用户手动选择（localStorage）> 站点安装/后台配置的默认语言（后端 settings.language）> 简体中文。
 * 语言范围仅限 zh-CN / zh-TW / en 三种，由 nuxt.config 的 i18n.locales 定义。
 */
export const useWikiLocale = () => {
  const { locale, locales, setLocale } = useI18n()
  const { site } = useAuthState()

  const LOCALE_LS_KEY = 'wiki.locale'
  const SUPPORTED = ['zh-CN', 'zh-TW', 'en'] as const
  type LocaleCode = typeof SUPPORTED[number]

  const normalize = (v: string | unknown): LocaleCode => {
    const s = typeof v === 'string' ? v : ''
    return (SUPPORTED as readonly string[]).includes(s) ? (s as LocaleCode) : 'zh-CN'
  }

  /** 首次访问时应用站点默认语言（仅当用户从未手动选过） */
  const applyDefault = async () => {
    if (import.meta.server) return
    let saved: string | null = null
    try {
      saved = localStorage.getItem(LOCALE_LS_KEY)
    } catch { /* 读取失败则忽略 */ }
    // 目标语言：有浏览器记忆则优先，否则使用后端返回的站点默认语言，兜底简中
    const target = saved
      ? normalize(saved)
      : (site.value?.language ? normalize(site.value.language) : 'zh-CN')
    if (target === locale.value) return
    await setLocale(target)
  }

  /** 设置页语言切换：立即生效并持久化 */
  const choose = (code: string) => {
    const c = normalize(code)
    try { localStorage.setItem(LOCALE_LS_KEY, c) } catch { /* 写入失败则忽略 */ }
    if (c !== locale.value) {
      // 使用 setLocale（而非直接改 locale.value）以触发目标语言的按需加载，避免显示翻译键名
      void setLocale(c)
    }
  }

  return {
    locale,
    locales,
    applyDefault,
    choose,
  }
}