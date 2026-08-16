<script setup lang="ts">
const route = useRoute()
const api = useApi()
const { init } = useAuth()
const toast = useToast()
const tag = computed(() => String(route.params.tag || ''))

const title = ref('')
const body = ref('')
const comment = ref('')
const loading = ref(true)
const saving = ref(false)
const mode = ref<'edit' | 'preview'>('edit')
const canEdit = ref(false)
const exists = ref(false)
const textarea = ref()

const getTextarea = () => (document.querySelector('textarea[data-slot="base"]') as HTMLTextAreaElement | null) ?? null
const caret = ref({ start: 0, end: 0 })
const syncCaret = () => {
  const el = getTextarea()
  if (el) caret.value = { start: el.selectionStart, end: el.selectionEnd }
}

// 撤销 / 恢复历史
const history = ref<string[]>([''])
const redoStack = ref<string[]>([])
let lastInput = 0
const pushHistory = (val: string, merge = false) => {
  const h = history.value
  if (val === h[h.length - 1]) return
  const now = Date.now()
  if (merge && now - lastInput < 400) h[h.length - 1] = val
  else {
    h.push(val)
    if (h.length > 200) h.shift()
  }
  redoStack.value = []
  lastInput = now
}
const focusEnd = () => {
  requestAnimationFrame(() => {
    const el = getTextarea()
    if (el) {
      el.focus()
      el.setSelectionRange(body.value.length, body.value.length)
    }
  })
}
const undo = () => {
  const h = history.value
  if (h.length <= 1) return
  redoStack.value.push(body.value)
  h.pop()
  body.value = h[h.length - 1]
  lastInput = 0
  focusEnd()
}
const redo = () => {
  if (!redoStack.value.length) return
  history.value.push(body.value)
  body.value = redoStack.value.pop()!
  lastInput = 0
  focusEnd()
}
const onInput = (e: Event) => pushHistory((e.target as HTMLTextAreaElement).value, true)
const onKeydown = (e: KeyboardEvent) => {
  if (!(e.ctrlKey || e.metaKey)) return
  const k = e.key.toLowerCase()
  if (k === 'z') {
    e.preventDefault()
    e.shiftKey ? redo() : undo()
  } else if (k === 'y') {
    e.preventDefault()
    redo()
  }
}

onMounted(async () => {
  await init()
  const r = await api.get('page.get', { tag: tag.value })
  if (r.ok) {
    const d = r.data as any
    canEdit.value = !!d.can_edit
    if (d.exists) {
      exists.value = true
      title.value = d.page.title
      body.value = d.page.body
      history.value = [body.value]
      redoStack.value = []
    } else {
      title.value = tag.value
    }
  }
  loading.value = false
})

const preview = computed(() => {
  try {
    return renderWiki(body.value, { tag: tag.value })
  } catch {
    return null
  }
})

const insert = (before: string, after = '') => {
  const el = getTextarea()
  if (!el) {
    body.value += before + after
    pushHistory(body.value)
    return
  }
  const focused = document.activeElement === el
  const start = focused ? el.selectionStart : caret.value.start
  const end = focused ? el.selectionEnd : caret.value.end
  const hasSel = start !== end
  const selected = body.value.slice(start, end)
  // 仅包裹型快捷键（有 after）且无选区时，才插入占位「文本」；普通插入不再追加
  const content = hasSel ? selected : after !== '' ? '文本' : ''
  body.value = body.value.slice(0, start) + before + content + after + body.value.slice(end)
  pushHistory(body.value)
  requestAnimationFrame(() => {
    const t = getTextarea()
    if (t) {
      t.focus()
      t.setSelectionRange(start + before.length, start + before.length + content.length)
    }
  })
}

const tools = [
  { label: '标题2', icon: 'i-lucide-heading-2', fn: () => insert('## ') },
  { label: '标题3', icon: 'i-lucide-heading-3', fn: () => insert('### ') },
  { label: '标题4', icon: 'i-lucide-heading-4', fn: () => insert('#### ') },
  { label: '粗体', icon: 'i-lucide-bold', fn: () => insert('**', '**') },
  { label: '斜体', icon: 'i-lucide-italic', fn: () => insert('*', '*') },
  { label: '下划线', icon: 'i-lucide-underline', fn: () => insert('__', '__') },
  { label: '删除线', icon: 'i-lucide-strikethrough', fn: () => insert('~~', '~~') },
  { label: '等宽', icon: 'i-lucide-code', fn: () => insert('`', '`') },
  { label: '代码块', icon: 'i-lucide-code-xml', fn: () => insert('```\n', '\n```') },
  { label: '内链', icon: 'i-lucide-link', fn: () => insert('[[显示名|页面名]]') },
  { label: '外链', icon: 'i-lucide-globe', fn: () => insert('[链接文字](https://example.com)') },
  { label: '图片', icon: 'i-lucide-image', fn: () => insert('![图片说明](https://example.com/image.png)') },
  { label: '列表', icon: 'i-lucide-list', fn: () => insert('- 项目\n') },
  { label: '表格', icon: 'i-lucide-table', fn: () => insert('| 表头1 | 表头2 |\n| --- | --- |\n| 内容1 | 内容2 |\n') },
  { label: '引用', icon: 'i-lucide-quote', fn: () => insert('> 引用内容\n') },
  { label: '分隔线', icon: 'i-lucide-minus', fn: () => insert('\n---\n') }
]

const save = async () => {
  if (!title.value.trim()) {
    toast.add({ title: '请填写标题', color: 'error' })
    return
  }
  saving.value = true
  const r = await api.post('page.save', { tag: tag.value, title: title.value, body: body.value, comment: comment.value })
  saving.value = false
  if (r.ok) {
    toast.add({ title: (r.data as any).created ? '页面已创建' : '已保存', color: 'success' })
    await navigateTo(`/${tag.value}`)
  } else {
    toast.add({ title: r.error?.message || '保存失败', color: 'error' })
  }
}
</script>

<template>
  <div class="max-w-4xl mx-auto px-4 py-8">
    <div v-if="loading" class="flex justify-center py-16">
      <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-(--ui-primary)" />
    </div>
    <template v-else>
      <div v-if="canEdit">
        <div class="flex items-center justify-between mb-4">
          <h1 class="text-2xl font-bold">{{ exists ? '编辑' : '创建' }}页面</h1>
          <div class="flex items-center gap-2">
            <div class="flex items-center gap-1 rounded-lg border border-(--ui-border) p-1">
              <UButton size="sm" :variant="mode === 'edit' ? 'solid' : 'ghost'" :color="mode === 'edit' ? 'primary' : 'neutral'" label="编辑" @click="mode = 'edit'" />
              <UButton size="sm" :variant="mode === 'preview' ? 'solid' : 'ghost'" :color="mode === 'preview' ? 'primary' : 'neutral'" label="预览" @click="mode = 'preview'" />
            </div>
            <UButton color="neutral" variant="ghost" :to="`/${tag}`" icon="i-lucide-arrow-left" label="返回" />
          </div>
        </div>

        <template v-if="mode === 'preview'">
          <div v-if="preview" class="wiki-content rounded-lg border border-(--ui-border) p-6" v-html="preview.html" />
        </template>

        <UForm v-else class="space-y-4" @submit="save">
          <UFormField label="页面名">
            <UInput :model-value="tag" disabled class="w-full" />
          </UFormField>
          <UFormField label="标题">
            <UInput v-model="title" placeholder="页面标题" required class="w-full" />
          </UFormField>
          <UFormField label="正文（Markdown 语法）">
            <div class="flex flex-wrap gap-1 mb-2">
              <UTooltip v-for="t in tools" :key="t.label" :text="t.label">
                <UButton :icon="t.icon" size="xs" color="neutral" variant="subtle" @click="t.fn" />
              </UTooltip>
            </div>
            <UTextarea
              ref="textarea"
              v-model="body"
              :rows="18"
              class="font-mono text-sm w-full"
              placeholder="使用 Markdown 语法编写内容…"
              @input="onInput"
              @keydown="onKeydown"
              @keyup="syncCaret"
              @mouseup="syncCaret"
              @blur="syncCaret"
            />
          </UFormField>
          <UFormField label="编辑摘要（可选）">
            <UInput v-model="comment" placeholder="简要描述本次修改" class="w-full" />
          </UFormField>
          <div class="flex items-center gap-3">
            <UButton type="submit" icon="i-lucide-save" :loading="saving" label="保存" />
            <UButton color="neutral" variant="ghost" :to="`/${tag}`" label="取消" />
          </div>
        </UForm>
      </div>

      <div v-else class="text-center py-16">
        <UIcon name="i-lucide-lock" class="size-10 text-(--ui-error) mb-4" />
        <p class="mb-4">你没有编辑该页面的权限。</p>
        <UButton :to="`/login`" label="去登录" />
      </div>
    </template>
  </div>
</template>
