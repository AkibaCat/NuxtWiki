<script setup lang="ts">
const route = useRoute()
const { user, init } = useAuth()
const api = useApi()
const toast = useToast()

const username = computed(() => String(route.params.username || ''))

const loading = ref(true)
const notFound = ref(false)
// { user, is_self, contributions, activity }
const data = ref<any>(null)
const watches = ref<any[]>([])

const isSelf = computed(() => !!data.value?.is_self)
const profile = computed(() => data.value?.user || null)
const contributions = computed(() => (data.value?.contributions as any[]) || [])
const activity = computed(() => (data.value?.activity as Record<string, number>) || {})
const status = computed(() => profile.value?.status || 'active')
const isBanned = computed(() => status.value === 'banned')

// 编辑资料弹窗
const editOpen = ref(false)
const onProfileSaved = async () => {
  await Promise.all([loadProfile(), loadWatches()])
}

// 贡献 / 订阅切换
const sectionTab = ref<'contrib' | 'watch'>('contrib')

const socialDefs = [
  { key: 'qq', label: 'QQ', icon: 'i-simple-icons-qq' },
  { key: 'wechat', label: 'WeChat', icon: 'i-simple-icons-wechat' },
  { key: 'bilibili', label: 'BiliBili', icon: 'i-simple-icons-bilibili' },
  { key: 'youtube', label: 'YouTube', icon: 'i-simple-icons-youtube' },
  { key: 'github', label: 'GitHub', icon: 'i-simple-icons-github' },
  { key: 'x', label: 'X', icon: 'i-simple-icons-x' },
]

const visibleSocials = computed(() => {
  const s = profile.value?.socials || {}
  return socialDefs.filter((d) => s[d.key])
})

onMounted(async () => {
  await init()
  // /account/me → 跳转到自己的用户名
  if (username.value === 'me') {
    if (!user.value) {
      await navigateTo('/login?redirect=/account/me')
      return
    }
    await navigateTo(`/account/${encodeURIComponent(user.value.username)}`)
    return
  }
  await Promise.all([loadProfile(), loadWatches()])
  loading.value = false
})

const loadProfile = async () => {
  const r = await api.get('user.profile', { username: username.value })
  if (r.ok) data.value = r.data
  else if (r.status === 404) notFound.value = true
}

const loadWatches = async () => {
  if (!user.value) return
  const r = await api.get('watch.list')
  if (r.ok) watches.value = (r.data as any[]) ?? []
}

const unwatch = async (tag: string) => {
  const r = await api.post('watch.remove', { tag })
  if (r.ok) {
    watches.value = watches.value.filter((w) => w.tag !== tag)
    toast.add({ title: '已取消订阅', color: 'success' })
  }
}

// ==================== GitHub 风格活跃图 ====================
const fmt = (d: Date) =>
  `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

// 用插值分位数划分 4 档活跃度（避免少量活跃被误判为高活跃）
const activityLevels = computed<[number, number, number, number]>(() => {
  const counts = Object.values(activity.value).filter((c) => c > 0).sort((a, b) => a - b)
  if (!counts.length) return [0, 0, 0, 0]
  // 线性插值分位数（与常见统计软件一致），max 取实际最大值
  const q = (p: number) => {
    const pos = p * (counts.length - 1)
    const lo = Math.floor(pos)
    const hi = Math.ceil(pos)
    if (lo === hi) return counts[lo]!
    return counts[lo]! + (counts[hi]! - counts[lo]!) * (pos - lo)
  }
  return [q(0.25), q(0.5), q(0.75), counts[counts.length - 1]!]
})

// 只有当数值真正高于上一档阈值时才提升等级，避免低活跃整体显示为高活跃色
const levelFor = (count: number): number => {
  if (count <= 0) return 0
  const [q1, q2, q3, max] = activityLevels.value
  if (count >= max && max > q3) return 4
  if (count >= q3 && q3 > q2) return 3
  if (count >= q2 && q2 > q1) return 2
  return 1
}

const weeks = computed(() => {
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const start = new Date(today)
  start.setDate(start.getDate() - 52 * 7 - start.getDay())
  const out: { date: Date; key: string; count: number; level: number }[][] = []
  for (let w = 0; w < 53; w++) {
    const week: { date: Date; key: string; count: number; level: number }[] = []
    for (let d = 0; d < 7; d++) {
      const dt = new Date(start)
      dt.setDate(start.getDate() + w * 7 + d)
      const key = fmt(dt)
      const count = activity.value[key] || 0
      week.push({ date: dt, key, count, level: levelFor(count) })
    }
    out.push(week)
  }
  return out
})

const totalContributions = computed(() => Object.values(activity.value).reduce((a, b) => a + b, 0))

const monthLabels = computed(() => {
  const labels: { col: number; label: string }[] = []
  const monthName = (d: Date) =>
    ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][d.getMonth()]!
  let prev = -1
  weeks.value.forEach((week, i) => {
    const m = week[0]!.date.getMonth()
    if (m !== prev) {
      labels.push({ col: i, label: monthName(week[0]!.date) })
      prev = m
    }
  })
  return labels
})

const colorMode = useColorMode()
const isDark = computed(() => colorMode.value === 'dark')

const levelColor = (level: number) => {
  if (level <= 0) return 'var(--ui-bg-elevated)'
  // 亮色主题：浅绿 → 深绿；暗色主题：深底 → 亮绿
  const light = ['#9be9a8', '#40c463', '#30a14e', '#216e39']
  const dark = ['#0e4429', '#006d32', '#26a641', '#39d353']
  return (isDark.value ? dark : light)[level - 1]
}

const weekdayCell = (dayIndex: number) => ['', 'Mon', '', 'Wed', '', 'Fri', ''][dayIndex]
</script>

<template>
  <div class="max-w-5xl mx-auto px-4 py-8">
    <div v-if="loading" class="flex justify-center py-16">
      <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-(--ui-primary)" />
    </div>

    <div v-else-if="notFound" class="text-center py-16">
      <UIcon name="i-lucide-user-x" class="size-12 text-(--ui-muted) mb-4" />
      <h1 class="text-2xl font-bold mb-2">{{ username }}</h1>
      <p class="text-(--ui-muted)">该用户不存在。</p>
    </div>

    <template v-else-if="profile">
      <!-- 账号状态横幅（正常账号不显示） -->
      <div
        v-if="status !== 'active'"
        class="mb-6 rounded-lg border px-4 py-3 flex items-start gap-3"
        :class="isBanned ? 'border-(--ui-error) bg-(--ui-error)/5 text-(--ui-error)' : 'border-(--ui-warning) bg-(--ui-warning)/5 text-(--ui-warning)'"
      >
        <UIcon :name="isBanned ? 'i-lucide-ban' : 'i-lucide-snowflake'" class="size-5 mt-0.5 shrink-0" />
        <div class="min-w-0">
          <p class="font-semibold">{{ isBanned ? '该账号已被封禁' : '该账号已被冻结' }}</p>
          <p v-if="profile.reason" class="text-sm mt-1 break-words">原因：{{ profile.reason }}</p>
        </div>
      </div>

      <!-- 资料头部（封禁账号仅显示用户名，昵称替换为违规账号、不显示头像） -->
      <div class="flex flex-wrap items-center gap-4 mb-8">
        <template v-if="isBanned">
          <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2 flex-wrap">
              <h1 class="text-2xl font-bold">违规账号</h1>
              <span class="text-sm text-(--ui-muted)">@{{ profile.username }}</span>
            </div>
          </div>
        </template>
        <template v-else>
          <img
            v-if="profile.avatar"
            :src="profile.avatar"
            :alt="profile.username"
            class="size-16 rounded-full object-cover border border-(--ui-border)"
          />
          <UAvatar v-else :alt="profile.username" size="xl" :text="(profile.nickname || profile.username).charAt(0)" />
          <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
              <h1 class="text-2xl font-bold shrink-0">{{ profile.nickname || profile.username }}</h1>
              <!-- 权限标签（等级）位于昵称右边，粗描边样式 -->
              <span v-if="profile.level" class="text-xs rounded-full border-2 border-(--ui-primary) text-(--ui-primary) px-2 py-0.5 shrink-0">{{ profile.level === 1 ? '管理员' : profile.level === 2 ? '高级用户' : '普通用户' }}</span>
              <!-- 编辑资料按钮：与昵称同行，固定在右侧 -->
              <UButton v-if="isSelf" icon="i-lucide-pencil" variant="subtle" class="ml-auto shrink-0" @click="editOpen = true">
                编辑资料
              </UButton>
            </div>
            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 mt-1">
              <span class="text-sm text-(--ui-muted)">@{{ profile.username }}</span>
              <span class="text-xs text-(--ui-muted)">加入于 {{ formatDate(profile.created_at) }}</span>
            </div>
            <!-- 个人介绍显示在加入时间下方 -->
            <p v-if="profile.bio" class="text-sm text-(--ui-muted) mt-1 whitespace-pre-wrap break-words">{{ profile.bio }}</p>
          </div>
        </template>
      </div>

      <!-- 外部链接小卡片（显示在用户信息下方，封禁账号不显示） -->
      <div v-if="!isBanned && visibleSocials.length" class="mb-8">
        <h2 class="font-semibold mb-3">社交链接</h2>
        <div class="flex flex-wrap gap-2">
          <a
            v-for="d in visibleSocials"
            :key="d.key"
            :href="profile.socials?.[d.key]"
            target="_blank"
            rel="noopener"
            :title="profile.socials?.[d.key]"
            class="inline-flex items-center gap-2 rounded-lg border border-(--ui-border) px-3 py-2 text-sm text-(--ui-text) hover:text-(--ui-primary) hover:border-(--ui-primary) no-underline"
          >
            <UIcon :name="d.icon" class="size-4 shrink-0" />
            {{ d.label }}
          </a>
        </div>
      </div>

      <!-- 活跃图 -->
      <div class="rounded-lg border border-(--ui-border) p-4 mb-8">
        <div class="flex items-center justify-between mb-4">
          <h2 class="font-semibold">活跃图</h2>
          <span class="text-sm text-(--ui-muted)">过去一年 {{ totalContributions }} 次编辑</span>
        </div>
        <!-- 仅格子区域（含日期标记）可横向滚动，标题/图例固定 -->
        <div class="overflow-x-auto [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
          <div class="min-w-max">
            <div class="relative h-4 mb-1" style="margin-left: 28px">
              <span
                v-for="m in monthLabels"
                :key="m.col"
                class="absolute top-0 text-[10px] text-(--ui-muted) leading-none"
                :style="{ left: `${m.col * 12}px` }"
              >{{ m.label }}</span>
            </div>
            <div class="flex">
              <div class="flex flex-col gap-[3px] pr-2 w-5 shrink-0 text-[10px] text-(--ui-muted)">
                <span v-for="d in 7" :key="d" class="block h-[9px] leading-[9px]">{{ weekdayCell(d - 1) }}</span>
              </div>
              <div class="flex gap-[3px]">
                <div v-for="(week, i) in weeks" :key="i" class="flex flex-col gap-[3px]">
                  <span
                    v-for="cell in week"
                    :key="cell.key"
                    class="block size-[9px] rounded-[1.5px]"
                    :style="{ backgroundColor: levelColor(cell.level) }"
                    :title="`${cell.key} · ${cell.count} 次编辑`"
                  />
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="flex items-center justify-end gap-1 mt-2 text-[10px] text-(--ui-muted)">
          <span>少</span>
          <span v-for="l in [0, 1, 2, 3, 4]" :key="l" class="block size-[9px] rounded-[1.5px]" :style="{ backgroundColor: levelColor(l) }" />
          <span>多</span>
        </div>
      </div>

      <!-- 贡献 / 订阅（切换显示；订阅仅本人可见，封禁账号不显示） -->
      <div v-if="isSelf && !isBanned" class="flex items-center gap-2 mb-4">
        <div class="inline-flex rounded-lg border border-(--ui-border) p-1 gap-1">
          <UButton
            size="sm"
            :variant="sectionTab === 'contrib' ? 'solid' : 'ghost'"
            @click="sectionTab = 'contrib'"
          >贡献 ({{ contributions.length }})</UButton>
          <UButton
            size="sm"
            :variant="sectionTab === 'watch' ? 'solid' : 'ghost'"
            @click="sectionTab = 'watch'"
          >订阅 ({{ watches.length }})</UButton>
        </div>
      </div>

      <div v-if="sectionTab === 'contrib' || !isSelf || isBanned">
        <h2 class="text-lg font-semibold mb-3">{{ isSelf ? '我的贡献' : '贡献' }} ({{ contributions.length }})</h2>
        <ul v-if="contributions.length" class="grid gap-3 sm:grid-cols-2">
          <li v-for="c in contributions" :key="c.tag" class="rounded-lg border border-(--ui-border) px-4 py-3">
            <div class="flex items-center gap-2">
              <NuxtLink :to="`/${c.tag}`" class="font-medium hover:text-(--ui-primary) truncate flex-1">
                {{ c.title }}
              </NuxtLink>
              <span class="text-xs text-(--ui-muted) shrink-0">{{ c.edits }} 次编辑</span>
            </div>
            <p class="text-xs text-(--ui-muted) mt-0.5">最近更新 {{ formatDate(c.updated_at) }}</p>
          </li>
        </ul>
        <p v-else class="text-sm text-(--ui-muted) py-8 text-center rounded-lg border border-(--ui-border)">
          {{ isSelf ? '你还没有贡献过任何页面。去创建或编辑一个页面吧。' : '该用户还没有贡献过任何页面。' }}
        </p>
      </div>

      <div v-else>
        <h2 class="text-lg font-semibold mb-3">我订阅的页面 ({{ watches.length }})</h2>
        <ul v-if="watches.length" class="grid gap-3 sm:grid-cols-2">
          <li v-for="w in watches" :key="w.tag" class="flex items-center gap-3 rounded-lg border border-(--ui-border) px-4 py-3">
            <NuxtLink :to="`/${w.tag}`" class="font-medium hover:text-(--ui-primary) flex-1 truncate">
              {{ w.title }}
            </NuxtLink>
            <span class="text-xs text-(--ui-muted) shrink-0">订阅于 {{ formatDate(w.subscribed_at) }}</span>
            <UButton icon="i-lucide-bell-off" size="xs" color="neutral" variant="ghost" @click="unwatch(w.tag)">
              取消
            </UButton>
          </li>
        </ul>
        <p v-else class="text-sm text-(--ui-muted) py-8 text-center rounded-lg border border-(--ui-border)">
          你还没有订阅任何页面。在页面详情页点击「订阅」即可接收更新通知。
        </p>
      </div>
    </template>

    <!-- 编辑资料弹窗 -->
    <EditProfileModal v-model:open="editOpen" @saved="onProfileSaved" />
  </div>
</template>
