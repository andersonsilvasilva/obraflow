import path from 'node:path'
import { defineConfig } from 'vitest/config'

// Config de testes isolada: sem plugins de build (React/Tailwind) para testes de lógica pura.
export default defineConfig({
  resolve: { alias: { '@': path.resolve(import.meta.dirname, './src') } },
  test: { environment: 'node', include: ['src/**/*.test.{ts,tsx}'] },
})
