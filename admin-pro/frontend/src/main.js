import Vue from 'vue'
import App from './App.vue'
import router from './router'
import ElementUI from 'element-ui'
import 'element-ui/lib/theme-chalk/index.css'
import Vuex from 'vuex'
import { nodes } from './store/modules/nodes'
import realtime from './store/modules/realtime'

Vue.use(ElementUI)
Vue.use(Vuex)
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
