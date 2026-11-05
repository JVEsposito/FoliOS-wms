# PR 11 · Tablet: cabecera de terreno compacta

**Rama:** `claude/tablet-cabecera-compacta` · **Base:** `main` · **Entrega:** implementación
**Título del PR:** `feat(tablet): cabecera compacta con menú del operador`
**Archivos:** `mobile/src/components/operator/OperatorHeader.tsx`, `mobile/src/components/operator/OperatorMenu.tsx` (nuevo), `mobile/src/screens/OperationalWorkspaceScreen.tsx`, `mobile/App.tsx`

## Problema

Antes de llegar a la maniobra, la tablet del camarero apilaba tres franjas:

1. la franja de módulo activo de `App.tsx` («MÓDULO ACTIVO: OPERACIÓN FRIGORÍFICO · Cambiar módulo»);
2. la cabecera con gajo, persona, pastilla «Modo conectado», cuatro botones (Labores, Ambiente · N,
   Plano y operación, Salir) y el nombre de la tablet;
3. un banner rojo «N cámaras requieren control ambiental · Revisar ahora», que repetía el
   contador del botón Ambiente con tono crítico.

## Cambio

Una sola cabecera de 72 dp:

- **Navegación al centro:** «Mi jornada» (antes «Labores»), «Ambiente» y «Plano y operación».
  La pestaña activa se marca con fondo y texto cian; las inactivas no llevan borde.
- **Ambiente con contador ámbar** (tono de atención, no crítico). El banner rojo se retira: el
  contador es el aviso de nivel 2 en su forma base. La escalada a franja se especifica en el PR
  de avisos por nivel.
- **Estado de conexión compacto:** punto de color + «Conectado». Sin conexión conserva la
  pastilla ámbar con borde.
- **Menú del operador** (`OperatorMenu`, iniciales + ▾) en el extremo derecho: identidad,
  rol y tablet, «Cambiar módulo» (solo si la persona tiene más de un módulo) y «Cerrar sesión».
- **`App.tsx`** deja de dibujar la franja de módulo activo sobre el espacio del camarero
  (`operacion` y `operacion_materiales`); los demás módulos la mantienen.

## Qué no cambia

- Las vistas y su lógica (`labores`, `ambiente`, `operacion`), la consulta del control
  ambiental cada 60 s, la presencia del operador y el cierre de sesión (`logoutFromTasks`).
- La pantalla «Plano y operación» conserva su propio encabezado y su botón «Salir».

## Pruebas

- `tests/JavaScript/OperatorHeaderCompactTest.mjs` (nueva): sin banner rojo, contador en la
  pestaña, menú con «Cambiar módulo» y «Cerrar sesión», franja de módulo oculta solo para el
  espacio del camarero.
- Siguen verdes `OperatorTabletVisualTest`, `BrandIdentityTest`, `OperatorTaskHomeTest` y
  `mobile/tests/operatorPresence.test.mjs` (la pestaña «Plano y operación» conserva su texto).
