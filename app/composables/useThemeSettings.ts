/**
 * 站点评级主题设置（随浏览器本地保存）。
 * 通过运行时覆盖 CSS 变量 --ui-primary 实现主色切换（Nuxt UI 仅依赖这一个主色变量），
 * 暗亮模式则由 useColorMode 单独负责。
 */
export type ThemeColor =
  | 'green' | 'sky' | 'orange' | 'purple' | 'red' | 'yellow'
  | 'sakura' | 'coral' | 'wine' | 'maple' | 'ginger'
  | 'mint' | 'darkgreen' | 'matcha'
  | 'navy' | 'haze' | 'klain' | 'tiffany'
  | 'taro' | 'gray' | 'milktea' | 'coffee'
  | 'obsidian' | 'ivory'

export interface ThemeSettings {
  color: ThemeColor
}

/** 预设主色 → --ui-primary 的 hex 值 */
export const THEME_PRESETS: Record<ThemeColor, string> = {
  green: '#00C16A',
  sky: '#0EA5E9',
  orange: '#F97316',
  purple: '#8B5CF6',
  red: '#E60012',
  yellow: '#FFD400',
  sakura: '#F472B6',
  coral: '#FF7F6E',
  wine: '#722F37',
  maple: '#E07A2F',
  ginger: '#D6A419',
  mint: '#4FD1A0',
  darkgreen: '#166B45',
  matcha: '#8EBB5F',
  navy: '#1A2E68',
  haze: '#8295AC',
  klain: '#002FA7',
  tiffany: '#0ABAB5',
  taro: '#AF8FE0',
  gray: '#808080',
  milktea: '#C9A27E',
  coffee: '#6F4E37',
  obsidian: '#201F1F',
  ivory: '#F2EEE7',
}

export const THEME_LABELS: Record<ThemeColor, string> = {
  green: '科技绿',
  sky: '天空蓝',
  orange: '活力橙',
  purple: '忧郁紫',
  red: '中国红',
  yellow: '柠檬黄',
  sakura: '樱花粉',
  coral: '珊瑚粉',
  wine: '酒红色',
  maple: '枫叶橙',
  ginger: '姜黄色',
  mint: '薄荷绿',
  darkgreen: '墨绿色',
  matcha: '抹茶绿',
  navy: '藏青色',
  haze: '雾霾蓝',
  klain: '克莱因蓝',
  tiffany: '蒂芙尼蓝',
  taro: '香芋紫',
  gray: '高级灰',
  milktea: '奶茶色',
  coffee: '咖啡棕',
  obsidian: '曜石黑',
  ivory: '象牙白',
}

/** 曜石黑 ⇄ 象牙白：互为反色，随明暗主题自动互换 */
export const OBSIDIAN = '#201F1F'
export const IVORY = '#F2EEE7'

const PAIR: ThemeColor[] = ['obsidian', 'ivory']

/** 解出某主色的实际主色 hex：曜石黑/象牙白按明暗自动互换 */
export function resolveHex(color: ThemeColor, dark: boolean): string {
  if (PAIR.includes(color)) return dark ? IVORY : OBSIDIAN
  return THEME_PRESETS[color]
}

const STORAGE_KEY = 'wiki.settings.theme'
const COLOR_KEYS: ThemeColor[] = Object.keys(THEME_PRESETS) as ThemeColor[]

export const useThemeSettings = () => {
  const settings = useState<ThemeSettings>('wiki.settings.theme', () => {
    const defaults: ThemeSettings = { color: 'green' }
    if (import.meta.client) {
      try {
        const raw = localStorage.getItem(STORAGE_KEY)
        if (raw) {
          const p = JSON.parse(raw)
          if (p && COLOR_KEYS.includes(p.color)) {
            return { color: p.color }
          }
        }
      } catch {
        /* 解析失败则使用默认 */
      }
    }
    return defaults
  })

  const colorMode = useColorMode()

  const persist = () => {
    if (import.meta.server) return
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(settings.value)) } catch { /* ignore */ }
  }

  // 当前应生效的主色 hex（随明暗联动曜石黑/象牙白）
  const resolvedHex = computed(() => resolveHex(settings.value.color, colorMode.value === 'dark'))

  const apply = () => {
    if (import.meta.server) return
    document.documentElement.style.setProperty('--ui-primary', resolvedHex.value, 'important')
  }

  const setColor = (c: ThemeColor) => {
    settings.value = { color: c }
    persist()
    apply()
  }

  if (import.meta.client) {
    onMounted(() => apply())
    // 曜石黑/象牙白需随明暗切换联动更新
    watch(resolvedHex, () => apply())
  }

  return { settings, resolvedHex, setColor, apply, OBSIDIAN, IVORY }
}