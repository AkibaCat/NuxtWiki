<script setup lang="ts">
const route = useRoute()
const api = useApi()
const { user, init } = useAuth()
const toast = useToast()
const tag = computed(() => String(route.params.tag || ''))

const levelOptions = [
  { label: '访客（未登录）', value: 0 },
  { label: '管理员', value: 1 },
  { label: '高级用户', value: 2 },
  { label: '普通用户', value: 3 }
]

const permDefs = [
  { key: 'read', label: '阅读权限', icon: 'i-lucide-eye', hint: '谁可以阅读本页面', def: 0 },
  { key: 'edit', label: '编辑权限', icon: 'i-lucide-pencil', hint: '谁可以编辑本页面', def: 3 },
  { key: 'history', label: '历史权限', icon: 'i-lucide-history', hint: '谁可以查看修订历史', def: 3 },
  { key: 'diff', label: '对比权限', icon: 'i-lucide-git-compare', hint: '谁可以对比版本差异', def: 2 },
  { key: 'backlinks', label: '回链权限', icon: 'i-lucide-link', hint: '谁可以查看反向链接', def: 3 },
  { key: 'acl', label: '权限管理', icon: 'i-lucide-shield', hint: '谁可以修改本页权限', def: 1 },
  { key: 'contributors', label: '贡献者', icon: 'i-lucide-users', hint: '谁可以查看贡献者列表', def: 0 }
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
    error.value = r.error?.message || '加载失败'
  }
  loading.value = false
})

const save = async () => {
  saving.value = true
  const payload: Record<string, number> = { tag: tag.value }
  for (const p of permDefs) payload['acl_' + p.key] = perms[p.key]
  const r = await api.post('page.update-acl', payload)
  saving.value = false
  if (r.ok) {
    toast.add({ title: '权限已更新', color: 'success' })
  } else {
    toast.add({ title: r.error?.message || '保存失败', color: 'error' })
  }
}
</script>

<template>
  <div class="max-w-2xl mx-auto px-4 py-8">
    <div class="flex items-center gap-3 mb-6">
      <UButton :to="`/${tag}`" icon="i-lucide-arrow-left" size="xs" color="neutral" variant="ghost">
        返回页面
      </UButton>
      <h1 class="text-2xl font-bold">访问控制</h1>
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
            :label="`${p.label} [${perms[p.key]}]`"
            :hint="p.hint"
          >
            <USelect v-model="perms[p.key]" :items="levelOptions" class="w-full" />
          </UFormField>
          <UAlert
            color="info"
            variant="subtle"
            icon="i-lucide-info"
            title="权限等级说明"
            description="等级数字越小代表越宽松：0 = 访客（未登录），1 = 管理员，2 = 高级用户，3 = 普通用户。选中某项即代表该等级及更高级的用户可执行此操作（管理员拥有最高权限）。"
          />
        </div>

        <template #footer>
          <UButton icon="i-lucide-save" :loading="saving" @click="save">
            保存权限
          </UButton>
        </template>
      </UCard>
    </div>
  </div>
</template>
