<script setup lang="ts">
// 页面职责：配置页面读/写/历史等各项权限所需的访问级别
const route = useRoute()
const api = useApi()
const { user, init } = useAuth()
const toast = useToast()
const { t } = useI18n()
const tag = computed(() => String(route.params.tag || ''))

const levelOptions = [
  { label: 'acl.levelGuest', value: 0 },
  { label: 'acl.levelAdmin', value: 1 },
  { label: 'acl.levelPower', value: 2 },
  { label: 'acl.levelUser', value: 3 }
]
const levelItems = computed(() => levelOptions.map((o) => ({ value: o.value, label: t(o.label) })))

const permDefs = [
  { key: 'read', label: 'acl.permRead', icon: 'i-lucide-eye', hint: 'acl.hintRead', def: 0 },
  { key: 'edit', label: 'acl.permEdit', icon: 'i-lucide-pencil', hint: 'acl.hintEdit', def: 3 },
  { key: 'history', label: 'acl.permHistory', icon: 'i-lucide-history', hint: 'acl.hintHistory', def: 3 },
  { key: 'diff', label: 'acl.permDiff', icon: 'i-lucide-git-compare', hint: 'acl.hintDiff', def: 2 },
  { key: 'backlinks', label: 'acl.permBacklinks', icon: 'i-lucide-link', hint: 'acl.hintBacklinks', def: 3 },
  { key: 'acl', label: 'acl.permAcl', icon: 'i-lucide-shield', hint: 'acl.hintAcl', def: 1 },
  { key: 'contributors', label: 'acl.permContributors', icon: 'i-lucide-users', hint: 'acl.hintContributors', def: 0 }
]

const perms = reactive<Record<string, number>>({})
for (const d of permDefs) perms[d.key] = d.def

const loading = ref(true)
const saving = ref(false)
const error = ref('')

onMounted(async () => {
  if (await init(), !user.value) {
    await navigateTo(`/login?redirect=/${tag.value}/acl`)
    return
  }
  const r = await api.get('page.perms', { tag: tag.value })
  if (r.ok) {
    const d = r.data as any
    for (const p of permDefs) {
      const v = Number(d['acl_' + p.key])
      perms[p.key] = v >= 0 && v <= 3 ? v : p.def
    }
  } else {
    error.value = r.error?.message || t('acl.loadFailed')
  }
  loading.value = false
})

const save = async () => {
  saving.value = true
  const payload: Record<string, string | number> = { tag: tag.value }
  for (const p of permDefs) payload['acl_' + p.key] = perms[p.key]!
  const r = await api.post('page.update-acl', payload)
  saving.value = false
  if (r.ok) {
    toast.add({ title: t('acl.saved'), color: 'success' })
  } else {
    toast.add({ title: r.error?.message || t('acl.saveFailed'), color: 'error' })
  }
}
</script>

<template>
  <div class="max-w-2xl mx-auto px-4 py-8">
    <div class="flex items-center gap-3 mb-6">
      <UButton :to="`/${tag}`" icon="i-lucide-arrow-left" size="xs" color="neutral" variant="ghost">
        {{ t('acl.back') }}
      </UButton>
      <h1 class="text-2xl font-bold">{{ t('acl.title') }}</h1>
    </div>

    <div v-if="loading" class="flex justify-center py-16">
      <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-(--ui-primary)" />
    </div>
    <div v-else-if="error" class="text-center py-16 text-(--ui-error)">{{ error }}</div>
    <div v-else>
      <UCard>
        <template #header>
          <p class="font-semibold">{{ tag }}</p>
        </template>

        <div class="space-y-4">
          <UFormField
            v-for="p in permDefs"
            :key="p.key"
            :label="`${t(p.label)} [${perms[p.key]}]`"
            :hint="t(p.hint)"
          >
            <USelect v-model="perms[p.key]" :items="levelItems" class="w-full" />
          </UFormField>
          <UAlert
            color="info"
            variant="subtle"
            icon="i-lucide-info"
            :title="t('acl.levelInfoTitle')"
            :description="t('acl.levelInfoDesc')"
          />
        </div>

        <template #footer>
          <UButton icon="i-lucide-save" :loading="saving" @click="save">
            {{ t('acl.save') }}
          </UButton>
        </template>
      </UCard>
    </div>
  </div>
</template>
