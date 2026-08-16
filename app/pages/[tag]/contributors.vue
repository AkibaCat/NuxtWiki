<script setup lang="ts">
const route = useRoute()
const api = useApi()
const tag = computed(() => String(route.params.tag || ''))

const contributors = ref<any[]>([])
const loading = ref(true)
const error = ref('')

onMounted(async () => {
  const r = await api.get('page.contributors', { tag: tag.value })
  if (r.ok) {
    contributors.value = (r.data as any[]) ?? []
  } else {
    error.value = r.error?.message || '加载失败'
  }
  loading.value = false
})
</script>

<template>
  <div class="max-w-4xl mx-auto px-4 py-8">
    <div class="flex items-center gap-3 mb-6">
      <UButton :to="`/${tag}`" icon="i-lucide-arrow-left" size="xs" color="neutral" variant="ghost">
        返回页面
      </UButton>
      <h1 class="text-2xl font-bold">贡献者</h1>
    </div>

    <div v-if="loading" class="flex justify-center py-16">
      <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-(--ui-primary)" />
    </div>
    <div v-else-if="error" class="text-center py-16 text-(--ui-error)">{{ error }}</div>
    <div v-else>
      <p class="text-sm text-(--ui-muted) mb-4">
        以下 {{ contributors.length }} 位用户贡献过
        <code class="bg-(--ui-bg-elevated) px-1 rounded">{{ tag }}</code> 页面
      </p>
      <div v-if="contributors.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <NuxtLink
          v-for="c in contributors"
          :key="c.username"
          :to="`/account/${encodeURIComponent(c.username)}`"
          class="rounded-lg border border-(--ui-border) p-4 flex items-center gap-3 hover:bg-(--ui-bg-elevated) hover:border-(--ui-primary)/40 transition-colors no-underline"
        >
          <img
            v-if="c.avatar"
            :src="c.avatar"
            :alt="c.username"
            class="size-11 rounded-full object-cover border border-(--ui-border) shrink-0"
          />
          <UAvatar v-else :alt="c.username" size="md" :text="(c.nickname || c.username).charAt(0)" />
          <div class="min-w-0">
            <p class="font-semibold truncate">{{ c.nickname || c.username }}</p>
            <p v-if="c.nickname && c.nickname !== c.username" class="text-xs text-(--ui-muted) truncate">@{{ c.username }}</p>
            <p class="text-xs text-(--ui-muted) mt-0.5">对本页贡献 {{ c.edits }} 次</p>
          </div>
        </NuxtLink>
      </div>
      <p v-else class="text-center py-16 text-(--ui-muted)">暂无贡献者</p>
    </div>
  </div>
</template>
