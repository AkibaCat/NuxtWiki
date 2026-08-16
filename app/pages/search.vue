<script setup lang="ts">
const api = useApi()
const toast = useToast()

const q = ref('')
const results = ref<any[]>([])
const total = ref(0)
const loading = ref(false)
const searched = ref(false)
const limit = 20
const offset = ref(0)

let timer: ReturnType<typeof setTimeout> | null = null

const doSearch = async () => {
  if (!q.value.trim()) {
    results.value = []
    total.value = 0
    searched.value = false
    return
  }
  loading.value = true
  const r = await api.get('page.search', { q: q.value.trim(), limit, offset: offset.value })
  if (r.ok) {
    const d = r.data as { total: number; results: any[] }
    total.value = d.total
    results.value = d.results
    searched.value = true
  } else {
    toast.add({ title: r.error?.message || '搜索失败', color: 'error' })
  }
  loading.value = false
}

const onInput = () => {
  if (timer) clearTimeout(timer)
  timer = setTimeout(() => {
    offset.value = 0
    doSearch()
  }, 400)
}

const highlight = (text: string) => {
  const k = q.value.trim()
  if (!k) return text
  const i = text.toLowerCase().indexOf(k.toLowerCase())
  if (i < 0) return text
  return text.slice(0, i) + '«' + text.slice(i, i + k.length) + '»' + text.slice(i + k.length)
}
</script>

<template>
  <div class="max-w-3xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold mb-6">搜索页面</h1>

    <UInput
      v-model="q"
      icon="i-lucide-search"
      size="lg"
      placeholder="输入关键词搜索…"
      autofocus
      :loading="loading"
      class="w-full"
      @update:model-value="onInput"
      @keyup.enter="doSearch"
    />

    <p v-if="searched" class="text-sm text-(--ui-muted) mt-4">
      找到 {{ total }} 个结果
    </p>

    <ul v-if="results.length" class="mt-4 divide-y divide-(--ui-border) rounded-lg border border-(--ui-border)">
      <li v-for="r in results" :key="r.tag" class="px-4 py-3">
        <NuxtLink :to="`/${r.tag}`" class="font-medium hover:text-(--ui-primary)">
          {{ highlight(r.title) }}
        </NuxtLink>
        <p class="text-sm text-(--ui-muted) mt-0.5 line-clamp-2" v-html="highlight(r.snippet)" />
        <p class="text-xs text-(--ui-muted) mt-1">
          {{ r.tag }} · 修订 r{{ r.revision }} · {{ formatDate(r.updated_at) }}
        </p>
      </li>
    </ul>

    <p v-if="searched && !results.length" class="text-center py-16 text-(--ui-muted)">
      没有找到相关页面
    </p>

    <div v-if="total > limit" class="flex justify-center mt-6 gap-2">
      <UButton
        color="neutral"
        variant="subtle"
        icon="i-lucide-chevron-left"
        :disabled="offset === 0"
        @click="offset -= limit; doSearch()"
      >
        上一页
      </UButton>
      <UButton
        color="neutral"
        variant="subtle"
        icon="i-lucide-chevron-right"
        :disabled="offset + limit >= total"
        @click="offset += limit; doSearch()"
      >
        下一页
      </UButton>
    </div>
  </div>
</template>
