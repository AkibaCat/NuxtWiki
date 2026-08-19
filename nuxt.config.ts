// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  modules: [
    '@nuxt/eslint',
    '@nuxt/ui',
    '@nuxtjs/i18n'
  ],

  // SPA 模式：Kangle 静态部署（纯前端 + PHP API）
  ssr: false,

  i18n: {
    // Kangle 纯静态部署：不使用 URL 前缀，全部语言打包进同一份静态资源
    strategy: 'no_prefix',
    langDir: 'locales/',
    defaultLocale: 'zh-CN',
    detectBrowserLanguage: false,
    locales: [
      { code: 'zh-CN', language: 'zh-CN', name: '简体中文', file: 'zh-CN.json' },
      { code: 'zh-TW', language: 'zh-TW', name: '繁體中文', file: 'zh-TW.json' },
      { code: 'en', language: 'en', name: 'English', file: 'en.json' }
    ]
  },

  devtools: {
    enabled: true
  },

  css: ['~/assets/css/main.css'],

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

  compatibilityDate: '2026-06-30'
})
