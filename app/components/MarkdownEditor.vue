<script setup lang="ts">
// 带行号的 Markdown 编辑器（行号栏 + 自动换行开关 + 原生 textarea）。
// 供普通编辑页与沉浸式页面编辑器复用。通过 defineExpose 暴露 textarea 供外部插入/光标操作。
const { t } = useI18n()
const props = defineProps<{
  modelValue: string
  placeholder?: string
  rows?: number
  /** 编辑器输入区固定显示高度（如 '480px'），缺省时用 rows 计算高度 */
  height?: string
  /** 外框圆角：all=四角 / bottom=仅底部 / bottom-left=仅左下 / bottom-right=仅右下 */
  corner?: 'all' | 'bottom' | 'bottom-left' | 'bottom-right'
}>()
const emit = defineEmits<{
  'update:modelValue': [value: string]
  input: [e: Event]
  keydown: [e: KeyboardEvent]
  keyup: [e: Event]
  mouseup: [e: Event]
  blur: [e: FocusEvent]
}>()

const body = computed({
  get: () => props.modelValue,
  set: (v: string) => emit('update:modelValue', v),
})

const wrap = ref(true)
const toggleWrap = () => {
  wrap.value = !wrap.value
  // 切换后同步滚动位置，避免错位
  requestAnimationFrame(syncScroll)
}

const wrapLabel = computed(() => (wrap.value ? t('editor.wrap') : t('editor.nowrap')))

const gutterOuter = ref<HTMLDivElement>()
const gutterInner = ref<HTMLDivElement>()
const taRef = ref<HTMLTextAreaElement>()
const mirLines = ref<HTMLDivElement[]>([])
const mirRef = ref<HTMLDivElement>()

// 每个逻辑行（按 \n 切分）的文本
const logicalLines = computed(() => body.value.split('\n'))

// 每行在界面上的可视行数（自动换行时可能 >1）。不换行时恒为 1。
const rowsPerLine = ref<number[]>(logicalLines.value.map(() => 1))

// 行高与 textarea 保持一致（像素值，用于对齐）
const LINE_H = 22

const cornerClass = computed(() => {
  const c = props.corner || 'all'
  return {
    all: 'rounded-lg',
    bottom: 'rounded-b-lg',
    'bottom-left': 'rounded-bl-lg',
    'bottom-right': 'rounded-br-lg',
  }[c]
})

// 生成行号单元格序列：每个可视行一个单元格，仅在逻辑行首显示行号。
const gutterCells = computed<Array<{ n: number | null }>>(() => {
  const cells: Array<{ n: number | null }> = []
  rowsPerLine.value.forEach((rows, idx) => {
    for (let r = 0; r < rows; r++) cells.push({ n: r === 0 ? idx + 1 : null })
  })
  return cells
})

// 通过隐藏镜像测量每个逻辑行的可视化行数（与 textarea 同宽、同字体、同换行规则）。
const measureRows = () => {
  const ta = taRef.value
  const mir = mirRef.value
  if (!ta || !mir) return
  // 镜像宽度 = textarea 内容区宽度（clientWidth 不含边框与滚动条），保证换行点一致
  mir.style.width = `${ta.clientWidth - 24}px` // 减去左右 p-3 内边距
  const els = mirLines.value
  rowsPerLine.value = els.map((el) => Math.max(1, Math.round(el.offsetHeight / LINE_H)))
}

// 重新测量需要确保镜像 DOM 已渲染（等下一次重排）
const scheduleMeasure = () => requestAnimationFrame(measureRows)

watch(() => props.modelValue, scheduleMeasure)
watch(wrap, scheduleMeasure)

// 让行号栏高度始终与 textarea 可视高度一致，并跟随 textarea 纵向滚动
const syncGutterHeight = () => {
  const ta = taRef.value
  const out = gutterOuter.value
  if (ta && out) out.style.height = `${ta.offsetHeight}px`
}

const syncScroll = () => {
  const ta = taRef.value
  const inner = gutterInner.value
  if (ta && inner) inner.style.transform = `translateY(${-ta.scrollTop}px)`
}

let ro: ResizeObserver | null = null
onMounted(() => {
  syncGutterHeight()
  measureRows()
  if (typeof ResizeObserver !== 'undefined' && taRef.value) {
    ro = new ResizeObserver(() => {
      syncGutterHeight()
      measureRows()
    })
    ro.observe(taRef.value)
  }
})
onBeforeUnmount(() => {
  ro?.disconnect()
})

// 暴露给父组件：textarea 元素 + 光标聚焦
defineExpose({
  getTextarea: () => taRef.value,
  focusEnd: () => {
    const ta = taRef.value
    if (ta) {
      ta.focus()
      ta.setSelectionRange(props.modelValue.length, props.modelValue.length)
    }
  },
})
</script>

<template>
  <div
    class="flex flex-col overflow-hidden border border-(--ui-border)"
    :class="cornerClass"
    :style="props.height ? { height: props.height } : undefined"
  >
    <!-- 工具条：自动换行开关 -->
    <div class="flex flex-none items-center justify-end gap-2 border-b border-(--ui-border) bg-(--ui-bg-elevated) px-3 py-1.5">
      <UButton
        size="xs"
        color="neutral"
        variant="subtle"
        :icon="wrap ? 'i-lucide-wrap-text' : 'i-lucide-arrow-right-left'"
        :label="wrapLabel"
        @click="toggleWrap"
      />
    </div>

    <!-- 编辑器主体：左行号 + 右 textarea（固定高度时占满剩余空间） -->
    <div class="flex min-h-0 flex-1">
      <!-- 行号栏：外层裁剪（高度与 textarea 同步），内层用 translateY 跟随滚动 -->
      <div ref="gutterOuter" class="flex-none overflow-hidden border-r border-(--ui-border) bg-(--ui-bg-elevated) select-none">
        <div ref="gutterInner" class="pb-3 pt-3 text-right" :style="{ willChange: 'transform' }">
          <div
            v-for="(cell, i) in gutterCells"
            :key="i"
            class="px-3 font-mono text-xs text-(--ui-text-muted)"
            :style="{ height: LINE_H + 'px', lineHeight: LINE_H + 'px' }"
          >{{ cell.n === null ? '' : cell.n }}</div>
        </div>
      </div>
      <!-- 行号栏跟随滚动时的同步参照：textarea 的滚动发生在各自容器内，行号栏需与 body 等高的滚动视口对齐 -->
      <div class="flex-1 min-w-0">
        <textarea
          ref="taRef"
          :value="body"
          :placeholder="placeholder"
          :rows="rows || 18"
          class="w-full bg-(--ui-bg) font-mono text-sm text-(--ui-text) p-3 outline-none focus:outline-none"
          :class="[props.height ? 'h-full resize-none' : 'resize-y', wrap ? 'whitespace-pre-wrap break-words' : 'whitespace-pre']"
          :wrap="wrap ? 'soft' : 'off'"
          :style="{ lineHeight: LINE_H + 'px', overflowY: 'auto', overflowX: wrap ? 'hidden' : 'auto', tabSize: 4 }"
          spellcheck="false"
          @input="body = ($event.target as HTMLTextAreaElement).value; emit('input', $event)"
          @keydown="emit('keydown', $event)"
          @keyup="emit('keyup', $event)"
          @mouseup="emit('mouseup', $event)"
          @blur="emit('blur', $event)"
          @scroll="syncScroll"
        />
      </div>
    </div>

    <!-- 隐藏镜像：用于测量每个逻辑行在自动换行下的可视行数（不参与交互与布局） -->
    <div
      ref="mirRef"
      aria-hidden="true"
      class="pointer-events-none invisible fixed left-0 top-0 font-mono text-sm whitespace-pre-wrap break-words"
      :style="{ lineHeight: LINE_H + 'px', tabSize: 4 }"
    >
      <div v-for="(line, i) in logicalLines" :key="i" :ref="(el: any) => { if (el) (mirLines as any)[i] = el }">{{ line }}</div>
    </div>
  </div>
</template>