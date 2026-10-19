# Webminder - Sistema de Gestión Remota

Sistema de gestión remota compatible con el panel de control Pymenetica. Permite monitoreo, mantenimiento y actualizaciones de la aplicación desde un panel centralizado.

## Funcionalidades

- ✅ **Autenticación por Firma HMAC-SHA256** - Comunicación segura con el panel
- ✅ **Status & Health Reporting** - Reporte integral de salud de la aplicación
- ✅ **Modo Mantenimiento** - Mostrar página 503 a usuarios mientras se hacen actualizaciones
- ✅ **Limpieza de Base de Datos** - Limpiar revisiones, spam, meta huérfana
- ✅ **Monitoreo de Actualizaciones** - Detectar dependencias disponibles
- ✅ **Single Sign-On (SSO)** - Acceso rápido para administradores del panel
- ✅ **Seguridad** - Detectar y corregir problemas de seguridad comunes

## Instalación

### 1. Configuración del Entorno

Agregar al archivo `.env`:

```bash
WEBMINDER_CLIENT_ID=your_client_id
WEBMINDER_CLIENT_SECRET=your_client_secret
WEBMINDER_BRAND_NAME=Ongoing
WEBMINDER_BRAND_URL=https://ongoing.es
WEBMINDER_BRAND_COLOR=#4441FF
WEBMINDER_BRAND_COLOR_DARK=#3A37D9
```

### 2. Publicar Configuración

```bash
php artisan vendor:publish --tag=webminder-config
```

Esto crea `config/webminder.php` con todas las opciones disponibles.

### 3. Agregar Middleware a Kernel

Editar `app/Http/Kernel.php`:

```php
protected $middleware = [
    // ... otros middleware
    \App\Webminder\Http\Middleware\MaintenanceMode::class,
];
```

## Endpoints Disponibles

Todos los endpoints están en la ruta `/api/webminder/`

### Sin Autenticación

#### `GET /ping`
Health check sin autenticación.

**Respuesta:**
```json
{
  "success": true,
  "timestamp": 1721648000
}
```

---

### Con Firma HMAC-SHA256

#### `POST /status`
Reporte completo de estado del sitio.

**Headers requeridos:**
```
X-Webminder-Signature: sha256=<signature>
X-Webminder-Timestamp: <unix_timestamp>
```

**Respuesta:**
```json
{
  "success": true,
  "data": {
    "site": {
      "name": "Mi Sitio",
      "url": "https://miapp.com",
      "version": "11.x.x",
      "php_version": "8.2.0",
      "environment": "production",
      "debug_mode": false
    },
    "database": {
      "connected": true,
      "driver": "mysql",
      "size_mb": 150.5,
      "tables_count": 42
    },
    "filesystem": {
      "storage_writable": true,
      "bootstrap_cache_writable": true,
      "public_writable": false
    },
    "cache": {
      "driver": "file",
      "working": true
    },
    "app": {
      "memory_usage": {
        "current_mb": 45.2,
        "peak_mb": 128.5,
        "limit_mb": 256
      }
    }
  },
  "configuration": {
    "maintenance_mode": false,
    "update_failures": {},
    "client_configured": true
  }
}
```

---

#### `POST /maintenance-mode`
Activar o desactivar el modo mantenimiento.

**Body:**
```json
{
  "active": true
}
```

**Respuesta:**
```json
{
  "success": true,
  "message": "Maintenance mode activated",
  "active": true
}
```

---

#### `POST /db-status`
Obtener métricas de salud de la base de datos.

**Respuesta:**
```json
{
  "success": true,
  "data": {
    "total_tables": 42,
    "total_size_mb": 150.5,
    "orphaned_postmeta": 3,
    "revision_posts": 25,
    "spam_comments": 0,
    "trash_posts": 5
  }
}
```

---

#### `POST /db-clean`
Limpiar la base de datos.

**Body:**
```json
{
  "clean_revisions": true,
  "clean_spam": true,
  "clean_orphaned_meta": true,
  "clean_trash": true,
  "optimize_tables": true
}
```

**Respuesta:**
```json
{
  "success": true,
  "message": "Database cleanup completed",
  "cleaned": ["clean_revisions", "clean_spam", "clean_orphaned_meta", "clean_trash", "optimize_tables"],
  "deleted_revisions": 25,
  "deleted_spam_comments": 0,
  "deleted_orphaned_meta": 3,
  "deleted_trash_posts": 5,
  "optimized_tables": 42
}
```

---

#### `POST /disconnect`
Desconectar este servidor del panel.

**Respuesta:**
```json
{
  "success": true,
  "message": "Agent successfully disconnected from panel"
}
```

---

#### `POST /updates`
Obtener actualizaciones disponibles de dependencias.

**Respuesta:**
```json
{
  "success": true,
  "data": {
    "laravel_framework": "11.x.x",
    "php_version": "8.2.0",
    "composer_packages": []
  }
}
```

---

#### `POST /plugins`
Obtener lista de paquetes instalados.

**Respuesta:**
```json
{
  "success": true,
  "data": {
    "laravel_version": "11.x.x",
    "php_version": "8.2.0",
    "packages": [
      {
        "name": "laravel/framework",
        "version": "11.x.x",
        "license": "MIT"
      }
    ]
  }
}
```

---

#### `POST /site-health`
Reporte completo de salud del sitio.

**Respuesta:**
```json
{
  "success": true,
  "data": {
    "site": { ... },
    "database": { ... },
    "filesystem": { ... },
    "cache": { ... },
    "app": { ... }
  }
}
```

---

#### `POST /sso`
Solicitar acceso SSO para administrador.

**Body:**
```json
{
  "user_email": "admin@ongoing.es"
}
```

**Respuesta:**
```json
{
  "success": true,
  "message": "SSO request accepted",
  "login_url": "https://miapp.com/admin",
  "token": "abc123def456",
  "expires_at": 1721648000
}
```

---

#### `POST /fix-security`
Corregir problemas de seguridad comunes.

**Body:**
```json
{
  "issue": "file_edit"
}
```

**Respuesta:**
```json
{
  "success": true,
  "message": "Security issue 'file_edit' fixed",
  "issue": "file_edit"
}
```

---

## Seguridad - Validación de Firma

El cliente debe firmar cada request con:

```php
$clientSecret = config('webminder.client_secret');
$payload = $request->getContent();
$timestamp = time();
$signature = 'sha256=' . hash_hmac('sha256', $payload . $timestamp, $clientSecret);
```

Headers requeridos:
```
X-Webminder-Signature: {$signature}
X-Webminder-Timestamp: {$timestamp}
```

**Tolerancia:** 15 minutos (900 segundos) para evitar replay attacks.

## Vista de Mantenimiento

Cuando `maintenance_mode` está activo, los usuarios no-administrador ven una página 503:

- Status HTTP: **503 Service Unavailable**
- Header: **Retry-After: 3600**
- Headers no-cache: Previene caché stale
- Acceso para administradores: **Bypassed** (pueden seguir usando la app)

## Estructura de Directorios

```
app/Webminder/
├── Http/
│   ├── Controllers/
│   │   └── WebminderController.php
│   └── Middleware/
│       ├── VerifyWebminderSignature.php
│       └── MaintenanceMode.php
├── Services/
│   ├── StatusService.php
│   ├── DatabaseService.php
│   └── ConfigurationService.php
├── Providers/
│   └── WebminderServiceProvider.php
└── Models/
    └── (para futuros modelos)

config/
└── webminder.php

routes/
└── webminder.php

resources/views/webminder/
└── maintenance.blade.php
```

## Límites y Configuración

- **Database cleanup max:** 500 posts/request (evita timeout)
- **Timestamp tolerance:** 900 segundos (15 minutos)
- **SSO token lifetime:** 3600 segundos (1 hora)
- **Cache driver:** Configurable (file, redis, memcached, etc.)

## Logging

Todos los eventos de Webminder se logean en:
- `storage/logs/laravel.log` - Eventos principales
- Middleware verifica firmas y rechaza requests inválidas

## Pruebas

Para probar manualmente los endpoints:

```bash
# Generar firma
php artisan tinker
$secret = config('webminder.client_secret');
$payload = '{}';
$timestamp = time();
$sig = 'sha256=' . hash_hmac('sha256', $payload . $timestamp, $secret);

# Test ping (sin auth)
curl -X GET http://localhost/api/webminder/ping

# Test status (con auth)
curl -X POST http://localhost/api/webminder/status \
  -H "X-Webminder-Signature: $sig" \
  -H "X-Webminder-Timestamp: $timestamp" \
  -H "Content-Type: application/json" \
  -d '{}'
```

## Extensiones Futuras

- [ ] Ejecutar actualizaciones de composer
- [ ] Backup automático antes de cambios
- [ ] Historial de eventos y auditoría
- [ ] Alertas y notificaciones
- [ ] Monitoreo de cron jobs
- [ ] Validación de dependencias de licencia
- [ ] Reporting en tiempo real

## License

Desarrollado para ONGOING DIGITAL SL
