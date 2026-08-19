<script setup lang="ts">
const { t } = useI18n()
const { user, registrationOpen, register } = useAuth()
const route = useRoute()
const toast = useToast()

// 注册页：需填写注册码注册，成功后跳转 redirect
const username = ref('')
const password = ref('')
const confirm = ref('')
const regcode = ref('')
const busy = ref(false)

const redirect = computed(() => {
  const r = route.query.redirect
  return typeof r === 'string' && r.startsWith('/') ? r : '/'
})

onMounted(async () => {
  const auth = useAuth()
  await auth.init()
  if (user.value) await navigateTo(redirect.value)
  // 站点关闭注册时禁止进入注册页
  if (!auth.registrationOpen.value) await navigateTo('/login')
})

const submit = async () => {
  if (!username.value.trim() || !password.value) {
    toast.add({ title: t('register.missingCredentials'), color: 'error' })
    return
  }
  if (password.value !== confirm.value) {
    toast.add({ title: t('register.passwordMismatch'), color: 'error' })
    return
  }
  if (!regcode.value.trim()) {
    toast.add({ title: t('register.missingRegcode'), color: 'error' })
    return
  }
  busy.value = true
  const r = await register(username.value.trim(), password.value, confirm.value, regcode.value.trim())
  busy.value = false
  if (r.ok) {
    toast.add({ title: t('register.success'), color: 'success' })
    await navigateTo(redirect.value)
  } else {
    toast.add({ title: r.error?.message || t('register.failed'), color: 'error' })
  }
}
</script>

<template>
  <div class="max-w-sm mx-auto px-4 py-16">
    <h1 class="text-2xl font-bold text-center mb-8">{{ t('register.title') }}</h1>
    <UCard>
      <UForm class="space-y-4" @submit.prevent="submit">
        <UFormField :label="t('register.username')" :hint="t('register.usernameHint')">
          <UInput v-model="username" icon="i-lucide-user" :placeholder="t('register.username')" autocomplete="username" class="w-full" />
        </UFormField>
        <UFormField :label="t('register.password')" :hint="t('register.passwordHint')">
          <UInput
            v-model="password"
            icon="i-lucide-lock"
            type="password"
            :placeholder="t('register.password')"
            autocomplete="new-password"
            class="w-full"
          />
        </UFormField>
        <UFormField :label="t('register.confirm')">
          <UInput
            v-model="confirm"
            icon="i-lucide-lock"
            type="password"
            :placeholder="t('register.confirmPlaceholder')"
            autocomplete="new-password"
            class="w-full"
          />
        </UFormField>
        <UFormField :label="t('register.regcode')" :hint="t('register.regcodeHint')">
          <UInput v-model="regcode" icon="i-lucide-key-round" placeholder="ZC-xxxxxxxxxxxxxxxx" class="w-full" />
        </UFormField>
        <UButton type="submit" block icon="i-lucide-user-plus" :loading="busy">{{ t('register.submit') }}</UButton>
      </UForm>
    </UCard>
    <p class="text-center text-sm text-(--ui-muted) mt-4">
      {{ t('register.hasAccount') }}
      <NuxtLink :to="`/login?redirect=${encodeURIComponent(redirect)}`" class="text-(--ui-primary)">{{ t('register.goLogin') }}</NuxtLink>
    </p>
  </div>
</template>