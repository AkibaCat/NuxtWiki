<script setup lang="ts">
// 应用根组件：挂载全局 UI 容器（UApp/UToaster），并渲染布局与页面。
useHead({
  meta: [
    { name: 'viewport', content: 'width=device-width, initial-scale=1' },
    { name: 'og:type', content: 'website' }
  ],
  link: [
    { rel: 'icon', href: '/nuxtwiki.svg' }
  ]
})
</script>

<template>
  <UApp>
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
    <UToaster />
  </UApp>
</template>
