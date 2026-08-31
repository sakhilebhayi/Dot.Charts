import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
  server: {
    port: 3000,
    open: true
  },
  build: {
    outDir: 'dist',
    assetsDir: 'assets',
    minify: 'esbuild',
    rollupOptions: {
      input: {
        main: resolve(__dirname, 'index.html'),
        backtest: resolve(__dirname, 'backtest.html'),
        login: resolve(__dirname, 'login.html'),
        register: resolve(__dirname, 'register.html'),
        history: resolve(__dirname, 'history.html'),
        strategyBuilder: resolve(__dirname, 'strategy-builder.html'),
        journal: resolve(__dirname, 'journal.html'),
        options: resolve(__dirname, 'options.html'),
      },
    },
  },
});
