/**
 * 页面目录（TOC）全局状态与跳转逻辑。
 * 目录数据由 WikiView 写入、布局层的移动端「此页目录」/ 桌面端侧栏共用读取，
 * 从而实现移动端第二导航栏的下拉浮窗展示。
 */
import type { TocItem } from '~/utils/wiki'

export const useToc = () => {
  const items = useState<TocItem[]>('wiki.toc.items', () => [])
  const visible = useState<boolean>('wiki.toc.visible', () => false)
  const open = useState<boolean>('wiki.toc.open', () => false)

  /** 由 WikiView 写入当前页面目录；空列表时移动端第二导航栏自动隐藏 */
  const setToc = (list: TocItem[] | undefined | null) => {
    items.value = list ?? []
    visible.value = (list?.length ?? 0) > 0
  }
  const toggle = () => { open.value = !open.value }
  const close = () => { open.value = false }

  // ---------- 目录跳转：平滑滚动到内容位置后再整节连续高亮 ----------
  const flashTimer = useState<ReturnType<typeof setTimeout> | null>('wiki.toc.flashTimer', () => null)
  const flashOverlay = useState<HTMLElement | null>('wiki.toc.flashOverlay', () => null)
  const flashRaf = useState<number | null>('wiki.toc.flashRaf', () => null)

  const clearFlash = () => {
    if (flashRaf.value != null) {
      cancelAnimationFrame(flashRaf.value)
      flashRaf.value = null
    }
    if (flashOverlay.value) {
      const parent = flashOverlay.value.parentElement
      flashOverlay.value.remove()
      if (parent) parent.classList.remove('wiki-flash-wrap')
      flashOverlay.value = null
    }
    if (flashTimer.value) {
      clearTimeout(flashTimer.value)
      flashTimer.value = null
    }
  }

  /** 在目标标题处创建整节闪烁覆盖层 */
  const createFlash = (container: HTMLElement, el: HTMLElement) => {
    clearFlash()

    // 收集小节：目标标题 + 其后内容，直到下一个同级 / 更高级标题为止
    const level = Number(el.tagName.slice(1))
    const block: HTMLElement[] = [el]
    let node = el.nextElementSibling
    while (node && !(node.tagName.startsWith('H') && Number(node.tagName.slice(1)) <= level)) {
      block.push(node as HTMLElement)
      node = node.nextElementSibling
    }

    // 用「单一覆盖层」盖住整个小节，消除块间外边距空白，动画同步连续
    container.classList.add('wiki-flash-wrap')
    const top = el.offsetTop
    const last = block[block.length - 1]!
    const height = last.offsetTop + last.offsetHeight - top
    const overlay = document.createElement('div')
    overlay.className = 'toc-flash-overlay'
    overlay.style.top = `${top}px`
    overlay.style.height = `${height}px`
    container.appendChild(overlay)
    flashOverlay.value = overlay

    flashTimer.value = setTimeout(() => clearFlash(), 1000)
  }

  const scrollToHeading = (id: string) => {
    const el = document.getElementById(id)
    if (!el) return
    // 平滑滚动到目标标题（scroll-margin-top 已预留导航栏偏移）
    el.scrollIntoView({ behavior: 'smooth', block: 'start' })

    const container = el.closest('.wiki-content') as HTMLElement | null
    if (!container) return
    clearFlash()

    // 先等平滑滚动结束，再触发闪烁，避免动画在滚动途中提前出现
    const SCROLL_IDLE_DELTA = 2
    const deadline = Date.now() + 1000
    let lastY = window.scrollY
    let stableFrames = 0
    const poll = () => {
      const y = window.scrollY
      if (Math.abs(y - lastY) < SCROLL_IDLE_DELTA) stableFrames++
      else stableFrames = 0
      lastY = y
      if (stableFrames >= 3 || Date.now() >= deadline) {
        flashRaf.value = null
        createFlash(container, el)
        return
      }
      flashRaf.value = requestAnimationFrame(poll)
    }
    flashRaf.value = requestAnimationFrame(poll)
  }

  return { items, visible, open, setToc, toggle, close, scrollToHeading }
}