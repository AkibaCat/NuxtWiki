<script setup lang="ts">
const { user, registrationOpen, register } = useAuth()
const route = useRoute()
const toast = useToast()

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
  if (!auth.registrationOpen.value) await navigateTo('/login')
})

const submit = async () => {
  if (!username.value.trim() || !password.value) {
    toast.add({ title: '请填写用户名和密码', color: 'error' })
    return
  }
  if (password.value !== confirm.value) {
    toast.add({ title: '两次输入的密码不一致', color: 'error' })
    return
  }
  if (!regcode.value.trim()) {
    toast.add({ title: '请填写注册码', color: 'error' })
    return
  }
  busy.value = true
  const r = await register(username.value.trim(), password.value, confirm.value, regcode.value.trim())
  busy.value = false
  if (r.ok) {
    toast.add({ title: '注册成功，已自动登录', color: 'success' })
    await navigateTo(redirect.value)
  } else {
    toast.add({ title: r.error?.message || '注册失败', color: 'error' })
  }
}
</script>

<template>
  <div class="max-w-sm mx-auto px-4 py-16">
    <h1 class="text-2xl font-bold text-center mb-8">注册账号</h1>
    <UCard>
      <UForm class="space-y-4" @submit.prevent="submit">
        <UFormField label="用户名" hint="2~32 个字符">
          <UInput v-model="username" icon="i-lucide-user" placeholder="用户名" autocomplete="username" class="w-full" />
        </UFormField>
        <UFormField label="密码" hint="至少 6 位">
          <UInput
            v-model="password"
            icon="i-lucide-lock"
            type="password"
            placeholder="密码"
            autocomplete="new-password"
            class="w-full"
          />
        </UFormField>
        <UFormField label="确认密码">
          <UInput
            v-model="confirm"
            icon="i-lucide-lock"
            type="password"
            placeholder="再次输入密码"
            autocomplete="new-password"
            class="w-full"
          />
        </UFormField>
        <UFormField label="注册码" hint="向管理员获取注册码">
          <UInput v-model="regcode" icon="i-lucide-key-round" placeholder="ZC-xxxxxxxxxxxxxxxx" class="w-full" />
        </UFormField>
        <UButton type="submit" block icon="i-lucide-user-plus" :loading="busy">注册</UButton>
      </UForm>
    </UCard>
    <p class="text-center text-sm text-(--ui-muted) mt-4">
      已有账号？
      <NuxtLink :to="`/login?redirect=${encodeURIComponent(redirect)}`" class="text-(--ui-primary)">去登录</NuxtLink>
    </p>
  </div>
</template>