import { RefreshCw } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Button } from '@/components/ui/button'
import { initTenantSync, onTenantMismatch, onTenantStale } from '@/lib/tenant-context'

/**
 * Avisa quando a organização ativa mudou em OUTRA aba (BroadcastChannel) ou quando o servidor recusou uma
 * operação por `tenant_mismatch`. Bloqueia a continuidade até recarregar: evita agir sobre o tenant errado.
 */
export function TenantSyncBanner() {
  const [stale, setStale] = useState(false)

  useEffect(() => {
    const closeChannel = initTenantSync()
    const offStale = onTenantStale(() => setStale(true))
    const offMismatch = onTenantMismatch(() => setStale(true))

    return () => {
      offStale()
      offMismatch()
      closeChannel()
    }
  }, [])

  if (!stale) return null

  return (
    <div role="alert" className="bg-warning-soft text-warning-strong border-warning/30 flex flex-wrap items-center justify-between gap-3 border-b px-4 py-2 text-sm">
      <p>
        <strong>A organização ativa foi alterada em outra aba.</strong> Recarregue a página para continuar com a organização correta —
        nenhuma operação desta aba foi executada.
      </p>
      <Button size="sm" onClick={() => window.location.reload()}>
        <RefreshCw className="size-4" /> Recarregar
      </Button>
    </div>
  )
}
