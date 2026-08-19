<script setup lang="ts">
// 页面职责：渲染指定 tag 的 wiki 页面正文
const route = useRoute()
const tag = computed(() => String(route.params.tag || ''))
</script>

<template>
  <WikiView :tag="tag" :key="tag" />
</template>
