@php
    $F = \App\Modules\Budgets\Report\ReportFormat::class;
    $m = $data['meta'];
    $t = $data['totals'];
    $hide = $data['options']['hide_bdi'];
    $e = fn ($v) => e((string) $v);
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Orçamento {{ $m['code'] }} · v{{ $m['version'] }}</title>
<style>
{!! $fontFace !!}
:root{
  --sapphire:#0f52ba; --deep:#0a2e66; --soft:#eaf1ff; --graphite:#252a35; --gray:#f3f4f6; --line:#e5e7eb; --muted:#6b7280;
  --success:#16a36a; --warning:#b45309;
}
*{box-sizing:border-box}
html{-webkit-print-color-adjust:exact;print-color-adjust:exact}
body{margin:0;font-family:'Geist',Arial,sans-serif;font-size:9.5pt;color:var(--graphite);line-height:1.4;font-variant-numeric:tabular-nums}

/* ---------- páginas: retrato padrão, paisagem para as tabelas largas, capa sem margem ---------- */
@page{
  size:A4 portrait; margin:20mm 14mm 17mm 14mm;
  @top-left{content:"ObraFlow  ·  Orçamento {{ $m['code'] }}  ·  versão {{ $m['version'] }}";font-family:'Geist',Arial,sans-serif;font-size:7.5pt;color:#6b7280;vertical-align:bottom;padding-bottom:3mm}
  @top-right{content:"{{ $m['draft'] ? 'RASCUNHO · versão não aprovada' : $m['status_label'].($m['approved_at'] ? ' em '.$m['approved_at'] : '') }}";font-family:'Geist',Arial,sans-serif;font-size:7.5pt;font-weight:600;color:{{ $m['draft'] ? '#b45309' : '#16a36a' }};vertical-align:bottom;padding-bottom:3mm}
  @bottom-left{content:"Emitido em {{ $m['issued_at'] }}  ·  Gerado pelo ObraFlow";font-family:'Geist',Arial,sans-serif;font-size:7.5pt;color:#6b7280;vertical-align:top;padding-top:3mm}
  @bottom-right{content:"Página " counter(page) " de " counter(pages);font-family:'Geist',Arial,sans-serif;font-size:7.5pt;color:#0a2e66;font-weight:600;vertical-align:top;padding-top:3mm}
}
@page wide{size:A4 landscape; margin:18mm 12mm 15mm 12mm}
/* capa: mesma geometria das demais páginas (a marca d'água fixa depende disso), sem cabeçalho e rodapé */
@page :first{@top-left{content:none} @top-right{content:none} @bottom-left{content:none} @bottom-right{content:none}}
.wide{page:wide}
.section{break-before:page}
.section:first-of-type{break-before:auto}

/* ---------- marca d'água (repete em todas as páginas) ---------- */
.watermark{position:fixed;top:50%;left:50%;width:0;height:0;z-index:0;pointer-events:none}
.watermark span{position:absolute;left:0;top:0;transform:translate(-50%,-50%) rotate(-28deg);white-space:nowrap;font-size:80pt;font-weight:800;letter-spacing:.12em;color:rgba(180,83,9,.07)}

/* ---------- capa ---------- */
.cover{height:259mm;position:relative;overflow:hidden;background:#fff}
.cover .band{position:absolute;inset:0 0 auto 0;height:112mm;border-radius:5mm;overflow:hidden;background:linear-gradient(135deg,#0a2e66 0%,#0f52ba 100%)}
.cover .band::after{content:"";position:absolute;right:-40mm;top:-30mm;width:150mm;height:150mm;border-radius:50%;background:rgba(255,255,255,.06)}
.cover .band::before{content:"";position:absolute;right:30mm;top:60mm;width:90mm;height:90mm;border-radius:50%;background:rgba(255,255,255,.05)}
.cover .brand{position:absolute;top:12mm;left:12mm;background:#fff;border-radius:3mm;padding:3.2mm 4.5mm}
.cover .brand img{height:11mm;display:block}
.cover .heading{position:absolute;top:42mm;left:12mm;right:12mm;color:#fff}
.cover .eyebrow{font-size:9pt;letter-spacing:.24em;text-transform:uppercase;opacity:.8}
.cover h1{font-size:34pt;font-weight:700;margin:2mm 0 1mm;letter-spacing:-.01em}
.cover .code{font-size:13pt;font-weight:500;opacity:.92}
.cover .title{font-size:12pt;margin-top:4mm;opacity:.95;max-width:150mm}
.cover .status{display:inline-block;margin-top:6mm;padding:1.4mm 3.5mm;border-radius:10mm;font-size:8.5pt;font-weight:600;letter-spacing:.04em;background:rgba(255,255,255,.16);color:#fff;border:1px solid rgba(255,255,255,.35)}
.cover .status.draft{background:#fef3c7;color:#92400e;border-color:#fde68a}
.cover .body{position:absolute;top:124mm;left:4mm;right:4mm}
.cover .grid{display:grid;grid-template-columns:1fr 1fr;gap:5mm 8mm}
.cover .field .k{font-size:7.5pt;text-transform:uppercase;letter-spacing:.12em;color:var(--muted);margin-bottom:1mm}
.cover .field .v{font-size:10.5pt;font-weight:500}
.cover .field .s{font-size:8.5pt;color:var(--muted)}
.cover .total{margin-top:12mm;border-radius:4mm;background:var(--soft);border:1px solid #d6e3ff;padding:7mm 8mm;display:flex;justify-content:space-between;align-items:center}
.cover .total .k{font-size:8pt;text-transform:uppercase;letter-spacing:.16em;color:var(--deep);font-weight:600}
.cover .total .v{font-size:24pt;font-weight:700;color:var(--deep)}
.cover .total .s{font-size:8.5pt;color:var(--muted);margin-top:1mm}
.cover .foot{position:absolute;bottom:0;left:4mm;right:4mm;display:flex;justify-content:space-between;font-size:8pt;color:var(--muted);border-top:1px solid var(--line);padding-top:4mm}

/* ---------- títulos ---------- */
.h{display:flex;align-items:flex-end;justify-content:space-between;border-bottom:2px solid var(--sapphire);padding-bottom:2mm;margin-bottom:5mm}
.h h2{font-size:16pt;color:var(--deep);margin:0;font-weight:700;letter-spacing:-.01em}
.h .n{font-size:8pt;color:var(--sapphire);font-weight:700;letter-spacing:.2em;text-transform:uppercase}
.h img{height:7mm}
h3{font-size:10.5pt;color:var(--sapphire);margin:6mm 0 2.5mm;font-weight:600}
.note{font-size:8pt;color:var(--muted);margin-top:2mm}

/* ---------- cartões ---------- */
.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:3mm;margin-bottom:5mm}
.kpi{border:1px solid var(--line);border-radius:3mm;padding:3.5mm 4mm;background:#fff}
.kpi .k{font-size:7.5pt;color:var(--muted);text-transform:uppercase;letter-spacing:.08em}
.kpi .v{font-size:12.5pt;font-weight:700;margin-top:1mm;color:var(--graphite)}
.kpi.main{background:var(--deep);border-color:var(--deep)}
.kpi.main .k{color:#c7d7f7}.kpi.main .v{color:#fff}

/* ---------- tabelas ---------- */
table{width:100%;border-collapse:collapse;position:relative;z-index:1}
thead{display:table-header-group}
tr{break-inside:avoid}
th{background:var(--sapphire);color:#fff;font-weight:600;font-size:7.8pt;text-transform:uppercase;letter-spacing:.04em;padding:2mm 2mm;text-align:left;white-space:nowrap}
th:first-child{border-top-left-radius:1.5mm}th:last-child{border-top-right-radius:1.5mm}
td{padding:1.6mm 2mm;border-bottom:1px solid var(--line);vertical-align:top;font-size:8.6pt}
tbody tr.item:nth-child(even) td{background:#fafbfc}
.r{text-align:right;white-space:nowrap}.c{text-align:center}
.mono{font-size:8pt;color:#4b5563}
tr.g0 td{background:var(--deep);color:#fff;font-weight:600}
tr.g1 td{background:var(--soft);color:var(--deep);font-weight:600}
tr.g2 td{background:#f5f8ff;color:var(--deep);font-weight:600}
tr.total td{background:var(--soft);font-weight:700;font-size:9.5pt;border-bottom:none}
tr.final td{background:var(--deep);color:#fff;font-weight:700;font-size:11pt}
tr.sub td{color:#4b5563}
.src{display:inline-block;font-size:7pt;padding:.3mm 1.6mm;border-radius:5mm;background:var(--gray);color:#374151;white-space:nowrap}
.src.SINAPI{background:#e0ebff;color:#0a2e66}
.bar{height:2.2mm;border-radius:2mm;background:var(--gray);overflow:hidden;min-width:28mm}
.bar span{display:block;height:100%;background:linear-gradient(90deg,#0f52ba,#5b8def)}
.badge{display:inline-block;min-width:6mm;text-align:center;font-weight:700;font-size:7.8pt;border-radius:1.5mm;padding:.3mm 1.2mm}
.badge.A{background:#dcfce7;color:#166534}.badge.B{background:#fef3c7;color:#92400e}.badge.C{background:var(--gray);color:#374151}

/* analítico */
.comp{break-inside:avoid;margin-bottom:4mm;border:1px solid var(--line);border-radius:2.5mm;overflow:hidden}
.comp .top{display:flex;gap:4mm;align-items:baseline;background:var(--soft);padding:2.2mm 3mm;color:var(--deep)}
.comp .top .code{font-weight:700;white-space:nowrap}
.comp .top .desc{flex:1;font-weight:600}
.comp .top .val{white-space:nowrap;font-weight:700}
.comp table th{background:#f8fafc;color:#374151;border-bottom:1px solid var(--line);border-radius:0}
.comp .direct{padding:2mm 3mm;color:var(--muted);font-style:italic;font-size:8.4pt}

/* BDI */
.formula{background:var(--gray);border-radius:2.5mm;padding:3mm 4mm;font-size:9.5pt;margin:2mm 0 4mm;color:var(--deep);font-weight:500}

/* gráfico */
.chart{border:1px solid var(--line);border-radius:3mm;padding:3mm;margin-bottom:4mm;break-inside:avoid}
.chart svg{width:100%;height:auto;display:block}

/* fechamento */
.notes{white-space:pre-wrap;border-left:3px solid var(--sapphire);background:#f8fafc;padding:3mm 4mm;border-radius:0 2mm 2mm 0}
.signs{display:grid;grid-template-columns:1fr 1fr;gap:14mm;margin-top:28mm}
.sign{border-top:1px solid #374151;padding-top:2mm;text-align:center;font-size:8.8pt}
.sign .s{color:var(--muted);font-size:8pt}
</style>
</head>
<body>
@if ($m['draft'])
  <div class="watermark"><span>RASCUNHO</span></div>
@endif

{{-- ===================================================== CAPA --}}
<div class="cover">
  <div class="band"></div>
  <div class="brand"><img src="{{ $logo }}" alt="ObraFlow"></div>
  <div class="heading">
    <div class="eyebrow">Proposta orçamentária</div>
    <h1>Orçamento</h1>
    <div class="code">{{ $m['code'] }} · versão {{ $m['version'] }}{{ $m['version_label'] && mb_strtolower($m['version_label']) !== 'versão '.$m['version'] ? ' — '.$m['version_label'] : '' }}</div>
    <div class="title">{{ $m['title'] }}</div>
    <div class="status {{ $m['draft'] ? 'draft' : '' }}">{{ $m['status_label'] }}{{ !$m['draft'] && $m['approved_at'] ? ' em '.$m['approved_at'] : '' }}{{ $m['draft'] ? ' · versão não aprovada' : '' }}</div>
  </div>
  <div class="body">
    <div class="grid">
      <div class="field"><div class="k">Empresa</div><div class="v">{{ $data['company']['name'] ?? '—' }}</div>@if ($data['company']['document'])<div class="s">CNPJ {{ $data['company']['document'] }}</div>@endif</div>
      <div class="field"><div class="k">Cliente</div><div class="v">{{ $data['customer']['name'] ?? '—' }}</div>@if (!empty($data['customer']['document']))<div class="s">{{ $data['customer']['document'] }}</div>@endif</div>
      <div class="field"><div class="k">Obra</div><div class="v">{{ $data['project'] ? $data['project']['code'].' — '.$data['project']['name'] : '—' }}</div>@if (!empty($data['project']['address']))<div class="s">{{ $data['project']['address'] }}</div>@endif</div>
      <div class="field"><div class="k">Base de referência</div><div class="v">{{ $m['reference'] ?? 'Sem base de referência' }}</div><div class="s">Encargos sociais: {{ $m['regime'] }}{{ $m['reference_date'] ? ' · data-base '.$m['reference_date'] : '' }}</div></div>
    </div>
    <div class="total">
      <div><div class="k">Valor final</div><div class="s">{{ $t['item_count'] }} itens{{ $t['global_discount'] !== '0.00' ? ' · desconto global de '.$F::percent($t['global_discount_percent']) : '' }}</div></div>
      <div class="v">{{ $F::money($t['final_price']) }}</div>
    </div>
  </div>
  <div class="foot"><span>Emitido em {{ $m['issued_at'] }} por {{ $m['issued_by'] }}</span><span>Gerado pelo ObraFlow</span></div>
</div>

{{-- ===================================================== RESUMO --}}
<div class="section">
  <div class="h"><div><div class="n">01</div><h2>Resumo</h2></div><img src="{{ $logoMark }}" alt=""></div>
  <div class="kpis">
    @if ($hide)
      <div class="kpi"><div class="k">Preço dos serviços</div><div class="v">{{ $F::money($t['items_sale']) }}</div></div>
      <div class="kpi"><div class="k">Custos indiretos</div><div class="v">{{ $F::money($t['indirect_cost']) }}</div></div>
      <div class="kpi"><div class="k">Desconto global</div><div class="v">{{ $F::money($t['global_discount']) }}</div></div>
    @else
      <div class="kpi"><div class="k">Custo total</div><div class="v">{{ $F::money($t['total_cost']) }}</div></div>
      <div class="kpi"><div class="k">BDI</div><div class="v">{{ $F::money($t['bdi_amount']) }}</div></div>
      <div class="kpi"><div class="k">Descontos</div><div class="v">{{ $F::money(bcadd($t['items_discount'], $t['global_discount'], 2)) }}</div></div>
    @endif
    <div class="kpi main"><div class="k">Valor final</div><div class="v">{{ $F::money($t['final_price']) }}</div></div>
  </div>

  <h3>Composição do preço</h3>
  <table>
    <tbody>
      @if (!$hide)
        <tr class="item"><td>Custo direto (soma dos itens)</td><td class="r">{{ $F::money($t['direct_cost']) }}</td></tr>
        <tr class="item"><td>BDI</td><td class="r">{{ $F::money($t['bdi_amount']) }}</td></tr>
        @if ($t['items_discount'] !== '0.00')<tr class="item"><td>Desconto nos itens</td><td class="r">− {{ $F::money($t['items_discount']) }}</td></tr>@endif
      @endif
      <tr class="item"><td>Preço dos serviços</td><td class="r">{{ $F::money($t['items_sale']) }}</td></tr>
      <tr class="item"><td>Custos indiretos (fora do BDI)</td><td class="r">{{ $F::money($t['indirect_cost']) }}</td></tr>
      @if ($t['global_discount'] !== '0.00')<tr class="item"><td>Desconto global ({{ $F::percent($t['global_discount_percent']) }})</td><td class="r">− {{ $F::money($t['global_discount']) }}</td></tr>@endif
      <tr class="final"><td>Valor final</td><td class="r">{{ $F::money($t['final_price']) }}</td></tr>
    </tbody>
  </table>

  <h3>Totais por etapa</h3>
  <table>
    <thead><tr><th style="width:12mm">Etapa</th><th>Descrição</th><th class="r" style="width:32mm">Valor</th><th class="r" style="width:16mm">%</th><th style="width:34mm"></th></tr></thead>
    <tbody>
      @foreach ($data['summary']['stages'] as $s)
        <tr class="item"><td class="mono">{{ $s['code'] }}</td><td>{{ $s['name'] }}</td><td class="r">{{ $F::money($s['sale']) }}</td><td class="r">{{ $F::percent($s['percent']) }}</td><td><div class="bar"><span style="width:{{ min(100, (float) $s['percent']) }}%"></span></div></td></tr>
      @endforeach
    </tbody>
  </table>

  @if ($data['summary']['by_kind'] !== [])
    <h3>Insumos por tipo</h3>
    <table>
      <thead><tr><th>Tipo</th><th class="r" style="width:32mm">Valor</th><th class="r" style="width:16mm">%</th><th style="width:34mm"></th></tr></thead>
      <tbody>
        @foreach ($data['summary']['by_kind'] as $k)
          <tr class="item"><td>{{ $k['label'] }}</td><td class="r">{{ $F::money($k['total']) }}</td><td class="r">{{ $F::percent($k['percent']) }}</td><td><div class="bar"><span style="width:{{ min(100, (float) $k['percent']) }}%"></span></div></td></tr>
        @endforeach
      </tbody>
    </table>
    <div class="note">Distribuição calculada a partir das composições dos itens (insumos finais). Itens sem composição não entram nesta tabela.</div>
  @endif
</div>

{{-- ===================================================== SINTÉTICO --}}
<div class="section wide">
  <div class="h"><div><div class="n">02</div><h2>Orçamento sintético</h2></div><img src="{{ $logoMark }}" alt=""></div>
  <table>
    <thead>
      <tr>
        <th style="width:15mm">Item</th><th style="width:17mm">Código</th><th style="width:20mm">Fonte</th><th>Descrição</th><th class="c" style="width:11mm">Un.</th><th class="r" style="width:20mm">Quant.</th>
        @if (!$hide)<th class="r" style="width:24mm">Custo unit.</th><th class="r" style="width:15mm">BDI</th>@endif
        <th class="r" style="width:24mm">Preço unit.</th><th class="r" style="width:28mm">Total</th>
      </tr>
    </thead>
    <tbody>
      @php $span = $hide ? 7 : 9; @endphp
      @foreach ($data['rows'] as $r)
        @if ($r['type'] === 'group')
          <tr class="g{{ min($r['level'], 2) }}"><td>{{ $r['code'] }}</td><td colspan="{{ $span - 1 }}">{{ $r['description'] }}</td><td class="r">{{ $F::money($r['total_sale']) }}</td></tr>
        @else
          <tr class="item">
            <td class="mono">{{ $r['code'] }}</td><td class="mono">{{ $r['reference_code'] }}</td><td><span class="src {{ $r['source'] === 'SINAPI' ? 'SINAPI' : '' }}">{{ $r['source'] }}</span></td>
            <td>{{ $r['description'] }}</td><td class="c">{{ $r['unit'] }}</td><td class="r">{{ $F::quantity($r['quantity']) }}</td>
            @if (!$hide)<td class="r">{{ $F::money($r['unit_cost']) }}</td><td class="r">{{ $F::percent($r['bdi_percent']) }}</td>@endif
            <td class="r">{{ $F::money($r['unit_sale']) }}</td><td class="r">{{ $F::money($r['total_sale']) }}</td>
          </tr>
        @endif
      @endforeach
      <tr class="total"><td colspan="{{ $span }}">Total dos serviços</td><td class="r">{{ $F::money($t['items_sale']) }}</td></tr>
      @if ($t['indirect_cost'] !== '0.00')<tr class="sub"><td colspan="{{ $span }}">Custos indiretos</td><td class="r">{{ $F::money($t['indirect_cost']) }}</td></tr>@endif
      @if ($t['global_discount'] !== '0.00')<tr class="sub"><td colspan="{{ $span }}">Desconto global ({{ $F::percent($t['global_discount_percent']) }})</td><td class="r">− {{ $F::money($t['global_discount']) }}</td></tr>@endif
      <tr class="final"><td colspan="{{ $span }}">Valor final</td><td class="r">{{ $F::money($t['final_price']) }}</td></tr>
    </tbody>
  </table>
  @if (!$hide)<div class="note">Preço unitário = custo unitário × (1 + BDI) × (1 − desconto do item). O total de cada item é arredondado e depois somado.</div>@endif
</div>

{{-- ===================================================== ANALÍTICO --}}
@isset($data['analytic'])
<div class="section wide">
  <div class="h"><div><div class="n">03</div><h2>Orçamento analítico</h2></div><img src="{{ $logoMark }}" alt=""></div>
  @foreach ($data['analytic'] as $a)
    <div class="comp">
      <div class="top"><span class="code">{{ $a['code'] }}</span><span class="src {{ $a['source'] === 'SINAPI' ? 'SINAPI' : '' }}">{{ $a['source'] }}{{ $a['composition'] ? ' '.$a['composition']['code'] : '' }}</span><span class="desc">{{ $a['description'] }}</span><span class="val">{{ $a['unit'] }} · {{ $F::money($a['unit_cost']) }}</span></div>
      @if ($a['composition'])
        <table>
          <thead><tr><th style="width:24mm">Tipo</th><th style="width:18mm">Código</th><th>Descrição</th><th class="c" style="width:12mm">Un.</th><th class="r" style="width:24mm">Coeficiente</th><th class="r" style="width:24mm">Preço unit.</th><th class="r" style="width:24mm">Custo</th></tr></thead>
          <tbody>
            @foreach ($a['composition']['items'] as $ci)
              <tr class="item"><td>{{ $ci['type'] }}</td><td class="mono">{{ $ci['code'] }}</td><td>{{ $ci['description'] }}</td><td class="c">{{ $ci['unit'] }}</td><td class="r">{{ $F::coefficient($ci['coefficient']) }}</td><td class="r">{{ $ci['unit_price'] !== null ? $F::money($ci['unit_price']) : '—' }}</td><td class="r">{{ $ci['cost'] !== null ? $F::money($ci['cost']) : '—' }}</td></tr>
            @endforeach
          </tbody>
        </table>
      @else
        <div class="direct">Item sem composição: custo unitário informado (preço direto).</div>
      @endif
    </div>
  @endforeach
</div>
@endisset

{{-- ===================================================== BDI --}}
@isset($data['bdi'])
@php $b = $data['bdi']; @endphp
<div class="section">
  <div class="h"><div><div class="n">04</div><h2>BDI e encargos</h2></div><img src="{{ $logoMark }}" alt=""></div>
  <h3>BDI global</h3>
  @if ($b['global'] === null)
    <p>Sem BDI global (0%).</p>
  @else
    <p>Método: <strong>{{ $b['global']['method'] }}</strong></p>
    @if ($b['global']['components'] !== [])
      <div class="formula">{{ $b['formula'] }}</div>
      <table>
        <thead><tr><th>Parcela</th><th class="r" style="width:30mm">%</th></tr></thead>
        <tbody>
          @foreach ($b['global']['components'] as $c)
            <tr class="item"><td>{{ $c['label'] }}</td><td class="r">{{ $F::percent($c['percent']) }}</td></tr>
          @endforeach
          <tr class="final"><td>BDI resultante</td><td class="r">{{ $F::percent($b['global']['percent']) }}</td></tr>
        </tbody>
      </table>
    @else
      <table><tbody><tr class="final"><td>BDI</td><td class="r">{{ $F::percent($b['global']['percent']) }}</td></tr></tbody></table>
    @endif
  @endif

  @foreach (['by_group' => 'BDI diferenciado por etapa', 'by_item' => 'BDI diferenciado por item'] as $key => $label)
    @if ($b[$key] !== [])
      <h3>{{ $label }}</h3>
      <table>
        <thead><tr><th style="width:18mm">Código</th><th>Descrição</th><th style="width:40mm">Método</th><th class="r" style="width:22mm">BDI</th></tr></thead>
        <tbody>
          @foreach ($b[$key] as $r)
            <tr class="item"><td class="mono">{{ $r['code'] }}</td><td>{{ $r['name'] }}</td><td>{{ $r['method'] }}</td><td class="r">{{ $F::percent($r['percent']) }}</td></tr>
          @endforeach
        </tbody>
      </table>
    @endif
  @endforeach

  <h3>Custos indiretos (fora do BDI)</h3>
  @if ($b['indirect'] === [])
    <p>Nenhum.</p>
  @else
    <table>
      <thead><tr><th>Descrição</th><th style="width:50mm">Base</th><th class="r" style="width:30mm">Valor</th></tr></thead>
      <tbody>
        @foreach ($b['indirect'] as $c)
          <tr class="item"><td>{{ $c['description'] }}</td><td>{{ $c['basis'] }}</td><td class="r">{{ $F::money($c['amount']) }}</td></tr>
        @endforeach
        <tr class="total"><td colspan="2">Total</td><td class="r">{{ $F::money($t['indirect_cost']) }}</td></tr>
      </tbody>
    </table>
  @endif

  <h3>Encargos sociais</h3>
  <p>Regime: <strong>{{ $b['regime'] }}</strong>{{ $m['reference'] ? ' · base '.$m['reference'] : '' }}.</p>
</div>
@endisset

{{-- ===================================================== CURVA ABC --}}
@isset($data['abc'])
<div class="section wide">
  <div class="h"><div><div class="n">05</div><h2>Curva ABC</h2></div><img src="{{ $logoMark }}" alt=""></div>
  @foreach (['resources' => 'Insumos (das composições)', 'services' => 'Serviços (pelo custo)'] as $key => $label)
    <h3>{{ $label }}</h3>
    <div class="chart">{!! $charts[$key] !!}</div>
    <table>
      <thead><tr><th class="r" style="width:11mm">Pos.</th><th style="width:18mm">Código</th><th>Descrição</th><th class="c" style="width:11mm">Un.</th><th class="r" style="width:24mm">Quantidade</th><th class="r" style="width:28mm">Valor</th><th class="r" style="width:16mm">%</th><th class="r" style="width:18mm">% acum.</th><th class="c" style="width:13mm">Faixa</th></tr></thead>
      <tbody>
        @foreach ($data['abc'][$key] as $i => $r)
          <tr class="item"><td class="r mono">{{ $i + 1 }}</td><td class="mono">{{ $r['code'] }}</td><td>{{ $r['description'] }}</td><td class="c">{{ $r['unit'] }}</td><td class="r">{{ $F::quantity($r['quantity']) }}</td><td class="r">{{ $F::money($r['total']) }}</td><td class="r">{{ $F::percent($r['percent']) }}</td><td class="r">{{ $F::percent($r['cumulative_percent']) }}</td><td class="c"><span class="badge {{ $r['class'] }}">{{ $r['class'] }}</span></td></tr>
        @endforeach
      </tbody>
    </table>
  @endforeach
  <div class="note">Faixas: A até 80% do valor acumulado, B até 95%, C o restante. A faixa é decidida por onde a linha começa.</div>
</div>
@endisset

{{-- ===================================================== FECHAMENTO --}}
@isset($data['closing'])
@php $c = $data['closing']; @endphp
<div class="section">
  <div class="h"><div><div class="n">06</div><h2>Fechamento</h2></div><img src="{{ $logoMark }}" alt=""></div>
  @if ($c['notes'])
    <h3>Observações</h3>
    <div class="notes">{{ $c['notes'] }}</div>
  @endif
  <p style="margin-top:10mm">{{ $c['place'] ? $c['place'].', ' : '' }}{{ $c['date'] }}.</p>
  <div class="signs">
    <div class="sign">
      <strong>{{ $c['technician']['name'] ?: " " }}</strong>
      <div class="s">Responsável técnico{{ $c['technician']['registry'] ? ' · '.$c['technician']['registry'] : '' }}</div>
      @if ($c['technician']['art'])<div class="s">ART/RRT {{ $c['technician']['art'] }}</div>@endif
    </div>
    <div class="sign">
      <strong>{{ $c['customer'] ?: " " }}</strong>
      <div class="s">Cliente — de acordo</div>
    </div>
  </div>
</div>
@endisset
</body>
</html>
