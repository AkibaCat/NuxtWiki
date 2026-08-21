/**
 * 页面编辑器「自动保存工作区」开关（随浏览器本地保存，默认关闭）。
 * 开关开启时，编辑器内的页面编辑内容会自动保存到后端工作区（workspace.get/save），
 * 下次打开编辑器时自动恢复；关闭时编辑内容仅保留在本次会话内。
 */
const LS_KEY = 'wiki.editor.autosave'

export const useEditorAutosave = () => {
  const enabled = useState<boolean>('wiki.editor.autosave', () => false)

  const read = () => {
    if (import.meta.server) return
    try {
      enabled.value = localStorage.getItem(LS_KEY) === '1'
    } catch {
      enabled.value = false
    }
  }

  const setEnabled = (v: boolean) => {
    enabled.value = v
    if (import.meta.server) return
    try {
      localStorage.setItem(LS_KEY, v ? '1' : '0')
    } catch {
      /* 写入失败则忽略 */
    }
  }

  if (import.meta.client) {
    read()
  }

  return { enabled, setEnabled }
}
