<script setup lang="ts">
const props = defineProps<{ tag: string }>()

const api = useApi()
const { user, ready, init } = useAuth()
const { site } = useAuthState()
const toast = useToast()

const page = ref<any>(null)
const loading = ref(true)
const error = ref('')

// ==================== 页面标题（随页面数据动态更新） ====================
const siteName = computed(() => site.value?.name || 'NuxtWiki')
// 站点首页特殊页：命中 home_tag / Home / HomePage 即视为站点首页，固定显示「首页」
const isHome = computed(() => !!page.value?.page && (props.tag === site.value?.home_tag || props.tag === 'Home' || props.tag === 'HomePage'))

const pageTitle = computed(() => {
  const title = page.value?.page?.title
  if (!title) return null
  // Home 为特殊页：固定显示「首页」
  if (isHome.value) return `${siteName.value} | 首页`
  return `${siteName.value} | ${title}`
})

useHead({ title: computed(() => pageTitle.value || null) })

// 订阅
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
    error.value = r.error?.message || '加载失败'
  }
  loading.value = false
}

// 所有页面导航（桌面端左侧栏）
const allPages = ref<any[]>([])
const loadAllPages = async () => {
  const r = await api.get('page.list')
  if (r.ok) allPages.value = (r.data as any[]) ?? []
}

// 返回顶部：滚动进度 + 显示控制
const scrollProgress = ref(0)
const showBackTop = ref(false)
// 进度环：半径 25 的圆周长（SVG stroke-dasharray 用）
const ringLen = 2 * Math.PI * 25
let scrollTicking = false
const onScroll = () => {
  if (scrollTicking) return
  scrollTicking = true
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

const canEdit = computed(() => page.value?.can_edit)
const canHistory = computed(() => page.value?.can_history)
const canDiff = computed(() => page.value?.can_diff)
const canBacklinks = computed(() => page.value?.can_backlinks)
const canAcl = computed(() => page.value?.can_acl)
const canContributors = computed(() => page.value?.can_contributors)

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
      <p class="text-(--ui-muted) mb-6">该页面尚未创建。</p>
      <UButton v-if="canEdit" :to="`/${page.tag}/edit`" icon="i-lucide-plus" label="创建此页面" />
    </div>

    <!-- 桌面端三栏：左=所有页面导航 / 中=页面内容(保持居中) / 右=本页内容导航；移动端仅显示内容 -->
    <div v-else class="xl:grid xl:grid-cols-[minmax(0,1fr)_minmax(0,56rem)_minmax(0,1fr)] xl:gap-6 xl:items-start">
      <!-- 桌面端左侧：所有页面导航（固定显示） -->
      <aside class="hidden xl:flex xl:justify-end xl:sticky xl:top-24 xl:self-start">
        <div class="w-full max-w-56 rounded-lg border border-(--ui-border) bg-(--ui-bg-elevated) p-4 text-sm max-h-[calc(100vh-7rem)] overflow-y-auto">
          <p class="font-semibold mb-2 flex items-center justify-between">
            <span>所有页面</span>
            <NuxtLink to="/pages" class="text-xs font-normal text-(--ui-primary) no-underline hover:opacity-80">全部</NuxtLink>
          </p>
          <nav v-if="allPages.length">
            <NuxtLink
              v-for="p in allPages"
              :key="p.tag"
              :to="`/${p.tag}`"
              class="block py-0.5 truncate no-underline transition-colors"
              :class="p.tag === page.page.tag ? 'text-(--ui-primary) font-medium' : 'text-(--ui-muted) hover:text-(--ui-primary)'"
            >{{ p.title }}</NuxtLink>
          </nav>
          <p v-else class="text-(--ui-muted)">暂无页面</p>
        </div>
      </aside>

      <!-- 中间：页面标题 + 内容（保持居中） -->
      <article class="min-w-0">
        <div class="mb-4">
          <div class="flex items-start justify-between gap-4">
            <h1 class="text-3xl font-bold">{{ page.page.title }}</h1>
            <div class="flex items-center gap-1 shrink-0">
              <UButton
                :icon="watching ? 'i-lucide-bell-ring' : 'i-lucide-bell'"
                :label="watching ? '已订阅' : '订阅'"
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
            <span>版本 {{ page.page.revision }}</span>
            <span>·</span>
            <span>阅读 {{ formatCount(page.page.hits) }}</span>
            <span>·</span>
            <span>订阅 {{ formatCount(page.subscriber_count) }}</span>
            <span>·</span>
            <span>贡献者 {{ page.contributors?.length || 0 }}</span>
            <span class="w-full sm:w-auto">最后更新 {{ formatDate(page.page.updated_at) }}</span>
            <span v-if="page.page.last_editor"> 编辑者 {{ page.page.last_nickname || page.page.last_editor }}</span>
          </div>
        </div>

        <!-- 操作按钮（移动端可横向滚动） -->
        <div class="flex items-center gap-1 mb-6 border-b border-b-(--ui-border) pb-3 overflow-x-auto whitespace-nowrap [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
          <UButton v-if="canEdit" :to="`/${page.page.tag}/edit`" icon="i-lucide-pencil" size="xs" color="neutral" variant="ghost" label="编辑" />
          <UButton v-if="canHistory" :to="`/${page.page.tag}/history`" icon="i-lucide-history" size="xs" color="neutral" variant="ghost" label="历史" />
          <UButton v-if="canDiff" :to="`/${page.page.tag}/diff`" icon="i-lucide-git-compare" size="xs" color="neutral" variant="ghost" label="对比" />
          <UButton v-if="canBacklinks" :to="`/${page.page.tag}/backlinks`" icon="i-lucide-link" size="xs" color="neutral" variant="ghost" label="回链" />
          <UButton v-if="canAcl" :to="`/${page.page.tag}/acl`" icon="i-lucide-shield" size="xs" color="neutral" variant="ghost" label="权限" />
          <UButton v-if="canContributors" :to="`/${page.page.tag}/contributors`" icon="i-lucide-users" size="xs" color="neutral" variant="ghost" label="贡献者" />
        </div>

        <!-- 窄屏（xl 以下不显示左右侧栏）时，本页目录显示在正文内容顶部 -->
        <div v-if="rendered?.toc?.length" class="xl:hidden mb-6 rounded-lg border border-(--ui-border) bg-(--ui-bg-elevated) p-4 text-sm">
          <p class="font-semibold mb-2">本页目录</p>
          <nav>
            <a
              v-for="item in rendered.toc"
              :key="item.id"
              :href="`#${item.id}`"
              :style="{ paddingLeft: `${(item.level - 2) * 16}px` }"
              class="block py-0.5 text-(--ui-muted) hover:text-(--ui-primary) no-underline transition-colors"
              v-html="item.text"
            />
          </nav>
        </div>

        <!-- 页面内容 -->
        <div class="wiki-content" v-html="rendered?.html"></div>
      </article>

      <!-- 桌面端右侧：本页内容导航（固定显示，识别 2 级 3 级标题生成跳转） -->
      <aside class="hidden xl:flex xl:justify-start xl:sticky xl:top-24 xl:self-start">
        <div class="w-full max-w-56 rounded-lg border border-(--ui-border) bg-(--ui-bg-elevated) p-4 text-sm max-h-[calc(100vh-7rem)] overflow-y-auto">
          <p class="font-semibold mb-2">本页目录</p>
          <nav v-if="rendered?.toc?.length">
            <a
              v-for="item in rendered.toc"
              :key="item.id"
              :href="`#${item.id}`"
              :style="{ paddingLeft: `${(item.level - 2) * 16}px` }"
              class="block py-0.5 text-(--ui-muted) hover:text-(--ui-primary) no-underline transition-colors"
              v-html="item.text"
            />
          </nav>
          <p v-else class="text-(--ui-muted)">本页暂无目录</p>
        </div>
      </aside>
    </div>

    <!-- 返回顶部按钮：圆形，右下角，粗描边为页面浏览进度条（SVG 圆端点） -->
    <button
      v-if="showBackTop"
      @click="scrollToTop"
      class="fixed bottom-6 right-6 z-50 size-14 rounded-full shadow-lg transition-opacity hover:opacity-90 flex items-center justify-center bg-(--ui-bg)"
      aria-label="返回顶部"
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

