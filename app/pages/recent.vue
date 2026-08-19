<script setup lang="ts">
const { t } = useI18n()
const api = useApi()

// 最近更新页：按时间范围展示最近修订内容
const rows = ref<any[]>([])
const loading = ref(true)
const error = ref('')
const days = ref(0)

// 加载最近更新（days 为 0 时不过滤，表示全部时限）
const load = async () => {
  loading.value = true
  const r = await api.get('page.recent', { limit: 50, days: days.value || undefined })
  if (r.ok) {
    rows.value = (r.data as any[]) ?? []
  } else {
    error.value = r.error?.message || t('recent.loadFailed')
  }
  loading.value = false
}

onMounted(load)
</script>

<template>
  <div class="max-w-4xl mx-auto px-4 py-8">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
      <h1 class="text-2xl font-bold">{{ t('recent.title') }}</h1>
      <URadioGroup
        v-model="days"
        :items="[
          { label: t('recent.all'), value: 0 },
          { label: t('recent.days7'), value: 7 },
          { label: t('recent.days30'), value: 30 }
        ]"
        color="primary"
        @update:model-value="load"
      />
    </div>

    <div v-if="loading" class="flex justify-center py-16">
      <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-(--ui-primary)" />
    </div>
    <div v-else-if="error" class="text-center py-16 text-(--ui-error)">{{ error }}</div>
    <div v-else>
      <ul class="divide-y divide-(--ui-border) rounded-lg border border-(--ui-border)">
        <li v-for="r in rows" :key="`${r.tag}-${r.revision}`" class="px-4 py-3">
          <div class="flex items-center gap-2">
            <NuxtLink :to="`/${r.tag}`" class="font-medium hover:text-(--ui-primary) truncate">
              {{ r.title }}
            </NuxtLink>
            <span class="text-xs text-(--ui-muted) shrink-0">r{{ r.revision }}</span>
          </div>
          <p v-if="r.comment" class="text-sm text-(--ui-muted) mt-0.5">{{ r.comment }}</p>
          <div class="flex items-center gap-2 mt-1 text-xs text-(--ui-muted)">
            <span>{{ r.nickname || r.username || t('recent.anonymous') }}</span>
            <span>·</span>
            <span>{{ formatDate(r.created_at) }}</span>
          </div>
        </li>
      </ul>
      <p v-if="!rows.length" class="text-center py-16 text-(--ui-muted)">{{ t('recent.empty') }}</p>
    </div>
  </div>
</template>
