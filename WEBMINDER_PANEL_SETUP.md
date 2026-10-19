# Webminder Panel - Guía de Configuración e Integración

Este módulo proporciona la funcionalidad del **Panel Centralizado de Control** para administrar sitios cliente gestionados con Webminder.

## Arquitectura Integrada

- **Namespace Backend:** `App\Webminder\Panel`
- **Rutas del Panel:** `/webminder-panel/*` (definidas en `routes/webminder_panel.php`)
- **ServiceProvider:** `App\Webminder\Panel\Providers\WebminderPanelServiceProvider`
- **Tablas de Base de Datos:**
  - `panel_clientes`
  - `panel_sitios_web`
  - `panel_credenciales`
  - `panel_estados_sitio`
  - `panel_eventos_auditoria`

## Puntos de Entrada

- **Dashboard Principal:** `GET /webminder-panel`
- **Resumen JSON:** `GET /webminder-panel/resumen`
- **Gestión de Clientes:** `/webminder-panel/clientes`
- **Gestión de Sitios Web:** `/webminder-panel/sitios`
- **Auditoría de Eventos:** `/webminder-panel/auditoria`

## Migraciones

Las tablas del panel se crean mediante la migración:
`database/migrations/2026_07_22_000001_create_webminder_panel_tables.php`

Para ejecutar las migraciones:
```bash
ddev artisan migrate
```
