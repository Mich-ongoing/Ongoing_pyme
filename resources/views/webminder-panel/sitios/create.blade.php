<x-layouts.panel title="Nuevo Sitio Web - Webminder Panel" headerTitle="Registrar Nuevo Sitio Web" headerSubtitle="Vincular un sitio cliente a la plataforma central">

    <div class="max-w-2xl mx-auto">
        <flux:card class="bg-slate-950/60 border border-slate-800 p-8 rounded-2xl">
            <div class="mb-6 pb-4 border-b border-slate-800">
                <flux:heading size="lg" class="text-slate-100 font-bold">Datos del Sitio Web</flux:heading>
                <flux:subheading class="text-slate-400 text-xs">Completa la información del sitio y asigna el cliente propietario</flux:subheading>
            </div>

            @if ($errors->any())
                <div class="bg-rose-500/10 border border-rose-500/20 text-rose-400 p-4 rounded-xl text-sm mb-6">
                    <ul class="space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('webminder-panel.sitios.store') }}" method="POST" class="space-y-5">
                @csrf

                <flux:field>
                    <flux:label class="text-slate-300">Cliente Propietario</flux:label>
                    <select name="cliente_id" id="cliente_id" required class="w-full px-4 py-2.5 rounded-xl bg-slate-900 border border-slate-800 text-slate-100 text-sm focus:border-indigo-500 focus:outline-none">
                        <option value="">-- Selecciona un cliente --</option>
                        @foreach($clientes as $cliente)
                            <option value="{{ $cliente->id }}">{{ $cliente->nombre }} ({{ $cliente->empresa ?? 'Particular' }})</option>
                        @endforeach
                    </select>
                </flux:field>

                <flux:field>
                    <flux:label class="text-slate-300">Nombre del Sitio Web</flux:label>
                    <flux:input 
                        type="text" 
                        name="nombre" 
                        placeholder="Ej. Mi Tienda WordPress" 
                        required 
                        class="bg-slate-950/60 border-slate-800 text-white placeholder-slate-500"
                    />
                </flux:field>

                <flux:field>
                    <flux:label class="text-slate-300">URL Completa del Sitio (con https://)</flux:label>
                    <flux:input 
                        type="url" 
                        name="url" 
                        placeholder="https://mitienda.es" 
                        required 
                        class="bg-slate-950/60 border-slate-800 text-white placeholder-slate-500"
                    />
                </flux:field>

                <flux:field>
                    <flux:label class="text-slate-300">Tecnología Principal</flux:label>
                    <select name="tecnologia" id="tecnologia" class="w-full px-4 py-2.5 rounded-xl bg-slate-900 border border-slate-800 text-slate-100 text-sm focus:border-indigo-500 focus:outline-none">
                        <option value="WordPress" selected>WordPress</option>
                        <option value="Laravel">Laravel</option>
                        <option value="WooCommerce">WooCommerce (WordPress)</option>
                        <option value="Otro">Otro</option>
                    </select>
                </flux:field>

                <flux:field>
                    <flux:label class="text-slate-300">Descripción / Notas Adicionales</flux:label>
                    <textarea name="descripcion" id="descripcion" rows="3" placeholder="Información opcional sobre el servidor o contrato" class="w-full px-4 py-2.5 rounded-xl bg-slate-950/60 border border-slate-800 text-slate-100 text-sm placeholder-slate-500 focus:border-indigo-500 focus:outline-none"></textarea>
                </flux:field>

                <div class="pt-4 flex gap-3">
                    <flux:button type="submit" variant="primary" class="flex-1 bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-3 rounded-xl shadow-lg shadow-indigo-600/30 transition cursor-pointer">
                        Guardar Sitio y Generar Credenciales API
                    </flux:button>
                    <flux:button href="/webminder-panel/sitios" variant="filled" class="bg-slate-900 hover:bg-slate-800 text-slate-300 px-5 py-3 rounded-xl border border-slate-800">
                        Cancelar
                    </flux:button>
                </div>
            </form>
        </flux:card>
    </div>

</x-layouts.panel>
