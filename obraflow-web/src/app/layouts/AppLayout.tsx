import { useEffect, useState } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { ErrorBoundary } from '@/components/feedback/ErrorBoundary'
import { TenantSyncBanner } from '@/components/feedback/TenantSyncBanner'
import { Sidebar } from '@/components/layout/Sidebar'
import { Topbar } from '@/components/layout/Topbar'
import { cn } from '@/lib/utils'

const STORAGE_KEY = 'obraflow:sidebar-collapsed'

function readCollapsed(): boolean {
  try {
    return localStorage.getItem(STORAGE_KEY) === '1'
  } catch {
    return false
  }
}

export function AppLayout() {
  const [collapsed, setCollapsed] = useState(readCollapsed)
  const [drawerOpen, setDrawerOpen] = useState(false)
  const { pathname } = useLocation()

  const toggle = () =>
    setCollapsed((c) => {
      try {
        localStorage.setItem(STORAGE_KEY, c ? '0' : '1')
      } catch {
        // armazenamento indisponível: preferência não é persistida
      }
      return !c
    })

  useEffect(() => {
    window.scrollTo(0, 0)
  }, [pathname])

  return (
    <div className="flex h-dvh overflow-hidden">
      <a href="#conteudo" className="bg-primary text-primary-foreground sr-only z-[200] rounded px-3 py-2 focus:not-sr-only focus:fixed focus:top-2 focus:left-2">
        Ir para o conteúdo
      </a>

      {/* Desktop */}
      <aside
        className={cn('hidden shrink-0 transition-[width] duration-200 lg:block', collapsed ? 'w-(--sidebar-width-collapsed)' : 'w-(--sidebar-width)')}
      >
        <Sidebar collapsed={collapsed} onToggle={toggle} />
      </aside>

      {/* Mobile / tablet: gaveta */}
      {drawerOpen && (
        <div className="fixed inset-0 z-50 lg:hidden">
          <button type="button" aria-label="Fechar menu" className="absolute inset-0 bg-black/40" onClick={() => setDrawerOpen(false)} />
          <aside className="absolute inset-y-0 left-0 w-(--sidebar-width) shadow-overlay">
            <Sidebar collapsed={false} onToggle={() => setDrawerOpen(false)} onNavigate={() => setDrawerOpen(false)} />
          </aside>
        </div>
      )}

      <div className="flex min-w-0 flex-1 flex-col">
        <TenantSyncBanner />
        <Topbar onOpenMenu={() => setDrawerOpen(true)} />
        <main id="conteudo" tabIndex={-1} className="flex-1 overflow-y-auto p-4 sm:p-6">
          <div className="mx-auto max-w-[1400px]">
            <ErrorBoundary key={pathname}>
              <Outlet />
            </ErrorBoundary>
          </div>
        </main>
      </div>
    </div>
  )
}
