import { ref } from 'vue'

const visible = ref(false)
const text = ref('')
const subtext = ref('')
const color = ref('success')

export function useNotify() {
    function notify(msg, opts = {}) {
        text.value = msg
        subtext.value = opts.subtitle ?? ''
        color.value = opts.color ?? 'success'
        visible.value = true
    }
    function dismiss() {
        visible.value = false
    }
    return { visible, text, subtext, color, notify, dismiss }
}
