/**
 * Erro padronizado da API ObraFlow.
 * Espelha o formato do backend: { error: { code, message, details?, request_id } }
 */
export class ApiError extends Error {
  readonly status: number
  readonly code: string
  readonly details?: Record<string, string[]>
  readonly requestId?: string

  constructor(init: {
    status: number
    code: string
    message: string
    details?: Record<string, string[]>
    requestId?: string
  }) {
    super(init.message)
    this.name = 'ApiError'
    this.status = init.status
    this.code = init.code
    this.details = init.details
    this.requestId = init.requestId
  }

  get isValidation() {
    return this.status === 422
  }
  get isUnauthenticated() {
    return this.status === 401
  }
  get isForbidden() {
    return this.status === 403
  }
}

interface ApiErrorBody {
  error?: {
    code?: string
    message?: string
    details?: Record<string, string[]>
    request_id?: string
  }
}

export async function parseApiError(response: Response): Promise<ApiError> {
  let body: ApiErrorBody = {}
  try {
    body = (await response.json()) as ApiErrorBody
  } catch {
    // corpo não é JSON
  }
  return new ApiError({
    status: response.status,
    code: body.error?.code ?? 'http_error',
    message: body.error?.message ?? 'Não foi possível concluir a operação.',
    details: body.error?.details,
    requestId: body.error?.request_id,
  })
}

/** Converte qualquer erro em mensagem amigável para o usuário. */
export function getErrorMessage(error: unknown): string {
  if (error instanceof ApiError) return error.message
  if (error instanceof TypeError) return 'Falha de conexão com o servidor.'
  if (error instanceof Error) return error.message
  return 'Ocorreu um erro inesperado.'
}
