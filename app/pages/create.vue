<script setup lang="ts">
const toast = useToast()
const newTag = ref('')

const create = async () => {
  const tag = newTag.value.trim()
  if (!tag) {
    toast.add({ title: '请输入页面名', color: 'error' })
    return
  }
  await navigateTo(`/${encodeURIComponent(tag)}/edit`)
}
</script>

<template>
  <div class="max-w-xl mx-auto px-4 py-16">
    <h1 class="text-2xl font-bold mb-2">创建页面</h1>
    <p class="text-sm text-(--ui-muted) mb-6">输入页面名后跳转到编辑页，保存后才真正创建。</p>
    <form class="flex items-center gap-3" @submit.prevent="create">
      <UInput v-model="newTag" placeholder="输入页面名…" class="flex-1 w-full" autofocus />
      <UButton type="submit" icon="i-lucide-arrow-right" label="开始编辑" />
    </form>
  </div>
</template>