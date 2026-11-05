import { font } from '../../theme/typography';
import { useState } from 'react';
import { ScrollView, StyleSheet, Text } from 'react-native';

import { estibaTokens as t } from '../../theme/estibaTokens';
import {
  EntityCode, EstibaAlert, EstibaButton, EstibaEmpty, EstibaField, EstibaHeading,
  EstibaMetric, EstibaPanel, EstibaProgress, EstibaSignal, EstibaSurface, EstibaTable,
} from './EstibaPrimitives';

/** Muestra para desarrollo; no está registrada en la navegación productiva. */
export function EstibaCatalog() {
  const [reason, setReason] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('Los controles responden solo dentro de este catálogo.');

  return <ScrollView style={s.screen} contentContainerStyle={s.content} keyboardShouldPersistTaps="handled">
    <EstibaSurface style={s.content}>
      <EstibaHeading title="Fundaciones visuales" eyebrow="FoliOS · CATÁLOGO TÁCTIL" description="Ejemplos ficticios. Sin conexión a datos de planta." />
      <EstibaPanel title="Lectura del paso actual">
        <EstibaSignal label="En ejecución" tone="info" />
        <EntityCode value="FOL-EJ-001" large />
        <Text style={s.body}>Cámara C3 · Banda B08 · Posición P04</Text>
        <EstibaButton label="Confirmar pallet ubicado" variant="confirm" onPress={() => setNotice('Confirmación de ejemplo. No se registró un movimiento.')} />
        <EstibaButton label="No coincide" variant="secondary" onPress={() => setNotice('Ejemplo de excepción. No se creó una discrepancia.')} />
        <EstibaAlert title="Bajo maniobra" detail="FOL-EJ-002 · Extraído temporalmente. Requiere retorno." tone="temporary" />
      </EstibaPanel>
      <EstibaPanel title="Secuencia de ejemplo">
        <EstibaProgress steps={[
          { key: '1', label: 'Extraer FOL-EJ-002', state: 'complete' },
          { key: '2', label: 'Ubicar FOL-EJ-001', state: 'current' },
          { key: '3', label: 'Devolver FOL-EJ-002', state: 'return' },
        ]} />
      </EstibaPanel>
      <EstibaPanel title="Avance y ausencia de datos">
        <EstibaMetric label="Concentración" value={17} total={20} tone="success" detail="85% · Objetivo ilustrativo: 80%" />
        <EstibaMetric label="Separación" value={14} total={20} detail="70% · Objetivo ilustrativo: 100%" />
        <EstibaMetric label="Despacho" value={0} total={20} />
        <EstibaMetric label="Temperatura ambiente" value={null} total={null} detail="Sin registro manual. No se infiere el estado térmico." />
      </EstibaPanel>
      <EstibaPanel title="Entrada con validación local">
        <EstibaField label="Motivo de la revisión" value={reason} onChangeText={setReason} hint="Al menos 3 caracteres." error={error} />
        <EstibaButton label="Probar validación" onPress={() => {
          const invalid = reason.trim().length < 3;
          setError(invalid ? 'Completa un motivo de al menos 3 caracteres.' : '');
          if (!invalid) setNotice('Validación de ejemplo correcta. El texto no se envió ni se guardó.');
        }} />
        <EstibaButton label="Cancelar maniobra" variant="critical" onPress={() => setNotice('Acción crítica de ejemplo. No se canceló una maniobra.')} />
        <EstibaButton label="Sin permiso para resolver" disabled />
        <EstibaButton label="Guardando…" busy />
      </EstibaPanel>
      <EstibaAlert title="Respuesta del catálogo" detail={notice} live />
      <EstibaPanel title="Tabla con desplazamiento horizontal">
        <EstibaTable caption="Registros ficticios" columns={[
          { key: 'folio', label: 'Folio', width: 180 },
          { key: 'position', label: 'Ubicación', width: 220 },
          { key: 'state', label: 'Condición', width: 220 },
        ]} rows={[
          { key: '1', cells: { folio: 'FOL-EJ-001', position: 'C3 / B08 / P04', state: 'Reservado' } },
          { key: '2', cells: { folio: 'FOL-EJ-002', position: 'Bajo maniobra', state: 'Extraído temporalmente' } },
        ]} />
      </EstibaPanel>
      <EstibaPanel title="Estados de consulta">
        <EstibaEmpty title="No hay discrepancias abiertas" description="Se muestra solo después de una respuesta válida del servidor." />
        <EstibaAlert title="No se pudo actualizar" detail="Se conserva la información anterior. Una conexión fallida no equivale a una lista vacía." tone="warning" />
        <EstibaSignal label="Sin registro" />
        <EstibaSignal label="Confirmado" tone="success" />
        <EstibaSignal label="Reservado" tone="reserved" />
        <EstibaSignal label="Bloqueado" tone="blocked" />
        <EstibaSignal label="Emergencia" tone="critical" />
      </EstibaPanel>
    </EstibaSurface>
  </ScrollView>;
}

const s = StyleSheet.create({
  screen: { backgroundColor: t.color.canvas },
  content: { padding: t.space[4], gap: t.space[6] },
  body: { fontFamily: font.regular, fontSize: t.fontSize.body, lineHeight: t.fontSize.body * t.lineHeight.body, color: t.color.text },
});
