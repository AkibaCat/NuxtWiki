<script setup lang="ts">
// 沉浸式全屏页面编辑器，使用默认布局（保留顶部导航栏）。
// 顶部一行显示已打开页面标签（未保存编辑以 * 标记）；工具栏显示页面名/标题 + 快捷语法 + 保存/预览。
// 打开页面进入编辑即获取编辑锁（防止他人并发编辑），离开编辑器自动释放。
// 开启「自动保存工作区」后，编辑内容自动保存到后端工作区并在下次打开时恢复。

const api = useApi()
const { user, ready, site, init } = useAuth()
const toast = useToast()
const route = useRoute()
const { t } = useI18n()

// ==================== 权限 ====================
// 注册用户（等级 1 管理员 / 2 高级 / 3 普通）可用；0 访客无权限
const forbidden = ref(false)
const canUse = computed(() => [1, 2, 3].includes(user.value?.level ?? 0))

// ==================== 自动保存工作区开关 ====================
const { enabled: autosave } = useEditorAutosave()

// 已打开的页面标签 + 当前激活标签（editorMode 为会话内的编辑器视图状态，不持久化）
// dirty：自上次保存到页面后是否有未保存编辑；baseUpdatedAt：内容加载进编辑器时的服务器时间（用于检测他人新提交）
// saved*：上次保存 / 加载时的内容快照，用于判断编辑是否已撤销回原样（一致则清除 dirty）
const tabs = ref<{ tag: string; title: string; body: string; style: string; comment: string; editorMode: 'content' | 'style'; dirty: boolean; baseUpdatedAt: string; savedTitle: string; savedBody: string; savedStyle: string }[]>([])
const activeTag = ref('')

onMounted(async () => {
  await init()
  if (!canUse.value) {
    forbidden.value = true
    return
  }
  // 开启自动保存时恢复上次的工作区草稿
  if (autosave.value) {
    await restoreWorkspace()
  }
  // 支持通过 ?open=标签 直接打开页面编辑（页面点“编辑”跳转至此）
  const openParam = route.query.open
  const raw = typeof openParam === 'string' ? [openParam] : Array.isArray(openParam) ? openParam : []
  const toOpen = raw.filter((v): v is string => v != null)
  for (const tag of toOpen) {
    await openTag(decodeURIComponent(tag))
  }
  if (!activeTab.value && tabs.value.length) activeTag.value = tabs.value[0]!.tag
  if (toOpen.length) {
    // 清理 query，避免刷新后重复添加
    await navigateTo('/editor', { replace: true })
  }
})

// 浏览器刷新 / 关闭：自动保存工作区草稿（开启自动保存时），不再弹窗拦截
const beforeUnload = () => {
  saveWorkspace()
}
onMounted(() => window.addEventListener('beforeunload', beforeUnload))
onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', beforeUnload)
  saveWorkspace()
  releaseAllLocks()
})

// ==================== 工作区（自动保存 / 恢复） ====================
const restoreWorkspace = async () => {
  const r = await api.get('workspace.get')
  if (!r.ok) return
  const d = r.data as any
  if (!d?.tabs?.length) return
  const restored = (d.tabs as any[]).map((tab) => ({
    tag: tab.tag,
    title: tab.title,
    body: tab.body,
    style: tab.style ?? '',
    comment: tab.comment ?? '',
    editorMode: 'content' as const,
    dirty: false,
    baseUpdatedAt: tab.baseUpdatedAt ?? '',
    // saved* 先留空，恢复后拉取服务器内容再比对，正确判定「未保存到页面的编辑」
    savedTitle: '',
    savedBody: '',
    savedStyle: '',
  }))
  // 恢复前先获取编辑锁：他人正编辑的页面跳过恢复
  const kept: typeof tabs.value = []
  for (const tab of restored) {
    if (await acquireLock(tab.tag)) kept.push(tab)
  }
  tabs.value = kept
  if (!kept.length) return
  activeTag.value = d.active_tag && kept.some((t) => t.tag === d.active_tag) ? d.active_tag : kept[0]!.tag
  // 逐个拉取服务器页面内容：与工作区内容比对判定「未保存编辑」（脏，显示 *）；
  // 同时检测草稿保存后是否有他人新提交（逐个询问）。
  const queued: string[] = []
  for (const tab of [...tabs.value]) {
    const pr = await api.get('page.get', { tag: tab.tag })
    const pd = pr.data as any
    if (pr.ok && pd?.exists) {
      tab.savedTitle = pd.page.title ?? ''
      tab.savedBody = pd.page.body ?? ''
      tab.savedStyle = pd.page.style ?? ''
      if (tab.baseUpdatedAt && toTime(pd.page.updated_at) > toTime(tab.baseUpdatedAt)) {
        queued.push(tab.tag)
      }
    } else {
      // 页面尚不存在（新建草稿）：基线为空页面
      tab.savedTitle = tab.tag
      tab.savedBody = ''
      tab.savedStyle = ''
    }
    // 工作区内容与已保存内容不一致 → 未保存到页面的编辑，显示 *
    tab.dirty = tab.title !== tab.savedTitle || tab.body !== tab.savedBody || tab.style !== tab.savedStyle
  }
  reloadQueue.value = queued
  nextConflict()
}

let saveTimer: ReturnType<typeof setTimeout> | null = null
const saveWorkspace = () => {
  if (!autosave.value) return
  if (saveTimer) {
    clearTimeout(saveTimer)
    saveTimer = null
  }
  api.post('workspace.save', { active_tag: activeTag.value, tabs: tabs.value }).catch(() => {})
}
const scheduleAutosave = () => {
  if (!autosave.value) return
  if (saveTimer) clearTimeout(saveTimer)
  saveTimer = setTimeout(() => {
    saveTimer = null
    saveWorkspace()
  }, 800)
}

// ==================== 编辑锁（页面并发编辑保护） ====================
// 打开页面进入编辑即获取编辑锁；他人持锁时禁止打开并弹窗提示。
// 持有期间每 30s 心跳续期；关闭标签或离开编辑器时释放（异常退出由服务端 90s 超时兜底）。
const lockHeld = new Set<string>()
let heartbeatTimer: ReturnType<typeof setInterval> | null = null
const lockModal = ref(false)
const lockMessage = ref('')

const releaseLock = (tag: string) => {
  if (!lockHeld.has(tag)) return
  lockHeld.delete(tag)
  api.post('page.unlock', { tag }).catch(() => {})
}
const releaseAllLocks = () => {
  if (heartbeatTimer) {
    clearInterval(heartbeatTimer)
    heartbeatTimer = null
  }
  const tags = [...lockHeld]
  lockHeld.clear()
  for (const tag of tags) {
    api.post('page.unlock', { tag }).catch(() => {})
  }
}
const startHeartbeat = () => {
  if (heartbeatTimer) return
  heartbeatTimer = setInterval(() => {
    for (const tag of [...lockHeld]) {
      api.post('page.lock', { tag }).catch(() => {})
    }
  }, 30000)
}
const acquireLock = async (tag: string): Promise<boolean> => {
  const r = await api.post('page.lock', { tag })
  if (r.ok) {
    lockHeld.add(tag)
    startHeartbeat()
    return true
  }
  if (r.error?.code === 'PAGE_LOCKED') {
    lockMessage.value = r.error.message
    lockModal.value = true
  }
  return false
}
const openTag = async (tag: string) => {
  if (!tag || tabs.value.some((x) => x.tag === tag)) return
  const r = await api.get('page.get', { tag })
  const d = r.data as any
  const exists = r.ok && d?.exists
  if (!(await acquireLock(tag))) return
  tabs.value.push({
    tag,
    title: exists ? d.page.title : tag,
    body: exists ? d.page.body : '',
    style: exists ? (d.page.style ?? '') : '',
    comment: '',
    editorMode: 'content',
    dirty: false,
    baseUpdatedAt: exists ? (d.page.updated_at ?? '') : '',
    savedTitle: exists ? d.page.title : tag,
    savedBody: exists ? d.page.body : '',
    savedStyle: exists ? (d.page.style ?? '') : '',
  })
}

// ==================== 标签管理 ====================
const activeTab = computed(() => tabs.value.find((t) => t.tag === activeTag.value) || null)

// 标记某标签已修改：按与已保存快照的比对结果刷新 dirty，并（开启自动保存时）延迟写入工作区
const markDirty = (tab: { title: string; body: string; style: string; savedTitle: string; savedBody: string; savedStyle: string; dirty: boolean } | null | undefined) => {
  if (!tab) return
  tab.dirty = tab.title !== tab.savedTitle || tab.body !== tab.savedBody || tab.style !== tab.savedStyle
  scheduleAutosave()
}

// 切换页面（标签）
const switchTab = (tag: string) => {
  if (tag !== activeTag.value) activeTag.value = tag
}

// ==================== 工作区恢复后的「他人新提交」检测 ====================
const reloadQueue = ref<string[]>([])
const reloadModal = ref(false)
const conflictTag = ref('')
const toTime = (s: string) => {
  const n = Date.parse(String(s).trim().replace(' ', 'T'))
  return Number.isNaN(n) ? 0 : n
}
const nextConflict = () => {
  if (!reloadQueue.value.length) return
  conflictTag.value = reloadQueue.value.shift()!
  reloadModal.value = true
}
const confirmReload = async () => {
  reloadModal.value = false
  const tag = conflictTag.value
  conflictTag.value = ''
  const tab = tabs.value.find((x) => x.tag === tag)
  if (!tab) return
  const r = await api.get('page.get', { tag })
  const d = r.data as any
  if (r.ok && d?.exists) {
    tab.title = d.page.title
    tab.body = d.page.body
    tab.style = d.page.style ?? ''
    tab.dirty = false
    tab.baseUpdatedAt = d.page.updated_at ?? ''
    tab.savedTitle = d.page.title
    tab.savedBody = d.page.body
    tab.savedStyle = d.page.style ?? ''
  }
  nextConflict()
}
const cancelReload = () => {
  reloadModal.value = false
  conflictTag.value = ''
  nextConflict()
}

// ==================== <title> 动态标题（写入全局覆盖，由布局统一输出） ====================
const siteName = computed(() => site.value?.name || 'NuxtWiki')
const { setTitle } = useWikiTitle()
const editorTitle = computed(() => {
  const base = siteName.value
  if (!activeTab.value) return `${base} | ${t('nav.editor')}`
  const tag = activeTab.value.tag
  // Home 为特殊页，括号内固定显示「首页」
  const isHome = tag === site.value?.home_tag || tag === 'Home' || tag === 'HomePage'
  const displayTitle = isHome ? t('nav.home') : (activeTab.value.title || tag)
  const suffix = isHome ? t('editor.title.home') : t('editor.title.withPage', { title: displayTitle })
  return `${base} | ${suffix}`
})
watch(editorTitle, (v) => setTitle(v), { immediate: true })
onUnmounted(() => setTitle(null))

// ==================== 打开页面弹窗 ====================
const openModal = ref(false)
const allPages = ref<any[]>([])
const selected = ref<string[]>([])
const listLoading = ref(false)
const openPicker = async () => {
  selected.value = []
  openModal.value = true
  listLoading.value = true
  const r = await api.get('page.list')
  listLoading.value = false
  if (r.ok) allPages.value = (r.data as any[]) ?? []
}
// ==================== 创建新页面 ====================
const createModal = ref(false)
const newTag = ref('')
const createPage = async () => {
  const tag = newTag.value.trim()
  if (!tag) return
  newTag.value = ''
  createModal.value = false
  if (tabs.value.some((x) => x.tag === tag)) {
    activeTag.value = tag
    return
  }
  // 新建页面同样获取编辑锁，防止他人并发创建同一页面
  if (!(await acquireLock(tag))) return
  tabs.value.push({ tag, title: tag, body: '', style: '', comment: '', editorMode: 'content', dirty: false, baseUpdatedAt: '', savedTitle: tag, savedBody: '', savedStyle: '' })
  activeTag.value = tag
}

const toggleSelect = (tag: string) => {
  const i = selected.value.indexOf(tag)
  if (i >= 0) selected.value.splice(i, 1)
  else selected.value.push(tag)
}
const confirmOpen = async () => {
  const toOpen = selected.value.filter((t) => !tabs.value.some((x) => x.tag === t))
  if (!toOpen.length) {
    openModal.value = false
    return
  }
  // 逐个拉取内容并获取编辑锁加入标签（页面不存在则以 tag 名为标题，空内容可创建）
  for (const tag of toOpen) {
    await openTag(tag)
  }
  if (!activeTab.value && tabs.value.length) activeTag.value = tabs.value[0]!.tag
  openModal.value = false
}
const closeTab = (tag: string) => {
  const i = tabs.value.findIndex((t) => t.tag === tag)
  if (i < 0) return
  tabs.value.splice(i, 1)
  if (activeTag.value === tag) {
    const next = tabs.value[i] || tabs.value[i - 1]
    activeTag.value = next ? next.tag : ''
  }
  releaseLock(tag)
  scheduleAutosave()
}

// ==================== 工具栏 ====================
const editorRefMap = new Map<string, any>()
const getTextarea = (tag: string) => editorRefMap.get(tag)?.getTextarea() as HTMLTextAreaElement | null
const insert = (tag: string, before: string, after = '') => {
  const tab = tabs.value.find((t) => t.tag === tag)
  const el = getTextarea(tag)
  const val = tab?.body || ''
  const start = el?.selectionStart ?? val.length
  const end = el?.selectionEnd ?? val.length
  const hasSel = el ? start !== end : false
  const content = hasSel ? val.slice(start, end) : after !== '' ? t('editor.placeholder.text') : ''
  if (tab) {
    tab.body = val.slice(0, start) + before + content + after + val.slice(end)
    markDirty(tab)
  }
  requestAnimationFrame(() => {
    const t = getTextarea(tag)
    if (t) {
      t.focus()
      t.setSelectionRange(start + before.length, start + before.length + content.length)
    }
  })
}
const tools = [
  { label: 'editor.tools.h2', icon: 'i-lucide-heading-2', fn: (ins: any) => ins('## ') },
  { label: 'editor.tools.h3', icon: 'i-lucide-heading-3', fn: (ins: any) => ins('### ') },
  { label: 'editor.tools.bold', icon: 'i-lucide-bold', fn: (ins: any) => ins('**', '**') },
  { label: 'editor.tools.italic', icon: 'i-lucide-italic', fn: (ins: any) => ins('*', '*') },
  { label: 'editor.tools.underline', icon: 'i-lucide-underline', fn: (ins: any) => ins('__', '__') },
  { label: 'editor.tools.strike', icon: 'i-lucide-strikethrough', fn: (ins: any) => ins('~~', '~~') },
  { label: 'editor.tools.code', icon: 'i-lucide-code', fn: (ins: any) => ins('`', '`') },
  { label: 'editor.tools.codeBlock', icon: 'i-lucide-code-xml', fn: (ins: any) => ins('```\n', '\n```') },
  { label: 'editor.tools.link', icon: 'i-lucide-link', fn: (ins: any) => ins(`[[${t('editor.placeholder.linkDisplay')}|${t('editor.placeholder.linkPage')}]]`) },
  { label: 'editor.tools.externalLink', icon: 'i-lucide-globe', fn: (ins: any) => ins(`[${t('editor.placeholder.linkText')}](${t('editor.placeholder.linkUrl')})`) },
  { label: 'editor.tools.image', icon: 'i-lucide-image', fn: (ins: any) => ins(`![${t('editor.placeholder.imageAlt')}](${t('editor.placeholder.imageUrl')})`) },
  { label: 'editor.tools.list', icon: 'i-lucide-list', fn: (ins: any) => ins(`- ${t('editor.placeholder.listItem')}\n`) },
  { label: 'editor.tools.table', icon: 'i-lucide-table', fn: (ins: any) => ins(`| ${t('editor.placeholder.tableHeader1')} | ${t('editor.placeholder.tableHeader2')} |\n| --- | --- |\n| ${t('editor.placeholder.tableContent1')} | ${t('editor.placeholder.tableContent2')} |\n`) },
  { label: 'editor.tools.quote', icon: 'i-lucide-quote', fn: (ins: any) => ins(`> ${t('editor.placeholder.quote')}\n`) },
  { label: 'editor.tools.divider', icon: 'i-lucide-minus', fn: (ins: any) => ins('\n---\n') },
]

// ==================== 保存 / 预览 ====================
const saving = ref(false)
const previewOn = ref(false)
const saveModal = ref(false)
const savedTag = ref('')
const save = async () => {
  const tab = activeTab.value
  if (!tab) return
  if (!tab.title.trim()) {
    toast.add({ title: t('editor.titleRequired'), color: 'error' })
    return
  }
  saving.value = true
  const r = await api.post('page.save', { tag: tab.tag, title: tab.title, body: tab.body, style: tab.style, comment: tab.comment })
  saving.value = false
  if (r.ok) {
    tab.dirty = false
    tab.savedTitle = tab.title
    tab.savedBody = tab.body
    tab.savedStyle = tab.style
    if (r.data?.updated_at) tab.baseUpdatedAt = r.data.updated_at
    savedTag.value = tab.tag
    saveModal.value = true
  } else {
    toast.add({ title: r.error?.message || t('editor.saveFailed'), color: 'error' })
  }
}
const currentPreview = computed(() => {
  const tab = activeTab.value
  if (!tab) return null
  try {
    return renderWiki(tab.body || '', { tag: tab.tag })
  } catch {
    return null
  }
})
// 页面样式的编译产物（作用域为 .wiki-content），用于预览实时反馈
const styleCompile = computed(() => compilePageStyleResult(activeTab.value?.style || ''))

// 预览样式标记：用 while 直接写 textContent，确保实时刷新（含改回空串时清空）。
// 同时监听 previewOn 以保证「已编译好的样式」在首次开启预览时也会写入——
// <style> 元素仅在预览开启时才挂载成立，仅监听 css 变化会漏掉首次打开的情况。
const previewStyleEl = ref<any>(null)
watch(() => [styleCompile.value.css, previewOn.value], () => {
  if (previewStyleEl.value) previewStyleEl.value.textContent = styleCompile.value.css || ''
}, { immediate: true, flush: 'post' })
</script>

<template>
  <div v-if="forbidden" class="flex min-h-80 flex-col items-center justify-center gap-4">
    <!-- 权限不足（低于普通用户）禁止访问 -->
    <UIcon name="i-lucide-shield-alert" class="size-10 text-(--ui-error)" />
    <p class="text-(--ui-muted)">{{ t('editor.noPermission') }}</p>
    <UButton to="/" color="neutral" variant="subtle" :label="t('editor.backToHome')" />
  </div>
  <div v-else class="flex flex-col" style="height: calc(100dvh - 9rem)">
    <!-- 顶部一行：已打开页面标签（未保存以 * 标记） -->
    <div class="flex items-center gap-1 overflow-x-auto border-b border-(--ui-border) bg-(--ui-bg-elevated) px-2 py-1.5">
      <UButton size="xs" color="neutral" variant="ghost" icon="i-lucide-folder-open" :label="t('editor.openPage')" @click="openPicker" />
      <UButton size="xs" color="neutral" variant="ghost" icon="i-lucide-file-plus-2" :label="t('editor.createPage')" @click="createModal = true" />
      <template v-if="tabs.length">
        <UButton
          v-for="tab in tabs"
          :key="tab.tag"
          size="xs"
          :color="tab.tag === activeTag ? 'primary' : 'neutral'"
          :variant="tab.tag === activeTag ? 'soft' : 'ghost'"
          @click="switchTab(tab.tag)"
        >
          <span class="max-w-40 truncate">{{ tab.tag }}</span>
          <span v-if="tab.dirty" class="text-(--ui-warning)">*</span>
          <UIcon name="i-lucide-x" class="size-3 shrink-0" @click.stop="closeTab(tab.tag)" />
        </UButton>
      </template>
    </div>

    <!-- 未打开任何页面：提示 + 打开页面按钮 -->
    <div v-if="!tabs.length" class="flex flex-1 flex-col items-center justify-center gap-4">
      <UIcon name="i-lucide-file-edit" class="size-10 text-(--ui-muted)" />
      <p class="text-(--ui-muted)">{{ t('editor.emptyWorkspace') }}</p>
      <UButton icon="i-lucide-folder-open" :label="t('editor.openPage')" @click="openPicker" />
    </div>

    <!-- 编辑器主体 -->
    <div v-else-if="activeTab" class="flex min-h-0 flex-1 flex-col">
      <!-- 工具栏一行：页面名 + 页面标题 -->
      <div class="flex items-center gap-3 border-b border-(--ui-border) bg-(--ui-bg-elevated) px-4 py-2">
        <span class="text-xs font-semibold text-(--ui-muted) shrink-0">{{ activeTag }}</span>
        <UInput
          :model-value="activeTab.title"
          size="sm"
          class="w-full"
          :placeholder="t('editor.pageTitle')"
          @update:model-value="(v: string | null) => { if (activeTab && v != null) { activeTab.title = v; markDirty(activeTab) } }"
        />
      </div>
      <!-- 工具栏二行：左快捷语法，右保存/预览 -->
      <div class="flex items-center justify-between gap-2 border-b border-(--ui-border) bg-(--ui-bg-elevated) px-2 py-1.5">
        <div class="flex flex-wrap items-center gap-1">
          <UTooltip v-for="tool in tools" :key="tool.label" :text="t(tool.label)">
            <UButton :icon="tool.icon" size="xs" color="neutral" variant="subtle" :disabled="activeTab.editorMode === 'style'" @click="tool.fn((b: string, a = '') => insert(activeTag, b, a))" />
          </UTooltip>
        </div>
        <div class="flex items-center gap-2 shrink-0">
          <UButton
            size="sm"
            color="neutral"
            variant="subtle"
            :icon="previewOn ? 'i-lucide-eye-off' : 'i-lucide-eye'"
            :label="previewOn ? t('editor.closePreview') : t('editor.preview')"
            @click="previewOn = !previewOn"
          />
          <UButton size="sm" icon="i-lucide-save" :loading="saving" :label="t('common.save')" @click="save" />
        </div>
      </div>

      <!-- 编辑框：预览开启时右半边显示预览 -->
      <div class="flex min-h-0 flex-1" :class="previewOn ? '' : 'flex-col'">
        <div :class="[previewOn ? 'w-1/2 min-w-0 flex flex-col overflow-hidden border-r border-(--ui-border)' : 'flex-1 min-h-0 flex flex-col']">
          <MarkdownEditor
            v-if="activeTab"
            :key="activeTag"
            :model-value="activeTab.body"
            :style-value="activeTab.style"
            :mode="activeTab.editorMode"
            :rows="24"
            height="100%"
            :placeholder="t('editor.bodyPlaceholder')"
            :style-placeholder="t('editor.stylePlaceholder')"
            :corner="previewOn ? 'bottom-left' : 'bottom'"
            class="min-h-0 flex-1"
            @update:model-value="(v: string) => { if (activeTab) { activeTab.body = v; markDirty(activeTab) } }"
            @update:style-value="(v: string) => { if (activeTab) { activeTab.style = v; markDirty(activeTab) } }"
            @update:mode="(v: 'content' | 'style') => { if (activeTab) activeTab.editorMode = v }"
          />
        </div>
        <div v-if="previewOn" class="w-1/2 min-w-0 flex flex-col overflow-hidden rounded-br-lg border border-(--ui-border) border-l-0">
          <p
            v-if="styleCompile.error"
            class="flex-none border-b border-(--ui-error)/40 bg-(--ui-error)/10 px-3 py-1.5 text-xs text-(--ui-error) break-all"
          >
            {{ styleCompile.error }}
          </p>
          <component :is="'style'" ref="previewStyleEl"></component>
          <div class="min-h-0 flex-1 overflow-y-auto p-5">
            <div v-if="currentPreview" class="wiki-content" v-html="currentPreview.html"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- 保存成功弹窗 -->
    <UModal v-model:open="saveModal">
      <template #content>
        <UCard>
          <p class="py-6 text-center text-(--ui-text)">{{ t('editor.saved') }}</p>
          <template #footer>
            <div class="flex items-center justify-end gap-2">
              <UButton variant="subtle" color="neutral" @click="saveModal = false">{{ t('editor.savedNo') }}</UButton>
              <UButton :label="t('editor.savedYes')" @click="saveModal = false; navigateTo(`/${savedTag}`)" />
            </div>
          </template>
        </UCard>
      </template>
    </UModal>

    <!-- 页面存在他人新提交：重新加载确认弹窗（橙色） -->
    <UModal v-model:open="reloadModal">
      <template #content>
        <UCard :ui="{ header: 'border-b border-(--ui-warning)/40' }">
          <template #header>
            <div class="flex items-center gap-2 text-(--ui-warning)">
              <UIcon name="i-lucide-refresh-cw" class="size-5 shrink-0" />
              <span class="font-semibold">{{ t('editor.newCommitTitle') }}</span>
            </div>
          </template>
          <p class="whitespace-pre-line py-2 text-sm text-(--ui-text)">{{ t('editor.reloadMessage') }}</p>
          <template #footer>
            <div class="flex items-center justify-end gap-2">
              <UButton color="neutral" variant="subtle" @click="cancelReload">{{ t('editor.stay') }}</UButton>
              <UButton color="warning" @click="confirmReload">{{ t('editor.reload') }}</UButton>
            </div>
          </template>
        </UCard>
      </template>
    </UModal>

    <!-- 页面正被他人编辑弹窗 -->
    <UModal v-model:open="lockModal">
      <template #content>
        <UCard>
          <template #header>{{ t('editor.lockedTitle') }}</template>
          <p class="flex items-start gap-2 py-2 text-sm text-(--ui-text)">
            <UIcon name="i-lucide-lock" class="mt-0.5 size-5 shrink-0 text-(--ui-warning)" />
            <span class="break-words">{{ lockMessage }}</span>
          </p>
          <template #footer>
            <div class="flex items-center justify-end">
              <UButton :label="t('common.ok')" @click="lockModal = false" />
            </div>
          </template>
        </UCard>
      </template>
    </UModal>

    <!-- 打开页面弹窗：四列展示，可多选 -->
    <UModal v-model:open="openModal" scrollable :ui="{ content: 'max-w-4xl max-h-[85vh]' }">
      <template #content>
        <UCard>
          <template #header>{{ t('editor.selectPage') }}</template>
          <div v-if="listLoading" class="flex justify-center py-10">
            <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-(--ui-primary)" />
          </div>
          <div v-else-if="allPages.length" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2 max-h-[50vh] overflow-y-auto pr-1">
            <UButton
              v-for="p in allPages"
              :key="p.tag"
              variant="subtle"
              :color="selected.includes(p.tag) ? 'primary' : 'neutral'"
              class="justify-start"
              @click="toggleSelect(p.tag)"
            >
              <UIcon v-if="selected.includes(p.tag)" name="i-lucide-check" class="size-4 shrink-0" />
              <UIcon v-else name="i-lucide-file-text" class="size-4 shrink-0" />
              <span class="truncate">{{ p.title }}</span>
            </UButton>
          </div>
          <div v-else class="py-10 text-center text-(--ui-muted)">{{ t('editor.noPages') }}</div>
          <template #footer>
            <div class="flex items-center justify-end gap-2">
              <UButton variant="subtle" color="neutral" @click="openModal = false">{{ t('common.cancel') }}</UButton>
              <UButton icon="i-lucide-folder-open" :label="t('editor.openSelected')" :disabled="!selected.length" @click="confirmOpen">
                {{ t('common.confirm') }}
              </UButton>
            </div>
          </template>
        </UCard>
      </template>
    </UModal>

    <!-- 创建新页面弹窗 -->
    <UModal v-model:open="createModal">
      <template #content>
        <UCard>
          <template #header>{{ t('editor.createNewPage') }}</template>
          <form class="space-y-4" @submit.prevent="createPage">
            <UFormField :label="t('editor.newPageName')" required>
              <UInput v-model="newTag" :placeholder="t('editor.newPagePlaceholder')" autofocus />
            </UFormField>
            <div class="flex items-center justify-end gap-2">
              <UButton type="button" variant="subtle" color="neutral" @click="createModal = false">{{ t('common.cancel') }}</UButton>
              <UButton type="submit" icon="i-lucide-file-plus-2" :label="t('common.create')" />
            </div>
          </form>
        </UCard>
      </template>
    </UModal>
  </div>
</template>

<style scoped>
/* 页面编辑器可点击按钮 hover 时显示点击（pointer）光标 */
:deep(button:not(:disabled)),
:deep(button:not(:disabled):hover) {
  cursor: pointer;
}
</style>
