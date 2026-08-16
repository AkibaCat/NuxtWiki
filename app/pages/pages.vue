<script setup lang="ts">
const api = useApi()

const pages = ref<any[]>([])
const loading = ref(true)
const error = ref('')
const q = ref('')
const letter = ref('')

onMounted(async () => {
  const r = await api.get('page.list')
  if (r.ok) {
    pages.value = (r.data as any[]) ?? []
  } else {
    error.value = r.error?.message || '加载失败'
  }
  loading.value = false
})

const filtered = computed(() => {
  let list = pages.value
  if (letter.value) list = list.filter((p) => (p.tag as string).slice(0, 1).toUpperCase() === letter.value)
  if (q.value) {
    const k = q.value.toLowerCase()
    list = list.filter((p) => p.tag.toLowerCase().includes(k) || p.title.toLowerCase().includes(k))
  }
  return list
})

const letters = computed(() => {
  const set = new Set<string>()
  for (const p of pages.value) set.add((p.tag as string).slice(0, 1).toUpperCase())
  return Array.from(set).sort()
})
</script>

<template>
  <div class="max-w-4xl mx-auto px-4 py-8">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
      <h1 class="text-2xl font-bold">全部页面</h1>
      <div class="flex items-center gap-2">
        <UInput v-model="q" icon="i-lucide-search" placeholder="筛选页面…" class="w-56" />
      </div>
    </div>

    <div class="flex flex-wrap gap-1.5 mb-6">
      <UButton size="xs" color="neutral" variant="ghost" :class="{ 'text-(--ui-primary)': letter === '' }" @click="letter = ''">
        全部
      </UButton>
      <UButton
        v-for="l in letters"
        :key="l"
        size="xs"
        color="neutral"
        variant="ghost"
        :class="{ 'text-(--ui-primary)': letter === l }"
        @click="letter = letter === l ? '' : l"
      >
        {{ l }}
      </UButton>
    </div>

    <div v-if="loading" class="flex justify-center py-16">
      <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-(--ui-primary)" />
    </div>
    <div v-else-if="error" class="text-center py-16 text-(--ui-error)">{{ error }}</div>
    <div v-else>
      <div v-if="filtered.length" class="grid gap-3 md:grid-cols-2">
        <NuxtLink
          v-for="p in filtered"
          :key="p.tag"
          :to="`/${p.tag}`"
          class="rounded-lg border border-(--ui-border) p-3 flex items-center gap-3 hover:bg-(--ui-bg-elevated) hover:border-(--ui-primary)/40 transition-colors no-underline"
        >
          <div class="min-w-0 flex-1">
            <p class="font-medium truncate">{{ p.title }}</p>
            <p class="text-xs text-(--ui-muted) truncate">{{ p.tag }}</p>
          </div>
          <div class="text-right shrink-0">
            <p class="text-xs text-(--ui-muted)">{{ formatDate(p.updated_at) }}</p>
            <p class="text-xs text-(--ui-muted)">{{ p.hits }} 次阅读</p>
          </div>
        </NuxtLink>
      </div>
      <p v-else class="text-center py-16 text-(--ui-muted)">没有匹配的页面</p>
    </div>
  </div>
</template>
