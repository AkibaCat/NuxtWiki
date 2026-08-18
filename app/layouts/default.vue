<script setup lang="ts">
const { user, ready, site, registrationOpen, init, logout } = useAuth()
const route = useRoute()

onMounted(() => {
  init()
  document.addEventListener('click', onDocClick)
})
onBeforeUnmount(() => {
  document.removeEventListener('click', onDocClick)
})

// ==================== 站点名称 + 页面标题（静态路由；动态标题由各页面在数据加载后覆盖） ====================
const PAGE_TITLES: Record<string, string> = {
  '/pages': '所有页面',
  '/recent': '最近更改',
  '/search': '搜索',
  '/settings': '设置',
  '/login': '登录',
  '/register': '注册',
  '/admin': '后台管理',
}

const siteName = computed(() => site.value?.name || 'NuxtWiki')

const { override } = useWikiTitle()

// 站点级主题设置：挂载时应用持久化的主色（暗亮由 useColorMode 独立处理）
useThemeSettings()

// ==================== 移动端第二导航栏：此页目录（下拉浮窗）+ 返回顶部 ====================
const { items: tocItems, visible: tocVisible, open: tocOpen, toggle: tocToggle, close: tocClose, scrollToHeading } = useToc()
const tocBarRef = ref<HTMLElement | null>(null)
const scrollTop = () => window.scrollTo({ top: 0, behavior: 'smooth' })
const onTocClick = (id: string) => {
  scrollToHeading(id)
  tocClose()
}

const routeTitle = computed(() => {
  const name = siteName.value
  const path = route.path

  // 页面覆盖标题（Wiki 页面 / 页面编辑器 / 账户页等由组件写入）优先
  if (override.value) return override.value

  // 站点首页：站点名 + 首页
  if (path === '/') return `${name} | 首页`

  // 固定页面（/admin /login /search …）
  if (PAGE_TITLES[path]) return `${name} | ${PAGE_TITLES[path]}`

  // 其余页面兜底为站点名
  return name
})

// 全局唯一 <title> 来源；动态标题通过 useWikiTitle 覆盖输出
useHead({ title: computed(() => routeTitle.value) })

// 代码块复制：事件委托（v-html 注入的按钮无法直接绑定）
const COPY_ICON = '<svg class="wiki-copy-icon" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>'
const CHECK_ICON = '<svg class="wiki-copy-icon" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>'
const copyText = async (text: string): Promise<boolean> => {
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text)
      return true
    }
  } catch {
    /* 走降级方案 */
  }
  const ta = document.createElement('textarea')
  ta.value = text
  ta.style.position = 'fixed'
  ta.style.opacity = '0'
  document.body.appendChild(ta)
  ta.select()
  let ok = false
  try { ok = document.execCommand('copy') } catch { ok = false }
  document.body.removeChild(ta)
  return ok
}
const onDocClick = async (e: Event) => {
  // 移动端「此页目录」浮窗：点击浮窗外部任意处即关闭
  const node = e.target as Node | null
  const bar = tocBarRef.value
  if (bar && tocOpen.value && !bar.contains(node)) tocClose()

  const target = e.target as HTMLElement | null
  const btn = target?.closest?.('.wiki-copy') as HTMLElement | null
  if (!btn) return
  const code = btn.dataset.code
  if (code === undefined) return
  const span = btn.querySelector('span')
  const old = span?.innerHTML || ''
  const ok = await copyText(code)
  if (span) {
    if (ok) {
      // 复制成功：图标 + “已复制”字样（图标在左）
      span.innerHTML = CHECK_ICON + '已复制'
      btn.classList.add('copied')
    } else {
      span.textContent = '复制失败'
    }
    setTimeout(() => {
      span.innerHTML = old
      btn.classList.remove('copied')
    }, 1500)
  }
}

const year = new Date().getFullYear()

const navLinks = computed(() => [
  { label: '首页', to: '/', icon: 'i-lucide-home' },
  { label: '所有页面', to: '/pages', icon: 'i-lucide-files' },
  { label: '最近更改', to: '/recent', icon: 'i-lucide-clock' },
  ...([1, 2, 3].includes(user.value?.level ?? 0) ? [{ label: '页面编辑器', to: '/editor', icon: 'i-lucide-file-edit' }] : []),
])

const userMenu = computed(() => {
  const items: any[] = [
    [{ label: user.value?.username || '我的账户', icon: 'i-lucide-user', to: `/account/${encodeURIComponent(user.value?.username || 'me')}`, color: 'primary' }],
    [{
      label: '退出登录',
      icon: 'i-lucide-log-out',
      onSelect: async () => {
        await logout()
        await navigateTo('/')
      }
    }]
  ]
  if (user.value?.is_admin) {
    items[0].push({ label: '管理后台', icon: 'i-lucide-settings', to: '/admin' })
  }
  return items
})
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <UHeader>
      <template #left>
        <NuxtLink to="/" class="flex items-center gap-2.5 shrink-0">
          <img src="/favicon.ico" :alt="site?.name || 'NuxtWiki'" class="h-7 w-7 shrink-0" />
          <span class="text-lg font-bold text-(--ui-text-highlighted)">{{ site?.name || 'NuxtWiki' }}</span>
        </NuxtLink>
      </template>

      <!-- UHeader 无 #center 插槽，导航项通过默认插槽渲染到中间 -->
      <div class="flex items-center gap-1 overflow-x-auto">
        <UButton
          v-for="l in navLinks"
          :key="l.to"
          :to="l.to"
          :label="l.label"
          :icon="l.icon"
          color="neutral"
          variant="ghost"
          :class="{ 'text-(--ui-primary)': $route.path === l.to }"
        />
      </div>

      <template #right>
        <div class="flex items-center gap-1">
          <UButton
            to="/search"
            icon="i-lucide-search"
            aria-label="搜索"
            color="neutral"
            variant="ghost"
          />
        </div>
        <UButton
          to="/settings"
          icon="i-lucide-settings"
          aria-label="设置"
          color="neutral"
          variant="ghost"
        />
        <template v-if="ready">
          <template v-if="user">
            <UDropdownMenu :items="userMenu" :content="{ align: 'end' }">
              <UButton color="neutral" variant="ghost" class="gap-2">
                <UAvatar :alt="user.username" size="sm" :src="user.avatar || undefined" :text="(user.nickname || user.username).charAt(0)" />
                <span class="hidden sm:inline">{{ user.username }}</span>
                <UIcon name="i-lucide-chevron-down" class="size-3.5" />
              </UButton>
            </UDropdownMenu>
          </template>
          <template v-else>
            <UButton to="/login" color="neutral" variant="ghost" label="登录" />
            <!-- 注册按钮仅桌面端显示（sm 及以上），移动端导航栏不展示 -->
            <span v-if="registrationOpen" class="hidden sm:inline-flex">
              <UButton to="/register" label="注册" />
            </span>
          </template>
        </template>
      </template>

      <!-- 移动端折叠菜单：导航项 -->
      <template #body>
        <div class="flex flex-col gap-1 p-4">
          <UButton
            v-for="l in navLinks"
            :key="l.to"
            :to="l.to"
            :label="l.label"
            :icon="l.icon"
            color="neutral"
            variant="ghost"
            :class="{ 'text-(--ui-primary)': $route.path === l.to }"
          />
        </div>
      </template>
    </UHeader>

    <!-- 移动端第二导航栏：此页目录（下拉浮窗）+ 返回顶部 -->
    <div
      v-if="tocVisible"
      ref="tocBarRef"
      class="xl:hidden sticky top-(--ui-header-height) z-40 border-b border-(--ui-border) bg-(--ui-bg)"
    >
      <div class="relative flex h-10 items-center justify-between px-2">
        <UButton
          color="neutral"
          variant="ghost"
          size="sm"
          label="此页目录"
          :icon="tocOpen ? 'i-lucide-chevron-down' : 'i-lucide-list'"
          :icon-last="true"
          :aria-expanded="tocOpen"
          @click="tocToggle"
        />
        <UButton
          color="neutral"
          variant="ghost"
          size="sm"
          icon="i-lucide-arrow-up"
          label="返回顶部"
          @click="scrollTop"
        />

        <!-- 向下展开的目录浮窗（覆盖正文上方，非弹窗） -->
        <Transition name="toc-drop">
          <div
            v-if="tocOpen"
            class="absolute top-full inset-x-2 mt-2 z-40 max-h-[60vh] overflow-y-auto rounded-xl border border-(--ui-border) bg-(--ui-bg-elevated) shadow-lg"
          >
          <nav class="px-3 py-2">
            <a
              v-for="item in tocItems"
              :key="item.id"
              :href="`#${item.id}`"
              :style="{ paddingLeft: `${(item.level - 2) * 16}px` }"
              class="block py-1.5 text-sm text-(--ui-muted) hover:text-(--ui-primary) no-underline transition-colors"
              @click.prevent="onTocClick(item.id)"
              v-html="item.text"
            />
          </nav>
        </div>
        </Transition>
      </div>
    </div>

    <UMain class="flex-1">
      <NuxtPage />
    </UMain>

    <UFooter>
      <template #left>
        <p v-if="site?.site_footer" class="text-sm text-(--ui-muted) whitespace-pre-line">{{ site.site_footer }}</p>
        <p v-else class="text-sm text-(--ui-muted)">
          © {{ year }} {{ site?.name || 'NuxtWiki' }} · 基于 Nuxt UI 与 PHP/MySQL
        </p>
      </template>
      <template #right>
        <UButton
          v-if="user?.is_admin"
          to="/api/index.php?r=feed.rss"
          target="_blank"
          icon="i-lucide-rss"
          aria-label="RSS 订阅"
          color="neutral"
          variant="ghost"
        />
        <UButton
          v-if="user?.is_admin"
          to="/admin"
          icon="i-lucide-settings"
          aria-label="管理后台"
          color="neutral"
          variant="ghost"
        />
      </template>
    </UFooter>
  </div>
</template>
