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
    let comentarios = {}, errorComentarios = null;
    try {
      comentarios = comentarios_();
    } catch (err) {
      errorComentarios = err.message; // sin comentarios el panel usa las descripciones por defecto
    }
    return json_({ values: r.values || [], comentarios: comentarios, error_comentarios: errorComentarios });
  } catch (err) {
    return json_({ error: 'No se pudo leer la pestaña «' + PESTANA + '»: ' + err.message });
  }
}

/** Primer comentario de cada celda de la pestaña: { "fila,columna": texto }, contando desde A1 = "0,0". */
function comentarios_() {
  // Por REST y no con el servicio Sheets: commentsViewMode es reciente y el servicio avanzado puede no conocerlo
  const url = 'https://sheets.googleapis.com/v4/spreadsheets/' + HOJA_ID
    + '?ranges=' + encodeURIComponent("'" + PESTANA + "'")
    + '&commentsViewMode=COMMENTS_VIEW_MODE_INCLUDED'
    + '&fields=' + encodeURIComponent('comments(anchorId,headPost(content,createTime)),sheets(commentAnchors)');
  const res = UrlFetchApp.fetch(url, { headers: { Authorization: 'Bearer ' + ScriptApp.getOAuthToken() }, muteHttpExceptions: true });
  if (res.getResponseCode() !== 200) throw new Error('HTTP ' + res.getResponseCode() + ': ' + res.getContentText().slice(0, 300));
  const r = JSON.parse(res.getContentText());
  const celdas = {};
  ((r.sheets && r.sheets[0] && r.sheets[0].commentAnchors) || []).forEach(function (a) {
    celdas[a.anchorId] = (a.range.startRowIndex || 0) + ',' + (a.range.startColumnIndex || 0);
  });
  const primero = {};
  (r.comments || []).forEach(function (c) {
    const celda = celdas[c.anchorId];
    if (!celda || !c.headPost) return;
    if (!primero[celda] || c.headPost.createTime < primero[celda].createTime) primero[celda] = c.headPost;
  });
  const salida = {};
  Object.keys(primero).forEach(function (k) { salida[k] = primero[k].content; });
  return salida;
}

function json_(datos) {
  return ContentService.createTextOutput(JSON.stringify(datos)).setMimeType(ContentService.MimeType.JSON);
}
