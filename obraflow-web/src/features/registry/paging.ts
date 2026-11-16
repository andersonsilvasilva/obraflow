import type { Page } from './api'
import type { ListParams } from './components/ResourcePage'

/** Adapta um endpoint que devolve a lista inteira (catálogos) para busca/paginação no navegador. */
export function clientPaged<T>(load: () => Promise<T[]>, text: (row: T) => string, filter?: (row: T, params: ListParams) => boolean) {
  return async (params: ListParams): Promise<Page<T>> => {
    const term = params.q.toLowerCase()
    const all = (await load()).filter((r) => (!term || text(r).toLowerCase().includes(term)) && (!filter || filter(r, params)))
    const lastPage = Math.max(1, Math.ceil(all.length / params.per_page))
    const page = Math.min(params.page, lastPage)
    return {
      data: all.slice((page - 1) * params.per_page, page * params.per_page),
      meta: { current_page: page, last_page: lastPage, total: all.length },
    }
  }
}
