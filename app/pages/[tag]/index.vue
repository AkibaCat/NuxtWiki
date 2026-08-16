<script setup lang="ts">
const route = useRoute()
const tag = computed(() => String(route.params.tag || ''))
</script>

<template>
  <WikiView :tag="tag" :key="tag" />
</template>
