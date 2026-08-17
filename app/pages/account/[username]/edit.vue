<script setup lang="ts">
const route = useRoute()
const { user, init } = useAuth()
const api = useApi()
const toast = useToast()

const paramName = computed(() => String(route.params.username || 'me'))

const loading = ref(true)
const saving = ref(false)

const form = reactive({
  nickname: '',
  avatar: '',
  bio: '',
  email: '',
  socials: { qq: '', wechat: '', bilibili: '', youtube: '', github: '', x: '' },
})

const socialDefs: { key: keyof typeof form.socials; label: string; icon: string; placeholder: string }[] = [
  { key: 'qq', label: 'QQ', icon: 'i-simple-icons-qq', placeholder: 'QQ 号码' },
  { key: 'wechat', label: 'WeChat', icon: 'i-simple-icons-wechat', placeholder: '微信号' },
  { key: 'bilibili', label: 'BiliBili', icon: 'i-simple-icons-bilibili', placeholder: '哔哩哔哩主页链接' },
  { key: 'youtube', label: 'YouTube', icon: 'i-simple-icons-youtube', placeholder: 'YouTube 频道链接' },
  { key: 'github', label: 'GitHub', icon: 'i-simple-icons-github', placeholder: 'GitHub 主页链接' },
  { key: 'x', label: 'X', icon: 'i-simple-icons-x', placeholder: 'X（Twitter）链接' },
]

onMounted(async () => {
  await init()
  if (!user.value) {
    await navigateTo('/login?redirect=/account/me/edit')
    return
  }
  // /account/me/edit 或 /account/{其他用户}/edit → 仅允许编辑自己
  const target = paramName.value === 'me' ? user.value.username : paramName.value
  if (target !== user.value.username) {
    toast.add({ title: '只能编辑自己的资料', color: 'error' })
    await navigateTo(`/account/${encodeURIComponent(user.value.username)}`)
    return
  }
  await loadProfile()
  loading.value = false
})

const loadProfile = async () => {
  const r = await api.get('user.profile', { username: user.value!.username })
  if (r.ok) {
    const p = r.data.user
    form.nickname = p.nickname || ''
    form.avatar = p.avatar || ''
    form.bio = p.bio || ''
    form.email = p.email || ''
    const s = p.socials || {}
    for (const d of socialDefs) form.socials[d.key] = s[d.key] || ''
  }
}

const save = async () => {
  saving.value = true
  const r = await api.post('user.profile', {
    nickname: form.nickname,
    avatar: form.avatar,
    bio: form.bio,
    email: form.email,
    socials: form.socials,
  })
  saving.value = false
  if (r.ok) {
    toast.add({ title: '个人资料已保存', color: 'success' })
    await navigateTo(`/account/${encodeURIComponent(user.value!.username)}`)
  } else {
    toast.add({ title: r.error?.message || '保存失败', color: 'error' })
  }
}

// ==================== 头像上传 + 1:1 裁剪 ====================
const avatarUploading = ref(false)
const cropSrc = ref('')
const cropZoom = ref(1)
const cropOffset = reactive({ x: 0, y: 0 })
const cropViewport = ref<HTMLDivElement>()
const cropImg = ref<HTMLImageElement>()
const avatarInput = ref<HTMLInputElement>()
const CROP_VIEW = 280 // 裁剪视口边长（px）
const cropW = ref(0)
const cropH = ref(0)

const triggerAvatarSelect = () => avatarInput.value?.click()

const onAvatarFile = (e: Event) => {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  const reader = new FileReader()
  reader.onload = () => {
    cropSrc.value = String(reader.result)
    cropZoom.value = 1
    cropOffset.x = 0
    cropOffset.y = 0
  }
  reader.readAsDataURL(file)
}

/** 关闭右侧裁剪面板 */
const closeCrop = () => {
  cropSrc.value = ''
}

const cropScale = computed(() => {
  if (!cropW.value || !cropH.value) return 1
  return Math.max(CROP_VIEW / cropW.value, CROP_VIEW / cropH.value) * cropZoom.value
})

const cropStyle = computed(() => ({
  width: `${cropW.value * cropScale.value}px`,
  height: `${cropH.value * cropScale.value}px`,
  transform: `translate(${cropOffset.x}px, ${cropOffset.y}px)`,
}))

const clampOffset = () => {
  const dispW = cropW.value * cropScale.value
  const dispH = cropH.value * cropScale.value
  cropOffset.x = Math.min(0, Math.max(CROP_VIEW - dispW, cropOffset.x))
  cropOffset.y = Math.min(0, Math.max(CROP_VIEW - dispH, cropOffset.y))
}

const initCrop = () => {
  const img = cropImg.value
  if (!img) return
  cropW.value = img.naturalWidth || img.width
  cropH.value = img.naturalHeight || img.height
  clampOffset()
}

let dragging = false
let dragStart = { x: 0, y: 0, ox: 0, oy: 0 }
const onCropDown = (e: PointerEvent) => {
  dragging = true
  dragStart = { x: e.clientX, y: e.clientY, ox: cropOffset.x, oy: cropOffset.y }
  cropViewport.value?.setPointerCapture?.(e.pointerId)
}
const onCropMove = (e: PointerEvent) => {
  if (!dragging) return
  cropOffset.x = dragStart.ox + (e.clientX - dragStart.x)
  cropOffset.y = dragStart.oy + (e.clientY - dragStart.y)
  clampOffset()
}
const onCropUp = () => { dragging = false }

/** 确认裁剪：取视口内区域，压缩为 160×160 WEBP 后上传 */
const confirmCrop = () => {
  const img = cropImg.value
  if (!img) return
  const canvas = document.createElement('canvas')
  canvas.width = 160
  canvas.height = 160
  const ctx = canvas.getContext('2d')
  if (!ctx) return
  const scale = cropScale.value
  const srcX = -cropOffset.x / scale
  const srcY = -cropOffset.y / scale
  const srcSize = CROP_VIEW / scale
  ctx.drawImage(img, srcX, srcY, srcSize, srcSize, 0, 0, 160, 160)
  canvas.toBlob(async (blob) => {
    if (!blob) return
    cropSrc.value = ''
    avatarUploading.value = true
    const fd = new FormData()
    fd.append('avatar', blob, 'avatar.webp')
    const r = await api.postForm('user.avatar', fd)
    avatarUploading.value = false
    if (r.ok && r.data?.user) {
      form.avatar = r.data.user.avatar
      toast.add({ title: '头像已上传', color: 'success' })
    } else {
      toast.add({ title: r.error?.message || '头像上传失败', color: 'error' })
    }
  }, 'image/webp', 0.85)
}
</script>

<template>
  <div class="max-w-4xl mx-auto px-4 py-8">
    <div v-if="loading" class="flex justify-center py-16">
      <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-(--ui-primary)" />
    </div>

    <template v-else>
      <div class="flex items-center gap-3 mb-6">
        <UButton :to="`/account/${encodeURIComponent(user?.username || 'me')}`" icon="i-lucide-arrow-left" size="xs" color="neutral" variant="ghost">
          返回主页
        </UButton>
        <h1 class="text-2xl font-bold">修改个人资料</h1>
      </div>

      <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px] items-start">
        <!-- 左：信息修改栏 -->
        <UCard>
          <div class="space-y-4">
            <UFormField label="昵称">
              <UInput v-model="form.nickname" placeholder="昵称" class="w-full" />
            </UFormField>
            <UFormField label="头像">
              <div class="flex items-center gap-3">
                <img v-if="form.avatar" :src="form.avatar" alt="头像" class="size-14 rounded-full object-cover border border-(--ui-border) shrink-0" />
                <UAvatar v-else :alt="user?.username || '?'" size="lg" :text="form.nickname ? form.nickname.charAt(0) : (user?.username?.charAt(0) || '?')" />
                <div class="flex flex-col gap-1">
                  <UButton icon="i-lucide-upload" variant="subtle" size="sm" :loading="avatarUploading" @click="triggerAvatarSelect">
                    上传图片
                  </UButton>
                  <UButton v-if="form.avatar" icon="i-lucide-trash" size="xs" color="neutral" variant="ghost" @click="form.avatar = ''">
                    移除头像
                  </UButton>
                </div>
                <input ref="avatarInput" type="file" accept="image/*" class="hidden" @change="onAvatarFile" />
              </div>
            </UFormField>
            <UFormField label="个人介绍">
              <UTextarea v-model="form.bio" placeholder="介绍一下自己…" :rows="3" class="w-full" />
            </UFormField>
            <UFormField label="邮箱">
              <UInput v-model="form.email" type="email" placeholder="you@example.com" class="w-full" />
            </UFormField>
            <div>
              <p class="text-sm font-medium mb-2">外部社交连接</p>
              <div class="grid gap-3 sm:grid-cols-2">
                <UFormField v-for="d in socialDefs" :key="d.key" :label="d.label">
                  <UInput v-model="form.socials[d.key]" :icon="d.icon" :placeholder="d.placeholder" class="w-full" />
                </UFormField>
              </div>
            </div>
            <div class="flex justify-end gap-2 pt-2">
              <UButton variant="subtle" color="neutral" :to="`/account/${encodeURIComponent(user?.username || 'me')}`">取消</UButton>
              <UButton icon="i-lucide-check" :loading="saving" @click="save">保存</UButton>
            </div>
          </div>
        </UCard>

        <!-- 右：头像裁剪面板（1:1） -->
        <UCard v-if="cropSrc" class="lg:sticky lg:top-4">
          <template #header>裁剪头像（1:1）</template>
          <div
            ref="cropViewport"
            class="relative mx-auto rounded-lg border border-(--ui-border) overflow-hidden bg-(--ui-bg-elevated) select-none touch-none"
            :style="{ width: CROP_VIEW + 'px', height: CROP_VIEW + 'px' }"
            @pointerdown="onCropDown"
            @pointermove="onCropMove"
            @pointerup="onCropUp"
            @pointercancel="onCropUp"
          >
            <img
              ref="cropImg"
              :src="cropSrc"
              class="absolute top-0 left-0 max-w-none cursor-move"
              :style="cropStyle"
              draggable="false"
              @load="initCrop"
            />
          </div>
          <div class="flex items-center gap-2 mt-4">
            <UIcon name="i-lucide-zoom-in" class="size-4 text-(--ui-muted) shrink-0" />
            <input
              v-model.number="cropZoom"
              type="range"
              min="1"
              max="4"
              step="0.01"
              class="flex-1 accent-(--ui-primary)"
              @input="clampOffset"
            />
            <UIcon name="i-lucide-zoom-out" class="size-4 text-(--ui-muted) shrink-0" />
          </div>
          <div class="flex justify-end gap-2 pt-4">
            <UButton variant="subtle" color="neutral" @click="closeCrop">取消</UButton>
            <UButton icon="i-lucide-check" @click="confirmCrop">确认</UButton>
          </div>
        </UCard>
      </div>
    </template>
  </div>
</template>
