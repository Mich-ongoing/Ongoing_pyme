<x-layouts.panel title="Nuevo Cliente - Webminder Panel" headerTitle="Registrar Nuevo Cliente" headerSubtitle="Dar de alta una nueva cuenta de cliente en la plataforma">

    <div class="max-w-xl mx-auto">
        <flux:card class="bg-slate-950/60 border border-slate-800 p-8 rounded-2xl">
            <div class="mb-6 pb-4 border-b border-slate-800">
                <flux:heading size="lg" class="text-slate-100 font-bold">Datos del Cliente</flux:heading>
            </div>

            <form action="{{ route('webminder-panel.clientes.store') }}" method="POST" class="space-y-5">
                @csrf

                <flux:field>
                    <flux:label class="text-slate-300">Nombre Completo</flux:label>
                    <flux:input 
                        type="text" 
                        name="nombre" 
                        placeholder="Ej. Juan Pérez" 
                        required 
                        class="bg-slate-950/60 border-slate-800 text-white placeholder-slate-500"
                    />
                </flux:field>

                <flux:field>
                    <flux:label class="text-slate-300">Empresa / Razón Social</flux:label>
                    <flux:input 
                        type="text" 
                        name="empresa" 
                        placeholder="Ej. Ongoing Digital S.L." 
                        class="bg-slate-950/60 border-slate-800 text-white placeholder-slate-500"
                    />
                </flux:field>

                <flux:field>
                    <flux:label class="text-slate-300">Correo Electrónico</flux:label>
                    <flux:input 
                        type="email" 
                        name="email" 
                        placeholder="cliente@empresa.com" 
                        required 
                        class="bg-slate-950/60 border-slate-800 text-white placeholder-slate-500"
                    />
                </flux:field>

                <flux:field>
                    <flux:label class="text-slate-300">Teléfono de Contacto</flux:label>
                    <flux:input 
                        type="text" 
                        name="telefono" 
                        placeholder="+34 600 000 000" 
                        class="bg-slate-950/60 border-slate-800 text-white placeholder-slate-500"
                    />
                </flux:field>

                <div class="pt-4 flex gap-3">
                    <flux:button type="submit" variant="primary" class="flex-1 bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-3 rounded-xl shadow-lg shadow-indigo-600/30 transition cursor-pointer">
                        Guardar Cliente
                    </flux:button>
                    <flux:button href="/webminder-panel/clientes" variant="filled" class="bg-slate-900 hover:bg-slate-800 text-slate-300 px-5 py-3 rounded-xl border border-slate-800">
                        Cancelar
                    </flux:button>
                </div>
            </form>
        </flux:card>
    </div>

</x-layouts.panel>
