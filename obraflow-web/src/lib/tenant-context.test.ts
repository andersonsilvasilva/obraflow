import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from './api-error'
import { http } from './http'
import {
  getActiveTenant,
  initTenantSync,
  onTenantMismatch,
  onTenantStale,
  resetTenantContextForTests,
  setActiveTenant,
} from './tenant-context'

const tick = () => new Promise((resolve) => setTimeout(resolve, 30))

function mockFetch(response: Response) {
  const spy = vi.fn().mockResolvedValue(response)
  vi.stubGlobal('fetch', spy)
  return spy
}

function sentHeaders(spy: ReturnType<typeof vi.fn>): Record<string, string> {
  return (spy.mock.calls[0][1] as RequestInit).headers as Record<string, string>
}

beforeEach(() => resetTenantContextForTests())
afterEach(() => {
  resetTenantContextForTests()
  vi.unstubAllGlobals()
})

describe('X-Tenant-Hint (não autoritativo)', () => {
  it('é enviado quando há tenant exibido', async () => {
    const spy = mockFetch(Response.json({ ok: true }))
    setActiveTenant('tenant-a')

    await http('/tenant')

    expect(sentHeaders(spy)['X-Tenant-Hint']).toBe('tenant-a')
  })

  it('não é enviado sem tenant (o servidor não depende dele)', async () => {
    const spy = mockFetch(Response.json({ ok: true }))

    await http('/auth/me')

    expect(sentHeaders(spy)).not.toHaveProperty('X-Tenant-Hint')
  })

  it('409 tenant_mismatch avisa a interface e propaga o erro (a operação NÃO foi executada)', async () => {
    mockFetch(Response.json({ error: { code: 'tenant_mismatch', message: 'recarregue' } }, { status: 409 }))
    const onMismatch = vi.fn()
    onTenantMismatch(onMismatch)
    setActiveTenant('tenant-a')

    await expect(http('/tenant/settings/x', { method: 'PUT', body: { value: 1 } })).rejects.toBeInstanceOf(ApiError)

    expect(onMismatch).toHaveBeenCalledTimes(1)
  })

  it('outros erros 409 NÃO disparam o aviso de tenant', async () => {
    mockFetch(Response.json({ error: { code: 'last_admin', message: 'x' } }, { status: 409 }))
    const onMismatch = vi.fn()
    onTenantMismatch(onMismatch)

    await expect(http('/members/1/suspend', { method: 'POST' })).rejects.toBeInstanceOf(ApiError)

    expect(onMismatch).not.toHaveBeenCalled()
  })
})

describe('sincronização entre abas (BroadcastChannel)', () => {
  it('avisa esta aba quando OUTRA aba troca para um tenant diferente', async () => {
    const stale = vi.fn()
    setActiveTenant('tenant-a')
    onTenantStale(stale)
    initTenantSync()

    const outraAba = new BroadcastChannel('obraflow:tenant')
    outraAba.postMessage({ type: 'tenant-changed', uuid: 'tenant-b' })
    await tick()
    outraAba.close()

    expect(stale).toHaveBeenCalledWith({ from: 'tenant-a', to: 'tenant-b' })
    expect(getActiveTenant()).toBe('tenant-a') // a aba NÃO muda sozinha: exige recarga
  })

  it('ignora a mesma organização e mensagens de outro tipo', async () => {
    const stale = vi.fn()
    setActiveTenant('tenant-a')
    onTenantStale(stale)
    initTenantSync()

    const outraAba = new BroadcastChannel('obraflow:tenant')
    outraAba.postMessage({ type: 'tenant-changed', uuid: 'tenant-a' })
    outraAba.postMessage({ type: 'outra-coisa', uuid: 'tenant-b' })
    await tick()
    outraAba.close()

    expect(stale).not.toHaveBeenCalled()
  })

  it('trocar o tenant nesta aba notifica as outras, mas não a si mesma', async () => {
    const stale = vi.fn()
    onTenantStale(stale)
    initTenantSync()

    const recebido: unknown[] = []
    const outraAba = new BroadcastChannel('obraflow:tenant')
    outraAba.onmessage = (event) => recebido.push(event.data)

    setActiveTenant('tenant-b')
    await tick()
    outraAba.close()

    expect(recebido).toEqual([{ type: 'tenant-changed', uuid: 'tenant-b' }])
    expect(stale).not.toHaveBeenCalled()
  })
})
