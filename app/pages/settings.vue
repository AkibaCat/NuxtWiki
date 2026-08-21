<script setup lang="ts">
const { settings, resolvedHex, setColor } = useThemeSettings()
const colorMode = useColorMode()
const { t } = useI18n()
// 自动保存工作区开关（随浏览器本地保存，默认关闭）
const { enabled: autosave, setEnabled: setAutosave } = useEditorAutosave()

// 设置页：界面语言 / 主题色 / 明暗主题偏好 / 自动保存工作区（均保存在浏览器本地）
// 界面语言切换（设置页选择，记入浏览器本地；首次访问按站点默认语言）
const lang = useWikiLocale()
const langOptions = computed(() =>
  (lang.locales.value as { code: string; name: string }[]).map(l => ({ label: l.name, value: l.code }))
)
const currentLang = computed({
  get: () => lang.locale.value as string,
  set: (v: string) => lang.choose(v),
})

// 暗亮切换（写入 color-mode 偏好，随浏览器本地保存）
const isDark = computed({
  get: () => colorMode.value === 'dark',
  set: (v: boolean) => { colorMode.preference = v ? 'dark' : 'light' },
})

// 左侧分组导航：首选项 / 主题
// 用 computed 包裹 t()，使组名在切换语言时实时更新
const activeGroup = ref('preferences')
const groups = computed(() => [
  { key: 'preferences', label: t('settings.groups.preferences'), icon: 'i-lucide-sliders-horizontal' },
  { key: 'theme', label: t('settings.groups.theme'), icon: 'i-lucide-palette' },
])

// 所有预设色（按色系排列）；曜石黑仅亮色显示、象牙白仅暗色显示（mode 为空表示双端均显示）
// 用 computed 包裹，使其在切换语言时重新求值 t()，保证色名实时更新
const presetColors = computed<{ key: ThemeColor; label: string; mode?: 'light' | 'dark' }[]>(() => [
  // 红粉色系
  { key: 'sakura', label: t('settings.colors.sakura') },
  { key: 'coral', label: t('settings.colors.coral') },
  { key: 'red', label: t('settings.colors.red') },
  { key: 'wine', label: t('settings.colors.wine') },
  // 橙黄系
  { key: 'orange', label: t('settings.colors.orange') },
  { key: 'maple', label: t('settings.colors.maple') },
  { key: 'yellow', label: t('settings.colors.yellow') },
  { key: 'ginger', label: t('settings.colors.ginger') },
  // 绿色系
  { key: 'green', label: t('settings.colors.green') },
  { key: 'mint', label: t('settings.colors.mint') },
  { key: 'matcha', label: t('settings.colors.matcha') },
  { key: 'darkgreen', label: t('settings.colors.darkgreen') },
  // 蓝色系
  { key: 'sky', label: t('settings.colors.sky') },
  { key: 'navy', label: t('settings.colors.navy') },
  { key: 'klain', label: t('settings.colors.klain') },
  { key: 'haze', label: t('settings.colors.haze') },
  { key: 'tiffany', label: t('settings.colors.tiffany') },
  // 紫色系
  { key: 'purple', label: t('settings.colors.purple') },
  { key: 'taro', label: t('settings.colors.taro') },
  // 暖棕 / 中性
  { key: 'milktea', label: t('settings.colors.milktea') },
  { key: 'coffee', label: t('settings.colors.coffee') },
  { key: 'gray', label: t('settings.colors.gray') },
  { key: 'obsidian', label: t('settings.colors.obsidian'), mode: 'light' },
  { key: 'ivory', label: t('settings.colors.ivory'), mode: 'dark' },
])

// 当前模式可见色
const visibleColors = computed(() =>
  presetColors.value.filter(p => !p.mode || (p.mode === 'light' ? !isDark.value : isDark.value))
)

// 曜石黑/象牙白互为反色：切换明暗后，选中态应落在当前实际生效的那个上
const effectiveKey = (key: ThemeColor) =>
  (key === 'obsidian' || key === 'ivory') ? (isDark.value ? 'ivory' : 'obsidian') : key

const colorActive = (key: ThemeColor) => effectiveKey(settings.value.color) === effectiveKey(key)

// 按钮色点：曜石黑/象牙白按明暗互换成实际色
const hideDotKey = (key: ThemeColor) => (key === 'obsidian' || key === 'ivory')

const dotColor = (key: ThemeColor) =>
  hideDotKey(key) ? (isDark.value ? IVORY : OBSIDIAN) : THEME_PRESETS[key]

const previewHex = computed(() => resolvedHex.value)
</script>

<template>
  <div class="max-w-4xl mx-auto px-4 py-10">
    <h1 class="text-2xl font-bold mb-1">{{ t('settings.title') }}</h1>
    <p class="text-sm text-(--ui-muted) mb-8">{{ t('settings.subtitle') }}</p>

    <!-- 左右两栏：左=设置分组导航 / 右=设置项 -->
    <div class="grid grid-cols-1 md:grid-cols-[13rem_minmax(0,1fr)] gap-6 items-start">
      <!-- 左侧：设置分组导航（桌面端随内容滚动固定） -->
      <aside class="rounded-lg border border-(--ui-border) bg-(--ui-bg-elevated) p-2 md:sticky md:top-24">
        <nav class="flex md:flex-col gap-1 overflow-x-auto">
          <button
            v-for="g in groups"
            :key="g.key"
            type="button"
            class="flex items-center gap-2 rounded-md px-3 py-2 text-sm whitespace-nowrap transition-colors cursor-pointer"
            :class="activeGroup === g.key ? 'bg-(--ui-primary)/10 text-(--ui-primary) font-medium' : 'text-(--ui-muted) hover:text-(--ui-text) hover:bg-(--ui-bg-elevated)'"
            @click="activeGroup = g.key"
          >
            <UIcon :name="g.icon" class="size-4 shrink-0" />
            {{ g.label }}
          </button>
        </nav>
      </aside>

      <!-- 右侧：设置项（按分组展示） -->
      <div class="min-w-0 space-y-6">
        <!-- 首选项：界面语言 / 自动保存工作区 -->
        <template v-if="activeGroup === 'preferences'">
          <UCard>
            <template #header><span class="font-semibold">{{ t('settings.language.title') }}</span></template>
            <div class="flex items-center justify-between gap-4">
              <div>
                <p class="font-medium">{{ t('settings.language.label') }}</p>
                <p class="text-sm text-(--ui-muted)">{{ t('settings.language.hint') }}</p>
              </div>
              <USelect v-model="currentLang" :items="langOptions" class="w-44" :aria-label="t('settings.language.title')" />
            </div>
          </UCard>

          <UCard>
            <template #header><span class="font-semibold">{{ t('settings.autosave.title') }}</span></template>
            <div class="flex items-center justify-between gap-4">
              <div>
                <p class="font-medium">{{ t('settings.autosave.label') }}</p>
                <p class="text-sm text-(--ui-muted)">{{ t('settings.autosave.hint') }}</p>
              </div>
              <USwitch v-model="autosave" @update:model-value="(v: boolean) => setAutosave(v)" />
            </div>
          </UCard>
        </template>

        <!-- 主题：外观 / 主题色 -->
        <template v-else-if="activeGroup === 'theme'">
          <UCard>
            <template #header><span class="font-semibold">{{ t('settings.appearance') }}</span></template>
            <div class="flex items-center justify-between gap-4">
              <div>
                <p class="font-medium">{{ t('settings.darkMode') }}</p>
                <p class="text-sm text-(--ui-muted)">{{ t('settings.darkModeHint') }}</p>
              </div>
              <USwitch v-model="isDark" />
            </div>
          </UCard>

          <UCard>
            <template #header>
              <div class="flex items-center justify-between gap-3">
                <span class="font-semibold">{{ t('settings.theme') }}</span>
                <span class="flex items-center gap-2 text-sm text-(--ui-muted)">
                  <span class="size-3 rounded-full" :style="{ background: previewHex }" />
                  {{ previewHex.toUpperCase() }}
                </span>
              </div>
            </template>

            <!-- 预设主题色 -->
            <div class="flex flex-wrap items-center gap-2">
              <button
                v-for="p in visibleColors"
                :key="p.key"
                type="button"
                @click="setColor(p.key)"
                class="flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm transition-colors cursor-pointer"
                :class="colorActive(p.key) ? 'border-(--ui-primary) text-(--ui-primary)' : 'border-(--ui-border) text-(--ui-muted) hover:text-(--ui-text)'"
              >
                <span class="size-3.5 rounded-full shrink-0" :style="{ background: dotColor(p.key) }" />
                {{ p.label }}
              </button>
            </div>
          </UCard>
        </template>
      </div>
    </div>
  </div>
</template>
