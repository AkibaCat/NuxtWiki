// 页面分组：默认分组名 + 归组工具。分组名是站点内容而非界面文案，因此默认分组名保持中文常量，不参与 i18n 翻译。
export const DEFAULT_GROUP = '默认页面'

/** 归一化页面分组：空值归入默认分组 */
export function groupOf(page: { group?: unknown }): string {
  const g = typeof page?.group === 'string' ? page.group.trim() : ''
  return g || DEFAULT_GROUP
}

/** 将页面列表按分组归类并排序，返回 [分组名, 该分组页面[]][] */
export function groupByPages(pages: any[]): [string, any[]][] {
  const map = new Map<string, any[]>()
  for (const p of pages ?? []) {
    const g = groupOf(p)
    if (!map.has(g)) map.set(g, [])
    map.get(g)!.push(p)
  }
  return [...map.entries()].sort((a, b) => (a[0] < b[0] ? -1 : a[0] > b[0] ? 1 : 0))
}