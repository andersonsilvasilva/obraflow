/**
 * Contexto de tenant NO NAVEGADOR (D-P01).
 *
 * A AUTORIDADE sobre o tenant ativo é sempre o servidor (sessão). Aqui só guardamos "o tenant que ESTA ABA está
 * exibindo", para dois usos:
 *  1. `X-Tenant-Hint`: dica NÃO autoritativa enviada em cada requisição; se divergir do tenant da sessão o servidor
 *     responde 409 `tenant_mismatch` SEM executar a operação (proteção contra aba desatualizada);
 *  2. `BroadcastChannel`: quando OUTRA aba troca de tenant, esta aba é avisada e pede recarga.
 *
 * O valor fica em sessionStorage (por aba). Falhas de armazenamento nunca quebram a aplicação.
 */

const STORAGE_KEY = 'obraflow:active-tenant'
const CHANNEL_NAME = 'obraflow:tenant'

export const TENANT_HINT_HEADER = 'X-Tenant-Hint'

interface TenantChangedMessage {
  type: 'tenant-changed'
  uuid: string | null
}

type StaleListener = (event: { from: string | null; to: string | null }) => void
type MismatchListener = () => void

let current: string | null = readStored()
let channel: BroadcastChannel | null = null
const staleListeners = new Set<StaleListener>()
const mismatchListeners = new Set<MismatchListener>()

function readStored(): string | null {
  try {
    return sessionStorage.getItem(STORAGE_KEY)
  } catch {
    return null
  }
}

function writeStored(uuid: string | null): void {
  try {
    if (uuid) sessionStorage.setItem(STORAGE_KEY, uuid)
    else sessionStorage.removeItem(STORAGE_KEY)
  } catch {
    // armazenamento indisponível (modo privado etc.): o hint passa a valer só em memória
  }
}

/** Tenant que esta aba está exibindo (ou null). */
export function getActiveTenant(): string | null {
  return current
}

/** Registra o tenant exibido (chame após selecionar/trocar) e avisa as OUTRAS abas. */
export function setActiveTenant(uuid: string | null): void {
  current = uuid
  writeStored(uuid)
  channel?.postMessage({ type: 'tenant-changed', uuid } satisfies TenantChangedMessage)
}

/** Chamado quando OUTRA aba troca o tenant e esta ficou desatualizada. Devolve a função de cancelamento. */
export function onTenantStale(listener: StaleListener): () => void {
  staleListeners.add(listener)
  return () => staleListeners.delete(listener)
}

/** Chamado quando o servidor responde 409 `tenant_mismatch` (a aba exibe um tenant que não é o da sessão). */
export function onTenantMismatch(listener: MismatchListener): () => void {
  mismatchListeners.add(listener)
  return () => mismatchListeners.delete(listener)
}

export function notifyTenantMismatch(): void {
  mismatchListeners.forEach((listener) => listener())
}

/** Abre o canal entre abas. Idempotente. Devolve a função que fecha o canal. */
export function initTenantSync(): () => void {
  if (typeof BroadcastChannel === 'undefined') return () => undefined

  if (!channel) {
    channel = new BroadcastChannel(CHANNEL_NAME)
    channel.onmessage = (event: MessageEvent<TenantChangedMessage>) => {
      const message = event.data
      if (message?.type !== 'tenant-changed') return
      if (message.uuid === current) return // mesma organização: nada a fazer

      staleListeners.forEach((listener) => listener({ from: current, to: message.uuid }))
    }
  }

  return closeTenantSync
}

export function closeTenantSync(): void {
  channel?.close()
  channel = null
}

/** Somente para testes. */
export function resetTenantContextForTests(): void {
  closeTenantSync()
  current = null
  staleListeners.clear()
  mismatchListeners.clear()
}
