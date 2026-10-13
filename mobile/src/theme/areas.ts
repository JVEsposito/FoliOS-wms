import type { ImageSourcePropType } from 'react-native';

import { estibaTokens as t } from './estibaTokens';

/**
 * Áreas FoliOS v2 en terreno: cada una es una hoja del gajo. El color `bright` es el de
 * superficies oscuras (encabezado y regla inferior). Ver docs/diseno/folios-v2/README.md § 1.3.
 */
export type AreaKey = 'operacion' | 'materia-prima' | 'frigorifico' | 'materiales' | 'consultas' | 'gerencia' | 'administracion';

type AreaDefinition = { label: string; bright: string; tint: string; line: string; gajo: ImageSourcePropType };

export const areas: Record<AreaKey, AreaDefinition> = {
  operacion: { label: 'Operación ahora', ...colors('operacion'), gajo: require('../../assets/gajo/gajo-operacion.png') },
  'materia-prima': { label: 'Materia Prima', ...colors('materiaPrima'), gajo: require('../../assets/gajo/gajo-materia-prima.png') },
  frigorifico: { label: 'Frigorífico', ...colors('frigorifico'), gajo: require('../../assets/gajo/gajo-frigorifico.png') },
  materiales: { label: 'Materiales', ...colors('materiales'), gajo: require('../../assets/gajo/gajo-materiales.png') },
  consultas: { label: 'Consultas', ...colors('consultas'), gajo: require('../../assets/gajo/gajo-consultas.png') },
  gerencia: { label: 'Gerencia', ...colors('gerencia'), gajo: require('../../assets/gajo/gajo-gerencia.png') },
  administracion: { label: 'Administración', ...colors('administracion'), gajo: require('../../assets/gajo/gajo-administracion.png') },
};

function colors(key: keyof typeof t.areaDark) {
  const area = t.areaDark[key];
  return { bright: area.bright, tint: area.tint, line: area.line };
}
