<script setup lang="ts">
// 页面职责：展示页面版本历史，管理员可回滚或删除修订
const route = useRoute()
const api = useApi()
const { user, init } = useAuth()
const toast = useToast()
const { t } = useI18n()
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
    toast.add({ title: t('history.reverted', { revision: n.revision }), color: 'success' })
    revertOpen.value = false
    revertTarget.value = null
    // 重新加载修订列表
    const rr = await api.get('page.revisions', { tag: tag.value })
    if (rr.ok) revisions.value = (rr.data as any[]) ?? []
  } else {
    toast.add({ title: r.error?.message || t('history.revertFailed'), color: 'error' })
  }
}

// 删除修订
const deleteTarget = ref<any>(null)
const deleteOpen = ref(false)
const deleting = ref(false)
const confirmDeleteRevision = (n: any) => {
  deleteTarget.value = n
  deleteOpen.value = true
}
const doDeleteRevision = async () => {
  const n = deleteTarget.value
  if (!n) return
  deleting.value = true
  const r = await api.post('page.delete-revision', { tag: tag.value, revision: n.revision })
  deleting.value = false
  if (r.ok) {
    toast.add({ title: t('history.deleted', { revision: n.revision }), color: 'success' })
    deleteOpen.value = false
    deleteTarget.value = null
    // 重新加载修订列表
    const rr = await api.get('page.revisions', { tag: tag.value })
    if (rr.ok) revisions.value = (rr.data as any[]) ?? []
  } else {
    toast.add({ title: r.error?.message || t('history.deleteFailed'), color: 'error' })
  }
}

onMounted(async () => {
  await init()
  const r = await api.get('page.revisions', { tag: tag.value })
  if (r.ok) {
    revisions.value = (r.data as any[]) ?? []
  } else {
    error.value = r.error?.message || t('history.loadFailed')
  }
  loading.value = false
})
</script>

<template>
  <div class="max-w-3xl mx-auto px-4 py-8">
    <div class="flex items-center gap-3 mb-6">
      <UButton :to="`/${tag}`" icon="i-lucide-arrow-left" size="xs" color="neutral" variant="ghost">
        {{ t('history.back') }}
      </UButton>
      <h1 class="text-2xl font-bold">{{ t('history.title') }}</h1>
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
              {{ t('history.revert') }}
            </UButton>
            <UButton
              v-if="isAdmin && n.revision < latestRevision"
              icon="i-lucide-trash-2"
              size="xs"
              color="error"
              variant="ghost"
              @click="confirmDeleteRevision(n)"
            >
              {{ t('common.delete') }}
            </UButton>
            <UButton :to="`/${tag}/diff?from=${n.revision === 1 ? 0 : n.revision - 1}&to=${n.revision}`" icon="i-lucide-git-compare" size="xs" color="neutral" variant="ghost">
              {{ t('history.compare') }}
            </UButton>
          </div>
          <div class="text-right text-xs text-(--ui-muted) shrink-0">
            <div>{{ n.nickname || n.username || t('recent.anonymous') }}</div>
            <div>{{ formatDate(n.created_at) }}</div>
          </div>
        </li>
      </ul>
      <p v-if="!revisions.length" class="text-center py-16 text-(--ui-muted)">{{ t('history.empty') }}</p>
    </div>

    <UModal v-model:open="revertOpen" scrollable :ui="{ content: 'max-w-md max-h-[80vh]' }">
      <template #content>
        <UCard>
          <template #header>{{ t('history.confirmRevert') }}</template>
          <p class="text-sm text-(--ui-text)">
            {{ t('history.revertConfirm', { tag, revision: revertTarget?.revision, title: revertTarget?.title }) }}
            <span class="text-(--ui-muted)">{{ t('history.revertHint') }}</span>
          </p>
          <template #footer>
            <div class="flex justify-end gap-2">
              <UButton variant="subtle" color="neutral" @click="revertOpen = false">{{ t('common.cancel') }}</UButton>
              <UButton icon="i-lucide-rotate-ccw" color="warning" :loading="reverting" @click="doRevert">
                {{ t('history.confirmRevert') }}
              </UButton>
            </div>
          </template>
        </UCard>
      </template>
    </UModal>

    <UModal v-model:open="deleteOpen" scrollable :ui="{ content: 'max-w-md max-h-[80vh]' }">
      <template #content>
        <UCard>
          <template #header>{{ t('history.confirmDeleteTitle') }}</template>
          <p class="text-sm text-(--ui-text)">
            {{ t('history.deleteConfirm', { tag, revision: deleteTarget?.revision, title: deleteTarget?.title }) }}
            <span class="text-(--ui-muted)">{{ t('history.deleteHint') }}</span>
          </p>
          <template #footer>
            <div class="flex justify-end gap-2">
              <UButton variant="subtle" color="neutral" @click="deleteOpen = false">{{ t('common.cancel') }}</UButton>
              <UButton icon="i-lucide-trash-2" color="error" :loading="deleting" @click="doDeleteRevision">
                {{ t('history.confirmDelete') }}
              </UButton>
            </div>
          </template>
        </UCard>
      </template>
    </UModal>
  </div>
</template>
