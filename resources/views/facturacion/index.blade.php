@php
    use App\Facturacion\FacturasDelMes as F;
    $fecha = \Carbon\CarbonImmutable::create($anio, $mes, 1)->locale('es');
    $clave = $fecha->format('Y-m');
    $listas = array_filter($facturas, fn ($f) => $f['estado'] === F::LISTA);
    $creadas = array_filter($facturas, fn ($f) => $f['estado'] === F::CREADA);
    $problemas = array_filter($facturas, fn ($f) => $f['estado'] === F::SIN_CONTACTO);
    $etiqueta = [
        F::LISTA => ['Lista para crear', 'bg-neutral-900 text-white'],
        F::CREADA => ['Borrador en Holded', 'bg-neutral-100 text-neutral-600'],
        F::SIN_CONTACTO => ['Revisar', 'bg-white text-neutral-900 ring-1 ring-neutral-900'],
    ];
@endphp
<x-layouts.facturacion :title="'Facturación ' . $fecha->translatedFormat('F Y')">
    <div class="flex flex-wrap items-end justify-between gap-6 mb-8">
        <div>
            <p class="text-sm text-neutral-500">Borradores de factura desde la hoja de KPIs</p>
            <h1 class="text-3xl font-semibold tracking-tight mt-1">{{ ucfirst($fecha->translatedFormat('F Y')) }}</h1>
        </div>
        <div class="flex items-center gap-2 text-sm">
            <a href="{{ route('facturacion.index', ['mes' => $fecha->subMonth()->format('Y-m')]) }}" class="px-3 py-2 rounded-lg border border-neutral-200 bg-white hover:border-neutral-400" aria-label="Mes anterior">←</a>
            <form method="GET" class="m-0">
                <input type="month" name="mes" value="{{ $clave }}" onchange="this.form.submit()" class="px-3 py-2 rounded-lg border border-neutral-200 bg-white" aria-label="Mes">
            </form>
            <a href="{{ route('facturacion.index', ['mes' => $fecha->addMonth()->format('Y-m')]) }}" class="px-3 py-2 rounded-lg border border-neutral-200 bg-white hover:border-neutral-400" aria-label="Mes siguiente">→</a>
            <a href="{{ route('facturacion.index', ['mes' => $clave, 'recargar' => 1]) }}" class="px-3 py-2 rounded-lg text-neutral-500 hover:text-neutral-900" title="Vuelve a leer la hoja y los contactos de Holded">Actualizar</a>
        </div>
    </div>

    @if ($error)
        <div class="mb-8 px-4 py-3 rounded-lg bg-white border border-neutral-900 text-sm">{{ $error }}</div>
    @endif

    <dl class="grid grid-cols-2 lg:grid-cols-4 gap-px bg-neutral-200 rounded-xl overflow-hidden border border-neutral-200 mb-10">
        @foreach ([
            ['Total del mes', F::euros(array_sum(array_column($facturas, 'total'))) . ' €'],
            ['Listas para crear', count($listas)],
            ['Ya en Holded', count($creadas)],
            ['Por revisar', count($problemas)],
        ] as [$nombre, $valor])
            <div class="bg-white px-5 py-4">
                <dt class="text-xs text-neutral-500">{{ $nombre }}</dt>
                <dd class="text-2xl font-semibold tracking-tight mt-1 tabular-nums">{{ $valor }}</dd>
            </div>
        @endforeach
    </dl>

    @if ($facturas)
        <form method="POST" action="{{ route('facturacion.crear') }}" onsubmit="const b = event.submitter; if (b && b.formAction !== this.action) return true; const n = b && b.name === 'solo' ? 1 : this.querySelectorAll('input[name=\'sociedades[]\']:checked').length; return n > 0 && confirm(n === 1 ? '¿Crear este borrador en Holded? Queda sin aprobar ni enviar.' : '¿Crear ' + n + ' borradores en Holded? Quedan sin aprobar ni enviar.')">
            @csrf
            <input type="hidden" name="mes" value="{{ $clave }}">

            <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
                <label class="flex items-center gap-2 text-sm text-neutral-600">
                    <input type="checkbox" class="accent-neutral-900" checked onchange="document.querySelectorAll('input[name=\'sociedades[]\']').forEach(c => c.checked = this.checked)">
                    Marcar todas las listas
                </label>
                <button type="submit" @disabled(! $listas) class="px-5 py-2.5 rounded-lg bg-neutral-900 text-white text-sm font-medium hover:bg-neutral-700 disabled:bg-neutral-300 disabled:cursor-not-allowed">
                    Crear borradores en Holded
                </button>
            </div>

            <div class="bg-white border border-neutral-200 rounded-xl divide-y divide-neutral-200">
                @foreach ($facturas as $f)
                    <div class="px-5 py-4 flex gap-4">
                        <div class="pt-1 w-5 shrink-0">
                            @if ($f['estado'] === F::LISTA)
                                <input type="checkbox" name="sociedades[]" value="{{ $f['sociedad'] }}" checked class="accent-neutral-900" aria-label="Crear la factura de {{ $f['sociedad'] }}">
                            @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                <div class="min-w-0">
                                    <p class="font-medium truncate">{{ $f['sociedad'] ?: 'Sin sociedad' }}</p>
                                    <p class="text-xs text-neutral-500 truncate">{{ implode(' · ', $f['clientes']) }}</p>
                                </div>
                                <div class="flex items-center gap-3">
                                    @if ($f['estado'] === F::LISTA)
                                        <button type="submit" name="solo" value="{{ $f['sociedad'] }}" class="text-xs px-2.5 py-1 rounded-lg border border-neutral-300 text-neutral-700 hover:border-neutral-900 hover:text-neutral-900">Crear</button>
                                    @endif
                                    <span class="text-xs px-2 py-0.5 rounded-full {{ $etiqueta[$f['estado']][1] }}">{{ $etiqueta[$f['estado']][0] }}</span>
                                    <span class="font-semibold tabular-nums whitespace-nowrap">{{ F::euros($f['total']) }} €</span>
                                </div>
                            </div>
                            <ul class="mt-3 space-y-2 text-sm">
                                {{-- Ya creada: se ve lo que se mandó a Holded --}}
                                @foreach ($f['borrador']?->lineas ?? $f['lineas'] as $i => $l)
                                    @php $k = F::clave($f['sociedad']); $uds = (int) old("unidades.$k.$i", $l['unidades'] ?? 1) ?: 1; @endphp
                                    <li class="flex justify-between gap-4">
                                        <div class="flex-1 min-w-0">
                                            <p class="text-neutral-900">{{ $l['concepto'] }}</p>
                                            @if ($f['estado'] === F::CREADA)
                                                <p class="text-neutral-500">{{ $l['descripcion'] }}</p>
                                            @else
                                                <input name="descripciones[{{ $k }}][{{ $i }}]" value="{{ old("descripciones.$k.$i", $l['descripcion']) }}" maxlength="255" placeholder="Descripción" aria-label="Descripción de {{ $l['concepto'] }}"
                                                    onkeydown="if (event.key === 'Enter') event.preventDefault()"
                                                    class="w-full -mx-1 px-1 rounded bg-transparent text-neutral-500 border border-transparent hover:border-neutral-200 focus:border-neutral-400 focus:bg-white focus:text-neutral-900 focus:outline-none">
                                            @endif
                                        </div>
                                        <div class="flex items-start gap-3 tabular-nums text-neutral-600 whitespace-nowrap">
                                            @if ($f['estado'] === F::CREADA)
                                                @if ($uds > 1)<span class="text-neutral-400">{{ $uds }} × {{ F::euros($l['importe'] / $uds) }} €</span>@endif
                                            @else
                                                {{-- El total de la línea es el de la hoja: más unidades = precio unitario menor --}}
                                                <label class="flex items-center gap-1 text-neutral-400">
                                                    <input type="number" name="unidades[{{ $k }}][{{ $i }}]" value="{{ $uds }}" min="1" max="999" step="1" aria-label="Unidades de {{ $l['concepto'] }}"
                                                        onkeydown="if (event.key === 'Enter') event.preventDefault()"
                                                        oninput="const n = Math.max(1, parseInt(this.value) || 1); this.nextElementSibling.textContent = n > 1 ? '× ' + (({{ $l['importe'] }}) / n).toLocaleString('es-ES', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' €' : 'ud.'"
                                                        class="w-14 px-1.5 py-0.5 rounded border border-neutral-200 bg-white text-right text-neutral-900">
                                                    <span>{{ $uds > 1 ? '× ' . F::euros($l['importe'] / $uds) . ' €' : 'ud.' }}</span>
                                                </label>
                                            @endif
                                            <span class="text-neutral-900 w-24 text-right">{{ F::euros($l['importe']) }} €</span>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                            @if ($f['avisos'])
                                <ul class="mt-3 space-y-1 text-xs text-neutral-900">
                                    @foreach ($f['avisos'] as $a)
                                        <li class="pl-3 border-l-2 border-neutral-900">{{ $a }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            @if ($f['contacto_nombre'])
                                <p class="mt-2 text-xs text-neutral-500">
                                    Contacto en Holded: <span class="text-neutral-900">{{ $f['contacto_nombre'] }}</span>
                                    @if ($f['manual'])
                                        · elegido a mano ·
                                        <button type="submit" name="quitar" value="{{ $f['sociedad'] }}" formaction="{{ route('facturacion.contacto') }}" class="underline hover:text-neutral-900">Cambiar</button>
                                    @endif
                                </p>
                            @endif
                            @if ($f['estado'] === F::SIN_CONTACTO && $contactos)
                                <fieldset class="mt-3 rounded-lg border border-neutral-200 bg-neutral-50 p-3">
                                    <legend class="sr-only">Contacto de Holded para {{ $f['sociedad'] }}</legend>
                                    @if ($f['opciones'])
                                        <div class="space-y-1.5 mb-3">
                                            @foreach ($f['opciones'] as $id => $nombre)
                                                <label class="flex items-center gap-2 text-sm cursor-pointer">
                                                    <input type="radio" name="contacto[{{ $f['sociedad'] }}]" value="{{ $id }}" class="accent-neutral-900" @checked($loop->first && count($f['opciones']) === 1)>
                                                    {{ $nombre }}
                                                </label>
                                            @endforeach
                                        </div>
                                    @endif
                                    <div class="flex flex-wrap items-center gap-2">
                                        {{-- Enter pulsaría el primer botón del formulario (un «Crear»): se usa el de este contacto --}}
                                        <input type="search" name="buscar[{{ $f['sociedad'] }}]" list="holded-contactos" autocomplete="off"
                                            placeholder="{{ $f['opciones'] ? '¿No es ninguno? Busca en Holded…' : 'Busca el contacto en Holded…' }}" aria-label="Buscar contacto de Holded para {{ $f['sociedad'] }}"
                                            onkeydown="if (event.key === 'Enter') { event.preventDefault(); this.closest('fieldset').querySelector('button').click() }"
                                            class="flex-1 min-w-56 px-3 py-1.5 rounded-lg border border-neutral-200 bg-white text-sm">
                                        <button type="submit" name="sociedad" value="{{ $f['sociedad'] }}" formaction="{{ route('facturacion.contacto') }}" class="text-xs px-3 py-1.5 rounded-lg bg-neutral-900 text-white hover:bg-neutral-700">Usar este contacto</button>
                                    </div>
                                </fieldset>
                            @endif
                            @if ($f['borrador'])
                                <p class="mt-2 text-xs text-neutral-400">Creado el {{ $f['borrador']->created_at->timezone('Europe/Madrid')->format('d/m/Y H:i') }} · id {{ $f['borrador']->holded_id }}</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="mt-4 text-xs text-neutral-500">Puedes cambiar la descripción y las unidades de cada línea; el total de cada línea sale siempre de la hoja (más unidades = menor precio por unidad). Importes sin IVA; se añade el IVA del 21 %. Los borradores se revisan, aprueban y envían en Holded. La hoja no se modifica.</p>
        </form>
        <datalist id="holded-contactos">
            @foreach ($contactos as $nombre)
                <option value="{{ $nombre }}"></option>
            @endforeach
        </datalist>
    @elseif (! $error)
        <p class="text-neutral-500">La hoja no tiene importes para este mes.</p>
    @endif
</x-layouts.facturacion>
