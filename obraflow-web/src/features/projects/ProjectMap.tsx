import 'leaflet/dist/leaflet.css'
import L from 'leaflet'
import { LocateFixed, MapPin } from 'lucide-react'
import { useState } from 'react'
import { MapContainer, Marker, TileLayer } from 'react-leaflet'
import { useToast } from '@/app/providers/ToastProvider'
import { Button } from '@/components/ui/button'
import { getErrorMessage } from '@/lib/api-error'
import { geocodeAddress } from '@/utils/geocode'
import { projectsApi, type Project } from './api'

/** Pino com as cores do ObraFlow (sem imagens externas): ícone desenhado em CSS. */
const pin = L.divIcon({
  className: '',
  html: '<span class="block size-5 -translate-y-2 rotate-45 rounded-full rounded-br-none border-2 border-(--primary-foreground) bg-(--primary) shadow"></span>',
  iconSize: [20, 20],
  iconAnchor: [10, 20],
})

const BRASIL: [number, number] = [-14.2, -51.9]

/**
 * Mapa da obra (A-47): o pino fica nas coordenadas do endereço do canteiro. "Localizar pelo endereço" consulta o
 * OpenStreetMap (só quando o usuário pede); arrastar o pino ajusta e grava a posição. Mapa: OpenStreetMap.
 */
export function ProjectMap({ project, canEdit, onSaved }: { project: Project; canEdit: boolean; onSaved: () => void }) {
  const { notify } = useToast()
  const address = project.site_address
  const saved = address?.latitude && address?.longitude ? ([Number(address.latitude), Number(address.longitude)] as [number, number]) : null
  const [point, setPoint] = useState<[number, number] | null>(saved)
  const [busy, setBusy] = useState(false)

  const save = async (p: [number, number], msg: string) => {
    if (!address) return
    setBusy(true)
    try {
      await projectsApi.addresses.update(project.uuid, address.id, { latitude: p[0].toFixed(7), longitude: p[1].toFixed(7) })
      setPoint(p)
      notify('success', msg)
      onSaved()
    } catch (e) {
      notify('error', getErrorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  const locate = async () => {
    if (!address) return
    setBusy(true)
    const found = await geocodeAddress(address)
    setBusy(false)
    if (!found) return notify('error', 'Endereço não encontrado no mapa. Arraste o pino até o local da obra.')
    await save([found.point.lat, found.point.lng], found.precise ? 'Obra localizada no mapa.' : 'Localizada pela cidade: arraste o pino até o local exato.')
  }

  return (
    <section className="mt-6" aria-label="Mapa da obra">
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <h2 className="flex items-center gap-1.5 text-sm font-medium">
          <MapPin className="size-4" aria-hidden /> Localização
        </h2>
        {address && canEdit && (
          <Button variant="outline" size="sm" disabled={busy} onClick={locate}>
            <LocateFixed className="size-3.5" /> Localizar pelo endereço
          </Button>
        )}
      </div>
      {!address ? (
        <p className="text-muted-foreground rounded-lg border p-4 text-sm">Informe o endereço do canteiro (botão Editar) para ver a obra no mapa.</p>
      ) : (
        <>
          <div className="h-72 overflow-hidden rounded-lg border">
            <MapContainer center={point ?? BRASIL} zoom={point ? 16 : 4} scrollWheelZoom={false} className="h-full w-full">
              <TileLayer attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>' url="https://tile.openstreetmap.org/{z}/{x}/{y}.png" />
              {point && (
                <Marker
                  position={point}
                  icon={pin}
                  draggable={canEdit}
                  eventHandlers={{ dragend: (e) => save([e.target.getLatLng().lat, e.target.getLatLng().lng], 'Posição da obra ajustada.') }}
                />
              )}
            </MapContainer>
          </div>
          <p className="text-muted-foreground mt-1 text-xs">
            {point ? (canEdit ? 'Arraste o pino para ajustar a posição.' : '') : 'Ainda sem posição: use "Localizar pelo endereço".'} Mapa: © OpenStreetMap.
          </p>
        </>
      )}
    </section>
  )
}
