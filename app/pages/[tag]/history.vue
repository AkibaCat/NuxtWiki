<script setup lang="ts">
const route = useRoute()
const api = useApi()
const { user, init } = useAuth()
const toast = useToast()
const tag = computed(() => String(route.params.tag || ''))

const revisions = ref<any[]>([])
const loading = ref(true)
const error = ref('')

const isAdmin = computed(() => user.value?.is_admin === true)

// 回滚
const revertTarget = ref<any>(null)
const revertOpen = ref(false)
const reverting = ref(false)
const latestRevision = computed(() => revisions.value[0]?.revision ?? 0)
const confirmRevert = (n: any) => {
  revertTarget.value = n
  revertOpen.value = true
}
const doRevert = async () => {
  const n = revertTarget.value
  if (!n) return
  reverting.value = true
  const r = await api.post('page.revert', { tag: tag.value, revision: n.revision })
  reverting.value = false
  if (r.ok) {
    toast.add({ title: `已回滚到 r${n.revision}`, color: 'success' })
    revertOpen.value = false
    revertTarget.value = null
    // 重新加载修订列表
    const rr = await api.get('page.revisions', { tag: tag.value })
    if (rr.ok) revisions.value = (rr.data as any[]) ?? []
  } else {
    toast.add({ title: r.error?.message || '回滚失败', color: 'error' })
  }
}

onMounted(async () => {
  await init()
  const r = await api.get('page.revisions', { tag: tag.value })
  if (r.ok) {
    revisions.value = (r.data as any[]) ?? []
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
      <h1 class="text-2xl font-bold">修订历史</h1>
    </div>

    <div v-if="loading" class="flex justify-center py-16">
      <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-(--ui-primary)" />
    </div>
    <div v-else-if="error" class="text-center py-16 text-(--ui-error)">{{ error }}</div>
    <div v-else>
      <ul class="divide-y divide-(--ui-border) rounded-lg border border-(--ui-border)">
        <li v-for="n in revisions" :key="n.revision" class="flex items-center gap-3 px-4 py-3">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2">
              <span class="text-xs font-mono text-(--ui-muted) shrink-0">r{{ n.revision }}</span>
              <NuxtLink :to="`/${tag}?rev=${n.revision}`" class="font-medium hover:text-(--ui-primary) truncate">
                {{ n.title }}
              </NuxtLink>
            </div>
            <p v-if="n.comment" class="text-sm text-(--ui-muted) truncate">{{ n.comment }}</p>
          </div>
          <div class="flex items-center gap-1 shrink-0">
            <UButton
              v-if="isAdmin && n.revision < latestRevision"
              icon="i-lucide-rotate-ccw"
              size="xs"
              color="warning"
              variant="ghost"
              @click="confirmRevert(n)"
            >
              回滚
            </UButton>
            <UButton :to="`/${tag}/diff?from=${n.revision === 1 ? 0 : n.revision - 1}&to=${n.revision}`" icon="i-lucide-git-compare" size="xs" color="neutral" variant="ghost">
              对比
            </UButton>
          </div>
          <div class="text-right text-xs text-(--ui-muted) shrink-0">
            <div>{{ n.nickname || n.username || '匿名' }}</div>
            <div>{{ formatDate(n.created_at) }}</div>
          </div>
        </li>
      </ul>
      <p v-if="!revisions.length" class="text-center py-16 text-(--ui-muted)">暂无修订记录</p>
    </div>

    <UModal v-model:open="revertOpen" scrollable :ui="{ content: 'max-w-md max-h-[80vh]' }">
      <template #content>
        <UCard>
          <template #header>确认回滚</template>
          <p class="text-sm text-(--ui-text)">
            确定要将 <span class="font-medium">{{ tag }}</span> 回滚到
            <span class="font-medium">r{{ revertTarget?.revision }}</span>
            （{{ revertTarget?.title }}）吗？
            <span class="text-(--ui-muted)">回滚会创建新版本，当前内容将保留在历史中。</span>
          </p>
          <template #footer>
            <div class="flex justify-end gap-2">
              <UButton variant="subtle" color="neutral" @click="revertOpen = false">取消</UButton>
              <UButton icon="i-lucide-rotate-ccw" color="warning" :loading="reverting" @click="doRevert">
                确认回滚
              </UButton>
            </div>
          </template>
        </UCard>
      </template>
    </UModal>
  </div>
</template>
