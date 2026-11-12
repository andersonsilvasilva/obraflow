import { z } from 'zod'

const schema = z.object({
  VITE_API_URL: z.string().url().default('http://localhost:8000/api/v1'),
  VITE_APP_NAME: z.string().default('ObraFlow'),
  VITE_USE_MOCKS: z
    .enum(['true', 'false'])
    .default('true')
    .transform((v) => v === 'true'),
})

const parsed = schema.safeParse(import.meta.env)

if (!parsed.success) {
  throw new Error(
    `Variáveis de ambiente inválidas: ${parsed.error.issues.map((i) => i.path.join('.')).join(', ')}`,
  )
}

export const env = {
  apiUrl: parsed.data.VITE_API_URL,
  appName: parsed.data.VITE_APP_NAME,
  useMocks: parsed.data.VITE_USE_MOCKS,
} as const
