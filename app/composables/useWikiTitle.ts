/**
 * 全局页面标题覆盖机制。
 * 布局层（default.vue）是唯一的 <title> 来源；各页面组件通过 setTitle 写入完整标题字符串，
 * 布局据此覆盖兜底标题，从而实现「一套规则全局通用」，避免多组件各自 useHead 相互覆盖失效。
 * 页面卸载时将覆盖值清空，交还布局兜底逻辑。
 */
export const useWikiTitle = () => {
  const override = useState<string | null>('wiki.title.override', () => null)
  const setTitle = (t: string | null) => {
    override.value = t
  }
  return { override, setTitle }
}