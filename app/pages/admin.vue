<script setup lang="ts">
const { user, ready, init } = useAuth()
const api = useApi()
const toast = useToast()

const tab = ref('stats')
const loading = ref(true)

onMounted(async () => {
  await init()
  if (!user.value) {
    await navigateTo('/login?redirect=/admin')
    return
  }
  if (!user.value.is_admin) {
    toast.add({ title: '需要管理员权限', color: 'error' })
    await navigateTo('/')
    return
  }
  await loadStats()
  loading.value = false
})

// ============ 概览 ============
const stats = ref<any>(null)
const loadStats = async () => {
  const r = await api.get('admin.stats')
  if (r.ok) stats.value = r.data
}

// ============ 设置 ============
const settings = ref<any>({})
const settingsBusy = ref(false)
const loadSettings = async () => {
  const r = await api.get('admin.settings')
  if (r.ok) settings.value = r.data
}
// 后端以字符串 '0'/'1' 存储，USwitch 需要布尔值，转换适配
const allowRegistration = computed({
  get: () => settings.value.allow_registration === true || settings.value.allow_registration === '1',
  set: (v: boolean) => { settings.value.allow_registration = v }
})
const saveSettings = async () => {
  settingsBusy.value = true
  const r = await api.post('admin.settings', settings.value)
  settingsBusy.value = false
  if (r.ok) {
    toast.add({ title: '设置已保存', color: 'success' })
    const auth = useAuth()
    if (auth.site.value) {
      auth.site.value.name = settings.value.site_name
      auth.site.value.description = settings.value.site_description
      auth.site.value.home_tag = settings.value.home_tag
    }
  } else {
    toast.add({ title: r.error?.message || '保存失败', color: 'error' })
  }
}

// ============ 页面管理 ============
const adminPages = ref<any[]>([])
const pagesLoading = ref(false)
const loadAdminPages = async () => {
  pagesLoading.value = true
  const r = await api.get('admin.pages')
  if (r.ok) adminPages.value = (r.data as any[]) ?? []
  pagesLoading.value = false
}
const deletePage = async (tag: string) => {
  if (!confirm(`确定要删除页面「${tag}」及其所有修订记录吗？此操作不可撤销。`)) return
  const r = await api.post('page.delete', { tag })
  if (r.ok) {
    toast.add({ title: '页面已删除', color: 'success' })
    await loadAdminPages()
    await loadStats()
  } else {
    toast.add({ title: r.error?.message || '删除失败', color: 'error' })
  }
}

// ============ 用户管理 ============
const users = ref<any[]>([])
const usersLoading = ref(false)
const userSearch = ref('')
const showCreate = ref(false)
const newUser = ref({ username: '', password: '', level: 3 })
const userBusy = ref(false)

// 用户等级选项（账号权限下拉框：管理/高级/普通）
const levelOptions = [
  { label: '管理员', value: 1 },
  { label: '高级用户', value: 2 },
  { label: '普通用户', value: 3 }
]
// 站点默认权限等级（后端以字符串存储 0~3，含访客等级）
const settingsLevelOptions = [
  { label: '访客（未登录）', value: '0' },
  { label: '管理员', value: '1' },
  { label: '高级用户', value: '2' },
  { label: '普通用户', value: '3' }
]

const loadUsers = async () => {
  usersLoading.value = true
  const r = await api.get('users.list', { q: userSearch.value || undefined })
  if (r.ok) users.value = (r.data as any[]) ?? []
  usersLoading.value = false
}
const createUser = async () => {
  userBusy.value = true
  const r = await api.post('users.create', newUser.value)
  userBusy.value = false
  if (r.ok) {
    toast.add({ title: '用户已创建', color: 'success' })
    newUser.value = { username: '', password: '', level: 3 }
    showCreate.value = false
    await loadUsers()
  } else {
    toast.add({ title: r.error?.message || '创建失败', color: 'error' })
  }
}
// 设置权限等级（管理/高级/普通）——后端会同步恢复为正常状态
const setRoleLevel = async (u: any, level: number) => {
  const r = await api.post('users.update', { username: u.username, level })
  if (r.ok) {
    u.level = level
    u.status = 'active'
    u.reason = ''
    toast.add({ title: `已将「${u.username}」设为${level === 1 ? '管理员' : level === 2 ? '高级用户' : '普通用户'}`, color: 'success' })
  } else {
    toast.add({ title: r.error?.message || '更新失败', color: 'error' })
  }
}
const deleteUser = async (u: any) => {
  if (!confirm(`确定要删除用户「${u.username}」吗？`)) return
  const r = await api.post('users.delete', { username: u.username })
  if (r.ok) {
    toast.add({ title: '用户已删除', color: 'success' })
    await loadUsers()
  } else {
    toast.add({ title: r.error?.message || '删除失败', color: 'error' })
  }
}
const setUserStatus = async (u: any, status: 'active' | 'frozen' | 'banned', reason = '') => {
  const r = await api.post('users.set-status', { username: u.username, status, reason })
  if (r.ok) {
    u.status = status
    u.reason = r.data?.reason || ''
    toast.add({ title: `已${status === 'active' ? '恢复正常' : status === 'frozen' ? '冻结' : '封禁'}用户「${u.username}」`, color: 'success' })
  } else {
    toast.add({ title: r.error?.message || '操作失败', color: 'error' })
  }
}

// ============ 冻结/封禁原因弹窗 ============
const statusModalOpen = ref(false)
const statusTarget = ref<any>(null)
const statusAction = ref<'frozen' | 'banned'>('frozen')
const statusReason = ref('')
const statusBusy = ref(false)

const openStatusModal = (u: any, action: 'frozen' | 'banned') => {
  statusTarget.value = u
  statusAction.value = action
  statusReason.value = ''
  statusModalOpen.value = true
}
const confirmStatusModal = async () => {
  if (!statusTarget.value) return
  statusBusy.value = true
  await setUserStatus(statusTarget.value, statusAction.value, statusReason.value.trim())
  statusBusy.value = false
  statusModalOpen.value = false
}

// ============ 注册码管理 ============
const regcodes = ref<any[]>([])
const regcodesLoading = ref(false)
const regcodeCount = ref(1)
const regcodeBusy = ref(false)

const loadRegcodes = async () => {
  regcodesLoading.value = true
  const r = await api.get('regcode.list')
  if (r.ok) regcodes.value = (r.data as any[]) ?? []
  regcodesLoading.value = false
}
const generateRegcodes = async () => {
  regcodeBusy.value = true
  const r = await api.post('regcode.generate', { count: regcodeCount.value })
  regcodeBusy.value = false
  if (r.ok) {
    toast.add({ title: `已生成 ${regcodeCount.value} 个注册码`, color: 'success' })
    await loadRegcodes()
  } else {
    toast.add({ title: r.error?.message || '生成失败', color: 'error' })
  }
}
const copyRegcode = async (code: string) => {
  await navigator.clipboard.writeText(code)
  toast.add({ title: '已复制到剪贴板', color: 'success' })
}
const deleteRegcode = async (id: number) => {
  if (!confirm('确定要删除此注册码吗？')) return
  const r = await api.post('regcode.delete', { id })
  if (r.ok) {
    toast.add({ title: '注册码已删除', color: 'success' })
    await loadRegcodes()
  }
}
const destroyRegcode = async (id: number) => {
  if (!confirm('确定要销毁此未使用的注册码吗？')) return
  const r = await api.post('regcode.destroy', { id })
  if (r.ok) {
    toast.add({ title: '注册码已销毁', color: 'success' })
    await loadRegcodes()
  }
}

// ============ 备份 ============
const backup = () => {
  window.location.href = `${api.base()}?r=admin.backup`
}

// ============ 数据导入 ============
const importBusy = ref(false)
const importFile = ref<File | null>(null)
const onImportFileChange = (e: Event) => {
  const input = e.target as HTMLInputElement
  importFile.value = input.files?.[0] || null
}
const importBackup = async () => {
  if (!importFile.value) {
    toast.add({ title: '请先选择备份文件', color: 'warning' })
    return
  }
  if (!confirm('导入将覆盖备份中包含的现有数据（页面/修订/用户等），此操作不可撤销，确定继续吗？')) return
  importBusy.value = true
  try {
    const text = await importFile.value.text()
    const r = await api.post('admin.restore', { data: text })
    if (r.ok) {
      const counts = (r.data as any)?.imported || {}
      toast.add({
        title: `导入成功：页面 ${counts.pages ?? 0}、修订 ${counts.revisions ?? 0}、用户 ${counts.users ?? 0}、订阅 ${counts.watchers ?? 0}、注册码 ${counts.regcodes ?? 0}、设置 ${counts.settings ?? 0}`,
        color: 'success'
      })
      await loadStats()
    } else {
      toast.add({ title: r.error?.message || '导入失败', color: 'error' })
    }
  } finally {
    importBusy.value = false
  }
}

watch(tab, (v) => {
  if (v === 'settings' && !settings.value.site_name) loadSettings()
  if (v === 'pages' && !adminPages.value.length) loadAdminPages()
  if (v === 'users' && !users.value.length) loadUsers()
  if (v === 'regcodes' && !regcodes.value.length) loadRegcodes()
})

const statCards = computed(() => {
  const s = stats.value
  if (!s) return []
  return [
    { label: '页面', value: s.pages, icon: 'i-lucide-file-text' },
    { label: '修订', value: s.revisions, icon: 'i-lucide-history' },
    { label: '用户', value: s.users, icon: 'i-lucide-users' },
    { label: '订阅', value: s.watchers, icon: 'i-lucide-bell-ring' },
    { label: '注册码', value: s.regcodes, icon: 'i-lucide-key-round' },
    { label: '总阅读', value: s.total_hits, icon: 'i-lucide-eye' }
  ]
})
</script>

<template>
  <div class="max-w-5xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold mb-6">管理后台</h1>

    <div v-if="loading" class="flex justify-center py-16">
      <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-(--ui-primary)" />
    </div>
    <div v-else>
      <UTabs
        v-model="tab"
        :ui="{ trigger: 'justify-center' }"
        :items="[
          { label: '概览', value: 'stats', slot: 'stats', icon: 'i-lucide-layout-dashboard' },
          { label: '站点设置', value: 'settings', slot: 'settings', icon: 'i-lucide-settings' },
          { label: '注册码', value: 'regcodes', slot: 'regcodes', icon: 'i-lucide-key-round' },
          { label: '页面管理', value: 'pages', slot: 'pages', icon: 'i-lucide-file-text' },
          { label: '用户管理', value: 'users', slot: 'users', icon: 'i-lucide-users' },
          { label: '备份', value: 'backup', slot: 'backup', icon: 'i-lucide-database-backup' }
        ]"
      >
        <!-- 宽度不足时仅显示图标，不显示文字（md 及以上才显示文字，6 个标签需要约 700px） -->
        <template #default="{ item }">
          <span class="hidden md:inline">{{ item.label }}</span>
        </template>
        <template #stats>
          <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mt-4">
            <UCard v-for="c in statCards" :key="c.label">
              <div class="flex items-center gap-3">
                <div class="rounded-lg bg-(--ui-primary)/10 p-2.5 text-(--ui-primary)">
                  <UIcon :name="c.icon" class="size-5" />
                </div>
                <div>
                  <p class="text-2xl font-bold">{{ formatCount(c.value) }}</p>
                  <p class="text-sm text-(--ui-muted)">{{ c.label }}</p>
                </div>
              </div>
            </UCard>
          </div>
          <div v-if="stats" class="mt-6 flex flex-wrap gap-4 text-sm text-(--ui-muted)">
            <span>数据库：{{ stats.driver }}</span>
            <span>版本：{{ stats.version }}</span>
          </div>
        </template>

        <template #settings>
          <UCard class="mt-4">
            <div class="space-y-4">
              <div class="grid md:grid-cols-2 gap-4">
                <UFormField label="站点名称">
                  <UInput v-model="settings.site_name" class="w-full" />
                </UFormField>
                <UFormField label="首页页面名">
                  <UInput v-model="settings.home_tag" class="w-full" />
                </UFormField>
              </div>
              <UFormField label="站点描述">
                <UTextarea v-model="settings.site_description" :rows="2" class="w-full" />
              </UFormField>
              <UFormField label="站点基础 URL" hint="用于邮件通知中的链接">
                <UInput v-model="settings.base_url" placeholder="https://example.com" class="w-full" />
              </UFormField>
              <div class="grid md:grid-cols-2 gap-4">
                <UFormField label="默认阅读权限等级">
                  <USelect v-model="settings.default_read_level" :items="settingsLevelOptions" class="w-full" />
                </UFormField>
                <UFormField label="默认编辑权限等级">
                  <USelect v-model="settings.default_edit_level" :items="settingsLevelOptions" class="w-full" />
                </UFormField>
                <UFormField label="默认历史权限等级">
                  <USelect v-model="settings.default_history_level" :items="settingsLevelOptions" class="w-full" />
                </UFormField>
                <UFormField label="默认对比权限等级">
                  <USelect v-model="settings.default_diff_level" :items="settingsLevelOptions" class="w-full" />
                </UFormField>
                <UFormField label="默认回链权限等级">
                  <USelect v-model="settings.default_backlinks_level" :items="settingsLevelOptions" class="w-full" />
                </UFormField>
                <UFormField label="默认权限管理等级">
                  <USelect v-model="settings.default_perms_level" :items="settingsLevelOptions" class="w-full" />
                </UFormField>
                <UFormField label="默认贡献者等级">
                  <USelect v-model="settings.default_contributors_level" :items="settingsLevelOptions" class="w-full" />
                </UFormField>
              </div>
              <UFormField label="开放注册">
                <USwitch v-model="allowRegistration" />
              </UFormField>
            </div>
            <template #footer>
              <UButton icon="i-lucide-save" :loading="settingsBusy" @click="saveSettings">保存设置</UButton>
            </template>
          </UCard>
        </template>

        <!-- 注册码管理 -->
        <template #regcodes>
          <div class="flex items-center justify-between mt-4 mb-3">
            <h2 class="font-semibold">注册码列表 ({{ regcodes.length }})</h2>
            <div class="flex items-center gap-2">
              <UInputNumber v-model="regcodeCount" :min="1" :max="100" class="w-20" />
              <UButton icon="i-lucide-plus" :loading="regcodeBusy" @click="generateRegcodes">生成</UButton>
            </div>
          </div>
          <UCard :ui="{ body: { padding: 'p-0' } }">
            <UTable
              :data="regcodes"
              :loading="regcodesLoading"
              :columns="[
                { accessorKey: 'code', header: '注册码' },
                { accessorKey: 'created_at', header: '生成时间' },
                { accessorKey: 'username', header: '使用用户' },
                { accessorKey: 'actions', header: '操作', enableSorting: false }
              ]"
            >
              <template #created_at-cell="{ row }">
                {{ formatDate(row.original.created_at) }}
              </template>
              <template #username-cell="{ row }">
                <span v-if="row.original.username">{{ row.original.username }}</span>
                <span v-else class="text-(--ui-muted)">-</span>
              </template>
              <template #actions-cell="{ row }">
                <div class="flex items-center gap-1">
                  <UButton v-if="!row.original.username" icon="i-lucide-copy" size="xs" color="neutral" variant="ghost" aria-label="复制" @click="copyRegcode(row.original.code)" />
                  <UButton v-if="!row.original.username" icon="i-lucide-flame" size="xs" color="neutral" variant="ghost" aria-label="销毁" @click="destroyRegcode(row.original.id)" />
                  <UButton v-if="row.original.username" icon="i-lucide-trash" size="xs" color="neutral" variant="ghost" aria-label="删除" @click="deleteRegcode(row.original.id)" />
                </div>
              </template>
            </UTable>
          </UCard>
        </template>

        <template #pages>
          <UCard class="mt-4" :ui="{ body: { padding: 'p-0' } }">
            <UTable
              :data="adminPages"
              :loading="pagesLoading"
              :columns="[
                { accessorKey: 'title', header: '标题' },
                { accessorKey: 'tag', header: '页面名' },
                { accessorKey: 'revision', header: '修订' },
                { accessorKey: 'hits', header: '阅读' },
                { accessorKey: 'last_editor', header: '编辑者' },
                { accessorKey: 'updated_at', header: '更新' },
                { accessorKey: 'acl', header: 'ACL' },
                { accessorKey: 'actions', header: '操作', enableSorting: false }
              ]"
            >
              <template #title-cell="{ row }">
                <NuxtLink :to="`/${row.original.tag}`" class="font-medium hover:text-(--ui-primary)">
                  {{ row.original.title }}
                </NuxtLink>
              </template>
              <template #updated_at-cell="{ row }">
                {{ formatDate(row.original.updated_at) }}
              </template>
              <template #actions-cell="{ row }">
                <div class="flex items-center gap-1">
                  <UButton :to="`/${row.original.tag}/edit`" icon="i-lucide-pencil" size="xs" color="neutral" variant="ghost" aria-label="编辑" />
                  <UButton icon="i-lucide-trash" size="xs" color="neutral" variant="ghost" aria-label="删除" @click="deletePage(row.original.tag)" />
                </div>
              </template>
            </UTable>
          </UCard>
        </template>

        <template #users>
          <div class="flex items-center justify-between mt-4 mb-3 gap-3">
            <h2 class="font-semibold shrink-0">用户列表 ({{ users.length }})</h2>
            <div class="flex items-center gap-2 ml-auto">
              <UInput v-model="userSearch" placeholder="按用户名/昵称搜索…" icon="i-lucide-search" class="w-56" clearable @update:model-value="loadUsers" />
              <UButton v-if="!showCreate" icon="i-lucide-user-plus" size="sm" @click="showCreate = true">新建用户</UButton>
            </div>
          </div>
          <UCard v-if="showCreate" class="mb-4">
            <div class="grid md:grid-cols-2 gap-4">
              <UFormField label="用户名">
                <UInput v-model="newUser.username" class="w-full" />
              </UFormField>
              <UFormField label="密码">
                <UInput v-model="newUser.password" type="password" class="w-full" />
              </UFormField>
              <UFormField label="权限等级">
                <USelect v-model="newUser.level" :items="levelOptions" class="w-full" />
              </UFormField>
            </div>
            <div class="flex gap-2 mt-4">
              <UButton icon="i-lucide-check" :loading="userBusy" @click="createUser">创建</UButton>
              <UButton color="neutral" variant="ghost" @click="showCreate = false">取消</UButton>
            </div>
          </UCard>
          <UCard :ui="{ body: { padding: 'p-0' } }">
            <UTable
              :data="users"
              :loading="usersLoading"
              :columns="[
                { accessorKey: 'username', header: '用户名' },
                { accessorKey: 'nickname', header: '昵称' },
                { accessorKey: 'role', header: '账号权限' },
                { accessorKey: 'reason', header: '原因' },
                { accessorKey: 'created_at', header: '注册时间' },
                { accessorKey: 'actions', header: '操作', enableSorting: false }
              ]"
            >
              <template #username-cell="{ row }">
                <NuxtLink :to="`/account/${encodeURIComponent(row.original.username)}`" class="font-medium hover:text-(--ui-primary)">
                  {{ row.original.username }}
                </NuxtLink>
              </template>
              <template #role-cell="{ row }">
                <USelect
                  :model-value="row.original.level"
                  :items="levelOptions"
                  class="w-full sm:w-40"
                  size="sm"
                  @update:model-value="setRoleLevel(row.original, $event)"
                />
              </template>
              <template #reason-cell="{ row }">
                <span v-if="row.original.reason" class="text-sm text-(--ui-muted)">{{ row.original.reason }}</span>
                <span v-else class="text-sm text-(--ui-muted)">-</span>
              </template>
              <template #created_at-cell="{ row }">
                {{ formatDate(row.original.created_at) }}
              </template>
              <!-- 操作列：冻结/封禁/解冻/解封/删除；宽度不足时仅显示图标 -->
              <template #actions-cell="{ row }">
                <div class="flex items-center gap-1">
                  <template v-if="row.original.username !== user?.username">
                    <UButton
                      v-if="row.original.status === 'active'"
                      icon="i-lucide-snowflake"
                      size="xs"
                      color="neutral"
                      variant="ghost"
                      aria-label="冻结"
                      @click="openStatusModal(row.original, 'frozen')"
                    >
                      <span class="hidden xl:inline">冻结</span>
                    </UButton>
                    <UButton
                      v-if="row.original.status !== 'banned'"
                      icon="i-lucide-ban"
                      size="xs"
                      color="error"
                      variant="ghost"
                      aria-label="封禁"
                      @click="openStatusModal(row.original, 'banned')"
                    >
                      <span class="hidden xl:inline">封禁</span>
                    </UButton>
                    <UButton
                      v-if="row.original.status === 'frozen'"
                      icon="i-lucide-check"
                      size="xs"
                      color="neutral"
                      variant="ghost"
                      aria-label="解冻"
                      @click="setUserStatus(row.original, 'active')"
                    >
                      <span class="hidden xl:inline">解冻</span>
                    </UButton>
                    <UButton
                      v-if="row.original.status === 'banned'"
                      icon="i-lucide-unlock"
                      size="xs"
                      color="neutral"
                      variant="ghost"
                      aria-label="解封"
                      @click="setUserStatus(row.original, 'active')"
                    >
                      <span class="hidden xl:inline">解封</span>
                    </UButton>
                    <UButton
                      icon="i-lucide-trash"
                      size="xs"
                      color="neutral"
                      variant="ghost"
                      aria-label="删除"
                      @click="deleteUser(row.original)"
                    >
                      <span class="hidden xl:inline">删除</span>
                    </UButton>
                  </template>
                </div>
              </template>
            </UTable>
          </UCard>
          <!-- 冻结/封禁原因弹窗 -->
          <UModal v-model:open="statusModalOpen">
            <template #content>
              <UCard>
                <template #header>{{ statusAction === 'frozen' ? '冻结用户' : '封禁用户' }}</template>
                <div class="space-y-4">
                  <p class="text-sm text-(--ui-muted)">
                    将{{ statusAction === 'frozen' ? '冻结' : '封禁' }}用户「{{ statusTarget?.username }}」。
                    {{ statusAction === 'frozen' ? '冻结后仅可登录与订阅，无法使用其他功能。' : '封禁后无法登录，个人主页将限制显示。' }}
                  </p>
                  <UFormField :label="statusAction === 'frozen' ? '冻结原因' : '封禁原因'" hint="将展示在该用户个人主页">
                    <UTextarea v-model="statusReason" :rows="3" placeholder="请输入原因…" class="w-full" />
                  </UFormField>
                </div>
                <template #footer>
                  <div class="flex justify-end gap-2">
                    <UButton variant="subtle" color="neutral" @click="statusModalOpen = false">取消</UButton>
                    <UButton :color="statusAction === 'banned' ? 'error' : 'warning'" icon="i-lucide-check" :loading="statusBusy" @click="confirmStatusModal">确认</UButton>
                  </div>
                </template>
              </UCard>
            </template>
          </UModal>
        </template>

        <template #backup>
          <div class="mt-4 space-y-4">
            <UCard>
              <div class="flex items-start gap-3">
                <div class="rounded-lg bg-(--ui-primary)/10 p-2.5 text-(--ui-primary)">
                  <UIcon name="i-lucide-database-backup" class="size-5" />
                </div>
                <div class="flex-1">
                  <p class="font-semibold">导出数据备份</p>
                  <p class="text-sm text-(--ui-muted) mt-1">
                    将导出全部用户、页面、修订、注册码、订阅与站点设置为 JSON 文件。
                  </p>
                </div>
              </div>
              <template #footer>
                <UButton icon="i-lucide-download" @click="backup">立即备份</UButton>
              </template>
            </UCard>

            <UCard>
              <div class="flex items-start gap-3">
                <div class="rounded-lg bg-(--ui-primary)/10 p-2.5 text-(--ui-primary)">
                  <UIcon name="i-lucide-upload" class="size-5" />
                </div>
                <div class="flex-1">
                  <p class="font-semibold">导入数据备份</p>
                  <p class="text-sm text-(--ui-muted) mt-1">
                    选择备份 JSON 文件并导入，兼容新版与旧版导出的数据。导入将覆盖备份中包含的现有数据，请谨慎操作。
                  </p>
                </div>
              </div>
              <template #footer>
                <div class="flex items-center gap-2 flex-wrap">
                  <UButton icon="i-lucide-upload" :loading="importBusy" :disabled="!importFile" @click="importBackup">导入</UButton>
                  <input
                    type="file"
                    accept=".json,application/json"
                    class="min-w-0 text-sm text-(--ui-muted) file:mr-2 file:cursor-pointer file:rounded-md file:border file:border-(--ui-border) file:bg-(--ui-bg-elevated) file:px-3 file:py-1.5 file:text-(--ui-text) hover:file:bg-(--ui-bg-active)"
                    @change="onImportFileChange"
                  />
                </div>
              </template>
            </UCard>
          </div>
        </template>
      </UTabs>
    </div>
  </div>
</template>