import Vue from 'vue'
import App from './App.vue'
import router from './router'
import ElementUI from 'element-ui'
import 'element-ui/lib/theme-chalk/index.css'
import Vuex from 'vuex'
import { nodes } from './store/modules/nodes'
import realtime from './store/modules/realtime'
import axios from 'axios'
import TaskNotificationPlugin from './utils/taskNotifier'
import LoggerPlugin from './utils/logger'

// 配置 axios
Vue.prototype.$http = axios
Vue.prototype.$axios = axios
axios.defaults.timeout = 30000
axios.defaults.baseURL = ''

Vue.use(ElementUI)
Vue.use(Vuex)
Vue.use(TaskNotificationPlugin)
Vue.use(LoggerPlugin)
Vue.config.productionTip = false

const store = new Vuex.Store({
  modules: { 
    nodes,
    realtime
  }
})

new Vue({
  router,
  store,
  render: h => h(App)
}).$mount('#app')
