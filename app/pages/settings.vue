<script setup lang="ts">
const { settings, resolvedHex, setColor } = useThemeSettings()
const colorMode = useColorMode()

// 暗亮切换（写入 color-mode 偏好，随浏览器本地保存）
const isDark = computed({
  get: () => colorMode.value === 'dark',
  set: (v: boolean) => { colorMode.preference = v ? 'dark' : 'light' },
})

// 所有预设色（按色系排列）；曜石黑仅亮色显示、象牙白仅暗色显示（mode 为空表示双端均显示）
const presetColors: { key: ThemeColor; label: string; mode?: 'light' | 'dark' }[] = [
  // 红粉色系
  { key: 'sakura', label: '樱花粉' },
  { key: 'coral', label: '珊瑚粉' },
  { key: 'red', label: '中国红' },
  { key: 'wine', label: '酒红色' },
  // 橙黄系
  { key: 'orange', label: '活力橙' },
  { key: 'maple', label: '枫叶橙' },
  { key: 'yellow', label: '柠檬黄' },
  { key: 'ginger', label: '姜黄色' },
  // 绿色系
  { key: 'green', label: '科技绿' },
  { key: 'mint', label: '薄荷绿' },
  { key: 'matcha', label: '抹茶绿' },
  { key: 'darkgreen', label: '墨绿色' },
  // 蓝色系
  { key: 'sky', label: '天空蓝' },
  { key: 'navy', label: '藏青色' },
  { key: 'klain', label: '克莱因蓝' },
  { key: 'haze', label: '雾霾蓝' },
  { key: 'tiffany', label: '蒂芙尼蓝' },
  // 紫色系
  { key: 'purple', label: '忧郁紫' },
  { key: 'taro', label: '香芋紫' },
  // 暖棕 / 中性
  { key: 'milktea', label: '奶茶色' },
  { key: 'coffee', label: '咖啡棕' },
  { key: 'gray', label: '高级灰' },
  { key: 'obsidian', label: '曜石黑', mode: 'light' },
  { key: 'ivory', label: '象牙白', mode: 'dark' },
]

// 当前模式可见色
const visibleColors = computed(() =>
  presetColors.filter(p => !p.mode || (p.mode === 'light' ? !isDark.value : isDark.value))
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
  <div class="max-w-2xl mx-auto px-4 py-10">
    <h1 class="text-2xl font-bold mb-1">设置</h1>
    <p class="text-sm text-(--ui-muted) mb-8">自定义站点的主题表现，保存于本浏览器。</p>

    <!-- 主题色 -->
    <UCard class="mb-6">
      <template #header>
        <div class="flex items-center justify-between gap-3">
          <span class="font-semibold">主题色</span>
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

    <!-- 页面暗亮 -->
    <UCard>
      <template #header><span class="font-semibold">外观</span></template>
      <div class="flex items-center justify-between gap-4">
        <div>
          <p class="font-medium">深色模式</p>
          <p class="text-sm text-(--ui-muted)">在浅色与深色显示之间切换</p>
        </div>
        <USwitch v-model="isDark" />
      </div>
    </UCard>
  </div>
</template>