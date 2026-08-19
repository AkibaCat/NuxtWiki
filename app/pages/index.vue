<script setup lang="ts">
const route = useRoute()
const { site } = useAuthState()

// 首页：展示站点首页内容（tag 取配置的 home_tag，缺省 Home）
const tag = computed(() => site.value?.home_tag || 'Home')
</script>

<template>
  <WikiView :tag="tag" :key="tag" />
</template>
