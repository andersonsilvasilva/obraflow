import { ModuleInPreparation } from '@/components/feedback/states'
import { PageHeader } from '@/components/layout/PageHeader'

/** Página padrão de módulos ainda não desenvolvidos — sem simular funcionalidades. */
export function ModulePage({ title, description }: { title: string; description: string }) {
  return (
    <>
      <PageHeader title={title} description={description} />
      <ModuleInPreparation module={title} />
    </>
  )
}
