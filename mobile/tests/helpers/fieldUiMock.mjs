import React from 'react';
import { font, fontForWeight } from '../../src/theme/typography.ts';

// Los arneses de operaciones simulan la cabecera; sus acciones contextuales siguen
// montadas. fieldHeader.test.mjs verifica la cabecera y el menú reales por separado.
export function fieldUiMock(id) {
  if (id.endsWith('/theme/typography')) return { font, fontForWeight };
  if (id.endsWith('/field/FieldHeader')) return {
    FieldHeader: ({ children, title, ...props }) => React.createElement('FieldHeader', props, React.createElement('Text', null, title), props.subtitle ? React.createElement('Text', null, props.subtitle) : null, children),
  };
  if (id.endsWith('/field/FieldNavigation')) return {
    FieldNavigationProvider: ({ children }) => children,
  };
  return undefined;
}
