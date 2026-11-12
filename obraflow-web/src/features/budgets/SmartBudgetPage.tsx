import { AlertTriangle, Check, Lightbulb, Sparkles, X } from 'lucide-react'
import { useState } from 'react'
import { Panel } from '@/components/data-display/DataTable'
import { StatusBadge } from '@/components/data-display/indicators'
import { FormField, TextArea } from '@/components/forms/fields'
import { PageHeader } from '@/components/layout/PageHeader'
import { Button } from '@/components/ui/button'

type Decision = 'pending' | 'accepted' | 'rejected'

// Conteúdo ILUSTRATIVO e fixo. Nenhuma IA é consultada nesta etapa.
const sample = {
  suggestions: [
    { id: 's1', title: 'Fundação em radier — serviço sugerido', detail: 'Incluir serviço de concreto estrutural e armação (itens fictícios).' },
    { id: 's2', title: 'Impermeabilização de áreas molhadas', detail: 'Etapa comum em obras residenciais; verificar necessidade no projeto.' },
    { id: 's3', title: 'Canteiro de obras e mobilização', detail: 'Considerar custos de instalação e administração local.' },
  ],
  inconsistencies: [
    'Há serviços de acabamento sem a etapa de instalações elétricas correspondente.',
    'Quantidade de alvenaria parece incompatível com a área informada (exemplo ilustrativo).',
  ],
  recommendations: [
    'Revisar o BDI conforme a política da empresa.',
    'Validar preços com a base de referência oficial antes de aprovar.',
    'Solicitar ao menos três cotações para os materiais de maior impacto (Curva ABC).',
  ],
}

export function SmartBudgetPage() {
  const [description, setDescription] = useState('')
  const [technical, setTechnical] = useState('')
  const [standard, setStandard] = useState<'Baixo' | 'Médio' | 'Alto'>('Médio')
  const [generated, setGenerated] = useState(false)
  const [decisions, setDecisions] = useState<Record<string, Decision>>({})

  return (
    <>
      <PageHeader title="Orçamento inteligente" description="Assistente para apoiar a elaboração e a análise de orçamentos." />

      <div role="note" className="bg-warning-soft text-warning-strong mb-4 flex items-start gap-2 rounded-lg px-3 py-2 text-sm">
        <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
        <p>
          <strong>Demonstração visual.</strong> Nenhuma IA foi consultada: as sugestões abaixo são exemplos fixos. Em produção, toda sugestão exigirá revisão e aprovação humana e não validará custos oficiais automaticamente.
        </p>
      </div>

      <div className="grid gap-4 lg:grid-cols-[2fr_3fr]">
        <Panel title="Descreva a obra ou o serviço">
          <div className="flex flex-col gap-4 p-4">
            <FormField label="Descrição da obra ou serviço">
              <TextArea rows={5} value={description} onChange={(e) => setDescription(e.target.value)} placeholder="Ex.: construção de residência térrea com 120 m²…" />
            </FormField>
            <FormField label="Informações técnicas" hint="Áreas, padrão de acabamento, localidade, prazos etc.">
              <TextArea rows={5} value={technical} onChange={(e) => setTechnical(e.target.value)} />
            </FormField>
            <fieldset>
              <legend className="mb-1.5 text-sm font-medium">Padrão de acabamento</legend>
              <div className="flex gap-2">
                {(['Baixo', 'Médio', 'Alto'] as const).map((p) => (
                  <Button key={p} type="button" size="sm" variant={standard === p ? 'default' : 'outline'} aria-pressed={standard === p} onClick={() => setStandard(p)}>
                    Padrão {p}
                  </Button>
                ))}
              </div>
            </fieldset>
            <Button onClick={() => setGenerated(true)} disabled={!description.trim()}>
              <Sparkles className="size-4" /> Gerar exemplo de análise (demonstração)
            </Button>
          </div>
        </Panel>

        <div className="flex flex-col gap-4">
          {!generated ? (
            <Panel>
              <p className="text-muted-foreground p-8 text-center text-sm">Preencha a descrição e gere o exemplo para ver o formato das sugestões, alertas e recomendações.</p>
            </Panel>
          ) : (
            <>
              <Panel title="Sugestões de serviços e composições" actions={<StatusBadge tone="warning">Demonstração</StatusBadge>}>
                <ul className="divide-y">
                  {sample.suggestions.map((s) => {
                    const d = decisions[s.id] ?? 'pending'
                    return (
                      <li key={s.id} className="flex flex-wrap items-start justify-between gap-3 px-4 py-3">
                        <div className="min-w-0 flex-1">
                          <p className="text-sm font-medium">{s.title}</p>
                          <p className="text-muted-foreground text-xs">{s.detail}</p>
                        </div>
                        {d === 'pending' ? (
                          <div className="flex gap-1">
                            <Button size="sm" variant="outline" onClick={() => setDecisions((x) => ({ ...x, [s.id]: 'accepted' }))}><Check className="size-4" /> Aceitar</Button>
                            <Button size="sm" variant="ghost" onClick={() => setDecisions((x) => ({ ...x, [s.id]: 'rejected' }))}><X className="size-4" /> Rejeitar</Button>
                          </div>
                        ) : (
                          <StatusBadge tone={d === 'accepted' ? 'success' : 'neutral'}>{d === 'accepted' ? 'Aceita (revisão humana)' : 'Rejeitada'}</StatusBadge>
                        )}
                      </li>
                    )
                  })}
                </ul>
              </Panel>

              <Panel title="Alertas de inconsistência">
                <ul className="divide-y">
                  {sample.inconsistencies.map((t) => (
                    <li key={t} className="flex items-start gap-2 px-4 py-3 text-sm"><AlertTriangle className="text-warning mt-0.5 size-4 shrink-0" aria-hidden />{t}</li>
                  ))}
                </ul>
              </Panel>

              <Panel title="Recomendações">
                <ul className="divide-y">
                  {sample.recommendations.map((t) => (
                    <li key={t} className="flex items-start gap-2 px-4 py-3 text-sm"><Lightbulb className="text-primary mt-0.5 size-4 shrink-0" aria-hidden />{t}</li>
                  ))}
                </ul>
              </Panel>
            </>
          )}
        </div>
      </div>
    </>
  )
}
