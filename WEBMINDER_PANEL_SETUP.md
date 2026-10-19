# Webminder: panel y worker

Panel Laravel (este proyecto) que gestiona WordPress de clientes a través del plugin
`webminder-worker/` (worker). Convive con **Cerrojo** (mu-plugin de seguridad propio), que
sigue siendo autónomo: el worker solo lee su estado y lanza su revisión.

## Cómo hablan

- Panel → worker: `POST https://sitio/wp-json/webminder/v1/<endpoint>`, firma HMAC-SHA256 de
  `METODO\nruta\ntimestamp\ncuerpo` en `X-Webminder-Signature-V2` (+ `X-Webminder-Timestamp`).
  El worker rechaza firmas de más de 15 min y las repetidas (replay).
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

## Pruebas

- Panel: `ddev artisan test`.
- Worker, dentro de un WordPress (DDEV) con el worker activo y Cerrojo en mu-plugins:
  `ddev wp eval-file prueba-worker.php` y `ddev wp eval-file prueba-actualizar.php`
  (copiar antes `tests/worker/*.php` a la raíz del WP). Ojo: si el WP tiene un SMTP real
  configurado, bloquear el correo antes (`pre_wp_mail` → true en un mu-plugin).
