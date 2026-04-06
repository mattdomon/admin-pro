<template>
  <div class="login-container">
    <el-card class="login-card">
      <h2>Admin Pro 登录</h2>
      <el-form :model="form" @submit.native.prevent="handleLogin">
        <el-form-item>
          <el-input v-model="form.username" placeholder="用户名: admin" />
        </el-form-item>
        <el-form-item>
          <el-input v-model="form.password" type="password" placeholder="密码: admin123" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" native-type="submit" :loading="loading" style="width:100%">
            登录
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<script>
import { login } from '@/api/auth'

export default {
  name: 'Login',
  data() {
    return {
      form: { username: 'admin', password: 'admin123' },
      loading: false
    }
  },
  methods: {
    async handleLogin() {
      this.loading = true
      try {
        const res = await login(this.form)
        localStorage.setItem('token', res.data.token)
        this.$router.push('/')
      } catch (e) {
        // error handled by interceptor
      } finally {
        this.loading = false
      }
    }
  }
}
</script>

<style scoped>
.login-container {
  display: flex;
  justify-content: center;
  align-items: center;
  height: 100vh;
  background: #2d3a4b;
}
.login-card {
  width: 400px;
}
.login-card h2 {
  text-align: center;
  color: #fff;
  margin-bottom: 20px;
}
</style>
