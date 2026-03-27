import { createRouter, createWebHistory } from 'vue-router'

const placeholder = (title) => ({
    template: `
        <div class="d-flex flex-column align-center justify-center" style="min-height:40vh;opacity:0.5">
            <v-icon size="48" color="primary" class="mb-3">mdi-tools</v-icon>
            <div class="text-h6 font-weight-bold">${title}</div>
            <div class="text-caption text-medium-emphasis mt-1">Coming soon</div>
        </div>
    `,
})

const routes = [
    { path: '/login',        name: 'login',        component: () => import('../views/LoginView.vue'),        meta: { public: true } },
    { path: '/',              component: () => import('../views/OverviewView.vue') },
    { path: '/merchants',    component: () => import('../views/MerchantsView.vue') },
    { path: '/transactions', component: () => import('../views/TransactionsView.vue') },
    { path: '/audit',        component: () => import('../views/AuditView.vue') },
    { path: '/liquidity',    component: () => import('../views/LiquidityView.vue') },
    { path: '/terminals',    component: () => import('../views/TerminalsView.vue') },
    { path: '/payments',     component: placeholder('Payments') },
    { path: '/billing',      component: placeholder('Billing') },
    { path: '/settings',     component: placeholder('Settings') },
]

const router = createRouter({
    history: createWebHistory(),
    routes,
})

router.beforeEach((to) => {
    const token = localStorage.getItem('admin_token')
    if (!to.meta.public && !token) return { name: 'login' }
    if (to.name === 'login' && token) return { path: '/' }
})

export default router
