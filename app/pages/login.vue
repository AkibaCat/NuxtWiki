<script setup lang="ts">
const { t } = useI18n()
const { user, ready, registrationOpen, login } = useAuth()
const route = useRoute()
const toast = useToast()

// 登录页：登录成功后跳转 redirect（已登录则直接跳转）
const username = ref('')
const password = ref('')
const busy = ref(false)

// redirect 仅接受站内相对路径，避免开放重定向
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
    toast.add({ title: t('login.missingCredentials'), color: 'error' })
    return
  }
  busy.value = true
  const r = await login(username.value.trim(), password.value)
  busy.value = false
  if (r.ok) {
    toast.add({ title: t('login.success'), color: 'success' })
    await navigateTo(redirect.value)
  } else {
    toast.add({ title: r.error?.message || t('login.failed'), color: 'error' })
  }
}
</script>

<template>
  <div class="max-w-sm mx-auto px-4 py-16">
    <h1 class="text-2xl font-bold text-center mb-8">{{ t('login.title') }}</h1>
    <UCard>
      <UForm class="space-y-4" @submit.prevent="submit">
        <UFormField :label="t('login.username')">
          <UInput v-model="username" icon="i-lucide-user" :placeholder="t('login.username')" autocomplete="username" class="w-full" />
        </UFormField>
        <UFormField :label="t('login.password')">
          <UInput
            v-model="password"
            icon="i-lucide-lock"
            type="password"
            :placeholder="t('login.password')"
            autocomplete="current-password"
            class="w-full"
          />
        </UFormField>
        <UButton type="submit" block icon="i-lucide-log-in" :loading="busy">{{ t('login.submit') }}</UButton>
      </UForm>
    </UCard>
    <p v-if="registrationOpen" class="text-center text-sm text-(--ui-muted) mt-4">
      {{ t('login.noAccount') }}
      <NuxtLink :to="`/register?redirect=${encodeURIComponent(redirect)}`" class="text-(--ui-primary)">{{ t('login.registerNow') }}</NuxtLink>
    </p>
  </div>
</template>
