<script setup lang="ts">
import type { Completion, CompletionContext, CompletionSource } from '@codemirror/autocomplete'
import { autocompletion, closeBrackets, closeBracketsKeymap, completionKeymap } from '@codemirror/autocomplete'
import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands'
import { sass } from '@codemirror/lang-sass'
import { HighlightStyle, indentOnInput, indentUnit, syntaxHighlighting } from '@codemirror/language'
import { Compartment, EditorState } from '@codemirror/state'
import { drawSelection, dropCursor, EditorView, highlightActiveLine, highlightActiveLineGutter, keymap, lineNumbers, placeholder, rectangularSelection } from '@codemirror/view'
import { tags } from '@lezer/highlight'

// 样式编辑器语法高亮：VSCode 配色（Light+ / Dark+）。
// 颜色通过 CSS 变量注入（--tok-editor-*，定义于 main.css，随亮/暗主题切换）。
const editorHighlight = HighlightStyle.define([
  { tag: tags.comment, color: 'var(--tok-editor-comment)', fontStyle: 'italic' },
  { tag: tags.keyword, color: 'var(--tok-editor-keyword)' },
  { tag: tags.atom, color: 'var(--tok-editor-keyword)' },
  { tag: tags.string, color: 'var(--tok-editor-string)' },
  { tag: tags.number, color: 'var(--tok-editor-number)' },
  { tag: tags.variableName, color: 'var(--tok-editor-variable)' },
  { tag: tags.propertyName, color: 'var(--tok-editor-property)' },
  { tag: tags.definition(tags.variableName), color: 'var(--tok-editor-variable)' },
  { tag: tags.function(tags.variableName), color: 'var(--tok-editor-function)' },
  { tag: tags.function(tags.propertyName), color: 'var(--tok-editor-function)' },
  { tag: tags.typeName, color: 'var(--tok-editor-type)' },
  { tag: tags.className, color: 'var(--tok-editor-class)' },
  { tag: tags.tagName, color: 'var(--tok-editor-tag)' },
  { tag: tags.attributeName, color: 'var(--tok-editor-attribute)' },
  { tag: tags.controlKeyword, color: 'var(--tok-editor-control)' },
])

// 样式编辑器基础主题：统一字体栈 + 随应用主题（亮/暗）联动的光标、选区、行号配色。
// 颜色引用 CSS 变量（--ui-* / --tok-*），切换主题时无需重建编辑器。
const editorTheme = EditorView.theme({
  '&': { height: '100%', backgroundColor: 'var(--ui-bg)' },
  '.cm-scroller': { fontFamily: "Consolas, 'Courier New', monospace", fontSize: '14px', lineHeight: '22px' },
  '.cm-content': { fontFamily: "Consolas, 'Courier New', monospace", fontSize: '14px', lineHeight: '22px', color: 'var(--ui-text)', caretColor: 'var(--ui-text)' },
  '.cm-cursor, .cm-dropCursor': { borderLeftColor: 'var(--ui-text)' },
  '.cm-gutters': { backgroundColor: 'var(--ui-bg-elevated)', color: 'var(--ui-text-muted)', borderRight: '1px solid var(--ui-border)' },
  '.cm-lineNumbers .cm-gutterElement': { color: 'var(--ui-text-muted)' },
  '.cm-activeLineGutter': { backgroundColor: 'transparent', color: 'var(--ui-text)' },
  '.cm-activeLine': { backgroundColor: 'color-mix(in srgb, var(--ui-primary) 7%, transparent)' },
  '.cm-selectionBackground': { backgroundColor: 'color-mix(in srgb, var(--ui-primary) 22%, transparent)' },
  '&.cm-focused .cm-selectionBackground': { backgroundColor: 'color-mix(in srgb, var(--ui-primary) 22%, transparent)' },
  // —— 补全下拉框（autocompletion tooltip）美化 ——
  '& .cm-tooltip-autocomplete': {
    border: '1px solid var(--ui-border)',
    borderRadius: '8px',
    backgroundColor: 'var(--ui-bg-elevated)',
    boxShadow: '0 8px 24px -6px color-mix(in srgb, black 35%, transparent), 0 2px 8px -2px color-mix(in srgb, black 20%, transparent)',
    overflow: 'hidden',
  },
  '& .cm-tooltip-autocomplete > ul': { fontFamily: "Consolas, 'Courier New', monospace", fontSize: '13px', padding: '4px' },
  '& .cm-tooltip-autocomplete .cm-completionListItem': {
    padding: '3px 8px',
    borderRadius: '5px',
    lineHeight: '20px',
    color: 'var(--ui-text)',
  },
  '& .cm-tooltip-autocomplete .cm-completionIcon': { fontSize: '12px', paddingRight: '4px', color: 'var(--ui-text-muted)' },
  '& .cm-tooltip-autocomplete .cm-completionDetail': { color: 'var(--ui-text-muted)', fontStyle: 'italic' },
  '& .cm-tooltip-autocomplete .cm-completionMatchedText': {
    fontWeight: '700',
    textDecoration: 'none',
    color: 'var(--ui-primary)',
  },
  '& .cm-tooltip-autocomplete [aria-selected]': {
    backgroundColor: 'color-mix(in srgb, var(--ui-primary) 14%, transparent)',
    color: 'var(--ui-text)',
  },
})
// 带行号的 Markdown 编辑器（行号栏 + 自动换行开关 + 原生 textarea）。
// 支持「内容编辑器 / 样式编辑器」双模式：内容为 Markdown 正文，样式为页面 SCSS 样式表。
// 通过 defineExpose 暴露 textarea / 当前模式，供外部插入、光标操作与工具条联动。
const { t } = useI18n()
const props = defineProps<{
  modelValue: string
  styleValue?: string
  placeholder?: string
  stylePlaceholder?: string
  rows?: number
  /** 编辑器输入区固定显示高度（如 '480px'），缺省时用 rows 计算高度 */
  height?: string
  /** 外框圆角：all=四角 / bottom=仅底部 / bottom-left=仅左下 / bottom-right=仅右下 */
  corner?: 'all' | 'bottom' | 'bottom-left' | 'bottom-right'
  /** 编辑模式：content=内容编辑器 / style=样式编辑器（缺省为 content） */
  mode?: 'content' | 'style'
}>()
const emit = defineEmits<{
  'update:modelValue': [value: string]
  'update:styleValue': [value: string]
  'update:mode': [value: 'content' | 'style']
  input: [e: Event]
  keydown: [e: KeyboardEvent]
  keyup: [e: Event]
  mouseup: [e: Event]
  blur: [e: FocusEvent]
}>()

// 内部模式（父组件未受控时兜底）；受控时以 props.mode 为准
const internalMode = ref<'content' | 'style'>('content')
const mode = computed<'content' | 'style'>(() => props.mode ?? internalMode.value)

// 当前模式对应的可见文本
const visible = computed(() => (mode.value === 'style' ? (props.styleValue ?? '') : props.modelValue))

const toggleMode = () => {
  const next = mode.value === 'style' ? 'content' : 'style'
  internalMode.value = next
  emit('update:mode', next)
  // 切换后同步滚动位置，避免错位
  requestAnimationFrame(syncScroll)
}

const wrap = ref(true)
const toggleWrap = () => {
  wrap.value = !wrap.value
  // 切换后同步滚动位置，避免错位
  requestAnimationFrame(syncScroll)
}

const wrapLabel = computed(() => (wrap.value ? t('editor.wrap') : t('editor.nowrap')))

// ============ 样式编辑器：CodeMirror（SCSS 语法高亮 / 补全 / Tab 缩进） ============
const cmEl = ref<HTMLDivElement>()
let cmView: EditorView | null = null
// 自动换行开关使用 Compartment 动态启停（样式编辑器同样支持换行切换）
const lineWrapComp = new Compartment()

const CSS_PROPS = [
  'color', 'background', 'background-color', 'background-image', 'border', 'border-color',
  'border-radius', 'border-style', 'border-width', 'margin', 'padding', 'font-size',
  'font-weight', 'font-family', 'font-style', 'line-height', 'text-align', 'text-decoration',
  'text-transform', 'letter-spacing', 'white-space', 'word-break', 'display', 'position',
  'top', 'right', 'bottom', 'left', 'width', 'height', 'min-width', 'min-height', 'max-width',
  'max-height', 'overflow', 'cursor', 'opacity', 'filter', 'box-shadow', 'text-shadow',
  'transition', 'transform', 'flex', 'flex-direction', 'flex-wrap', 'gap', 'grid',
  'align-items', 'justify-content', 'list-style', 'z-index', 'float', 'vertical-align', 'content',
]
const SASS_KEYWORDS = [
  '@import', '@use', '@mixin', '@include', '@if', '@else', '@each', '@for', '@while',
  '@function', '@return', '@media', '@supports', '@extend', '@content', '@debug', '@warn', '!important',
]

// 收集文档里已定义的顶层变量 $name，作为 [$name] 的补全候选
const scssCompletions: CompletionSource = (context) => {
  const doc = context.state.doc.toString()
  const vars: string[] = []
  const re = /(?:^|\n)\s*\$([\w-]+)\s*:/g
  let m: RegExpExecArray | null
  while ((m = re.exec(doc))) vars.push(m[1]!)
  const word = context.matchBefore(/[$@A-Za-z][$\w-]*$/)
  if (!word) return null
  if (word.from === word.to && !context.explicit) return null
  const opts: Completion[] = []
  if (word.text.startsWith('$')) {
    for (const v of vars) opts.push({ label: `$${v}`, type: 'variable', detail: 'variable' })
  } else if (word.text.startsWith('@')) {
    for (const k of SASS_KEYWORDS) opts.push({ label: k, type: 'keyword' })
  } else {
    for (const p of CSS_PROPS) opts.push({ label: p, type: 'property', apply: `${p}: ` })
    for (const v of vars) opts.push({ label: `$${v}`, type: 'variable', detail: 'variable' })
  }
  return { from: word.from, options: opts }
}

const mountCM = () => {
  if (!cmEl.value || cmView) return
  const state = EditorState.create({
    doc: props.styleValue ?? '',
    extensions: [
      lineNumbers(),
      highlightActiveLineGutter(),
      highlightActiveLine(),
      drawSelection(),
      dropCursor(),
      rectangularSelection(),
      history(),
      indentOnInput(),
      indentUnit.of('  '),
      keymap.of([...closeBracketsKeymap, ...completionKeymap, indentWithTab, ...defaultKeymap, ...historyKeymap]),
      closeBrackets(),
      autocompletion({ override: [scssCompletions] }),
      editorTheme,
      syntaxHighlighting(editorHighlight),
      sass(),
      lineWrapComp.of(wrap.value ? EditorView.lineWrapping : []),
      placeholder(props.stylePlaceholder ?? ''),
      EditorView.updateListener.of((u) => {
        if (u.docChanged) emit('update:styleValue', u.state.doc.toString())
      }),
    ],
  })
  cmView = new EditorView({ state, parent: cmEl.value })
}

const destroyCM = () => {
  cmView?.destroy()
  cmView = null
}

const syncCM = () => {
  if (mode.value === 'style') mountCM()
  else destroyCM()
}
watch(mode, () => nextTick(syncCM))

// 样式模式下切换换行：重配置 CodeMirror 的 lineWrapping 扩展（保留撤销历史与光标）
watch(wrap, () => {
  if (mode.value === 'style' && cmView) {
    cmView.dispatch({ effects: lineWrapComp.reconfigure(wrap.value ? EditorView.lineWrapping : []) })
  }
})

// 占位文本按模式区分（内容 / 样式）
const inputPlaceholder = computed(() =>
  mode.value === 'style' ? (props.stylePlaceholder ?? '') : (props.placeholder ?? '')
)

const onInput = (e: Event) => {
  const v = (e.target as HTMLTextAreaElement).value
  if (mode.value === 'style') emit('update:styleValue', v)
  else emit('update:modelValue', v)
  emit('input', e)
}

const gutterOuter = ref<HTMLDivElement>()
const gutterInner = ref<HTMLDivElement>()
const taRef = ref<HTMLTextAreaElement>()
const mirLines = ref<HTMLDivElement[]>([])
const mirRef = ref<HTMLDivElement>()

// 每个逻辑行（按 \n 切分）的文本
const logicalLines = computed(() => visible.value.split('\n'))

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

watch(visible, scheduleMeasure)
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
  nextTick(syncCM)
})
onBeforeUnmount(() => {
  ro?.disconnect()
  destroyCM()
})

// 暴露给父组件：textarea 元素 + 光标聚焦 + 当前模式
defineExpose({
  getTextarea: () => taRef.value,
  getMode: () => mode.value,
  focusEnd: () => {
    const ta = taRef.value
    if (ta) {
      ta.focus()
      ta.setSelectionRange(visible.value.length, visible.value.length)
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
    <!-- 工具条：左=切换编辑器（内容/样式），右=自动换行开关 -->
    <div class="flex flex-none items-center justify-between gap-2 border-b border-(--ui-border) bg-(--ui-bg-elevated) px-3 py-1.5">
      <UTooltip :text="t('editor.switchEditor')">
        <UButton
          size="xs"
          color="neutral"
          variant="subtle"
          :icon="mode === 'style' ? 'i-lucide-file-text' : 'i-lucide-palette'"
          :label="mode === 'style' ? t('editor.contentMode') : t('editor.styleMode')"
          @click="toggleMode"
        />
      </UTooltip>
      <UButton
        size="xs"
        color="neutral"
        variant="subtle"
        :icon="wrap ? 'i-lucide-wrap-text' : 'i-lucide-arrow-right-left'"
        :label="wrapLabel"
        @click="toggleWrap"
      />
    </div>

    <!-- 内容模式：左行号 + 右 textarea；样式模式：CodeMirror（SCSS 高亮/补全/Tab 缩进） -->
    <div class="flex min-h-0 flex-1">
      <!-- ===== 内容编辑器 ===== -->
      <template v-if="mode === 'content'">
        <!-- 行号栏：外层裁剪（高度与 textarea 同步），内层用 translateY 跟随滚动 -->
        <div ref="gutterOuter" class="flex-none overflow-hidden border-r border-(--ui-border) bg-(--ui-bg-elevated) select-none">
          <div ref="gutterInner" class="pb-3 pt-3 text-right" :style="{ willChange: 'transform' }">
            <div
              v-for="(cell, i) in gutterCells"
              :key="i"
              class="px-3 font-[Consolas,'Courier_New',monospace] text-xs text-(--ui-text-muted)"
              :style="{ height: LINE_H + 'px', lineHeight: LINE_H + 'px' }"
            >{{ cell.n === null ? '' : cell.n }}</div>
          </div>
        </div>
        <!-- 行号栏跟随滚动时的同步参照：textarea 的滚动发生在各自容器内，行号栏需与 body 等高的滚动视口对齐 -->
        <div class="flex-1 min-w-0">
          <textarea
            ref="taRef"
            :value="visible"
            :placeholder="inputPlaceholder"
            :rows="rows || 18"
            class="w-full bg-(--ui-bg) font-[Consolas,'Courier_New',monospace] text-sm text-(--ui-text) p-3 outline-none focus:outline-none"
            :class="[props.height ? 'h-full resize-none' : 'resize-y', wrap ? 'whitespace-pre-wrap break-words' : 'whitespace-pre']"
            :wrap="wrap ? 'soft' : 'off'"
            :style="{ lineHeight: LINE_H + 'px', overflowY: 'auto', overflowX: wrap ? 'hidden' : 'auto', tabSize: 4 }"
            spellcheck="false"
            @input="onInput"
            @keydown="emit('keydown', $event)"
            @keyup="emit('keyup', $event)"
            @mouseup="emit('mouseup', $event)"
            @blur="emit('blur', $event)"
            @scroll="syncScroll"
          />
        </div>
      </template>
      <!-- ===== 样式编辑器（CodeMirror） ===== -->
      <div v-else ref="cmEl" class="h-full w-full min-w-0 overflow-hidden [&_.cm-editor]:h-full"></div>
    </div>

    <!-- 隐藏镜像：用于测量每个逻辑行在自动换行下的可视行数（不参与交互与布局） -->
    <div
      v-if="mode === 'content'"
      ref="mirRef"
      aria-hidden="true"
      class="pointer-events-none invisible fixed left-0 top-0 font-[Consolas,'Courier_New',monospace] text-sm whitespace-pre-wrap break-words"
      :style="{ lineHeight: LINE_H + 'px', tabSize: 4 }"
    >
      <div v-for="(line, i) in logicalLines" :key="i" :ref="(el: any) => { if (el) (mirLines as any)[i] = el }">{{ line }}</div>
    </div>
  </div>
</template>