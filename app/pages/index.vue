<script setup lang="ts">
const route = useRoute()
const { site } = useAuthState()

// 首页即 HomePage
const tag = computed(() => site.value?.home_tag || 'HomePage')
</script>

<template>
  <WikiView :tag="tag" :key="tag" />
</template>
