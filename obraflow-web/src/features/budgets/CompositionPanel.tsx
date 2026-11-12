import { StatusBadge } from '@/components/data-display/indicators'
import { Modal } from '@/components/feedback/Modal'
import type { Composition } from '@/types'
import { formatCurrency, formatDecimal } from '@/utils/format'
import { compositionUnitCost, resourceTypeLabel } from './calc'

/** Painel lateral com o detalhamento de uma composição de custos. */
export function CostComposition({ composition, onClose }: { composition: Composition | null; onClose: () => void }) {
  return (
    <Modal
      open={composition !== null}
      onClose={onClose}
      variant="side"
      title={composition ? `Composição ${composition.code}` : 'Composição'}
      description={composition?.description}
    >
      {composition && (
        <div className="flex flex-col gap-4">
          <dl className="grid grid-cols-2 gap-3 text-sm">
            <div><dt className="text-muted-foreground text-xs">Código</dt><dd className="font-medium">{composition.code}</dd></div>
            <div><dt className="text-muted-foreground text-xs">Unidade</dt><dd className="font-medium">{composition.unit}</dd></div>
          </dl>
          <p className="bg-primary-soft text-primary-deep rounded-lg px-3 py-2 text-xs" role="note">
            Composição demonstrativa — coeficientes e preços fictícios, sem origem oficial.
          </p>
          <div className="overflow-x-auto rounded-lg border">
            <table className="w-full min-w-max text-sm">
              <caption className="sr-only">Insumos da composição {composition.code}</caption>
              <thead>
                <tr className="bg-muted/60 text-muted-foreground text-xs uppercase">
                  <th scope="col" className="px-3 py-2 text-left font-medium">Insumo</th>
                  <th scope="col" className="px-3 py-2 text-left font-medium">Tipo</th>
                  <th scope="col" className="px-3 py-2 text-left font-medium">Un.</th>
                  <th scope="col" className="px-3 py-2 text-right font-medium">Coef.</th>
                  <th scope="col" className="px-3 py-2 text-right font-medium">Preço un.</th>
                  <th scope="col" className="px-3 py-2 text-right font-medium">Custo</th>
                </tr>
              </thead>
              <tbody>
                {composition.inputs.map((i) => (
                  <tr key={i.id} className="border-t">
                    <td className="px-3 py-2">{i.description}</td>
                    <td className="px-3 py-2"><StatusBadge tone="primary">{resourceTypeLabel[i.type]}</StatusBadge></td>
                    <td className="px-3 py-2">{i.unit}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{formatDecimal(i.coefficient)}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{formatCurrency(i.unitPrice)}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{formatCurrency(i.coefficient * i.unitPrice)}</td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr className="bg-primary-soft border-t font-semibold">
                  <td colSpan={5} className="px-3 py-2 text-right">Custo total por {composition.unit}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{formatCurrency(compositionUnitCost(composition))}</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      )}
    </Modal>
  )
}
