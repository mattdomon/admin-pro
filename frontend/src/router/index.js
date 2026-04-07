import Vue from 'vue'
import VueRouter from 'vue-router'
import Login from '@/views/Login.vue'
import Home from '@/views/Home.vue'
import Dashboard from '@/views/Dashboard.vue'
import OpenClaw from '@/views/OpenClaw.vue'
import OpenClawControl from '@/views/OpenClawControl.vue'

Vue.use(VueRouter)

const routes = [
  { path: '/login', name: 'Login', component: Login },
  { 
    path: '/', 
    name: 'Home', 
    component: Home,
    redirect: '/dashboard',
    children: [
      // 仪表盘
      { path: '/dashboard', name: 'Dashboard', component: Dashboard },
      { path: '/realtime', name: 'RealtimeDashboard', component: () => import('@/views/dashboard/Realtime.vue') },
      
      // OpenClaw 管理
      { path: '/openclaw', name: 'OpenClaw', component: OpenClaw },
      { path: '/openclaw-control', name: 'OpenClawControl', component: OpenClawControl },
      { path: '/openclaw/sessions', name: 'SessionManagement', component: () => import('@/views/openclaw/SessionManagement.vue') },
      { path: '/ai/models', name: 'ModelManagement', component: () => import('@/views/ai/Models.vue') },
      { path: '/ai/chat', name: 'AIChat', component: () => import('@/views/ai/Chat.vue') },
      
      // 任务管理
      { path: '/task/dispatch', name: 'TaskDispatcher', component: () => import('@/views/task/Dispatcher.vue') },
      { path: '/tasks/:taskId', name: 'TaskDetail', component: () => import('@/views/task/Detail.vue') },
      { path: '/scripts', name: 'ScriptManager', component: () => import('@/views/script/Manager.vue') },
      { path: '/batch', name: 'BatchTaskManager', component: () => import('@/views/batch/TaskManager.vue') },
      { path: '/terminal', name: 'LogConsole', component: () => import('@/views/terminal/LogConsole.vue') },
      
      // Agent 管理
      { path: '/agents', name: 'AgentManager', component: () => import('@/views/agent/Manager.vue') },

      // Bridge WebSocket 测试
      { path: '/bridge/test', name: 'BridgeTestPanel', component: () => import('@/views/bridge/TestPanel.vue') },

      // 系统管理
      { path: '/device', name: 'DeviceIndex', component: () => import('@/views/device/Index.vue') },
      { path: '/monitor', name: 'SystemMonitor', component: () => import('@/views/monitor/SystemMonitor.vue') },
      
      // 节点管理
      { path: '/nodes', name: 'NodesIndex', component: () => import('@/views/nodes/Index.vue') }
    ]
  }
]

const router = new VueRouter({
  mode: 'history',
  routes
})

router.beforeEach((to, from, next) => {
  const token = localStorage.getItem('token')
  if (to.path !== '/login' && !token) {
    next('/login')
  } else {
    next()
  }
})

export default router
