/**
 * NuxtWiki · 页面样式表（SCSS）编译工具
 * 把页面样式表（支持 SCSS/Sass 语法）编译为 CSS，并作用域到 `页面内容容器` 下，
 * 避免样式泄漏到整站。顶层 Sass 变量会以自定义属性（--name）暴露，
 * 以便正文语法 [文本]{$name} 直接取用。
 */
import { compileString } from 'sass'

/** 页面样式作用域类名（与 WikiView / 编辑器预览的内容容器一致） */
const SCOPE = '.wiki-content'

/** 顶层 Sass 变量（简单的一行 `$name: value;` 定义）。返回去重后的变量名列表 */
const extractScssVars = (scss: string): string[] => {
  const names: string[] = []
  const seen = new Set<string>()
  const re = /^\s{0,2}\$([\w-]+)\s*:\s*[^;\r\n{}]+;(?:\s*\/\/.*)?$/gm
  let m: RegExpExecArray | null
  while ((m = re.exec(scss))) {
    const name = (m[1] ?? '').trim()
    // $TC 为内置主题色，保留且不允许被样式编辑器自定义
    if (!name || name.toLowerCase() === 'tc') continue
    if (seen.has(name)) continue
    seen.add(name)
    names.push(name)
  }
  return names
}

/**
 * 编译页面 SCSS：返回 { css, error }。
 * - 包一层 `.wiki-content {}` 作用域；
 * - 把顶层变量以自定义属性追加到作用域根上，供 […]{$name} 引用；
 * - 编译失败时 css 为空串、error 为错误信息（避免破坏页面展示，同时向编辑器反馈）。
 */
export const compilePageStyleResult = (scss: string): { css: string; error: string } => {
  const source = (scss ?? '').trim()
  if (!source) return { css: '', error: '' }
  try {
    const vars = extractScssVars(source)
    const varProps = vars.map((n) => `  --${n}: #{$${n}};`).join('\n')
    const wrapped = `\n${SCOPE} {\n${source}\n${varProps}\n}\n`
    return { css: compileString(wrapped, { syntax: 'scss', style: 'expanded' }).css, error: '' }
  } catch (e) {
    return { css: '', error: e instanceof Error ? e.message : String(e) }
  }
}

/** 仅返回编译后的 CSS（空串表示空样式或编译失败） */
export const compilePageStyle = (scss: string): string => compilePageStyleResult(scss).css