const BASE = import.meta.env.VITE_API_URL ?? '/api'

const h = () => ({
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    'Authorization': `Bearer ${localStorage.getItem('admin_token') ?? ''}`,
})

const get = (path) => fetch(`${BASE}${path}`, { headers: h() }).then(r => r.json())
const post = (path, body) => fetch(`${BASE}${path}`, {
    method: 'POST',
    headers: h(),
    ...(body ? { body: JSON.stringify(body) } : {}),
}).then(r => r.json())

export const api = {
    login: (email, password) => post('/admin/login', { email, password }),
    logout: () => post('/admin/logout'),
    stats: () => get('/admin/stats'),
    merchants: (params = {}) => get(`/admin/merchants?${new URLSearchParams(params)}`),
    transactions: (params = {}) => get(`/admin/transactions?${new URLSearchParams(params)}`),
    audit: () => get('/admin/audit'),
    wallets: () => get('/admin/wallets'),
    terminals: () => get('/admin/terminals'),
    approveMerchant: (id) => post(`/admin/merchants/${id}/approve`),
    suspendMerchant: (id) => post(`/admin/merchants/${id}/suspend`),
    lockTerminal: (id) => post(`/admin/terminals/${id}/lock`),
    unlockTerminal: (id) => post(`/admin/terminals/${id}/unlock`),
}
