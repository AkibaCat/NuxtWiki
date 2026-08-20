/**
 * NuxtWiki · Markdown 渲染器
 * 支持 GitHub 风格 Markdown 常用语法 + Wiki 扩展（内部链接 / 附件 / 目录），
 * 输出安全 HTML（所有文本均转义）。
 *
 * 支持的语法：
 *   标题      # H1  ## H2  ### H3 ... ###### H6
 *   加粗      **text**
 *   斜体      *text*
 *   下划线    __text__   （Wiki 扩展）
 *   删除线    ~~text~~
 *   行内代码  `code`
 *   代码块    ``` 或 ~~~ 围栏（可带语言名）   或 行首 4 空格缩进
 *   引用      > text
 *   无序列表  - item / * item / + item
 *   有序列表  1. item
 *   表格      | a | b | + 对齐分隔行 |:---|:---:|---:|
 *   分隔线    --- / *** / ___
 *   内部链接  [[Page]] 或 [[文本|Page]]
 *   外部链接  [文本](url)
 *   自定义颜色 [文本]{颜色*}   颜色*支持 6 位十六进制（3c9c5c）、RGB（60:156:92）、%类名（.class）、$Sass变量；$TC 为内置主题色
 *   容器       ::: 类型 [文本]  以独立成行的 ":::" 结束；类型 info/tip/warning/danger/details
 *   图片附件  ![说明|文件名](文件名.jpg)   或  {{说明|文件名}} / {{文件名}}
 *   HTML     白名单标签（div/section/details/table/span/mark/kbd/sup/sub/a/img/iframe 等），
 *            内部可继续嵌套 Markdown；危险标签与事件属性自动过滤
 *   目录      (toc) 或 [TOC] 标记 + 自动生成
 */

export interface TocItem {
  id: string
  level: number
  text: string
}

export interface RenderResult {
  html: string
  toc: TocItem[]
}

export interface RenderOptions {
  tag: string
  apiBase?: string
}

const IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp']

const esc = (s: string) =>
  s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;')

const slugify = (s: string, used: Map<string, number>) => {
  let base = s.trim().toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-+|-+$/g, '')
  if (!base) base = 'sec'
  const n = used.get(base) || 0
  used.set(base, n + 1)
  return n === 0 ? base : `${base}-${n}`
}

/** 判断字符串是否为可安全打开的 URL */
const isSafeUrl = (u: string): boolean => {
  const t = u.trim()
  if (/^(https?:|mailto:|ftp:|#)/i.test(t)) return true
  if (t.startsWith('//')) return true
  if (t.startsWith('/')) return true
  if (t.startsWith('./') || t.startsWith('../')) return true
  return false
}

// ==================== HTML 嵌套支持（白名单） ====================

/** 行内文本标签（内部嵌套行内 Markdown） */
const HTML_INLINE = new Set(['a', 'span', 'mark', 'kbd', 'sub', 'sup', 'ins', 'u', 's', 'em', 'strong', 'b', 'i', 'small', 'q', 'samp', 'var', 'time', 'code', 'del', 'abbr'])
/** void 元素（无内容、不配对） */
const HTML_VOID = new Set(['br', 'wbr', 'hr'])
/** 媒体/嵌入标签（自闭合或内部仅透传安全 HTML） */
const HTML_MEDIA = new Set(['img', 'iframe', 'video', 'audio', 'source', 'track'])
/** 块级容器标签（内部嵌套块级 Markdown） */
const HTML_BLOCK = new Set([
  'div', 'section', 'article', 'aside', 'header', 'footer', 'main', 'nav',
  'details', 'summary', 'figure', 'figcaption', 'blockquote', 'center',
  'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption',
  'ul', 'ol', 'li', 'dl', 'dt', 'dd',
  'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'pre'
])
/** 块级标签中，内部按行内解析（不嵌套块级） */
const HTML_INNER_INLINE = new Set(['td', 'th', 'caption', 'summary', 'dt', 'dd', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'])
/** 块级标签中，内部原样转义（代码展示） */
const HTML_RAW_INNER = new Set(['pre'])
/** 全部白名单标签 */
const HTML_ALLOWED_TAGS = new Set([...HTML_INLINE, ...HTML_VOID, ...HTML_MEDIA, ...HTML_BLOCK])

/** 通用属性白名单（其余标签专有属性见 TAG_ATTRS，data-* 额外放行） */
const COMMON_ATTRS = new Set(['class', 'id', 'style', 'title', 'lang', 'dir', 'role', 'hidden', 'align'])
const TAG_ATTRS: Record<string, string[]> = {
  a: ['href', 'target', 'rel', 'download', 'name'],
  img: ['src', 'srcset', 'sizes', 'loading', 'alt', 'width', 'height'],
  iframe: ['src', 'width', 'height', 'frameborder', 'allowfullscreen', 'allow', 'loading', 'sandbox', 'scrolling', 'title'],
  video: ['src', 'poster', 'controls', 'autoplay', 'loop', 'muted', 'preload', 'playsinline', 'width', 'height'],
  audio: ['src', 'controls', 'autoplay', 'loop', 'muted', 'preload'],
  source: ['src', 'srcset', 'type', 'media', 'sizes'],
  track: ['src', 'kind', 'srclang', 'label', 'default'],
  details: ['open'],
  time: ['datetime'],
  q: ['cite'],
  blockquote: ['cite'],
  ol: ['start', 'type', 'reversed'],
  li: ['value'],
  table: ['border', 'cellpadding', 'cellspacing', 'width'],
  td: ['colspan', 'rowspan', 'width', 'height', 'valign'],
  th: ['colspan', 'rowspan', 'scope', 'width', 'height', 'valign'],
  col: ['span', 'width'],
  colgroup: ['span'],
}

/** 解析一段标签字符串 "<tag attrs>" → 标签名 / 属性原始串 / 是否自闭合 */
const parseTag = (s: string): { tag: string; attrs: string; selfClosing: boolean } | null => {
  const m = s.match(/^<([a-zA-Z][\w-]*)\b([^<>]*)>/)
  if (!m) return null
  let attrs = (m[2] ?? '').trim()
  let selfClosing = false
  if (attrs.endsWith('/')) {
    selfClosing = true
    attrs = attrs.slice(0, -1).trim()
  }
  return { tag: (m[1] ?? '').toLowerCase(), attrs, selfClosing }
}

/** 解析属性串 → [名, 值][] */
const parseAttrs = (raw: string): [string, string][] => {
  const out: [string, string][] = []
  const re = /([a-zA-Z_:][\w:.-]*)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/g
  let m: RegExpExecArray | null
  while ((m = re.exec(raw))) out.push([m[1] ?? '', m[2] ?? m[3] ?? m[4] ?? ''])
  return out
}

/** 生成安全的属性串（过滤事件属性、危险协议、非白名单属性） */
const safeAttrs = (tag: string, raw: string): string => {
  const allowed = new Set(COMMON_ATTRS)
  for (const a of TAG_ATTRS[tag] || []) allowed.add(a)
  const out: string[] = []
  for (const [name, val] of parseAttrs(raw)) {
    const ln = name.toLowerCase()
    if (ln.startsWith('on')) continue
    if (!allowed.has(ln) && !ln.startsWith('data-')) continue
    let v = val
    if (ln === 'href' || ln === 'src' || ln === 'poster' || ln === 'cite') {
      if (!isSafeUrl(v)) continue
      if (ln === 'src' && tag === 'iframe' && !/^(https?:)?\/\//i.test(v.trim())) continue
    }
    if (ln === 'srcset') {
      const items = v.split(',').map((x) => x.trim()).filter(Boolean)
      if (!items.every((it) => isSafeUrl(it.split(/\s+/)[0] || ''))) continue
    }
    if (ln === 'style' && /expression\s*\(|javascript:|@import|-moz-binding|behavior\s*:/i.test(v)) continue
    out.push(v === '' ? name : `${name}="${esc(v)}"`)
  }
  return out.length ? ' ' + out.join(' ') : ''
}

/** 从文本位置 startIdx 起，定位 <tag> 配对，返回内部内容与结束位置（支持跨行与嵌套） */
const findHtmlPair = (text: string, startIdx: number, tag: string): { inner: string; end: number } | null => {
  const open = text.slice(startIdx).match(/^<([a-zA-Z][\w-]*)\b[^<>]*>/)
  if (!open) return null
  let pos = startIdx + open[0].length
  let depth = 1
  const re = new RegExp(`<${tag}\\b(?![^>]*?/\\s*>)|</${tag}\\s*>`, 'gi')
  re.lastIndex = pos
  while (true) {
    const m = re.exec(text)
    if (!m) return null
    if (m[0][1] === '/') {
      depth--
      if (depth === 0) return { inner: text.slice(pos, m.index), end: m.index + m[0].length }
    } else {
      depth++
    }
  }
}

/** 把一段原始 HTML 安全化：白名单标签保留（属性过滤），其余转义 */
const sanitizeHtml = (raw: string): string => {
  const placeholders: string[] = []
  const masked = raw.replace(/<\/?([a-zA-Z][\w-]*)\b[^<>]*\/?>/g, (m) => {
    placeholders.push(m)
    return `\u0000${placeholders.length - 1}\u0000`
  })
  const text = esc(masked)
  return text.replace(/\u0000(\d+)\u0000/g, (_g, n: string) => {
    const m = placeholders[Number(n)]
    if (m === undefined) return ''
    const pt = parseTag(m)
    if (!pt || !HTML_ALLOWED_TAGS.has(pt.tag)) return esc(m)
    const self = pt.selfClosing ? ' /' : ''
    return `<${pt.tag}${safeAttrs(pt.tag, pt.attrs)}${self}>`
  })
}

/** 剥离 HTML 标签（取纯文本） */
const stripHtmlTags = (s: string): string => s.replace(/<[^>]*>/g, '')

/** 匹配行内 HTML（白名单）：结束标签 / 开始标签（自闭合 / 配对嵌套） */
const matchHtmlInline = (rest: string, opts: RenderOptions): { html: string; len: number } | null => {
  const closeM = rest.match(/^<\/\s*([a-zA-Z][\w-]*)\s*>/)
  if (closeM) {
    const tag = (closeM[1] ?? '').toLowerCase()
    if (!HTML_INLINE.has(tag)) return null
    return { html: `</${tag}>`, len: closeM[0].length }
  }
  const openM = rest.match(/^<([a-zA-Z][\w-]*)\b[^<>]*>/)
  if (!openM) return null
  const pt = parseTag(openM[0])
  if (!pt) return null
  const { tag } = pt
  if (!HTML_ALLOWED_TAGS.has(tag)) return { html: esc(openM[0]), len: openM[0].length }
  const attrsHtml = safeAttrs(tag, pt.attrs)
  // 媒体/嵌入标签
  if (HTML_MEDIA.has(tag)) {
    if (pt.selfClosing) return { html: `<${tag}${attrsHtml} />`, len: openM[0].length }
    if (tag === 'img' || tag === 'source' || tag === 'track') return { html: `<${tag}${attrsHtml}>`, len: openM[0].length }
    const pair = findHtmlPair(rest, 0, tag)
    if (pair) return { html: `<${tag}${attrsHtml}>${sanitizeHtml(pair.inner)}</${tag}>`, len: pair.end }
    return { html: `<${tag}${attrsHtml}>`, len: openM[0].length }
  }
  // void 元素
  if (HTML_VOID.has(tag)) return { html: `<${tag}${attrsHtml}>`, len: openM[0].length }
  // 行内代码 <code>：内部原样转义（代码展示），不做任何 Markdown 样式解析
  if (tag === 'code') {
    const pair = findHtmlPair(rest, 0, 'code')
    if (pair) return { html: `<code${attrsHtml}>${esc(pair.inner)}</code>`, len: pair.end }
    return { html: `<code${attrsHtml}>`, len: openM[0].length }
  }
  // 行内文本标签（含出现在段内/行内的块级容器标签）：配对 + 内部嵌套 Markdown
  // 注意：块级标签在行内出现时也须走此路径，否则原始未过滤属性会被原样输出（XSS 风险）
  const pair = findHtmlPair(rest, 0, tag)
  if (pair) return { html: `<${tag}${attrsHtml}>${parseInline(pair.inner, opts)}</${tag}>`, len: pair.end }
  return { html: `<${tag}${attrsHtml}>`, len: openM[0].length }
}

/** 转义，但保留白名单 HTML 标签（便于后续行内/块级解析） */
const escHtml = (s: string): string => {
  const placeholders: string[] = []
  const masked = s.replace(/<\/?([a-zA-Z][\w-]*)\b[^<>]*\/?>/g, (m, name) => {
    if (HTML_ALLOWED_TAGS.has(name.toLowerCase())) {
      placeholders.push(m)
      return `\u0000${placeholders.length - 1}\u0000`
    }
    return m
  })
  const escaped = masked
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;')
  return escaped.replace(/\u0000(\d+)\u0000/g, (_g, n: string) => placeholders[Number(n)] ?? '')
}

// ==================== 行内解析 ====================

interface InlineToken {
  re: RegExp
  render: (m: RegExpExecArray, opts: RenderOptions) => string
}

const makeTokens = (opts: RenderOptions): InlineToken[] => {
  const att = (filename: string) => {
    const base = (opts.apiBase || '/api/index.php').replace(/[?#].*$/, '')
    return `${base}?r=attachments.get&tag=${encodeURIComponent(opts.tag)}&name=${encodeURIComponent(filename)}`
  }
  return [
    // 行内代码 `code`（支持多反引号定界符，如 ``code`code``，内容不解析其他标记）
    // 内容内若保留有白名单 HTML 标签，需转义 < > 避免作为标签渲染
    { re: /(`+)([^\n]*?)\1/, render: (m) => `<code>${(m[2] ?? '').replace(/</g, '&lt;').replace(/>/g, '&gt;')}</code>` },
    // 图片 ![说明](文件名.jpg) / ![alt](url)
    {
      re: /!\[([^\]]*)\]\(([^()\n]+)\)/,
      render: (m, o) => {
        const url = (m[2] ?? '').trim()
        const alt = (m[1] ?? '').trim() || url
        const ext = url.split('.').pop()?.toLowerCase() || ''
        if (isSafeUrl(url)) {
          return `<img src="${esc(url)}" alt="${esc(alt)}" loading="lazy" />`
        }
        if (IMAGE_EXTS.includes(ext)) {
          return `<img src="${esc(att(url))}" alt="${esc(alt)}" loading="lazy" />`
        }
        return `<a href="${esc(att(url))}" class="attachment" download>${parseInline(url, o)}</a>`
      }
    },
    // 自定义颜色/样式 [文本]{颜色*} —— 颜色* 可为：十六进制（3c9c5c / #3c9c5c）、RGB（60:156:92）、
    //   类引用（%text → class="text"，由页面样式表定义）、Sass 变量引用（$var → var(--var)）。
    //   $TC 为内置特殊变量（站点主题色），不可在样式编辑器中定义。
    {
      re: /\[([^\]\n]+)\]\{([^}\n]+)\}/,
      render: (m, o) => {
        const text = (m[1] ?? '').trim()
        const arg = (m[2] ?? '').trim()
        const inner = () => parseInline(text, o)
        // Sass 变量引用 $name（$TC 内置主题色）
        const vm = arg.match(/^\$([\w-]+)$/)
        if (vm) {
          if ((vm[1] ?? '').toLowerCase() === 'tc') return `<span style="color:var(--ui-primary);">${inner()}</span>`
          return `<span style="color:var(--${vm[1]});">${inner()}</span>`
        }
        // 类引用 %text → class="text"（页面样式表可定义 .text 规则）
        const cm = arg.match(/^%([A-Za-z_][\w-]*)$/)
        if (cm) return `<span class="${esc(cm[1]!)}">${inner()}</span>`
        // 十六进制（支持可省略 #）
        if (/^#?[0-9a-fA-F]{3,6}$/.test(arg)) {
          return `<span style="color:${esc(arg[0] === '#' ? arg : '#' + arg)};">${inner()}</span>`
        }
        // RGB r:g:b
        const rm = arg.match(/^(\d{1,3}):(\d{1,3}):(\d{1,3})$/u)
        if (rm) {
          const r = Number(rm[1]), g = Number(rm[2]), b = Number(rm[3])
          if (r <= 255 && g <= 255 && b <= 255) return `<span style="color:rgb(${r},${g},${b});">${inner()}</span>`
        }
        return m[0]
      }
    },
    // 内部链接 [[Page]] / [[文本|Page]]
    {
      re: /\[\[([^\]\n]+)\]\]/,
      render: (m, o) => {
        const parts = (m[1] ?? '').split('|')
        const page = (parts[parts.length - 1] ?? '').trim()
        const label = parts.length > 1 ? (parts[0] ?? '') : page
        if (isSafeUrl(page)) {
          return `<a href="${esc(page)}" target="_blank" rel="noopener noreferrer">${parseInline(label, o)}</a>`
        }
        return `<a href="/${encodeURIComponent(page)}">${parseInline(label, o)}</a>`
      }
    },
    // 图片链接 [![alt](图片)](目标URL)：点击图片跳转链接（需在外部链接之前，避免 ] 被截断）
    {
      re: /\[(!\[[^\]]*\]\([^()\n]+\))\]\(([^()\n]+)\)/,
      render: (m, o) => {
        const url = (m[2] ?? '').trim()
        if (!isSafeUrl(url)) return m[0]
        return `<a href="${esc(url)}" target="_blank" rel="noopener noreferrer">${parseInline(m[1] ?? '', o)}</a>`
      }
    },
    // 外部链接 [文本](url)
    {
      re: /\[([^\]\n]+)\]\(([^()\n]+)\)/,
      render: (m, o) => {
        const url = (m[2] ?? '').trim()
        if (!isSafeUrl(url)) return m[0]
        return `<a href="${esc(url)}" target="_blank" rel="noopener noreferrer">${parseInline(m[1] ?? '', o)}</a>`
      }
    },
    // 附件 {{说明|文件名}} / {{文件名}}
    {
      re: /\{\{([^{}\n]+)\}\}/,
      render: (m, o) => {
        const parts = (m[1] ?? '').split('|')
        const filename = (parts[parts.length - 1] ?? '').trim()
        const alt = parts.length > 1 ? (parts[0] ?? '').trim() : filename
        const ext = filename.split('.').pop()?.toLowerCase() || ''
        if (IMAGE_EXTS.includes(ext)) {
          return `<img src="${esc(att(filename))}" alt="${esc(alt)}" loading="lazy" />`
        }
        return `<a href="${esc(att(filename))}" class="attachment" download>${parseInline(filename, o)}</a>`
      }
    },
    // 加粗 **text**
    { re: /\*\*([^*\n]+)\*\*/, render: (m, o) => `<strong>${parseInline(m[1] ?? '', o)}</strong>` },
    // 删除线 ~~text~~
    { re: /~~([^~\n]+)~~/, render: (m, o) => `<del>${parseInline(m[1] ?? '', o)}</del>` },
    // 下划线 __text__（Wiki 扩展）
    { re: /__([^_\n]+)__/, render: (m, o) => `<u>${parseInline(m[1] ?? '', o)}</u>` },
    // 斜体 *text*
    { re: /\*([^*\n]+)\*/, render: (m, o) => `<em>${parseInline(m[1] ?? '', o)}</em>` }
  ]
}

/** 解析已转义文本中的行内标记（不重复转义） */
const parseInline = (escaped: string, opts: RenderOptions): string => {
  const tokens = makeTokens(opts)
  let result = ''
  let rest = escaped

  while (rest.length > 0) {
    // 行内 HTML 标签（白名单，内部支持嵌套 Markdown）优先：
    // 定位最靠前的白名单标签，即便出现在行中/段内也须走安全过滤路径
    let html: { idx: number; html: string; len: number } | null = null
    const lt = rest.indexOf('<')
    if (lt !== -1) {
      const h = matchHtmlInline(rest.slice(lt), opts)
      if (h) html = { idx: lt, html: h.html, len: h.len }
    }
    // 找到最靠前的 token 匹配
    let best: { idx: number; len: number; html: string } | null = null
    for (const t of tokens) {
      const m = t.re.exec(rest)
      if (m && (best === null || m.index < best.idx)) {
        best = { idx: m.index, len: m[0].length, html: t.render(m, opts) }
      }
    }
    // 取两者中更靠前的处理（同位置时优先 HTML，确保属性过滤）
    if (html && (best === null || html.idx <= best.idx)) {
      result += rest.slice(0, html.idx) + html.html
      rest = rest.slice(html.idx + html.len)
    } else if (best) {
      result += rest.slice(0, best.idx) + best.html
      rest = rest.slice(best.idx + best.len)
    } else {
      result += rest
      break
    }
  }
  return result
}

/** 行内解析入口：先整体转义（保留白名单 HTML），再解析标记 */
const inline = (text: string, opts: RenderOptions): string => parseInline(escHtml(text), opts)

// ==================== 代码块：语法高亮（轻量内置，无需第三方依赖） ====================

interface HLToken { re: RegExp; cls: string }
interface HLLang { flags?: string; rules: HLToken[] }

/** 语言别名 → 规范名 */
const langAlias: Record<string, string> = {
  javascript: 'js',
  typescript: 'ts',
  python: 'py',
  sh: 'bash',
  shell: 'bash',
  yml: 'yaml',
  yaml: 'yaml',
  'c++': 'cpp',
  cxx: 'cpp',
  cs: 'csharp',
  'c#': 'csharp',
  md: 'markdown',
  plaintext: 'text',
}

const HL: Record<string, HLLang> = {
  js: { rules: [
    { re: /\/\*[\s\S]*?\*\//, cls: 'tok-cmt' },
    { re: /\/\/[^\n]*/, cls: 'tok-cmt' },
    { re: /`(?:[^`\\]|\\.)*`/, cls: 'tok-str' },
    { re: /"(?:[^"\\\n]|\\.)*"/, cls: 'tok-str' },
    { re: /'(?:[^'\\\n]|\\.)*'/, cls: 'tok-str' },
    { re: /\b(?:const|let|var|function|return|if|else|for|while|do|switch|case|break|continue|new|class|extends|import|export|from|async|await|try|catch|finally|throw|typeof|instanceof|in|of|this|null|undefined|true|false|void|delete|yield|static|get|set|default|super)\b/, cls: 'tok-kw' },
    { re: /\b(?:Number|String|Boolean|Array|Object|Promise|Map|Set|WeakMap|Symbol|Math|JSON|Date|RegExp|Error|console|document|window|globalThis|parseInt|parseFloat|isNaN|setTimeout|setInterval)\b/, cls: 'tok-built' },
    { re: /\b\d+(?:\.\d+)?(?:[eE][+-]?\d+)?\b/, cls: 'tok-num' },
    { re: /\b[A-Za-z_$][\w$]*(?=\s*\()/, cls: 'tok-fn' },
  ] },
  ts: { rules: [
    { re: /\/\*[\s\S]*?\*\//, cls: 'tok-cmt' },
    { re: /\/\/[^\n]*/, cls: 'tok-cmt' },
    { re: /`(?:[^`\\]|\\.)*`/, cls: 'tok-str' },
    { re: /"(?:[^"\\\n]|\\.)*"/, cls: 'tok-str' },
    { re: /'(?:[^'\\\n]|\\.)*'/, cls: 'tok-str' },
    { re: /\b(?:const|let|var|function|return|if|else|for|while|do|switch|case|break|continue|new|class|extends|implements|interface|type|enum|namespace|declare|abstract|readonly|import|export|from|async|await|try|catch|finally|throw|typeof|instanceof|keyof|in|of|this|null|undefined|true|false|void|delete|yield|static|get|set|default|super|public|private|protected)\b/, cls: 'tok-kw' },
    { re: /\b(?:string|number|boolean|any|unknown|never|void|object|symbol|bigint)\b/, cls: 'tok-built' },
    { re: /\b(?:Number|String|Boolean|Array|Object|Promise|Map|Set|Record|Partial|Pick|Omit|console|document|window|JSON|Math|Date)\b/, cls: 'tok-built' },
    { re: /\b\d+(?:\.\d+)?(?:[eE][+-]?\d+)?\b/, cls: 'tok-num' },
    { re: /\b[A-Za-z_$][\w$]*(?=\s*\()/, cls: 'tok-fn' },
  ] },
  php: { rules: [
    { re: /\/\*[\s\S]*?\*\//, cls: 'tok-cmt' },
    { re: /\/\/[^\n]*/, cls: 'tok-cmt' },
    { re: /#[^\n]*/, cls: 'tok-cmt' },
    { re: /"(?:[^"\\\n]|\\.)*"/, cls: 'tok-str' },
    { re: /'(?:[^'\\\n]|\\.)*'/, cls: 'tok-str' },
    { re: /\$[A-Za-z_]\w*/, cls: 'tok-var' },
    { re: /\b(?:function|class|interface|trait|extends|implements|return|if|else|elseif|for|foreach|while|do|switch|case|break|continue|new|try|catch|finally|throw|public|private|protected|static|final|abstract|const|use|namespace|require|require_once|include|include_once|echo|print|isset|empty|unset|array|list|match|fn|and|or|xor|true|false|null|global|yield|declare|readonly|enum|clone|instanceof|insteadof|goto)\b/, cls: 'tok-kw' },
    { re: /\b(?:self|parent|this)\b/, cls: 'tok-built' },
    { re: /\b\d+(?:\.\d+)?\b/, cls: 'tok-num' },
    { re: /\b[A-Za-z_]\w*(?=\s*\()/, cls: 'tok-fn' },
  ] },
  html: { rules: [
    { re: /<!--[\s\S]*?-->/, cls: 'tok-cmt' },
    { re: /"(?:[^"\\\n]|\\.)*"/, cls: 'tok-str' },
    { re: /'(?:[^'\\\n]|\\.)*'/, cls: 'tok-str' },
    { re: /<\/?[A-Za-z][\w-]*/, cls: 'tok-tag' },
    { re: /\/?>/, cls: 'tok-tag' },
    { re: /\b[\w-]+(?==)/, cls: 'tok-attr' },
    { re: /&[a-zA-Z#0-9]+;/, cls: 'tok-ent' },
    { re: /\b\d+(?:\.\d+)?\b/, cls: 'tok-num' },
  ] },
  css: { rules: [
    { re: /\/\*[\s\S]*?\*\//, cls: 'tok-cmt' },
    { re: /"(?:[^"\\\n]|\\.)*"/, cls: 'tok-str' },
    { re: /'(?:[^'\\\n]|\\.)*'/, cls: 'tok-str' },
    { re: /#[0-9a-fA-F]{3,8}\b/, cls: 'tok-num' },
    { re: /\b\d+(?:\.\d+)?(?:px|em|rem|%|vh|vw|vmin|vmax|s|ms|deg|rad|fr)?\b/, cls: 'tok-num' },
    { re: /@[\w-]+/, cls: 'tok-kw' },
    { re: /[\w-]+(?=\s*:)/, cls: 'tok-prop' },
    { re: /\b(?:important|inherit|initial|unset|auto|none|solid|dashed|dotted|hidden|block|flex|inline|inline-block|grid|absolute|relative|fixed|sticky|center|left|right|top|bottom)\b/, cls: 'tok-built' },
  ] },
  sql: { flags: 'i', rules: [
    { re: /--[^\n]*/, cls: 'tok-cmt' },
    { re: /\/\*[\s\S]*?\*\//, cls: 'tok-cmt' },
    { re: /"(?:[^"\\\n]|\\.)*"/, cls: 'tok-str' },
    { re: /'(?:[^'\\\n]|\\.)*'/, cls: 'tok-str' },
    { re: /`[^`]*`/, cls: 'tok-str' },
    { re: /\b(?:SELECT|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|FROM|WHERE|JOIN|LEFT|RIGHT|INNER|OUTER|FULL|CROSS|ON|AS|AND|OR|NOT|NULL|IN|IS|LIKE|ORDER|BY|GROUP|HAVING|LIMIT|OFFSET|SET|VALUES|INTO|TABLE|INDEX|KEY|PRIMARY|FOREIGN|UNIQUE|CONSTRAINT|DEFAULT|REFERENCES|ASC|DESC|DISTINCT|COUNT|SUM|MAX|MIN|AVG|CASE|WHEN|THEN|ELSE|END|BEGIN|COMMIT|ROLLBACK|TRANSACTION|UNION|ALL|EXISTS|BETWEEN|INT|INTEGER|BIGINT|VARCHAR|CHAR|TEXT|BOOLEAN|DATETIME|TIMESTAMP|DATE|FLOAT|DOUBLE|DECIMAL|BLOB)\b/, cls: 'tok-kw' },
    { re: /\b\d+(?:\.\d+)?\b/, cls: 'tok-num' },
  ] },
  py: { rules: [
    { re: /#[^\n]*/, cls: 'tok-cmt' },
    { re: /"""(?:[^"\\]|\\.|"(?!""))*?"""/, cls: 'tok-str' },
    { re: /'''(?:[^'\\]|\\.|'(?!''))*?'''/, cls: 'tok-str' },
    { re: /"(?:[^"\\\n]|\\.)*"/, cls: 'tok-str' },
    { re: /'(?:[^'\\\n]|\\.)*'/, cls: 'tok-str' },
    { re: /@[\w.]+/, cls: 'tok-deco' },
    { re: /\b(?:def|class|return|if|elif|else|for|while|try|except|finally|with|as|import|from|lambda|yield|pass|break|continue|global|nonlocal|raise|assert|async|await|in|is|not|and|or|True|False|None|del)\b/, cls: 'tok-kw' },
    { re: /\b(?:print|len|range|int|str|float|bool|list|dict|set|tuple|type|isinstance|super|self|open|enumerate|zip|map|filter|sorted|sum|min|max|abs|input|repr|format|round)\b/, cls: 'tok-built' },
    { re: /\b\d+(?:\.\d+)?(?:[eE][+-]?\d+)?\b/, cls: 'tok-num' },
    { re: /\b[A-Za-z_]\w*(?=\s*\()/, cls: 'tok-fn' },
  ] },
  bash: { rules: [
    { re: /#[^\n]*/, cls: 'tok-cmt' },
    { re: /"(?:[^"\\\n]|\\.)*"/, cls: 'tok-str' },
    { re: /'(?:[^'\\\n]|\\.)*'/, cls: 'tok-str' },
    { re: /\$\{?[A-Za-z_]\w*\}?/, cls: 'tok-var' },
    { re: /\b(?:if|then|else|elif|fi|for|while|do|done|case|esac|function|return|exit|local|export|readonly|in|select|until)\b/, cls: 'tok-kw' },
    { re: /\b(?:echo|printf|cd|ls|cat|grep|sed|awk|curl|wget|mkdir|rm|cp|mv|touch|chmod|chown|sudo|apt|yum|npm|pnpm|yarn|git|docker|source|tar|zip|unzip|find|xargs)\b/, cls: 'tok-built' },
    { re: /\b\d+\b/, cls: 'tok-num' },
  ] },
  json: { rules: [
    { re: /"(?:[^"\\]|\\.)*"/, cls: 'tok-str' },
    { re: /\b(?:true|false|null)\b/, cls: 'tok-kw' },
    { re: /\b\d+(?:\.\d+)?(?:[eE][+-]?\d+)?\b/, cls: 'tok-num' },
  ] },
  yaml: { rules: [
    { re: /#[^\n]*/, cls: 'tok-cmt' },
    { re: /"(?:[^"\\\n]|\\.)*"/, cls: 'tok-str' },
    { re: /'(?:[^'\\\n]|\\.)*'/, cls: 'tok-str' },
    { re: /\b(?:true|false|null|yes|no|on|off)\b/, cls: 'tok-kw' },
    { re: /\b\d+(?:\.\d+)?\b/, cls: 'tok-num' },
    { re: /\b[\w.-]+(?=\s*:)/, cls: 'tok-key' },
  ] },
  java: { rules: [
    { re: /\/\*[\s\S]*?\*\//, cls: 'tok-cmt' },
    { re: /\/\/[^\n]*/, cls: 'tok-cmt' },
    { re: /"(?:[^"\\\n]|\\.)*"/, cls: 'tok-str' },
    { re: /'(?:[^'\\\n]|\\.)*'/, cls: 'tok-str' },
    { re: /\b(?:public|private|protected|static|final|class|interface|extends|implements|new|return|if|else|for|while|do|switch|case|break|continue|try|catch|finally|throw|throws|void|int|long|float|double|boolean|char|byte|short|this|super|package|import|abstract|synchronized|volatile|enum|instanceof|true|false|null|default|record)\b/, cls: 'tok-kw' },
    { re: /\b\d+(?:\.\d+)?[lLfFdD]?\b/, cls: 'tok-num' },
    { re: /\b[A-Za-z_]\w*(?=\s*\()/, cls: 'tok-fn' },
  ] },
  cpp: { rules: [
    { re: /\/\*[\s\S]*?\*\//, cls: 'tok-cmt' },
    { re: /\/\/[^\n]*/, cls: 'tok-cmt' },
    { re: /"(?:[^"\\\n]|\\.)*"/, cls: 'tok-str' },
    { re: /'(?:[^'\\\n]|\\.)*'/, cls: 'tok-str' },
    { re: /^[ \t]*#[^\n]*/, cls: 'tok-cmt' },
    { re: /\b(?:int|char|float|double|void|long|short|unsigned|signed|struct|union|enum|typedef|const|static|extern|register|volatile|return|if|else|for|while|do|switch|case|break|continue|goto|sizeof|class|namespace|using|public|private|protected|template|typename|new|delete|try|catch|throw|true|false|this|virtual|override|final|inline|auto|nullptr|bool)\b/, cls: 'tok-kw' },
    { re: /\b\d+(?:\.\d+)?(?:[eE][+-]?\d+)?[uUlL]*\b/, cls: 'tok-num' },
    { re: /\b[A-Za-z_]\w*(?=\s*\()/, cls: 'tok-fn' },
  ] },
  c: { rules: [
    { re: /\/\*[\s\S]*?\*\//, cls: 'tok-cmt' },
    { re: /\/\/[^\n]*/, cls: 'tok-cmt' },
    { re: /"(?:[^"\\\n]|\\.)*"/, cls: 'tok-str' },
    { re: /'(?:[^'\\\n]|\\.)*'/, cls: 'tok-str' },
    { re: /^[ \t]*#[^\n]*/, cls: 'tok-cmt' },
    { re: /\b(?:int|char|float|double|void|long|short|unsigned|signed|struct|union|enum|typedef|const|static|extern|register|volatile|return|if|else|for|while|do|switch|case|break|continue|goto|sizeof|true|false|NULL)\b/, cls: 'tok-kw' },
    { re: /\b\d+(?:\.\d+)?(?:[eE][+-]?\d+)?[uUlL]*\b/, cls: 'tok-num' },
    { re: /\b[A-Za-z_]\w*(?=\s*\()/, cls: 'tok-fn' },
  ] },
  go: { rules: [
    { re: /\/\*[\s\S]*?\*\//, cls: 'tok-cmt' },
    { re: /\/\/[^\n]*/, cls: 'tok-cmt' },
    { re: /"(?:[^"\\\n]|\\.)*"/, cls: 'tok-str' },
    { re: /'(?:[^'\\\n]|\\.)*'/, cls: 'tok-str' },
    { re: /`[^`]*`/, cls: 'tok-str' },
    { re: /\b(?:package|import|func|var|const|type|struct|interface|map|chan|go|defer|return|if|else|for|range|switch|case|default|break|continue|fallthrough|select|goto|true|false|nil)\b/, cls: 'tok-kw' },
    { re: /\b(?:string|int|int64|uint|float32|float64|bool|byte|rune|error|len|cap|make|new|append|panic|recover)\b/, cls: 'tok-built' },
    { re: /\b\d+(?:\.\d+)?\b/, cls: 'tok-num' },
    { re: /\b[A-Za-z_]\w*(?=\s*\()/, cls: 'tok-fn' },
  ] },
  rust: { rules: [
    { re: /\/\*[\s\S]*?\*\//, cls: 'tok-cmt' },
    { re: /\/\/[^\n]*/, cls: 'tok-cmt' },
    { re: /"(?:[^"\\\n]|\\.)*"/, cls: 'tok-str' },
    { re: /'(?:[^'\\\n]|\\.)*'/, cls: 'tok-str' },
    { re: /r#?"[^"\n]*"/, cls: 'tok-str' },
    { re: /\b(?:fn|let|mut|const|static|struct|enum|trait|impl|mod|use|crate|pub|self|Self|return|if|else|match|for|while|loop|break|continue|move|async|await|unsafe|type|where|dyn|true|false|ref|as)\b/, cls: 'tok-kw' },
    { re: /\b(?:String|Vec|Option|Result|Box|HashMap|i8|i16|i32|i64|u8|u16|u32|u64|f32|f64|usize|isize|bool|char|str)\b/, cls: 'tok-built' },
    { re: /\b\d+(?:\.\d+)?(?:[eE][+-]?\d+)?\b/, cls: 'tok-num' },
    { re: /\b[A-Za-z_]\w*(?=\s*\()/, cls: 'tok-fn' },
  ] },
  csharp: { rules: [
    { re: /\/\*[\s\S]*?\*\//, cls: 'tok-cmt' },
    { re: /\/\/[^\n]*/, cls: 'tok-cmt' },
    { re: /"(?:[^"\\\n]|\\.)*"/, cls: 'tok-str' },
    { re: /'(?:[^'\\\n]|\\.)*'/, cls: 'tok-str' },
    { re: /@"[^"]*"/, cls: 'tok-str' },
    { re: /\b(?:public|private|protected|internal|static|sealed|abstract|class|interface|struct|enum|namespace|using|new|return|if|else|for|foreach|while|do|switch|case|break|continue|try|catch|finally|throw|void|int|long|float|double|decimal|bool|string|char|var|const|readonly|this|base|override|virtual|async|await|true|false|null|get|set|partial|record|init|is|as|in|out|ref)\b/, cls: 'tok-kw' },
    { re: /\b(?:Console|String|Int32|Int64|Double|Decimal|Boolean|DateTime|Math|Task|List|Dictionary|Dictionary|Enumerable|Exception|Object)\b/, cls: 'tok-built' },
    { re: /\b\d+(?:\.\d+)?[dDfFmMlL]?\b/, cls: 'tok-num' },
    { re: /\b[A-Za-z_]\w*(?=\s*\()/, cls: 'tok-fn' },
  ] },
}

/** 语法高亮：返回已转义的带 <span> 高亮 HTML；未知语言仅转义 */
const highlight = (code: string, rawLang: string): string => {
  const alias = langAlias[rawLang.toLowerCase()] || rawLang.toLowerCase()
  const lang = HL[alias]
  if (!lang || !lang.rules.length) return esc(code)
  const re = new RegExp(lang.rules.map((t) => `(${t.re.source})`).join('|'), 'g' + (lang.flags || ''))
  return code.replace(re, (match, ...groups: (string | undefined)[]) => {
    for (let i = 0; i < lang.rules.length; i++) {
      const g = groups[i]
      const rule = lang.rules[i]
      if (rule && typeof g === 'string') {
        return `<span class="${rule.cls}">${esc(g)}</span>`
      }
    }
    return esc(match)
  })
}

// ==================== 块级解析 ====================

interface ListNode {
  level: number
  type: string
  html: string
  children: ListNode[]
}

/** 构建嵌套列表（树形结构 → 正确嵌套的 <ul>/<ol>） */
const buildList = (items: { level: number; type: string; html: string }[]): string => {
  const buildTree = (): ListNode[] => {
    const roots: ListNode[] = []
    const stack: ListNode[] = []
    for (const it of items) {
      const node: ListNode = { ...it, children: [] }
      while (stack.length > 0 && stack[stack.length - 1]!.level >= it.level) stack.pop()
      if (stack.length === 0) roots.push(node)
      else stack[stack.length - 1]!.children.push(node)
      stack.push(node)
    }
    return roots
  }

  const renderNode = (node: ListNode): string => {
    const children = node.children.length > 0 ? renderList(node.children) : ''
    return `<li>${node.html}${children}</li>`
  }

  const renderList = (nodes: ListNode[]): string => {
    let out = ''
    let current: string | null = null
    for (const n of nodes) {
      if (n.type !== current) {
        if (current) out += `</${current}>`
        out += `<${n.type}>`
        current = n.type
      }
      out += renderNode(n)
    }
    if (current) out += `</${current}>`
    return out
  }

  return renderList(buildTree())
}

export const renderWiki = (markup: string, opts: RenderOptions): RenderResult => {
  const toc: TocItem[] = []
  const used = new Map<string, number>()
  const lines: string[] = markup.replace(/\r\n/g, '\n').split('\n')
  const blocks: string[] = []
  let i = 0

  while (i < lines.length) {
    const line = lines[i]!
    const trimmed = line.trim()

    // 空行
    if (trimmed === '') {
      i++
      continue
    }

    // 目录标记（目录统一渲染在页面内容上方，标记本身不输出内容）
    if (trimmed === '(toc)' || trimmed === '[TOC]') {
      i++
      continue
    }

    // 围栏代码块 ```lang / ~~~lang
    const fenceM = line.match(/^ {0,3}(`{3,}|~{3,})[ \t]*([^\s`]*)?[ \t]*$/)
    if (fenceM) {
      const ch = fenceM[1]![0]
      const lang = fenceM[2] || ''
      const buf: string[] = []
      i++
      while (i < lines.length && !/^ {0,3}(`{3,}|~{3,})[ \t]*$/.test(lines[i]!)) {
        buf.push(lines[i]!)
        i++
      }
      i++ // 跳过结束标记
      const raw = buf.join('\n')
      const langName = (langAlias[lang.toLowerCase()] || lang.toLowerCase())
      const hasHL = Boolean(HL[langName])
      const codeHtml = highlight(raw, langName)
      const langLabel = langName ? `<span class="wiki-lang">${esc(langName)}</span>` : ''
      const copyIcon = '<svg class="wiki-copy-icon" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>'
      const copyBtn = `<button type="button" class="wiki-copy" data-code="${esc(raw)}" title="复制代码"><span>${copyIcon}</span></button>`
      blocks.push(`<pre class="wiki-code"${lang ? ` data-lang="${esc(langName)}"` : ''}><code class="${hasHL ? esc('language-' + langName) : 'language-plaintext'}">${codeHtml}</code>${langLabel}${copyBtn}</pre>`)
      continue
    }

    // 容器 ::: 类型 [文本]  （info / tip / warning / danger / details）
    //   以 "::: 类型 ..." 开启，以独立成行的 ":::" 结束；[文本]为可选标题
    const contM = line.match(/^ {0,3}:::[ \t]+([a-zA-Z]+)[ \t]*(.*)$/)
    if (contM && ['info', 'tip', 'warning', 'danger', 'details'].includes((contM[1] ?? '').toLowerCase())) {
      const type = (contM[1] ?? '').toLowerCase()
      const title = (contM[2] ?? '').trim()
      const buf: string[] = []
      i++
      while (i < lines.length && !/^ {0,3}:::[ \t]*$/.test(lines[i]!)) {
        buf.push(lines[i]!)
        i++
      }
      i++ // 跳过结束符
      const bodyHtml = renderWiki(buf.join('\n'), opts).html
      const defTitle: Record<string, string> = { info: 'Info', tip: 'Tip', warning: 'Warning', danger: 'Danger', details: 'Details' }
      const titleHtml = title ? inline(title, opts) : defTitle[type]
      const cls = `wiki-container wiki-container-${type}`
      if (type === 'details') {
        blocks.push(`<details class="${cls}"><summary>${titleHtml}</summary>${bodyHtml}</details>`)
      } else {
        blocks.push(`<div class="${cls}"><div class="wiki-container-title">${titleHtml}</div><div class="wiki-container-body">${bodyHtml}</div></div>`)
      }
      continue
    }

    // 引用块
    if (/^ {0,3}>/.test(line)) {
      const buf: string[] = []
      while (i < lines.length && /^ {0,3}>/.test(lines[i]!)) {
        buf.push(lines[i]!.replace(/^ {0,3}> ?/, ''))
        i++
      }
      blocks.push(`<blockquote>${renderWiki(buf.join('\n'), opts).html}</blockquote>`)
      continue
    }

    // 块级 HTML 容器（白名单块级标签，内部支持嵌套 Markdown）
    const bhtml = line.match(/^ {0,3}<([a-zA-Z][\w-]*)\b([^<>]*)>/)
    if (bhtml && HTML_BLOCK.has((bhtml[1] ?? '').toLowerCase())) {
      const tag = (bhtml[1] ?? '').toLowerCase()
      const pt = parseTag(bhtml[0])
      const attrsHtml = pt ? safeAttrs(tag, pt.attrs) : ''
      // void 块级标签（如 <hr>）直接输出
      if (HTML_VOID.has(tag)) {
        blocks.push(`<${tag}${attrsHtml}>`)
        i++
        continue
      }
      if (pt?.selfClosing) {
        blocks.push(`<${tag}${attrsHtml} />`)
        i++
        continue
      }
      const remaining = lines.slice(i).join('\n')
      const pair = findHtmlPair(remaining, 0, tag)
      if (pair) {
        const innerHtml = HTML_RAW_INNER.has(tag)
          ? esc(pair.inner)
          : HTML_INNER_INLINE.has(tag)
            ? inline(pair.inner, opts)
            : renderWiki(pair.inner, opts).html
        if (/^h[1-6]$/.test(tag)) {
          const level = Number(tag[1])
          const plain = stripHtmlTags(pair.inner)
          const id = slugify(plain, used)
          // 目录仅识别 2 级与 3 级标题
          if (level === 2 || level === 3) {
            toc.push({ id, level, text: inline(pair.inner, opts) })
          }
          blocks.push(`<h${level}${attrsHtml} id="${id}">${innerHtml}</h${level}>`)
        } else {
          blocks.push(`<${tag}${attrsHtml}>${innerHtml}</${tag}>`)
        }
        i += remaining.slice(0, pair.end).split('\n').length
        continue
      }
      // 未闭合 → 回退为普通行处理
    }

    // ATX 标题 # ~ ######
    const h = line.match(/^ {0,3}(#{1,6})[ \t]+(.+)$/)
    if (h) {
      const level = h[1]!.length
      const raw = h[2]!.replace(/[ \t]+#+[ \t]*$/, '').trim()
      const id = slugify(raw, used)
      // 目录仅识别 2 级与 3 级标题（页面标题本身与更深的标题不纳入）
      if (level === 2 || level === 3) {
        toc.push({ id, level, text: inline(raw, opts) })
      }
      blocks.push(`<h${level} id="${id}">${inline(raw, opts)}</h${level}>`)
      i++
      continue
    }

    // 表格
    if (trimmed.startsWith('|')) {
      const rows: string[][] = []
      while (i < lines.length && lines[i]!.trim().startsWith('|')) {
        const rowStr = lines[i]!.trim().replace(/^\|/, '').replace(/\|$/, '')
        rows.push(rowStr.split('|').map((c) => c.trim()))
        i++
      }
      // 第二行为对齐分隔行 → 首行为表头
      let header = -1
      let aligns: string[] = []
      if (rows.length >= 2 && rows[1]!.length > 0 && rows[1]!.every((c) => /^:?-+:?$/.test(c))) {
        header = 0
        aligns = rows[1]!.map((c) =>
          c.startsWith(':') && c.endsWith(':') ? 'center' : c.endsWith(':') ? 'right' : c.startsWith(':') ? 'left' : ''
        )
      }
      const renderRow = (cells: string[], tag: string) =>
        `<tr>${cells
          .map((c, ci) => `<${tag}${aligns[ci] ? ` style="text-align:${aligns[ci]}"` : ''}>${inline(c, opts)}</${tag}>`)
          .join('')}</tr>`

      if (header === 0) {
        blocks.push(
          `<table><thead>${renderRow(rows[0]!, 'th')}</thead><tbody>${rows.slice(2).map((r) => renderRow(r, 'td')).join('')}</tbody></table>`
        )
      } else {
        blocks.push(`<table><tbody>${rows.map((r) => renderRow(r, 'td')).join('')}</tbody></table>`)
      }
      continue
    }

    // 分隔线 --- / *** / ___
    if (/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/.test(line)) {
      blocks.push('<hr />')
      i++
      continue
    }

    // 列表
    const listM = line.match(/^(\s*)([-*+]|\d+[.)])\s+(.*)$/)
    if (listM) {
      const items: { level: number; type: string; html: string }[] = []
      while (i < lines.length) {
        const m = lines[i]!.match(/^(\s*)([-*+]|\d+[.)])\s+(.*)$/)
        if (!m) break
        const indent = (m[1] ?? '').length
        const marker = m[2] ?? ''
        const content = (m[3] ?? '').trim()
        const type = /^[-*+]/.test(marker) ? 'ul' : 'ol'
        const level = indent === 0 ? 1 : Math.floor(indent / 2) + 1
        items.push({ level, type, html: content === '' ? '' : inline(content, opts) })
        i++
      }
      blocks.push(buildList(items))
      continue
    }

    // 缩进代码块（行首 4 个空格）
    if (/^ {4,}\S/.test(line)) {
      const buf: string[] = []
      while (i < lines.length && /^ {4,}\S/.test(lines[i]!)) {
        buf.push(lines[i]!.replace(/^ {4}/, ''))
        i++
      }
      blocks.push(`<pre><code>${esc(buf.join('\n'))}</code></pre>`)
      continue
    }

    // 段落（累积）
    const buf: string[] = [line]
    i++
    while (i < lines.length) {
      const nxt = lines[i]!
      const nt = nxt.trim()
      if (nt === '') break
      if (/^ {0,3}#{1,6}[ \t]/.test(nxt)) break
      if (/^ {0,3}(`{3,}|~{3,})/.test(nxt)) break
      if (/^ {0,3}>/.test(nxt)) break
      if (nt.startsWith('|')) break
      if (/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/.test(nxt)) break
      if (/^(\s*)([-*+]|\d+[.)])\s+/.test(nxt)) break
      if (/^ {0,3}<[a-zA-Z][\w-]*\b/.test(nxt)) break
      if (/^ {4,}\S/.test(nxt)) break
      buf.push(nxt)
      i++
    }
    blocks.push(`<p>${inline(buf.join('\n'), opts)}</p>`)
  }

  return { html: blocks.join('\n'), toc }
}
