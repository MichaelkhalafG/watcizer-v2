module.exports = {
  apps: [{
    name: 'brandfashion-next',
    script: 'node_modules/.bin/next',
    args: 'start',
    cwd: '/home/u591083448/watchizer/Frontend-brandfashion',
    instances: 2,
    exec_mode: 'cluster',
    max_memory_restart: '1G',
    env: {
      NODE_ENV: 'production',
      PORT: 3001,
      NODE_OPTIONS: '--max-old-space-size=2048',
    },
  }],
}
