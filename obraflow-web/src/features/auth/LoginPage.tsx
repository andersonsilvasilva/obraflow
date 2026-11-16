import { zodResolver } from '@hookform/resolvers/zod'
import { HardHat, Loader2 } from 'lucide-react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { useLocation, useNavigate } from 'react-router-dom'
import { z } from 'zod'
import { Alert } from '@/components/feedback/Alert'
import { FormField, TextInput } from '@/components/forms/fields'
import { Button } from '@/components/ui/button'
import { ApiError, getErrorMessage } from '@/lib/api-error'
import { useAuth } from './auth-context'
import { authService } from './auth-service'

const schema = z.object({
  email: z.string().trim().min(1, 'Informe o e-mail.').email('E-mail inválido.'),
  password: z.string().min(1, 'Informe a senha.'),
})
type Form = z.infer<typeof schema>

/** Login por e-mail e senha (sessão por cookie). Recuperação de senha na mesma tela. */
export function LoginPage() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [error, setError] = useState<string | null>(null)
  const [forgotSent, setForgotSent] = useState<string | null>(null)
  const { register, handleSubmit, getValues, formState } = useForm<Form>({ resolver: zodResolver(schema) })

  const onSubmit = handleSubmit(async ({ email, password }) => {
    setError(null)
    try {
      const me = await login(email, password)
      const from = (location.state as { from?: string } | null)?.from
      navigate(me.active_tenant ? (from ?? '/') : '/organizacao', { replace: true })
    } catch (e) {
      setError(e instanceof ApiError && e.status === 429 ? 'Muitas tentativas. Aguarde um minuto e tente novamente.' : getErrorMessage(e))
    }
  })

  const forgot = async () => {
    const email = getValues('email')?.trim()
    if (!email) {
      setError('Informe o e-mail para recuperar a senha.')
      return
    }
    setError(null)
    try {
      setForgotSent((await authService.forgotPassword(email)).message)
    } catch (e) {
      setError(getErrorMessage(e))
    }
  }

  return (
    <main className="bg-background flex min-h-dvh items-center justify-center p-4">
      <div className="bg-card shadow-card w-full max-w-sm rounded-xl border p-6">
        <div className="mb-6 flex items-center gap-2">
          <span className="bg-primary text-primary-foreground rounded-lg p-1.5">
            <HardHat className="size-5" aria-hidden />
          </span>
          <span className="text-xl font-semibold tracking-tight">ObraFlow</span>
        </div>
        <h1 className="mb-1 text-lg font-semibold">Entrar</h1>
        <p className="text-muted-foreground mb-5 text-sm">Use seu e-mail e senha de acesso.</p>

        {error && <Alert tone="danger" className="mb-4">{error}</Alert>}
        {forgotSent && <Alert tone="success" className="mb-4">{forgotSent}</Alert>}

        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
          <FormField label="E-mail" required error={formState.errors.email?.message}>
            <TextInput type="email" autoComplete="username" autoFocus {...register('email')} />
          </FormField>
          <FormField label="Senha" required error={formState.errors.password?.message}>
            <TextInput type="password" autoComplete="current-password" {...register('password')} />
          </FormField>
          <Button type="submit" size="lg" disabled={formState.isSubmitting}>
            {formState.isSubmitting && <Loader2 className="size-4 animate-spin" aria-hidden />}
            Entrar
          </Button>
        </form>
        <button type="button" onClick={forgot} className="text-primary mt-4 text-sm hover:underline">
          Esqueci minha senha
        </button>
      </div>
    </main>
  )
}
