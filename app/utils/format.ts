/** 文件大小格式化 */
export const formatSize = (bytes: number): string => {
  if (!bytes) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)))
  return `${(bytes / 1024 ** i).toFixed(i === 0 ? 0 : 1)} ${units[i]}`
}

/** 数量格式化：K / M / B 单位 */
export const formatCount = (n: number): string => {
  const num = Number(n) || 0
  if (num < 1000) return String(num)
  const units = ['', 'K', 'M', 'B', 'T']
  const i = Math.min(units.length - 1, Math.floor(Math.log(num) / Math.log(1000)))
  const v = num / 1000 ** i
  return `${v >= 100 ? Math.round(v) : v.toFixed(1)}${units[i]}`
}

/** 日期时间格式化 */
export const formatDate = (s: string): string => {
  const d = new Date(s)
  if (Number.isNaN(d.getTime())) return s
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
}
