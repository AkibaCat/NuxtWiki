import type { UseFetchOptions } from '#app'

export interface ApiError {
  code: string
  message: string
}

export interface ApiResult<T = unknown> {
  ok: boolean
  status: number
  data?: T
  error?: ApiError
}

/** 全局 API 客户端（基于 fetch，自动携带 CSRF） */
export const useApi = () => {
  const config = useRuntimeConfig()
  const { csrf } = useAuthState()

  const base = () => (config.public.apiBase as string || '/api/index.php')

  const buildUrl = (action: string, query?: Record<string, string | number | undefined>) => {
    const url = new URL(base(), window.location.origin)
    url.searchParams.set('r', action)
    if (query) {
      for (const [k, v] of Object.entries(query)) {
        if (v !== undefined && v !== null && v !== '') {
          url.searchParams.set(k, String(v))
        }
      }
    }
    return url.toString()
  }

  const request = async <T = unknown>(
    action: string,
    opts: {
      method?: 'GET' | 'POST' | 'PUT' | 'DELETE'
      body?: Record<string, unknown> | unknown[]
      query?: Record<string, string | number | undefined>
      formData?: FormData
      skipCsrf?: boolean
    } = {}
  ): Promise<ApiResult<T>> => {
    const { method = opts.body || opts.formData ? 'POST' : 'GET', body, query, formData, skipCsrf } = opts
    const headers: Record<string, string> = {}
    if (csrf.value && !skipCsrf && (method === 'POST' || method === 'PUT' || method === 'DELETE')) {
      headers['X-CSRF-Token'] = csrf.value
    }
    if (body && !formData) {
      headers['Content-Type'] = 'application/json'
    }

    const res = await fetch(buildUrl(action, query), {
      method,
      headers,
      body: formData ?? (body ? JSON.stringify(body) : undefined),
      credentials: 'include'
    })

    // CSRF 失效时刷新令牌后重试一次
    if (res.status === 419 && !skipCsrf) {
      const r2 = await fetch(buildUrl('auth.csrf'), { credentials: 'include' })
      const j2 = await r2.json().catch(() => null)
      if (j2?.data?.csrf) {
        csrf.value = j2.data.csrf
        return request<T>(action, { ...opts, skipCsrf: false })
      }
    }

    const json = await res.json().catch(() => null) as ApiResult<T> | null
    if (json) {
      json.status = res.status
      return json
    }
    return { ok: false, status: res.status, error: { code: 'NETWORK', message: '无法连接到服务器' } }
  }

  const get = <T = unknown>(action: string, query?: Record<string, string | number | undefined>) =>
    request<T>(action, { method: 'GET', query })

  const post = <T = unknown>(action: string, body?: Record<string, unknown> | unknown[], opts?: { query?: Record<string, string | number | undefined>, skipCsrf?: boolean }) =>
    request<T>(action, { method: 'POST', body, ...opts })

  const postForm = <T = unknown>(action: string, formData: FormData) =>
    request<T>(action, { method: 'POST', formData })

  return { request, get, post, postForm, base }
}

export type Api = ReturnType<typeof useApi>
