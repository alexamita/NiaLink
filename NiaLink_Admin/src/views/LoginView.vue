<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth.js'

const router = useRouter()
const auth = useAuthStore()

const email = ref('')
const password = ref('')
const showPassword = ref(false)
const loading = ref(false)
const error = ref('')

async function submit() {
    error.value = ''
    if (!email.value || !password.value) {
        error.value = 'Please enter your email and password.'
        return
    }
    loading.value = true
    try {
        await auth.login(email.value, password.value)
        router.replace('/')
    } catch (e) {
        error.value = e.message ?? 'Login failed. Please try again.'
    } finally {
        loading.value = false
    }
}
</script>

<template>
    <div class="login-page">

        <!-- Card -->
        <v-card class="login-card" rounded="xl" elevation="0">

            <!-- Logo + heading -->
            <div class="login-header">
                <div class="login-mark-wrap">
                    <div class="login-mark">
                        <v-icon size="22" color="white">mdi-link-variant</v-icon>
                    </div>
                </div>
                <div class="login-wordmark">
                    <span class="login-nia">Nia</span><span class="login-link">Link</span>
                </div>
                <div class="login-title">Admin Console</div>
                <div class="login-subtitle">Sign in to manage the platform</div>
            </div>

            <v-divider class="my-5" />

            <!-- Error alert -->
            <v-alert
                v-if="error"
                type="error"
                variant="tonal"
                density="compact"
                rounded="lg"
                class="mb-4"
                closable
                @click:close="error = ''"
            >
                {{ error }}
            </v-alert>

            <!-- Form -->
            <form @submit.prevent="submit">
                <div class="text-caption font-weight-medium mb-1" style="color:rgba(0,0,0,0.6)">
                    Email address
                </div>
                <v-text-field
                    v-model="email"
                    type="email"
                    placeholder="admin@nialink.co.ke"
                    variant="outlined"
                    density="compact"
                    rounded="lg"
                    hide-details="auto"
                    autocomplete="email"
                    prepend-inner-icon="mdi-email-outline"
                    class="mb-4"
                    :disabled="loading"
                />

                <div class="d-flex align-center justify-space-between mb-1">
                    <div class="text-caption font-weight-medium" style="color:rgba(0,0,0,0.6)">
                        Password
                    </div>
                </div>
                <v-text-field
                    v-model="password"
                    :type="showPassword ? 'text' : 'password'"
                    placeholder="••••••••"
                    variant="outlined"
                    density="compact"
                    rounded="lg"
                    hide-details="auto"
                    autocomplete="current-password"
                    prepend-inner-icon="mdi-lock-outline"
                    :append-inner-icon="showPassword ? 'mdi-eye-off-outline' : 'mdi-eye-outline'"
                    class="mb-5"
                    :disabled="loading"
                    @click:append-inner="showPassword = !showPassword"
                />

                <v-btn
                    type="submit"
                    color="primary"
                    variant="flat"
                    size="large"
                    rounded="lg"
                    block
                    :loading="loading"
                >
                    Sign in
                </v-btn>
            </form>

        </v-card>

        <!-- Footer note -->
        <div class="text-caption text-medium-emphasis mt-5 text-center">
            Restricted access — authorised personnel only
        </div>

    </div>
</template>

<style scoped>
.login-page {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: #f5f6f8;
    padding: 24px;
}

.login-card {
    width: 100%;
    max-width: 400px;
    padding: 32px;
    border: 1px solid rgba(0, 0, 0, 0.08);
    background: #fff;
}

.login-header {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0;
    padding-bottom: 2px;
}

.login-mark-wrap {
    margin-bottom: 14px;
}

.login-mark {
    width: 52px;
    height: 52px;
    border-radius: 16px;
    background: #2B9D8F;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 16px rgba(43, 157, 143, 0.35), inset 0 1px 0 rgba(255,255,255,0.18);
}

.login-wordmark {
    font-size: 24px;
    font-weight: 800;
    letter-spacing: -0.04em;
    line-height: 1;
    margin-bottom: 6px;
}

.login-nia {
    color: rgba(0, 0, 0, 0.85);
}

.login-link {
    color: #2B9D8F;
}

.login-title {
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: rgba(0, 0, 0, 0.38);
    margin-bottom: 4px;
}

.login-subtitle {
    font-size: 13px;
    color: rgba(0, 0, 0, 0.45);
}
</style>
