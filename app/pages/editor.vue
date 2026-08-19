<script setup lang="ts">
// 沉浸式全屏页面编辑器，使用默认布局（保留顶部导航栏）。
// 顶部一行显示已打开页面标签；工具栏显示页面名/标题 + 快捷语法 + 保存/预览；
// 离开自动保存工作区（后端持久化），再次打开自动恢复。

const api = useApi()
const { user, ready, site, init } = useAuth()
const toast = useToast()
const route = useRoute()
const { t } = useI18n()

// ==================== 权限 ====================
// 注册用户（等级 1 管理员 / 2 高级 / 3 普通）可用；0 访客无权限
const forbidden = ref(false)
const canUse = computed(() => [1, 2, 3].includes(user.value?.level ?? 0))

// 工作区：打开的页面标签 + 当前激活标签
const tabs = ref<{ tag: string; title: string; body: string; comment: string }[]>([])
const activeTag = ref('')

onMounted(async () => {
  await init()
  if (!canUse.value) {
    forbidden.value = true
    return
  }
  // 恢复工作区
  const r = await api.get('workspace.get')
  if (r.ok) {
    const d = r.data as any
    if (d?.tabs?.length) {
      tabs.value = (d.tabs as any[]).map((t) => ({
        tag: t.tag,
        title: t.title,
        body: t.body,
        comment: t.comment,
      }))
      lastSaved = JSON.stringify(tabs.value)
      activeTag.value = d.active_tag && tabs.value.some((t) => t.tag === d.active_tag) ? d.active_tag : tabs.value[0]!.tag
    }
  }
  // 支持通过 ?open=标签 直接加入工作区（页面点“编辑”跳转至此）
  const openParam = route.query.open
  const raw = typeof openParam === 'string' ? [openParam] : Array.isArray(openParam) ? openParam : []
  const toOpen = raw.filter((v): v is string => v != null)
  if (toOpen.length) {
    for (const tag of toOpen) {
      const t = decodeURIComponent(tag)
      if (!t || tabs.value.some((x) => x.tag === t)) continue
      const rr = await api.get('page.get', { tag: t })
      const d = rr.data as any
      const exists = rr.ok && d?.exists
      tabs.value.push({
        tag: t,
        title: exists ? d.page.title : t,
        body: exists ? d.page.body : '',
        comment: '',
      })
    }
    if (!activeTab.value && tabs.value.length) activeTag.value = tabs.value[0]!.tag
    // 清理 query，避免刷新后重复添加
    await navigateTo('/editor', { replace: true })
  }
})
onBeforeUnmount(() => {
  saveWorkspace()
})

// ==================== 工作区自动保存 ====================
let lastSaved = ''
const saveWorkspace = async () => {
  const current = JSON.stringify(tabs.value)
  if (current === lastSaved) return
  await api.post('workspace.save', { active_tag: activeTag.value, tabs: tabs.value })
  lastSaved = current
}

// ==================== 标签管理 ====================
const activeTab = computed(() => tabs.value.find((t) => t.tag === activeTag.value) || null)

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
const createPage = () => {
  const tag = newTag.value.trim()
  if (!tag) return
  newTag.value = ''
  createModal.value = false
  if (!tabs.value.some((x) => x.tag === tag)) {
    tabs.value.push({ tag, title: tag, body: '', comment: '' })
    saveWorkspace()
  }
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
  // 逐个拉取内容加入标签（页面不存在则以 tag 名为标题，空内容可创建）
  for (const tag of toOpen) {
    const r = await api.get('page.get', { tag })
    const d = r.data as any
    const exists = r.ok && d?.exists
    if (tabs.value.some((x) => x.tag === tag)) continue
    tabs.value.push({
      tag,
      title: exists ? d.page.title : tag,
      body: exists ? d.page.body : '',
      comment: '',
    })
  }
  if (!activeTab.value) activeTag.value = tabs.value[0]!.tag
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
  if (tab) tab.body = val.slice(0, start) + before + content + after + val.slice(end)
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
  const r = await api.post('page.save', { tag: tab.tag, title: tab.title, body: tab.body, comment: tab.comment })
  saving.value = false
  if (r.ok) {
    await saveWorkspace()
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
</script>

<template>
  <div v-if="forbidden" class="flex min-h-80 flex-col items-center justify-center gap-4">
    <!-- 权限不足（低于普通用户）禁止访问 -->
    <UIcon name="i-lucide-shield-alert" class="size-10 text-(--ui-error)" />
    <p class="text-(--ui-muted)">{{ t('editor.noPermission') }}</p>
    <UButton to="/" color="neutral" variant="subtle" :label="t('editor.backToHome')" />
  </div>
  <div v-else class="flex flex-col" style="height: calc(100dvh - 9rem)">
    <!-- 顶部一行：已打开页面标签 -->
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
          @click="activeTag = tab.tag"
        >
          <span class="max-w-40 truncate">{{ tab.tag }}</span>
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
        <UInput v-model="activeTab.title" size="sm" class="w-full" :placeholder="t('editor.pageTitle')" />
      </div>
      <!-- 工具栏二行：左快捷语法，右保存/预览 -->
      <div class="flex items-center justify-between gap-2 border-b border-(--ui-border) bg-(--ui-bg-elevated) px-2 py-1.5">
        <div class="flex flex-wrap items-center gap-1">
          <UTooltip v-for="tool in tools" :key="tool.label" :text="t(tool.label)">
            <UButton :icon="tool.icon" size="xs" color="neutral" variant="subtle" @click="tool.fn((b: string, a = '') => insert(activeTag, b, a))" />
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
            :rows="24"
            height="100%"
            :placeholder="t('editor.bodyPlaceholder')"
            :corner="previewOn ? 'bottom-left' : 'bottom'"
            class="min-h-0 flex-1"
            @update:model-value="(v: string) => { if (activeTab) activeTab.body = v }"
            @input="(e: any) => { if (e.target && activeTab) activeTab.body = (e.target as HTMLTextAreaElement).value }"
          />
        </div>
        <div v-if="previewOn" class="w-1/2 min-w-0 overflow-hidden rounded-br-lg border border-(--ui-border) border-l-0">
          <div class="h-full overflow-y-auto p-5">
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