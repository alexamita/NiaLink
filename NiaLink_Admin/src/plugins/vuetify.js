import 'vuetify/styles'
import '@mdi/font/css/materialdesignicons.css'
import { createVuetify } from 'vuetify'
import * as components from 'vuetify/components'
import * as directives from 'vuetify/directives'

export default createVuetify({
    components,
    directives,
    theme: {
        defaultTheme: 'light',
        themes: {
            light: {
                colors: {
                    primary: '#2B9D8F',
                    secondary: '#4DB6AC',
                    surface: '#FFFFFF',
                    background: '#EFF4F3',
                    success: '#22C55E',
                    warning: '#F59E0B',
                    error: '#EF4444',
                    info: '#3B82F6',
                }
            },
            dark: {
                colors: {
                    primary: '#2B9D8F',
                    secondary: '#4DB6AC',
                    surface: '#1E2A38',
                    background: '#131E2B',
                    success: '#22C55E',
                    warning: '#F59E0B',
                    error: '#EF4444',
                    info: '#3B82F6',
                }
            }
        }
    },
    defaults: {
        VCard: {
            elevation: 0,
        },
        VBtn: {
            style: 'text-transform: none; letter-spacing: 0; font-weight: 500;',
        },
        VChip: {
            style: 'font-weight: 600;',
        },
        VListItem: {
            style: 'font-size: 13px;',
        },
    }
})
