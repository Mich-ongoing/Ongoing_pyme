<?php

namespace App\Webminder\Panel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AligeraLicencia extends Model
{
    protected $table = 'aligera_licencias';

    protected $guarded = ['id'];

    protected $casts = [
        'caduca_en' => 'datetime',
    ];

    /** plan => [nombre, webs] (precios en la landing: 39, 79 y 199 € al año) */
    public const PLANES = [
        'personal' => ['Personal', 1],
        'profesional' => ['Profesional', 3],
        'agencia' => ['Agencia', 25],
    ];

    /** Margen tras la fecha de renovación: Stripe reintenta el cobro unos días antes de cancelar */
    public const GRACIA_DIAS = 7;

    public function activaciones(): HasMany
    {
        return $this->hasMany(AligeraActivacion::class, 'licencia_id');
    }

    public static function nueva(string $email, string $plan, \DateTimeInterface $caduca, array $extra = []): self
    {
        return self::create($extra + [
            'clave' => 'ALG-' . implode('-', str_split(strtoupper(bin2hex(random_bytes(8))), 4)),
            'email' => $email,
            'plan' => $plan,
            'sitios_max' => self::PLANES[$plan][1],
            'caduca_en' => $caduca,
        ]);
    }

    public function vigente(): bool
    {
        return $this->caduca_en->isFuture();
    }

    /** «www.Acme.es/tienda» y «https://acme.es» cuentan como la misma web. */
    public static function dominio(string $url): string
    {
        $host = parse_url(str_contains($url, '://') ? $url : 'https://' . $url, PHP_URL_HOST) ?: '';

        return preg_replace('/^www\./', '', strtolower($host));
    }

    /** La clave al comprador. Si falla, no se pierde: está en el panel y se puede reenviar. */
    public function enviarClave(): bool
    {
        $nombre = self::PLANES[$this->plan][0];
        $webs = $this->sitios_max === 1 ? '1 web' : "{$this->sitios_max} webs";
        $portal = config('services.aligera.portal');
        $texto = "¡Gracias por comprar Aligera Pro!\n\n"
            . "Tu clave de licencia: {$this->clave}\n"
            . "Plan {$nombre} ({$webs}), válida hasta el " . $this->caduca_en->timezone('Europe/Madrid')->format('d/m/Y') . " y se renueva sola cada año.\n\n"
            . "Para activarla: en WordPress ve a Aligera → Licencia, pega la clave y pulsa «Activar». Con la licencia activa recibirás las actualizaciones en Escritorio → Actualizaciones, como cualquier plugin.\n\n"
            . 'Descarga del plugin: ' . route('aligera.descargar', $this->clave) . "\n"
            . ($portal ? "Facturas, cambio de plan o de tarjeta y cancelar la renovación: {$portal}\n" : '')
            . "\nSi tienes cualquier duda, responde a este correo.\n";
        try {
            Mail::raw($texto, fn ($m) => $m->to($this->email)->subject('Tu licencia de Aligera Pro'));

            return true;
        } catch (\Throwable $e) {
            Log::error("Aligera: no se pudo enviar la clave {$this->id} a {$this->email}: {$e->getMessage()}");

            return false;
        }
    }
}
