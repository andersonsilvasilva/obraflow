import type { Group } from './api'

/** Etapas em ordem de árvore com profundidade. */
export function orderedGroups(groups: Group[]): { group: Group; depth: number }[] {
  const out: { group: Group; depth: number }[] = []
  const walk = (parent: number | null, depth: number) =>
    groups
      .filter((g) => g.parent_id === parent)
      .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id)
      .forEach((g) => {
        out.push({ group: g, depth })
        walk(g.id, depth + 1)
      })
  walk(null, 0)
  return out
}
