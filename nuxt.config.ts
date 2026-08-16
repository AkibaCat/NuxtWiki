// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  modules: [
    '@nuxt/eslint',
    '@nuxt/ui'
  ],

  devtools: {
    enabled: true
  },

  css: ['~/assets/css/main.css'],

  // SPA 模式：Kangle 静态部署（纯前端 + PHP API）
  ssr: false,

  // 运行时配置（生产可用 NUXT_PUBLIC_API_BASE 覆盖）
  runtimeConfig: {
    public: {
      apiBase: '/api/index.php'
    }
  },

  // 本地开发：将 /api 代理到 PHP 内置服务器
  nitro: {
    devProxy: {
      '/api': 'http://localhost:8765'
    }
  },

  app: {
    head: {
      htmlAttrs: { lang: 'zh-CN' }
    }
  },

  compatibilityDate: '2026-06-30',

  eslint: {
    config: {
      stylistic: {
        commaDangle: 'never',
        braceStyle: '1tbs'
      }
    }
  }
})
