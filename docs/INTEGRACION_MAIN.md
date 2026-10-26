# Integración en main

Un PR puede quedar como «merged» sin que su contenido llegue a `main`. Ocurre
cuando se fusiona en una rama intermedia: por ejemplo, en la rama de otro PR que
ya había entrado a main. Fue el caso de #508 y #509, fusionados en
`agent/planificador-compatibilidad-envases` y `agent/planificador-labores-coordinadas`
después de que esas ramas llegaran a main. Su contenido se recuperó en #513.

## Controles

**En cada PR** (`.github/workflows/integracion-main.yml`, trabajo *Base del PR*):
falla si el PR no apunta a `main`. Un PR apilado puede fusionarse igual, pero queda
a la vista que alguien debe integrar después su rama en main.

**Auditoría diaria y en cada push a main** (trabajo *Contenido fuera de main*):
recorre todas las ramas y las cabezas de todos los PR. Para cada una comprueba si
fusionarla en main cambiaría algo (`git merge-tree`). No confía en el estado
«merged» ni en los mensajes de commit: si el resultado es idéntico a main, el
contenido ya está integrado, aunque haya llegado por squash, cherry-pick o un PR
agregador (#476, #480, #485).

Se omiten los PR abiertos y las ramas sin PR con commits de los últimos tres días.
Ambos casos son trabajo en curso.

## Revisar un hallazgo

El resumen del flujo lista las cabezas con contenido fuera de main. Para cada una:

1. Ver qué falta: `git diff origin/main $(git merge-tree --write-tree origin/main <sha> | head -1)`.
2. Si debe llegar a main, abrir un PR contra `main` con ese contenido.
3. Si ya está con otra forma, o se reemplazó a propósito, registrarla en
   `scripts/integracion/revisados.txt` con su SHA completo, el estado y el motivo:
   - `integrado`: está en main con otra forma.
   - `obsoleto`: se reemplazó o no debe aplicarse.
   - `pendiente`: falta una decisión; se informa como aviso, sin fallar.

Si la rama recibe commits nuevos cambia su SHA y vuelve a revisarse.

Ejecución local (requiere git 2.38 o superior):

```sh
scripts/integracion/auditar-fuera-de-main.sh --traer
```

## Revisión del 25-10-2026

Sobre `main` en `2f65f86a` (#517), unas 1.000 ramas y cabezas de PR. Las cadenas
apiladas de Finanzas, RRHH, SAG, COMEX y envases (#460–#465, #468, #470–#474,
#477–#479, #482–#484, #487) llegaron completas por sus PR agregadores y no generan
hallazgos. Quedaron 21 cabezas con diferencias:

- **Integradas con otra forma (16):** #508 y #509, con sus dos ramas intermedias, ya en
  main vía #513; cuatro ramas cuyos parches ya están en main; y PR antiguos con
  refactor posterior (#158, #169/#170, #301, #401, #492).
- **Obsoletas (3):** el shell «Estiba» (#269) y el contraste de septiembre, ambos
  reemplazados por el rediseño FoliOS. También el renombre de la migración de envases
  `2026_10_16_090000`: ya corrió con su nombre y renombrarla haría que se reejecute.
- **Pendientes de decisión (2):**
  - #196: filtros de folios en prefrío. Exigía exportadora y especie, y luego
    filtraba por variedad, CSG y fecha de ingreso. Nunca llegó a main, que solo
    tiene búsqueda libre.
  - #511: implementación alternativa de varias bodegas. Main usa #510. #511 incluía
    «bodegas sin plano»: la entrega se hace desde la orden móvil, sin cámara ni sesión.
