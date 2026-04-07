/**
 * 前端日志管理器
 * 统一管理前端日志，支持不同级别和模块
 */

class Logger {
  constructor() {
    this.enabled = process.env.NODE_ENV === 'development'
    this.logBuffer = []
    this.maxBufferSize = 1000
  }
  
  /**
   * 信息日志
   */
  info(module, message, ...args) {
    this.log('INFO', module, message, ...args)
  }
  
  /**
   * 警告日志
   */
  warn(module, message, ...args) {
    this.log('WARN', module, message, ...args)
  }
  
  /**
   * 错误日志
   */
  error(module, message, ...args) {
    this.log('ERROR', module, message, ...args)
  }
  
  /**
   * 调试日志
   */
  debug(module, message, ...args) {
    if (this.enabled) {
      this.log('DEBUG', module, message, ...args)
    }
  }
  
  /**
   * 记录日志
   */
  log(level, module, message, ...args) {
    const timestamp = new Date().toISOString()
    const logEntry = {
      timestamp,
      level,
      module,
      message,
      args: args.length > 0 ? args : undefined,
      url: window.location.href,
      userAgent: navigator.userAgent
    }
    
    // 添加到缓冲区
    this.addToBuffer(logEntry)
    
    // 根据级别选择输出方式
    if (this.enabled) {
      const consoleMethod = this.getConsoleMethod(level)
      const prefix = `[${timestamp}] [${level}] [${module}]`
      
      if (args.length > 0) {
        consoleMethod(prefix, message, ...args)
      } else {
        consoleMethod(prefix, message)
      }
    }
    
    // 错误级别的日志发送到后端
    if (level === 'ERROR') {
      this.sendToBackend(logEntry)
    }
  }
  
  /**
   * 获取对应的console方法
   */
  getConsoleMethod(level) {
    switch (level) {
      case 'ERROR':
        return console.error
      case 'WARN':
        return console.warn
      case 'DEBUG':
        return console.debug
      default:
        return console.log
    }
  }
  
  /**
   * 添加到缓冲区
   */
  addToBuffer(logEntry) {
    this.logBuffer.push(logEntry)
    
    // 保持缓冲区大小
    if (this.logBuffer.length > this.maxBufferSize) {
      this.logBuffer.shift()
    }
  }
  
  /**
   * 发送错误日志到后端
   */
  async sendToBackend(logEntry) {
    try {
      // 这里可以发送到后端日志接口
      // await axios.post('/api/logs/frontend', logEntry)
      
      // 暂时只在生产环境发送
      if (process.env.NODE_ENV === 'production') {
        // 实现发送逻辑
      }
    } catch (e) {
      // 发送失败时静默处理，避免循环错误
    }
  }
  
  /**
   * 获取日志缓冲区
   */
  getBuffer() {
    return [...this.logBuffer]
  }
  
  /**
   * 清空日志缓冲区
   */
  clearBuffer() {
    this.logBuffer = []
  }
  
  /**
   * 启用/禁用日志
   */
  setEnabled(enabled) {
    this.enabled = enabled
  }
}

// 创建全局日志实例
const logger = new Logger()

// Vue插件
const LoggerPlugin = {
  install(Vue) {
    // 添加到Vue原型
    Vue.prototype.$log = logger
    
    // 添加快捷方法
    Vue.prototype.$logInfo = (module, message, ...args) => logger.info(module, message, ...args)
    Vue.prototype.$logWarn = (module, message, ...args) => logger.warn(module, message, ...args)
    Vue.prototype.$logError = (module, message, ...args) => logger.error(module, message, ...args)
    Vue.prototype.$logDebug = (module, message, ...args) => logger.debug(module, message, ...args)
  }
}

export default LoggerPlugin
export { logger }