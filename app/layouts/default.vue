<script setup lang="ts">
// 全局布局：顶部导航（桌面/移动端折叠）、移动端「此页目录」栏 + 返回顶部、正文主体与页脚。
// 同时作为 <title> 的唯一来源，并通过事件委托处理代码块复制按钮点击。
const { user, ready, site, registrationOpen, init, logout } = useAuth()
const { t } = useI18n()
const route = useRoute()
const { override } = useWikiTitle()
const api = useApi()

// 站点显示语言：init 完成后按其默认语言设置 locale（无浏览器记忆时）
const { applyDefault } = useWikiLocale()

onMounted(async () => {
  await init()
  applyDefault()
  document.addEventListener('click', onDocClick)
})
onBeforeUnmount(() => {
  document.removeEventListener('click', onDocClick)
})

// ==================== 站点名称 + 页面标题（静态路由；动态标题由各页面在数据加载后覆盖） ====================
const PAGE_TITLES: Record<string, string> = {
  '/pages': 'nav.title.pages',
  '/recent': 'nav.title.recent',
  '/search': 'nav.title.search',
  '/settings': 'nav.title.settings',
  '/login': 'nav.title.login',
  '/register': 'nav.title.register',
  '/admin': 'nav.title.admin',
}

const siteName = computed(() => site.value?.name || 'NuxtWiki')

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

// ==================== 移动端「页面浏览」弹窗：按分组排列 + 顶部筛选框 ====================
const browseModal = ref(false)
const browsePages = ref<any[]>([])
const browseQuery = ref('')
const browseMode = ref<'page' | 'group'>('page')
const groupedBrowse = computed(() => {
  const q = browseQuery.value.trim().toLowerCase()
  if (browseMode.value === 'group') {
    const groups = groupByPages(browsePages.value)
    if (!q) return groups
    return groups.filter(([name]) => name.toLowerCase().includes(q))
  }
  let list = browsePages.value
  if (q) list = list.filter((p) => (p.title || '').toLowerCase().includes(q) || (p.tag || '').toLowerCase().includes(q))
  return groupByPages(list)
})
const browseVisible = computed(
  () => groupedBrowse.value.length > 0 || (browseMode.value === 'page' && !browseQuery.value.trim() && browsePages.value.length > 0)
)
const openBrowse = async () => {
  browseQuery.value = ''
  browseModal.value = true
  // 首次打开时加载全部分页；之后复用缓存
  if (!browsePages.value.length) {
    const r = await api.get('page.list')
    if (r.ok) browsePages.value = (r.data as any[]) ?? []
  }
}

const routeTitle = computed(() => {
  const name = siteName.value
  const path = route.path

  // 页面覆盖标题（Wiki 页面 / 页面编辑器 / 账户页等由组件写入）优先
  if (override.value) return override.value

  // 站点首页：站点名 + 首页
  if (path === '/') return `${name} | ${t('nav.title.home')}`

  // 固定页面（/admin /login /search …）
  if (PAGE_TITLES[path]) return `${name} | ${t(PAGE_TITLES[path])}`

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
      span.innerHTML = CHECK_ICON + t('common.copied')
      btn.classList.add('copied')
    } else {
      span.textContent = t('common.copyFailed')
    }
    setTimeout(() => {
      span.innerHTML = old
      btn.classList.remove('copied')
    }, 1500)
  }
}

const year = new Date().getFullYear()

const navLinks = computed(() => [
  { label: t('nav.home'), to: '/', icon: 'i-lucide-home' },
  { label: t('nav.pages'), to: '/pages', icon: 'i-lucide-files' },
  { label: t('nav.recent'), to: '/recent', icon: 'i-lucide-clock' },
  ...([1, 2, 3].includes(user.value?.level ?? 0) ? [{ label: t('nav.editor'), to: '/editor', icon: 'i-lucide-file-edit' }] : []),
])

const userMenu = computed(() => {
  const items: any[] = [
    [{ label: user.value?.username || t('nav.myAccount'), icon: 'i-lucide-user', to: `/account/${encodeURIComponent(user.value?.username || 'me')}`, color: 'primary' }],
    [{
      label: t('nav.logout'),
      icon: 'i-lucide-log-out',
      onSelect: async () => {
        await logout()
        await navigateTo('/')
      }
    }]
  ]
  if (user.value?.is_admin) {
    items[0].push({ label: t('nav.admin'), icon: 'i-lucide-settings', to: '/admin' })
  }
  return items
})
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <UHeader>
      <template #left>
        <NuxtLink to="/" class="flex items-center gap-2.5 shrink-0">
          <img src="/nuxtwiki.svg" :alt="site?.name || 'NuxtWiki'" class="h-7 w-7 shrink-0" />
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
            :aria-label="t('nav.search')"
            color="neutral"
            variant="ghost"
            :class="{ 'text-(--ui-primary)': $route.path === '/search' }"
          />
        </div>
        <UButton
          to="/settings"
          icon="i-lucide-settings"
          :aria-label="t('nav.title.settings')"
          color="neutral"
          variant="ghost"
          :class="{ 'text-(--ui-primary)': $route.path === '/settings' }"
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
            <UButton to="/login" color="neutral" variant="ghost" :label="t('nav.login')" />
            <!-- 注册按钮仅桌面端显示（sm 及以上），移动端导航栏不展示 -->
            <span v-if="registrationOpen" class="hidden sm:inline-flex">
              <UButton to="/register" :label="t('nav.register')" />
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
        <div class="flex items-center">
          <!-- 页面浏览：按分组排列的页面列表弹窗 -->
          <UButton
            color="neutral"
            variant="ghost"
            size="sm"
            icon="i-lucide-layout-grid"
            :label="t('groups.browse')"
            @click="openBrowse"
          />
          <UButton
            color="neutral"
            variant="ghost"
            size="sm"
            :label="t('toc.title')"
            :icon="tocOpen ? 'i-lucide-chevron-down' : 'i-lucide-list'"
            :icon-last="true"
            :aria-expanded="tocOpen"
            @click="tocToggle"
          />
        </div>
        <UButton
          color="neutral"
          variant="ghost"
          size="sm"
          icon="i-lucide-arrow-up"
          :label="t('toc.backToTop')"
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
          © {{ year }} {{ site?.name || 'NuxtWiki' }} · {{ t('footer.builtWith') }}
        </p>
      </template>
      <template #right>
        <UButton
          v-if="user?.is_admin"
          to="/api/index.php?r=feed.rss"
          target="_blank"
          icon="i-lucide-rss"
          :aria-label="t('footer.rss')"
          color="neutral"
          variant="ghost"
        />
        <UButton
          v-if="user?.is_admin"
          to="/admin"
          icon="i-lucide-settings"
          :aria-label="t('nav.admin')"
          color="neutral"
          variant="ghost"
        />
      </template>
    </UFooter>

    <!-- 移动端「页面浏览」弹窗：顶部筛选框 + 按分组排列 -->
    <UModal v-model:open="browseModal" scrollable>
      <template #content>
        <UCard>
          <template #header>
            <div class="flex items-center gap-2">
              <span class="font-semibold">{{ t('groups.browse') }}</span>
              <div class="flex items-center gap-1 ml-auto">
                <UInput
                  v-model="browseQuery"
                  size="xs"
                  icon="i-lucide-search"
                  class="w-40"
                  :placeholder="browseMode === 'group' ? t('groups.groupFilter') : t('groups.pageFilter')"
                />
                <UButton
                  icon="i-lucide-list-filter"
                  size="xs"
                  color="neutral"
                  variant="ghost"
                  class="shrink-0"
                  @click="browseMode = browseMode === 'group' ? 'page' : 'group'"
                >
                  {{ browseMode === 'group' ? t('groups.byGroup') : t('groups.byPage') }}
                </UButton>
              </div>
            </div>
          </template>
          <div v-if="browseVisible" class="max-h-[60vh] space-y-3 overflow-y-auto pr-1">
            <div v-for="[g, list] in groupedBrowse" :key="g">
              <p class="mb-1 text-xs font-semibold text-(--ui-muted)">{{ g }}</p>
              <NuxtLink
                v-for="p in list"
                :key="p.tag"
                :to="`/${p.tag}`"
                class="flex items-center justify-between rounded-md px-2 py-1.5 text-sm no-underline transition-colors hover:bg-(--ui-bg-elevated) hover:text-(--ui-primary)"
                @click="browseModal = false"
              >
                <span class="truncate">{{ p.title }}</span>
                <span class="ml-2 shrink-0 text-xs text-(--ui-muted)">{{ p.tag }}</span>
              </NuxtLink>
            </div>
          </div>
          <div v-else class="py-10 text-center text-(--ui-muted)">{{ t('groups.empty') }}</div>
        </UCard>
      </template>
    </UModal>
  </div>
</template>
