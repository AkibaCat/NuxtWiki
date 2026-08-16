<script setup lang="ts">
const { user, init } = useAuth()

onMounted(async () => {
  await init()
  if (!user.value) {
    await navigateTo('/login?redirect=/account')
    return
  }
  await navigateTo(`/account/${encodeURIComponent(user.value.username)}`)
})
</script>

<template>
  <div class="flex justify-center py-16">
    <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-(--ui-primary)" />
  </div>
</template>
