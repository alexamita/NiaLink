import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api.js'

export const useAuthStore = defineStore('auth', () => {
    const token = ref(localStorage.getItem('admin_token') ?? null)
    const user = ref(JSON.parse(localStorage.getItem('admin_user') ?? 'null'))

    const isLoggedIn = computed(() => !!token.value)

    async function login(email, password) {
        const data = await api.login(email, password)
        if (!data?.access_token) throw new Error(data?.message ?? 'Login failed')
        token.value = data.access_token
        user.value = data.user ?? null
        localStorage.setItem('admin_token', data.access_token)
        localStorage.setItem('admin_user', JSON.stringify(data.user ?? null))
    }

    async function logout() {
        try {
            await api.logout()
        } catch { /* token may already be invalid — proceed */ }
        token.value = null
        user.value = null
        localStorage.removeItem('admin_token')
        localStorage.removeItem('admin_user')
    }

    return { token, user, isLoggedIn, login, logout }
})
