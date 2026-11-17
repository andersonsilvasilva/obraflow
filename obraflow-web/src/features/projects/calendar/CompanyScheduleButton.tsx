import { Clock } from 'lucide-react'
import { useState } from 'react'
import { Modal } from '@/components/feedback/Modal'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/features/auth/auth-context'
import { calendarApi } from './api'
import { ScheduleEditor } from './ScheduleEditor'

/** Ação da linha em Cadastros → Empresas: horário-padrão da empresa, herdado pelas obras dela (D-P63). */
export function CompanyScheduleButton({ company }: { company: { uuid: string; legal_name: string } }) {
  const { can } = useAuth()
  const [open, setOpen] = useState(false)
  return (
    <>
      <Button variant="ghost" size="icon" aria-label={`Horário de trabalho de ${company.legal_name}`} title="Horário de trabalho" onClick={() => setOpen(true)}>
        <Clock className="size-4" />
      </Button>
      {open && (
        <Modal open onClose={() => setOpen(false)} title="Horário de trabalho" description={company.legal_name} size="xl">
          <ScheduleEditor
            scope="company"
            queryKey={['company-schedule', company.uuid]}
            load={() => calendarApi.companySchedule(company.uuid)}
            save={(days) => calendarApi.saveCompanySchedule(company.uuid, days)}
            canEdit={can('companies.manage')}
          />
        </Modal>
      )}
    </>
  )
}
