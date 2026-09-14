import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { createAppRouter } from './router'
import './styles/tokens.css'
import './styles/app.css'
import './styles/resources.css'
import App from './App.vue'

const pinia = createPinia()
const router = createAppRouter(pinia)
const app = createApp(App).use(pinia).use(router)
void router.isReady().then(() => app.mount('#app'))
