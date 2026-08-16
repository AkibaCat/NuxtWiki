<script setup lang="ts">
const route = useRoute()
const api = useApi()
const tag = computed(() => String(route.params.tag || ''))

const diff = ref<any>(null)
const revisions = ref<any[]>([])
const loading = ref(true)
const error = ref('')
const fromRev = ref(0)
const toRev = ref(0)

const compare = async (from?: number, to?: number) => {
  loading.value = true
  const r = await api.get('page.diff', { tag: tag.value, from: from || undefined, to: to || undefined })
  if (r.ok) {
    diff.value = r.data
    fromRev.value = (r.data as any).from
    toRev.value = (r.data as any).to
  } else {
    error.value = r.error?.message || '加载失败'
  }
  loading.value = false
}

onMounted(async () => {
  const r = await api.get('page.revisions', { tag: tag.value })
  if (r.ok) {
    revisions.value = (r.data as any[]) ?? []
    const from = Number(route.query.from || 0)
    const to = Number(route.query.to || revisions.value[0]?.revision || 0)
    await compare(from, to)
  } else {
    error.value = r.error?.message || '加载失败'
    loading.value = false
  }
})

// 将 diff 行（前缀：'  '=相同 / '- '=删除 / '+ '=新增）转换为左右双列对齐的行
const rows = computed(() => {
  const lines = (diff.value?.lines as string[] | undefined) ?? []
  return lines.map((e) => {
    if (e.startsWith('- ')) return { left: e.slice(2), right: '', type: 'del' }
    if (e.startsWith('+ ')) return { left: '', right: e.slice(2), type: 'add' }
    return { left: e.slice(2), right: e.slice(2), type: 'same' }
  })
})
</script>

<template>
  <div class="max-w-4xl mx-auto px-4 py-8">
    <div class="flex items-center gap-3 mb-6">
      <UButton :to="`/${tag}`" icon="i-lucide-arrow-left" size="xs" color="neutral" variant="ghost">
        返回页面
      </UButton>
      <h1 class="text-2xl font-bold">版本对比</h1>
    </div>

    <div v-if="loading" class="flex justify-center py-16">
      <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-(--ui-primary)" />
    </div>
    <div v-else-if="error" class="text-center py-16 text-(--ui-error)">{{ error }}</div>
    <div v-else>
      <div class="flex flex-wrap items-center gap-2 mb-4">
        <USelect
          v-model="fromRev"
          size="sm"
          :items="revisions.map(t => ({ label: `r${t.revision} · ${formatDate(t.created_at)}`, value: t.revision }))"
          class="w-64"
        />
        <span class="text-sm text-(--ui-muted)">→</span>
        <USelect
          v-model="toRev"
          size="sm"
          :items="revisions.map(t => ({ label: `r${t.revision} · ${formatDate(t.created_at)}`, value: t.revision }))"
          class="w-64"
        />
        <UButton size="sm" icon="i-lucide-git-compare" @click="compare(fromRev, toRev)">
          对比
        </UButton>
      </div>

      <div class="rounded-lg border border-(--ui-border) overflow-hidden">
        <div class="grid grid-cols-2 text-sm border-b border-b-(--ui-border) bg-(--ui-bg-elevated)">
          <div class="px-4 py-2 font-medium">{{ tag }}
            <span v-if="diff.from_meta" class="text-(--ui-muted) font-normal">r{{ diff.from_meta.revision }}（{{ diff.from_meta.nickname || diff.from_meta.username || '匿名' }}）</span>
            <span v-else class="text-(--ui-muted) font-normal">空（新增）</span>
          </div>
          <div class="px-4 py-2 font-medium border-l border-l-(--ui-border)">{{ tag }}
            <span v-if="diff.to_meta" class="text-(--ui-muted) font-normal">r{{ diff.to_meta.revision }}（{{ diff.to_meta.nickname || diff.to_meta.username || '匿名' }}）</span>
          </div>
        </div>
        <div class="overflow-x-auto">
          <div v-for="(row, i) in rows" :key="i" class="grid grid-cols-2 text-xs leading-relaxed font-mono">
            <div
              class="px-4 py-0.5 whitespace-pre-wrap break-words min-h-4"
              :class="row.type === 'del' ? 'bg-(--ui-error)/10 text-(--ui-error)' : row.type === 'add' ? 'bg-(--ui-bg-elevated) text-(--ui-muted)' : ''"
            >{{ row.left }}</div>
            <div
              class="px-4 py-0.5 whitespace-pre-wrap break-words min-h-4 border-l border-l-(--ui-border)"
              :class="row.type === 'add' ? 'bg-(--ui-success)/10 text-(--ui-success)' : row.type === 'del' ? 'bg-(--ui-bg-elevated) text-(--ui-muted)' : ''"
            >{{ row.right }}</div>
          </div>
          <p v-if="!rows.length" class="text-center py-8 text-sm text-(--ui-muted)">两个版本内容一致</p>
        </div>
      </div>
    </div>
  </div>
</template>
