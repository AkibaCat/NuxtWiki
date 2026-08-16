<script setup lang="ts">
const route = useRoute()
const api = useApi()
const tag = computed(() => String(route.params.tag || ''))

const backlinks = ref<any[]>([])
const loading = ref(true)
const error = ref('')

onMounted(async () => {
  const r = await api.get('page.backlinks', { tag: tag.value })
  if (r.ok) {
    backlinks.value = (r.data as any[]) ?? []
  } else {
    error.value = r.error?.message || '加载失败'
  }
  loading.value = false
})
</script>

<template>
  <div class="max-w-3xl mx-auto px-4 py-8">
    <div class="flex items-center gap-3 mb-6">
      <UButton :to="`/${tag}`" icon="i-lucide-arrow-left" size="xs" color="neutral" variant="ghost">
        返回页面
      </UButton>
      <h1 class="text-2xl font-bold">反向链接</h1>
    </div>

    <div v-if="loading" class="flex justify-center py-16">
      <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-(--ui-primary)" />
    </div>
    <div v-else-if="error" class="text-center py-16 text-(--ui-error)">{{ error }}</div>
    <div v-else>
      <p class="text-sm text-(--ui-muted) mb-4">
        以下 {{ backlinks.length }} 个页面链接到
        <code class="bg-(--ui-bg-elevated) px-1 rounded">[[{{ tag }}]]</code>
      </p>
      <ul v-if="backlinks.length" class="divide-y divide-(--ui-border) rounded-lg border border-(--ui-border)">
        <li v-for="e in backlinks" :key="e.tag" class="px-4 py-3">
          <NuxtLink :to="`/${e.tag}`" class="font-medium hover:text-(--ui-primary)">
            {{ e.title }}
          </NuxtLink>
          <span class="text-xs text-(--ui-muted) ml-2">{{ e.tag }}</span>
        </li>
      </ul>
      <p v-else class="text-center py-16 text-(--ui-muted)">没有页面链接到本页</p>
    </div>
  </div>
</template>
