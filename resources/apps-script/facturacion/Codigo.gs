/**
 * Lector de la hoja de KPIs para la facturación de watch.ongoing.es.
 * Script independiente (script.google.com), no dentro de la hoja: el archivo de la hoja no cambia.
 * Solo lee (permiso spreadsheets.readonly en appsscript.json) y solo responde a quien trae el token.
 * Lee con el servicio avanzado Sheets: SpreadsheetApp.openById exige permiso de escritura.
 *
 * Puesta en marcha:
 *  1. Pega aquí el ID de la hoja (docs.google.com/spreadsheets/d/<ID>/edit).
 *  2. Configuración del proyecto › Propiedades del script › añade TOKEN con el valor que te den.
 *  3. Implementar › Nueva implementación › Aplicación web · Ejecutar como: yo · Acceso: cualquier usuario.
 *  4. La URL que termina en /exec va al .env del panel como FACTURACION_APPS_SCRIPT_URL.
 */
const HOJA_ID = 'PEGA_AQUI_EL_ID_DE_LA_HOJA';
const PESTANA = 'BBDD KPIs Analysis';

function doGet(e) {
  const token = PropertiesService.getScriptProperties().getProperty('TOKEN');
  if (!token || !e || !e.parameter || e.parameter.token !== token) {
    return json_({ error: 'No autorizado' });
  }
  try {
    const r = Sheets.Spreadsheets.Values.get(HOJA_ID, "'" + PESTANA + "'", { valueRenderOption: 'UNFORMATTED_VALUE' });
    return json_({ values: r.values || [] });
  } catch (err) {
    return json_({ error: 'No se pudo leer la pestaña «' + PESTANA + '»: ' + err.message });
  }
}

function json_(datos) {
  return ContentService.createTextOutput(JSON.stringify(datos)).setMimeType(ContentService.MimeType.JSON);
}
