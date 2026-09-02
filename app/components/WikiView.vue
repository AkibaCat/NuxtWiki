<script setup lang="ts">
// 页面详情视图：按 tag 加载并渲染 Wiki 页面。负责页面标题、目录（TOC）、订阅开关、返回顶部，以及桌面端三栏布局。
const props = defineProps<{ tag: string }>()

const { t } = useI18n()

const api = useApi()
const { user, ready, init } = useAuth()
const { site } = useAuthState()
const toast = useToast()

const page = ref<any>(null)
const loading = ref(true)
const error = ref('')

// ==================== 页面标题（写入全局覆盖，由布局统一输出） ====================
const siteName = computed(() => site.value?.name || 'NuxtWiki')
const { setTitle } = useWikiTitle()
// 站点首页特殊页：命中 home_tag / Home / HomePage 即视为站点首页，固定显示「首页」
const isHome = computed(() => !!page.value?.page && (props.tag === site.value?.home_tag || props.tag === 'Home' || props.tag === 'HomePage'))

const pageTitle = computed(() => {
  const title = page.value?.page?.title
  if (!title) return null
  // Home 为特殊页：固定显示「首页」
  if (isHome.value) return `${siteName.value} | ${t('nav.home')}`
  return `${siteName.value} | ${title}`
})

watch(pageTitle, (v) => setTitle(v), { immediate: true })
onUnmounted(() => setTitle(null))

// ==================== 目录（TOC）：状态写入 + 跳转逻辑 ====================
const { setToc, scrollToHeading } = useToc()

const watching = ref(false)
const watchBusy = ref(false)

const load = async () => {
  loading.value = true
  error.value = ''
  const r = await api.get('page.get', { tag: props.tag })
  if (r.ok) {
    page.value = r.data
    watching.value = !!r.data.watching
  } else {
    error.value = r.error?.message || t('wiki.loadError')
  }
  loading.value = false
}

// 所有页面导航（桌面端左侧栏）
const allPages = ref<any[]>([])
const loadAllPages = async () => {
  const r = await api.get('page.list')
  if (r.ok) allPages.value = (r.data as any[]) ?? []
}

// 侧边栏筛选：顶部输入框（右侧切换按「分组」或「页面」筛选）
const sideMode = ref<'page' | 'group'>('page')
const sideQuery = ref('')
// 页面模式按标题/tag 过滤页面后归组；分组模式先按分组名过滤组
const groupedSidebar = computed(() => {
  const q = sideQuery.value.trim().toLowerCase()
  if (sideMode.value === 'group') {
    const groups = groupByPages(allPages.value)
    if (!q) return groups
    return groups.filter(([name]) => name.toLowerCase().includes(q))
  }
  let list = allPages.value
  if (q) list = list.filter((p) => (p.title || '').toLowerCase().includes(q) || (p.tag || '').toLowerCase().includes(q))
  return groupByPages(list)
})
const sidebarHasResults = computed(() => {
  const q = sideQuery.value.trim()
  if (groupedSidebar.value.length) return true
  // 分组模式下：分组名不命中即无结果；页面模式下空关键词且有页面视为有结果（空列表时归组为空）
  return sideMode.value === 'page' && !q && allPages.value.length > 0
})

// 返回顶部：滚动进度 + 显示控制
const scrollProgress = ref(0)
const showBackTop = ref(false)
// 进度环：半径 25 的圆周长（SVG stroke-dasharray 用）
const ringLen = 2 * Math.PI * 25
let scrollTicking = false
const onScroll = () => {
  if (scrollTicking) return
  scrollTicking = true
  // 用 requestAnimationFrame 合并滚动事件，避免高频触发重复计算
  window.requestAnimationFrame(() => {
    const max = document.documentElement.scrollHeight - window.innerHeight
    scrollProgress.value = max > 0 ? Math.min(1, Math.max(0, window.scrollY / max)) : 0
    showBackTop.value = window.scrollY > 300
    scrollTicking = false
  })
}
const scrollToTop = () => {
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

onMounted(async () => {
  await init()
  loadAllPages()
  await load()
  window.addEventListener('scroll', onScroll, { passive: true })
  onScroll()
})

onBeforeUnmount(() => {
  window.removeEventListener('scroll', onScroll)
})

const rendered = computed(() => {
  if (!page.value?.exists) return null
  return renderWiki(page.value.page.body, { tag: page.value.page.tag })
})

// 页面样式表编译产物（作用域为 .wiki-content），随页面注入
const compiledPageStyle = computed(() => compilePageStyle(page.value?.page?.style || ''))

// 目录数据写入全局（供布局移动端「此页目录」下拉浮窗读取）；离开页面时清空
watch(() => rendered.value?.toc, (toc) => setToc(toc), { immediate: true })
onMounted(() => window.addEventListener('scroll', onScroll, { passive: true }))
onUnmounted(() => setToc([]))

const canEdit = computed(() => page.value?.can_edit)
const canHistory = computed(() => page.value?.can_history)
const canDiff = computed(() => page.value?.can_diff)
const canBacklinks = computed(() => page.value?.can_backlinks)
const canAcl = computed(() => page.value?.can_acl)
const canContributors = computed(() => page.value?.can_contributors)

// 编辑保护横幅：页面正被其他用户编辑时，对可编辑者显示提示
const editLock = computed(() => page.value?.edit_lock)
const showEditBanner = computed(() => {
  const l = editLock.value
  return !!l?.active && !!canEdit.value && l.user_id !== user.value?.id
})
const editBannerNickname = computed(() => editLock.value?.nickname || '')

const toggleWatch = async () => {
  if (!user.value) {
    await navigateTo('/login')
    return
  }
  watchBusy.value = true
  const r = watching.value
    ? await api.post('watch.remove', { tag: props.tag })
    : await api.post('watch.add', { tag: props.tag })
  if (r.ok) watching.value = !watching.value
  watchBusy.value = false
}
</script>

<template>
  <div class="px-4 py-8">
    <div v-if="loading" class="max-w-4xl mx-auto flex justify-center py-16">
      <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-(--ui-primary)" />
    </div>

    <div v-else-if="error" class="max-w-4xl mx-auto text-center py-16">
      <UIcon name="i-lucide-alert-circle" class="size-10 text-(--ui-error) mb-4" />
      <p class="text-(--ui-error)">{{ error }}</p>
    </div>

    <div v-else-if="!page?.exists" class="max-w-4xl mx-auto text-center py-16">
      <UIcon name="i-lucide-file-question" class="size-12 text-(--ui-muted) mb-4" />
      <h1 class="text-2xl font-bold mb-2">{{ page.tag }}</h1>
      <p class="text-(--ui-muted) mb-6">{{ t('wiki.pageNotFound') }}</p>
      <UButton v-if="canEdit" :to="`/editor?open=${encodeURIComponent(page.tag)}`" icon="i-lucide-plus" :label="t('wiki.createPage')" />
    </div>

    <!-- 桌面端三栏：左=所有页面导航 / 中=页面内容(保持居中) / 右=本页内容导航；移动端仅显示内容 -->
    <div v-else class="xl:grid xl:grid-cols-[minmax(0,1fr)_minmax(0,56rem)_minmax(0,1fr)] xl:gap-6 xl:items-start">
      <!-- 桌面端左侧：所有页面导航（固定显示） -->
      <aside class="hidden xl:flex xl:justify-end xl:sticky xl:top-24 xl:self-start">
        <div class="w-full max-w-56 rounded-lg border border-(--ui-border) bg-(--ui-bg-elevated) p-4 text-sm max-h-[calc(100vh-7rem)] overflow-y-auto">
          <p class="font-semibold mb-2 flex items-center justify-between">
            <span>{{ t('nav.pages') }}</span>
            <NuxtLink to="/pages" class="text-xs font-normal text-(--ui-primary) no-underline hover:opacity-80">{{ t('wiki.all') }}</NuxtLink>
          </p>
          <div class="mb-2 flex items-center gap-1">
            <UInput
              v-model="sideQuery"
              size="xs"
              icon="i-lucide-search"
              class="min-w-0 flex-1"
              :placeholder="sideMode === 'group' ? t('groups.groupFilter') : t('groups.pageFilter')"
            />
            <UTooltip :text="sideMode === 'group' ? t('groups.byPage') : t('groups.byGroup')">
              <UButton
                icon="i-lucide-list-filter"
                size="xs"
                color="neutral"
                variant="ghost"
                class="shrink-0"
                :aria-label="t('groups.byPage')"
                @click="sideMode = sideMode === 'group' ? 'page' : 'group'"
              >
                {{ sideMode === 'group' ? t('groups.byGroup') : t('groups.byPage') }}
              </UButton>
            </UTooltip>
          </div>
          <nav v-if="sidebarHasResults">
            <template v-for="[g, list] in groupedSidebar" :key="g">
              <hr class="mt-2 h-px bg-gray-300 border-0 dark:bg-gray-700" />
              <p class="mt-2 first:mt-0 text-lg font-semibold text-(--ui-muted)">{{ g }}</p>
              <NuxtLink
                v-for="p in list"
                :key="p.tag"
                :to="`/${p.tag}`"
                class="block py-0.5 truncate no-underline transition-colors"
                :class="p.tag === page.page.tag ? 'text-(--ui-primary) font-medium' : 'text-(--ui-muted) hover:text-(--ui-primary)'"
              >{{ p.title }}</NuxtLink>
            </template>
          </nav>
          <p v-else class="text-(--ui-muted)">{{ t('groups.empty') }}</p>
        </div>
      </aside>

      <!-- 中间：页面标题 + 内容（保持居中） -->
      <article class="min-w-0">
        <!-- 编辑保护横幅：其他用户正在编辑本页 -->
        <div v-if="showEditBanner" class="mb-4 flex items-center gap-2 rounded-lg border border-(--ui-warning)/50 bg-(--ui-warning)/10 px-3 py-2 text-sm text-(--ui-warning)">
          <UIcon name="i-lucide-pencil" class="size-4 shrink-0" />
          <span>{{ t('wiki.editingBanner', { nickname: editBannerNickname }) }}</span>
        </div>
        <div class="mb-4">
          <div class="flex items-start justify-between gap-4">
            <h1 class="text-3xl font-bold">{{ page.page.title }}</h1>
            <div class="flex items-center gap-1 shrink-0">
              <UButton
                :icon="watching ? 'i-lucide-bell-ring' : 'i-lucide-bell'"
                :label="watching ? t('wiki.unwatch') : t('wiki.watch')"
                size="sm"
                color="neutral"
                variant="subtle"
                :loading="watchBusy"
                @click="toggleWatch"
              />
            </div>
          </div>
          <!-- 页面信息：移动端更新时间换行显示 -->
          <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-2 text-sm text-(--ui-muted)">
            <span>{{ t('wiki.revision') }} {{ page.page.revision }}</span>
            <span>·</span>
            <span>{{ t('wiki.hits') }} {{ formatCount(page.page.hits) }}</span>
            <span>·</span>
            <span>{{ t('wiki.subscribers') }} {{ formatCount(page.subscriber_count) }}</span>
            <span>·</span>
            <span>{{ t('wiki.contributors') }} {{ page.contributor_count || 0 }}</span>
            <span class="w-full sm:w-auto">{{ t('wiki.lastModified') }} {{ formatDate(page.page.updated_at) }}</span>
            <span v-if="page.page.last_editor"> {{ t('wiki.lastEditor') }} {{ page.page.last_nickname || page.page.last_editor }}</span>
          </div>
        </div>

        <!-- 操作按钮（移动端可横向滚动） -->
        <div class="flex items-center gap-1 mb-6 border-b border-b-(--ui-border) pb-3 overflow-x-auto whitespace-nowrap [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
          <UButton v-if="canEdit" :to="`/editor?open=${encodeURIComponent(page.page.tag)}`" icon="i-lucide-pencil" size="xs" color="neutral" variant="ghost" :label="t('wiki.edit')" />
          <UButton v-if="canHistory" :to="`/${page.page.tag}/history`" icon="i-lucide-history" size="xs" color="neutral" variant="ghost" :label="t('wiki.history')" />
          <UButton v-if="canDiff" :to="`/${page.page.tag}/diff`" icon="i-lucide-git-compare" size="xs" color="neutral" variant="ghost" :label="t('wiki.diff')" />
          <UButton v-if="canBacklinks" :to="`/${page.page.tag}/backlinks`" icon="i-lucide-link" size="xs" color="neutral" variant="ghost" :label="t('wiki.backlinks')" />
          <UButton v-if="canAcl" :to="`/${page.page.tag}/acl`" icon="i-lucide-shield" size="xs" color="neutral" variant="ghost" :label="t('wiki.acl')" />
          <UButton v-if="canContributors" :to="`/${page.page.tag}/contributors`" icon="i-lucide-users" size="xs" color="neutral" variant="ghost" :label="t('wiki.contributors')" />
        </div>

        <component :is="'style'">{{ compiledPageStyle }}</component>
        <div class="wiki-content" v-html="rendered?.html"></div>
      </article>

      <!-- 桌面端右侧：本页内容导航（固定显示，识别 2 级 3 级标题生成跳转） -->
      <aside class="hidden xl:flex xl:justify-start xl:sticky xl:top-24 xl:self-start">
        <div class="w-full max-w-56 rounded-lg border border-(--ui-border) bg-(--ui-bg-elevated) p-4 text-sm max-h-[calc(100vh-7rem)] overflow-y-auto">
          <p class="font-semibold mb-2 flex items-center justify-between gap-2">
            <span>{{ t('wiki.toc') }}</span>
            <UButton
              color="neutral"
              variant="ghost"
              size="xs"
              icon="i-lucide-arrow-up"
              :label="t('wiki.backToTop')"
              class="toc-back-top"
              @click="scrollToTop"
            />
          </p>
          <nav v-if="rendered?.toc?.length">
            <a
              v-for="item in rendered.toc"
              :key="item.id"
              :href="`#${item.id}`"
              :style="{ paddingLeft: `${(item.level - 2) * 16}px` }"
              class="block py-0.5 text-(--ui-muted) hover:text-(--ui-primary) no-underline transition-colors"
              @click.prevent="scrollToHeading(item.id)"
              v-html="item.text"
            />
          </nav>
          <p v-else class="text-(--ui-muted)">{{ t('wiki.noToc') }}</p>
        </div>
      </aside>
    </div>

    <!-- 返回顶部按钮：圆形，右下角，粗描边为页面浏览进度条（SVG 圆端点） -->
    <button
      v-if="showBackTop"
      @click="scrollToTop"
      class="fixed bottom-6 right-6 z-50 size-14 rounded-full shadow-lg transition-opacity hover:opacity-90 flex items-center justify-center bg-(--ui-bg)"
      :aria-label="t('wiki.backToTop')"
    >
      <svg class="absolute inset-0 size-full" viewBox="0 0 56 56" aria-hidden="true">
        <!-- 背景环 -->
        <circle cx="28" cy="28" r="25" fill="none" stroke="var(--ui-border)" stroke-width="4" />
        <!-- 进度环（圆端点） -->
        <circle
          cx="28"
          cy="28"
          r="25"
          fill="none"
          stroke="var(--ui-primary)"
          stroke-width="4"
          stroke-linecap="round"
          :stroke-dasharray="ringLen"
          :stroke-dashoffset="ringLen * (1 - scrollProgress)"
          transform="rotate(-90 28 28)"
        />
      </svg>
      <UIcon name="i-lucide-arrow-up" class="size-5 text-(--ui-primary)" />
    </button>
  </div>
</template>

