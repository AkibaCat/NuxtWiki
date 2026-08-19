<script setup lang="ts">
const { t } = useI18n()
const { user, ready, init } = useAuth()
const api = useApi()
const toast = useToast()

// 后台管理页：概览统计 / 站点设置 / 注册码 / 页面 / 用户 / 备份与导入，仅管理员可访问
const tab = ref('stats')
const loading = ref(true)

onMounted(async () => {
  await init()
  if (!user.value) {
    await navigateTo('/login?redirect=/admin')
    return
  }
  if (!user.value.is_admin) {
    toast.add({ title: t('admin.permDenied'), color: 'error' })
    await navigateTo('/')
    return
  }
  await loadStats()
  loading.value = false
  await autoCheckVersion()
})

// ============ 概览 ============
const stats = ref<any>(null)
const loadStats = async () => {
  const r = await api.get('admin.stats')
  if (r.ok) stats.value = r.data
}

// ============ 版本更新检查 ============
// 当前版本优先取版本检查结果（后端维护），未检查前兜底用 stats.version
const currentVersion = computed(() => versionInfo.value?.current_version || stats.value?.version || '')
const versionInfo = ref<any>(null)
const versionChecking = ref(false)
const versionModalOpen = ref(false)

// 简单转义（Release Notes 为仓库 Markdown，经 v-html 渲染前转义避免注入）
const escapeHtml = (s: string) =>
  s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')

const releaseNotesHtml = computed(() => {
  const md = versionInfo.value?.release_notes || ''
  if (!md) return `<p>${t('admin.noReleaseNotes')}</p>`
  return md
    .split('\n')
    .map((line: string) => {
      if (/^### /.test(line)) return `<p class="mt-3 mb-1 font-semibold">${escapeHtml(line.slice(4))}</p>`
      if (/^## /.test(line)) return `<p class="mt-3 mb-1 text-base font-bold">${escapeHtml(line.slice(3))}</p>`
      if (/^[-•*] /.test(line)) return `<p class="flex gap-2 pl-1"><span>•</span><span>${escapeHtml(line.replace(/^[-•*] /, ''))}</span></p>`
      if (line.trim() === '') return ''
      return `<p>${escapeHtml(line)}</p>`
    })
    .join('')
})

// 版本检查：silent=true 为自动检查（静默失败），否则为手动点击（失败/无更新均有提示）
const checkVersion = async (opts: { silent?: boolean; refresh?: boolean } = {}) => {
  versionChecking.value = true
  const r = await api.get('admin.version-check', { refresh: opts.refresh ? 1 : undefined })
  versionChecking.value = false
  if (!r.ok) {
    if (!opts.silent) toast.add({ title: r.error?.message || t('admin.checkUpdateFail'), color: 'error' })
    return
  }
  versionInfo.value = r.data
  if (r.data?.has_update) {
    // 检测到新版本：弹出可点击查看的消息提示
    toast.add({
      title: t('admin.updateAvailable'),
      color: 'primary',
      actions: [{ label: t('admin.view'), onClick: () => { versionModalOpen.value = true } }]
    })
  } else if (!opts.silent) {
    toast.add({ title: t('admin.upToDate'), color: 'success' })
  }
}

// 自动检查：每天首次登录后台时触发一次（按浏览器记录）
const autoCheckVersion = async () => {
  const KEY = 'nuxtwiki_version_check_date'
  try {
    const today = new Date().toISOString().slice(0, 10)
    if (localStorage.getItem(KEY) === today) return
    localStorage.setItem(KEY, today)
    await checkVersion({ silent: true })
  } catch {
    // 浏览器存储不可用时静默跳过
  }
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
    toast.add({ title: t('admin.saved'), color: 'success' })
    const auth = useAuth()
    if (auth.site.value) {
      auth.site.value.name = settings.value.site_name
      auth.site.value.description = settings.value.site_description
      auth.site.value.home_tag = settings.value.home_tag
      auth.site.value.site_footer = settings.value.site_footer
    }
  } else {
    toast.add({ title: r.error?.message || t('admin.saveFail'), color: 'error' })
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
  if (!confirm(t('admin.confirmDeletePage', { tag }))) return
  const r = await api.post('page.delete', { tag })
  if (r.ok) {
    toast.add({ title: t('admin.pageDeleted'), color: 'success' })
    await loadAdminPages()
    await loadStats()
  } else {
    toast.add({ title: r.error?.message || t('admin.deletePageFail'), color: 'error' })
  }
}

// ============ 页面权限设置弹窗 ============
// 页面权限等级选项（0 访客 / 1 管理员 / 2 高级 / 3 普通）
const aclLevelOptions = [
  { label: t('admin.role.guest'), value: 0 },
  { label: t('admin.role.admin'), value: 1 },
  { label: t('admin.role.advanced'), value: 2 },
  { label: t('admin.role.user'), value: 3 }
]
const permDefs = [
  { key: 'read', label: t('admin.perm.read'), icon: 'i-lucide-eye', hint: t('admin.perm.readHint'), def: 0 },
  { key: 'edit', label: t('admin.perm.edit'), icon: 'i-lucide-pencil', hint: t('admin.perm.editHint'), def: 3 },
  { key: 'history', label: t('admin.perm.history'), icon: 'i-lucide-history', hint: t('admin.perm.historyHint'), def: 3 },
  { key: 'diff', label: t('admin.perm.diff'), icon: 'i-lucide-git-compare', hint: t('admin.perm.diffHint'), def: 2 },
  { key: 'backlinks', label: t('admin.perm.backlinks'), icon: 'i-lucide-link', hint: t('admin.perm.backlinksHint'), def: 3 },
  { key: 'acl', label: t('admin.perm.acl'), icon: 'i-lucide-shield', hint: t('admin.perm.aclHint'), def: 1 },
  { key: 'contributors', label: t('admin.perm.contributors'), icon: 'i-lucide-users', hint: t('admin.perm.contributorsHint'), def: 0 }
]
const aclModalOpen = ref(false)
const aclTarget = ref<any>(null)
const aclPerms = reactive<Record<string, number>>({})
const aclSaving = ref(false)

const openAclModal = async (row: any) => {
  aclTarget.value = row
  for (const p of permDefs) aclPerms[p.key] = p.def
  const r = await api.get('page.perms', { tag: row.tag })
  if (r.ok) {
    const d = r.data as any
    for (const p of permDefs) {
      const v = Number(d['acl_' + p.key])
      aclPerms[p.key] = v >= 0 && v <= 3 ? v : p.def
    }
  } else {
    toast.add({ title: r.error?.message || t('admin.loadPermFail'), color: 'error' })
  }
  aclModalOpen.value = true
}
const saveAcl = async () => {
  if (!aclTarget.value) return
  aclSaving.value = true
  const payload: Record<string, number> = { tag: aclTarget.value.tag }
  for (const p of permDefs) payload['acl_' + p.key] = aclPerms[p.key]!
  const r = await api.post('page.update-acl', payload)
  aclSaving.value = false
  if (r.ok) {
    toast.add({ title: t('admin.permsUpdated'), color: 'success' })
    aclModalOpen.value = false
  } else {
    toast.add({ title: r.error?.message || t('admin.saveFail'), color: 'error' })
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
  { label: t('admin.role.admin'), value: 1 },
  { label: t('admin.role.advanced'), value: 2 },
  { label: t('admin.role.user'), value: 3 }
]
// 站点默认权限等级（后端以字符串存储 0~3，含访客等级）
const settingsLevelOptions = [
  { label: t('admin.role.guest'), value: '0' },
  { label: t('admin.role.admin'), value: '1' },
  { label: t('admin.role.advanced'), value: '2' },
  { label: t('admin.role.user'), value: '3' }
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
    toast.add({ title: t('admin.userCreated'), color: 'success' })
    newUser.value = { username: '', password: '', level: 3 }
    showCreate.value = false
    await loadUsers()
  } else {
    toast.add({ title: r.error?.message || t('admin.createUserFail'), color: 'error' })
  }
}
// 设置权限等级（管理/高级/普通）——后端会同步恢复为正常状态
const setRoleLevel = async (u: any, level: number) => {
  const r = await api.post('users.update', { username: u.username, level })
  if (r.ok) {
    u.level = level
    u.status = 'active'
    u.reason = ''
    const role = level === 1 ? t('admin.role.admin') : level === 2 ? t('admin.role.advanced') : t('admin.role.user')
    toast.add({ title: t('admin.roleChanged', { name: u.username, role }), color: 'success' })
  } else {
    toast.add({ title: r.error?.message || t('admin.updateUserFail'), color: 'error' })
  }
}
const deleteUser = async (u: any) => {
  if (!confirm(t('admin.confirmDeleteUser', { name: u.username }))) return
  const r = await api.post('users.delete', { username: u.username })
  if (r.ok) {
    toast.add({ title: t('admin.userDeleted'), color: 'success' })
    await loadUsers()
  } else {
    toast.add({ title: r.error?.message || t('admin.deleteUserFail'), color: 'error' })
  }
}
const setUserStatus = async (u: any, status: 'active' | 'frozen' | 'banned', reason = '') => {
  const r = await api.post('users.set-status', { username: u.username, status, reason })
  if (r.ok) {
    u.status = status
    u.reason = r.data?.reason || ''
    const action = status === 'active' ? t('admin.status.restored') : status === 'frozen' ? t('admin.status.frozen') : t('admin.status.banned')
    toast.add({ title: t('admin.userStatusChanged', { action, name: u.username }), color: 'success' })
  } else {
    toast.add({ title: r.error?.message || t('admin.opFail'), color: 'error' })
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
    toast.add({ title: t('admin.regcodesGenerated', { count: regcodeCount.value }), color: 'success' })
    await loadRegcodes()
  } else {
    toast.add({ title: r.error?.message || t('admin.genRegcodesFail'), color: 'error' })
  }
}
const copyRegcode = async (code: string) => {
  await navigator.clipboard.writeText(code)
  toast.add({ title: t('admin.copiedToClipboard'), color: 'success' })
}
const deleteRegcode = async (id: number) => {
  if (!confirm(t('admin.confirmDeleteRegcode'))) return
  const r = await api.post('regcode.delete', { id })
  if (r.ok) {
    toast.add({ title: t('admin.regcodeDeleted'), color: 'success' })
    await loadRegcodes()
  }
}
const destroyRegcode = async (id: number) => {
  if (!confirm(t('admin.confirmDestroyRegcode'))) return
  const r = await api.post('regcode.destroy', { id })
  if (r.ok) {
    toast.add({ title: t('admin.regcodeDestroyed'), color: 'success' })
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
    toast.add({ title: t('admin.selectBackupFile'), color: 'warning' })
    return
  }
  if (!confirm(t('admin.confirmImport'))) return
  importBusy.value = true
  try {
    const text = await importFile.value.text()
    const r = await api.post('admin.restore', { data: text })
    if (r.ok) {
      const counts = (r.data as any)?.imported || {}
      toast.add({
        title: t('admin.importSuccess', {
          pages: counts.pages ?? 0,
          revisions: counts.revisions ?? 0,
          users: counts.users ?? 0,
          watchers: counts.watchers ?? 0,
          regcodes: counts.regcodes ?? 0,
          settings: counts.settings ?? 0
        }),
        color: 'success'
      })
      await loadStats()
    } else {
      toast.add({ title: r.error?.message || t('admin.importFail'), color: 'error' })
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
    { label: t('admin.stats.pages'), value: s.pages, icon: 'i-lucide-file-text' },
    { label: t('admin.stats.revisions'), value: s.revisions, icon: 'i-lucide-history' },
    { label: t('admin.stats.users'), value: s.users, icon: 'i-lucide-users' },
    { label: t('admin.stats.watchers'), value: s.watchers, icon: 'i-lucide-bell-ring' },
    { label: t('admin.stats.regcodes'), value: s.regcodes, icon: 'i-lucide-key-round' },
    { label: t('admin.stats.totalHits'), value: s.total_hits, icon: 'i-lucide-eye' }
  ]
})
</script>

<template>
  <div class="max-w-5xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold mb-6">{{ t('admin.title') }}</h1>

    <!-- 版本更新横幅：检测到新版本时显示 -->
    <div v-if="versionInfo?.has_update" class="mb-6 flex items-center justify-between gap-3 rounded-lg border border-(--ui-primary) bg-(--ui-primary)/10 px-4 py-3">
      <div class="flex items-center gap-2 text-sm">
        <UIcon name="i-lucide-sparkles" class="size-4 shrink-0 text-(--ui-primary)" />
        <span>{{ t('admin.updateBanner') }}：<span class="font-medium text-white">{{ versionInfo.current_version }}</span><span class="text-(--ui-muted)"> → </span><span class="font-semibold text-green-500">{{ versionInfo.latest_version }}</span></span>
      </div>
      <UButton size="sm" color="primary" icon="i-lucide-eye" @click="versionModalOpen = true">{{ t('admin.viewNewVersion') }}</UButton>
    </div>

    <div v-if="loading" class="flex justify-center py-16">
      <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-(--ui-primary)" />
    </div>
    <div v-else>
      <UTabs
        v-model="tab"
        :ui="{ trigger: 'justify-center' }"
        :items="[
          { label: t('admin.tabs.stats'), value: 'stats', slot: 'stats', icon: 'i-lucide-layout-dashboard' },
          { label: t('admin.tabs.settings'), value: 'settings', slot: 'settings', icon: 'i-lucide-settings' },
          { label: t('admin.tabs.regcodes'), value: 'regcodes', slot: 'regcodes', icon: 'i-lucide-key-round' },
          { label: t('admin.tabs.pages'), value: 'pages', slot: 'pages', icon: 'i-lucide-file-text' },
          { label: t('admin.tabs.users'), value: 'users', slot: 'users', icon: 'i-lucide-users' },
          { label: t('admin.tabs.backup'), value: 'backup', slot: 'backup', icon: 'i-lucide-database-backup' }
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
          <div v-if="stats" class="mt-6 flex flex-wrap items-center gap-4 text-sm text-(--ui-muted)">
            <span>{{ t('admin.database') }}：{{ stats.driver }}</span>
            <span class="flex items-center gap-2">
              <span>{{ t('admin.version') }}：{{ currentVersion }}</span>
              <UButton
                size="xs"
                variant="ghost"
                color="neutral"
                icon="i-lucide-refresh-cw"
                :loading="versionChecking"
                @click="checkVersion({ refresh: true })"
              >{{ t('admin.checkUpdate') }}</UButton>
            </span>
          </div>
        </template>

        <template #settings>
          <UCard class="mt-4">
            <div class="space-y-4">
              <div class="grid md:grid-cols-2 gap-4">
                <UFormField :label="t('admin.settings.siteName')">
                  <UInput v-model="settings.site_name" class="w-full" />
                </UFormField>
                <UFormField :label="t('admin.settings.homeTag')">
                  <UInput v-model="settings.home_tag" class="w-full" />
                </UFormField>
              </div>
              <UFormField :label="t('admin.settings.siteDescription')">
                <UTextarea v-model="settings.site_description" :rows="2" class="w-full" />
              </UFormField>
              <UFormField :label="t('admin.settings.footerContent')" :hint="t('admin.settings.footerHint')">
                <UTextarea v-model="settings.site_footer" :rows="3" class="w-full" />
              </UFormField>
              <div class="grid md:grid-cols-2 gap-4">
                <UFormField :label="t('admin.settings.defaultReadLevel')">
                  <USelect v-model="settings.default_read_level" :items="settingsLevelOptions" class="w-full" />
                </UFormField>
                <UFormField :label="t('admin.settings.defaultEditLevel')">
                  <USelect v-model="settings.default_edit_level" :items="settingsLevelOptions" class="w-full" />
                </UFormField>
                <UFormField :label="t('admin.settings.defaultHistoryLevel')">
                  <USelect v-model="settings.default_history_level" :items="settingsLevelOptions" class="w-full" />
                </UFormField>
                <UFormField :label="t('admin.settings.defaultDiffLevel')">
                  <USelect v-model="settings.default_diff_level" :items="settingsLevelOptions" class="w-full" />
                </UFormField>
                <UFormField :label="t('admin.settings.defaultBacklinksLevel')">
                  <USelect v-model="settings.default_backlinks_level" :items="settingsLevelOptions" class="w-full" />
                </UFormField>
                <UFormField :label="t('admin.settings.defaultPermsLevel')">
                  <USelect v-model="settings.default_perms_level" :items="settingsLevelOptions" class="w-full" />
                </UFormField>
                <UFormField :label="t('admin.settings.defaultContributorsLevel')">
                  <USelect v-model="settings.default_contributors_level" :items="settingsLevelOptions" class="w-full" />
                </UFormField>
              </div>
              <UFormField :label="t('admin.settings.allowRegistration')">
                <USwitch v-model="allowRegistration" />
              </UFormField>
            </div>
            <template #footer>
              <UButton icon="i-lucide-save" :loading="settingsBusy" @click="saveSettings">{{ t('admin.saveSettings') }}</UButton>
            </template>
          </UCard>
        </template>

        <!-- 注册码管理 -->
        <template #regcodes>
          <div class="flex items-center justify-between mt-4 mb-3">
            <h2 class="font-semibold">{{ t('admin.regcodesList') }} ({{ regcodes.length }})</h2>
            <div class="flex items-center gap-2">
              <UInputNumber v-model="regcodeCount" :min="1" :max="100" class="w-20" />
              <UButton icon="i-lucide-plus" :loading="regcodeBusy" @click="generateRegcodes">{{ t('admin.generate') }}</UButton>
            </div>
          </div>
          <UCard :ui="{ body: 'p-0' }">
            <UTable
              :data="regcodes"
              :loading="regcodesLoading"
              :columns="[
                { accessorKey: 'code', header: t('admin.regcodes.code') },
                { accessorKey: 'created_at', header: t('admin.regcodes.createdAt') },
                { accessorKey: 'username', header: t('admin.regcodes.user') },
                { accessorKey: 'actions', header: t('admin.regcodes.actions'), enableSorting: false }
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
                  <UButton v-if="!row.original.username" icon="i-lucide-copy" size="xs" color="neutral" variant="ghost" :aria-label="t('admin.copy')" @click="copyRegcode(row.original.code)" />
                  <UButton v-if="!row.original.username" icon="i-lucide-flame" size="xs" color="neutral" variant="ghost" :aria-label="t('admin.destroy')" @click="destroyRegcode(row.original.id)" />
                  <UButton v-if="row.original.username" icon="i-lucide-trash" size="xs" color="neutral" variant="ghost" :aria-label="t('common.delete')" @click="deleteRegcode(row.original.id)" />
                </div>
              </template>
            </UTable>
          </UCard>
        </template>

        <template #pages>
          <UCard class="mt-4" :ui="{ body: 'p-0' }">
            <UTable
              :data="adminPages"
              :loading="pagesLoading"
              :columns="[
                { accessorKey: 'title', header: t('admin.pages.title') },
                { accessorKey: 'tag', header: t('admin.pages.tag') },
                { accessorKey: 'revision', header: t('admin.pages.revision') },
                { accessorKey: 'hits', header: t('admin.pages.hits') },
                { accessorKey: 'last_editor', header: t('admin.pages.editor') },
                { accessorKey: 'updated_at', header: t('admin.pages.updated') },
                { accessorKey: 'actions', header: t('admin.pages.actions'), enableSorting: false }
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
                  <UButton icon="i-lucide-shield" size="xs" color="neutral" variant="ghost" :aria-label="t('admin.perm.title')" @click="openAclModal(row.original)" />
                  <UButton :to="`/editor?open=${encodeURIComponent(row.original.tag)}`" icon="i-lucide-pencil" size="xs" color="neutral" variant="ghost" :aria-label="t('common.edit')" />
                  <UButton icon="i-lucide-trash" size="xs" color="neutral" variant="ghost" :aria-label="t('common.delete')" @click="deletePage(row.original.tag)" />
                </div>
              </template>
            </UTable>
          </UCard>
          <!-- 页面权限设置弹窗 -->
          <UModal v-model:open="aclModalOpen">
            <template #content>
              <UCard>
                <template #header>{{ t('admin.perm.title') }} · {{ aclTarget?.tag }}</template>
                <div class="space-y-4">
                  <UFormField
                    v-for="p in permDefs"
                    :key="p.key"
                    :label="`${p.label} [${aclPerms[p.key]}]`"
                    :hint="p.hint"
                  >
                    <USelect v-model="aclPerms[p.key]" :items="aclLevelOptions" class="w-full" />
                  </UFormField>
                  <UAlert
                    color="info"
                    variant="subtle"
                    icon="i-lucide-info"
                    :title="t('admin.aclLevelTitle')"
                    :description="t('admin.aclLevelDesc')"
                  />
                </div>
                <template #footer>
                  <div class="flex justify-end gap-2">
                    <UButton variant="subtle" color="neutral" @click="aclModalOpen = false">{{ t('common.cancel') }}</UButton>
                    <UButton icon="i-lucide-save" :loading="aclSaving" @click="saveAcl">{{ t('admin.savePerms') }}</UButton>
                  </div>
                </template>
              </UCard>
            </template>
          </UModal>
        </template>

        <template #users>
          <div class="flex items-center justify-between mt-4 mb-3 gap-3">
            <h2 class="font-semibold shrink-0">{{ t('admin.usersList') }} ({{ users.length }})</h2>
            <div class="flex items-center gap-2 ml-auto">
              <UInput v-model="userSearch" :placeholder="t('admin.searchUsersPlaceholder')" icon="i-lucide-search" class="w-56" clearable @update:model-value="loadUsers" />
              <UButton v-if="!showCreate" icon="i-lucide-user-plus" size="sm" @click="showCreate = true">{{ t('admin.createUser') }}</UButton>
            </div>
          </div>
          <UCard v-if="showCreate" class="mb-4">
            <div class="grid md:grid-cols-2 gap-4">
              <UFormField :label="t('admin.users.username')">
                <UInput v-model="newUser.username" class="w-full" />
              </UFormField>
              <UFormField :label="t('admin.users.password')">
                <UInput v-model="newUser.password" type="password" class="w-full" />
              </UFormField>
              <UFormField :label="t('admin.users.level')">
                <USelect v-model="newUser.level" :items="levelOptions" class="w-full" />
              </UFormField>
            </div>
            <div class="flex gap-2 mt-4">
              <UButton icon="i-lucide-check" :loading="userBusy" @click="createUser">{{ t('common.create') }}</UButton>
              <UButton color="neutral" variant="ghost" @click="showCreate = false">{{ t('common.cancel') }}</UButton>
            </div>
          </UCard>
          <UCard :ui="{ body: 'p-0' }">
            <UTable
              :data="users"
              :loading="usersLoading"
              :columns="[
                { accessorKey: 'username', header: t('admin.users.username') },
                { accessorKey: 'role', header: t('admin.users.role') },
                { accessorKey: 'reason', header: t('admin.users.reason') },
                { accessorKey: 'created_at', header: t('admin.users.createdAt') },
                { accessorKey: 'actions', header: t('admin.users.actions'), enableSorting: false }
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
              <!-- 操作列：冻结/解冻/封禁/解封/删除；宽度不足时仅显示图标 -->
              <template #actions-cell="{ row }">
                <div class="flex items-center gap-1">
                  <template v-if="row.original.username !== user?.username">
                    <UButton
                      v-if="row.original.status === 'active'"
                      icon="i-lucide-snowflake"
                      size="xs"
                      color="neutral"
                      variant="ghost"
                      :aria-label="t('admin.status.freeze')"
                      @click="openStatusModal(row.original, 'frozen')"
                    >
                      <span class="hidden xl:inline">{{ t('admin.status.freeze') }}</span>
                    </UButton>
                    <UButton
                      v-if="row.original.status === 'frozen'"
                      icon="i-lucide-check"
                      size="xs"
                      color="neutral"
                      variant="ghost"
                      :aria-label="t('admin.status.unfreeze')"
                      @click="setUserStatus(row.original, 'active')"
                    >
                      <span class="hidden xl:inline">{{ t('admin.status.unfreeze') }}</span>
                    </UButton>
                    <UButton
                      v-if="row.original.status !== 'banned'"
                      icon="i-lucide-ban"
                      size="xs"
                      color="error"
                      variant="ghost"
                      :aria-label="t('admin.status.ban')"
                      @click="openStatusModal(row.original, 'banned')"
                    >
                      <span class="hidden xl:inline">{{ t('admin.status.ban') }}</span>
                    </UButton>
                    <UButton
                      v-if="row.original.status === 'banned'"
                      icon="i-lucide-unlock"
                      size="xs"
                      color="neutral"
                      variant="ghost"
                      :aria-label="t('admin.status.unban')"
                      @click="setUserStatus(row.original, 'active')"
                    >
                      <span class="hidden xl:inline">{{ t('admin.status.unban') }}</span>
                    </UButton>
                    <UButton
                      icon="i-lucide-trash"
                      size="xs"
                      color="neutral"
                      variant="ghost"
                      :aria-label="t('common.delete')"
                      @click="deleteUser(row.original)"
                    >
                      <span class="hidden xl:inline">{{ t('common.delete') }}</span>
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
                <template #header>{{ statusAction === 'frozen' ? t('admin.statusModal.freezeTitle') : t('admin.statusModal.banTitle') }}</template>
                <div class="space-y-4">
                  <p class="text-sm text-(--ui-muted)">
                    {{ statusAction === 'frozen' ? t('admin.statusModal.freezeBody', { name: statusTarget?.username }) : t('admin.statusModal.banBody', { name: statusTarget?.username }) }}
                  </p>
                  <UFormField :label="statusAction === 'frozen' ? t('admin.statusModal.freezeReason') : t('admin.statusModal.banReason')" :hint="t('admin.statusModal.reasonHint')">
                    <UTextarea v-model="statusReason" :rows="3" :placeholder="t('admin.statusModal.reasonPlaceholder')" class="w-full" />
                  </UFormField>
                </div>
                <template #footer>
                  <div class="flex justify-end gap-2">
                    <UButton variant="subtle" color="neutral" @click="statusModalOpen = false">{{ t('common.cancel') }}</UButton>
                    <UButton :color="statusAction === 'banned' ? 'error' : 'warning'" icon="i-lucide-check" :loading="statusBusy" @click="confirmStatusModal">{{ t('common.confirm') }}</UButton>
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
                  <p class="font-semibold">{{ t('admin.backup.exportTitle') }}</p>
                  <p class="text-sm text-(--ui-muted) mt-1">
                    {{ t('admin.backup.exportDesc') }}
                  </p>
                </div>
              </div>
              <template #footer>
                <UButton icon="i-lucide-download" @click="backup">{{ t('admin.backup.now') }}</UButton>
              </template>
            </UCard>

            <UCard>
              <div class="flex items-start gap-3">
                <div class="rounded-lg bg-(--ui-primary)/10 p-2.5 text-(--ui-primary)">
                  <UIcon name="i-lucide-upload" class="size-5" />
                </div>
                <div class="flex-1">
                  <p class="font-semibold">{{ t('admin.backup.importTitle') }}</p>
                  <p class="text-sm text-(--ui-muted) mt-1">
                    {{ t('admin.backup.importDesc') }}
                  </p>
                </div>
              </div>
              <template #footer>
                <div class="flex items-center gap-2 flex-wrap">
                  <UButton icon="i-lucide-upload" :loading="importBusy" :disabled="!importFile" @click="importBackup">{{ t('admin.backup.import') }}</UButton>
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

    <!-- 版本更新详情弹窗 -->
    <UModal v-model:open="versionModalOpen" scrollable :ui="{ content: 'max-w-lg max-h-[85vh]' }">
      <template #content>
        <UCard>
          <template #header>
            <div class="flex items-center gap-2">
              <UIcon name="i-lucide-sparkles" class="size-5 text-(--ui-primary)" />
              <span class="font-semibold">{{ t('admin.versionModal.title') }}</span>
            </div>
          </template>
          <div class="space-y-4">
            <div class="flex items-center gap-2 rounded-lg bg-(--ui-bg-elevated) px-4 py-3 text-sm">
              <span class="font-medium text-white">{{ versionInfo?.current_version }}</span>
              <UIcon name="i-lucide-arrow-right" class="size-4 shrink-0 text-(--ui-muted)" />
              <span class="font-semibold text-green-500">{{ versionInfo?.latest_version }}</span>
            </div>
            <div class="max-h-[42vh] overflow-y-auto rounded-lg border border-(--ui-border) p-4 text-sm leading-relaxed text-(--ui-text)" v-html="releaseNotesHtml"></div>
          </div>
          <template #footer>
            <div class="flex items-center justify-between gap-2">
              <UButton icon="i-lucide-external-link" variant="outline" color="neutral" :to="versionInfo?.release_url" target="_blank">{{ t('admin.versionModal.viewRelease') }}</UButton>
              <div class="flex items-center gap-2">
                <UButton variant="subtle" color="neutral" @click="versionModalOpen = false">{{ t('admin.close') }}</UButton>
              </div>
            </div>
          </template>
        </UCard>
      </template>
    </UModal>
  </div>
</template>