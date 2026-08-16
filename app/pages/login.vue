<script setup lang="ts">
const { user, ready, registrationOpen, login } = useAuth()
const route = useRoute()
const toast = useToast()

const username = ref('')
const password = ref('')
const busy = ref(false)

const redirect = computed(() => {
  const r = route.query.redirect
  return typeof r === 'string' && r.startsWith('/') ? r : '/'
})

onMounted(async () => {
  await useAuth().init()
  if (user.value) await navigateTo(redirect.value)
})

const submit = async () => {
  if (!username.value.trim() || !password.value) {
    toast.add({ title: '请输入用户名和密码', color: 'error' })
    return
  }
  busy.value = true
  const r = await login(username.value.trim(), password.value)
  busy.value = false
  if (r.ok) {
    toast.add({ title: '登录成功', color: 'success' })
    await navigateTo(redirect.value)
  } else {
    toast.add({ title: r.error?.message || '登录失败', color: 'error' })
  }
}
</script>

<template>
  <div class="max-w-sm mx-auto px-4 py-16">
    <h1 class="text-2xl font-bold text-center mb-8">登录</h1>
    <UCard>
      <UForm class="space-y-4" @submit.prevent="submit">
        <UFormField label="用户名">
          <UInput v-model="username" icon="i-lucide-user" placeholder="用户名" autocomplete="username" class="w-full" />
        </UFormField>
        <UFormField label="密码">
          <UInput
            v-model="password"
            icon="i-lucide-lock"
            type="password"
            placeholder="密码"
            autocomplete="current-password"
            class="w-full"
          />
        </UFormField>
        <UButton type="submit" block icon="i-lucide-log-in" :loading="busy">登录</UButton>
      </UForm>
    </UCard>
    <p v-if="registrationOpen" class="text-center text-sm text-(--ui-muted) mt-4">
      还没有账号？
      <NuxtLink :to="`/register?redirect=${encodeURIComponent(redirect)}`" class="text-(--ui-primary)">立即注册</NuxtLink>
    </p>
  </div>
</template>
