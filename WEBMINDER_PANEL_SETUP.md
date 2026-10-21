# Webminder: panel y worker

Panel Laravel (este proyecto) que gestiona WordPress de clientes a través del plugin
`webminder-worker/` (worker). Convive con **Cerrojo** (mu-plugin de seguridad propio), que
sigue siendo autónomo: el worker solo lee su estado y lanza su revisión.

## Cómo hablan

- Panel → worker: `POST https://sitio/wp-json/webminder/v1/<endpoint>`, firma HMAC-SHA256 de
  `METODO\nruta\ntimestamp\ncuerpo` en `X-Webminder-Signature-V2` (+ `X-Webminder-Timestamp`).
  El worker rechaza firmas de más de 15 min y las repetidas (replay).
- Operaciones largas (copia de BD, actualización, revisión de Cerrojo): el panel manda un `job`,
  el worker (≥ 2.1) responde al momento y sigue en segundo plano; el panel consulta `/job` cada 5 s.
  Así Cloudflare (corta a los 100 s con HTTP 524) no interrumpe nada.
- Worker → panel: log de actividad a `POST /api/webminder/ingest`, misma firma, idempotente.
- Autoactualización del worker: manifiesto y zip servidos por el panel en `/webminder/worker/`.
- Mientras quede algún worker < 2.0 el panel envía también la firma v1 (ver `shortcut:` en
  `AgenteComunicacionService`). Los 1.x no tienen rotación, copia de BD, Cerrojo ni ingesta.

## Vincular un sitio

1. Panel → Sitios → nuevo sitio (genera credenciales) → Credenciales API → copiar el token.
2. En el WordPress: instalar el worker, Herramientas → Ongoing Mantenimiento → pegar el token.
   El token lleva la URL del panel (de `APP_URL`), de ahí salen la ingesta y el manifiesto.
3. SSO 1-clic: necesita una cuenta `fernando@ongoing.es` o `soporte@ongoing.es` con rol
   administrador. Nunca entra con la del cliente. **Con Cerrojo**, esa cuenta debe estar en su
   lista blanca o la degrada a suscriptor: `wp cerrojo admin <usuario>`.

## Desplegar en Plesk (91.146.99.114)

1. `.env` de producción: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<dominio-del-panel>`
   (https obligatorio: el worker rechaza manifiestos http), `QUEUE_CONNECTION=database`,
   `DB_QUEUE_RETRY_AFTER=3700`. La `APP_KEY` cifra los secretos de los sitios: si se pierde,
   hay que volver a vincular todos.
2. `composer install --no-dev -o`, `npm ci && npm run build`, `php artisan migrate --force`,
   `php artisan config:cache route:cache view:cache`.
3. Un único cron en Plesk, cada minuto: `php artisan schedule:run` (lanza la cola de
   actualizaciones y `webminder:verificar` cada hora).
4. Publicar el worker: `php artisan webminder:worker-zip` (tras cambiar `VERSION` en el plugin).
   Los sitios lo ven como actualización normal de WordPress en ≤12 h.
5. Cerrojo: copiar el `cerrojo.php` vigente (de `~/Projects/cerrojo`) a `storage/app/cerrojo/cerrojo.php`
   (no va en git). Es la versión que instala «Actualizar en todas» en la sección Cerrojo.

## Comparación visual (fase 2)

Antes de cada plugin/tema el panel captura la portada (escritorio 1366 px y móvil 390 px) dos
veces; lo que cambia entre ellas es ruido (sliders, fechas) y se ignora. Tras actualizar captura
otra vez: si cambia más del 3 % de la página el elemento queda en "la web se ve distinta", con
las capturas de antes, después y los cambios en rojo, y un botón **Revertir** (el worker ≥ 2.2
guarda la versión anterior 7 días). Las capturas se borran a los 30 días.

Usa Cloudflare Browser Run (Chrome sin pantalla en Cloudflare). En el `.env`:
`WEBMINDER_CF_ACCOUNT_ID` (Cloudflare → la cuenta → ID de cuenta) y `WEBMINDER_CF_TOKEN` (token de
API con permiso **Browser Rendering - Edit**). Sin ellas la comparación queda apagada.
Plan gratuito: 1 captura cada 10 s y 10 min de navegador al día (~6 capturas por elemento,
unos 15-20 elementos al día); el panel espera solo cuando Cloudflare responde 429. Con Workers
Paid (5 $/mes) no hay límite diario.

## Avisos de caída y mantenimiento nocturno (watchWP)

- `webminder:vigilar` cada 5 min: pide la portada sin caché; si no responde o da 5xx dos veces
  seguidas (30 s entre medias) manda «🔴 está caída»; cuando vuelve, «🟢 vuelve a funcionar» con el
  tiempo caída. Un 4xx no cuenta como caída. De paso rescata lotes atascados (ver abajo).
- `webminder:nocturna` a las 23:00 (Madrid): lee qué hay pendiente en cada sitio (núcleo, plugins,
  temas y traducciones, en ese orden) y crea un lote por sitio con la misma `ronda`. Los lotes van de
  uno en uno; cuando acaba el último se manda el informe (una sola vez por ronda).
- **Por pasos:** `ActualizarSitio` hace un paso por job (copia de BD o un ítem) y encola el siguiente
  10 s después. El id del trabajo en el sitio se guarda en `job_remoto`: si el proceso del panel muere,
  la cola repite el job (tras `retry_after`, 2500 s) y retoma ese trabajo en vez de lanzarlo otra vez.
  Un lote en curso 90 min sin avanzar se da por cortado; cualquier lote interrumpido manda «⚠».
- El núcleo no tiene vuelta atrás de archivos: si la web falla después, el lote se para y avisa (hay
  copia de BD).
- `.env` de producción:
  ```
  MAIL_MAILER=smtp
  MAIL_SCHEME=smtps
  MAIL_HOST=smtp.resend.com
  MAIL_PORT=465
  MAIL_USERNAME=resend
  MAIL_PASSWORD=re_...            # API key de Resend con permiso "Sending access"
  MAIL_FROM_ADDRESS=watch@ongoing.es   # dominio verificado en Resend
  MAIL_FROM_NAME=watchWP
  WEBMINDER_AVISOS_EMAIL=...
  DB_QUEUE_RETRY_AFTER=2500       # o quitarla: si es menor que 2400 un paso se lanzaría dos veces
  ```
  Probar con `php artisan webminder:probar-correo` (Laravel Toolkit → Artisan).

## Injerto (aprobación de código y vigilancia de scripts)

- El plugin Injerto (`~/Projects/injerto`, fuera de este repo) no publica nada sin una firma
  Ed25519 del panel. Guardar en la web → el worker (≥ 2.4.0) lo manda como evento crítico
  `injerto_solicitud` por la ingesta → aparece en **Injerto** del panel y se avisa a
  `WEBMINDER_AVISOS_EMAIL` → al aprobar, el panel firma y lo envía a `/injerto` del worker.
- Clave privada: `WEBMINDER_INJERTO_CLAVE` en el `.env` (copia en `~/.ssh/watchwp-injerto.key`
  de Michell). La pública está escrita en el plugin (`INJERTO_CLAVE_PUBLICA`). Si se pierde la
  privada: `php artisan webminder:injerto-claves`, nueva pública en el plugin y reaprobar todo.
- Vigilancia: en las webs con Injerto (`tiene_injerto`, sale del /status horario), `webminder:vigilar`
  apunta los dominios externos de los scripts de la portada. La primera vez es la referencia; un
  dominio nuevo no aprobado avisa una vez y queda en **Injerto** para aceptarlo u olvidarlo.

## Pruebas

- Panel: `ddev artisan test`.
- Worker, dentro de un WordPress (DDEV) con el worker activo y Cerrojo en mu-plugins:
  `ddev wp eval-file prueba-worker.php` y `ddev wp eval-file prueba-actualizar.php`
  (copiar antes `tests/worker/*.php` a la raíz del WP). Ojo: si el WP tiene un SMTP real
  configurado, bloquear el correo antes (`pre_wp_mail` → true en un mu-plugin).
