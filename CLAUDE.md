# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

FoliOS es una WMS para una planta agroindustrial: Romana, Validación MP/PT, Prefrío, Cámaras/estiba, Cargas, Despacho, Materiales, Envases, y además Finanzas, Comex y RRHH. El código, los nombres de clases, la documentación y los mensajes de commit están en español.

## Comandos

Backend (Laravel 13, PHP 8.3, `ext-zip` obligatorio):

```bash
composer install && npm ci
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed      # datos demo solo en local/testing; contraseña "password"
composer dev                          # serve + queue:listen + pail + vite en paralelo
npm run build                         # Vite
```

Pruebas y estilo:

```bash
php artisan test                                  # suite completa (Unit + Feature)
php artisan test --filter=NombreDelTest           # una clase o un método
php artisan test tests/Feature/Api/AlgoTest.php   # un archivo
vendor/bin/pint --test                            # estilo (CI falla si no pasa); sin --test lo corrige
npm run test:js                                   # node --test tests/JavaScript/*.mjs
npm run test:browser                              # Playwright sobre Oficina (tests/Browser)
npm run design:check                              # tokens de diseño sincronizados
php artisan ui:catalogo                           # exporta storage/app/ui/catalogo.html
```

`phpunit.xml` usa SQLite en memoria, pero CI corre la suite sobre **MySQL 8.4** con `--fail-on-warning --stop-on-failure`. Las pruebas de concurrencia, deadlocks y snapshots (`*Concurrencia*Test`, `*MysqlTest`, `EjecutaSolicitudesConcurrentes`) se omiten en SQLite. Para reproducir CI, apunta `DB_CONNECTION=mysql` a una base de prueba y corre `php artisan migrate:fresh --force` antes de la suite.

App móvil (`mobile/`, Expo + React Native + TypeScript):

```bash
cd mobile && npm ci
npm run typecheck          # tsc --noEmit
npm run test:catalog       # node --test tests/*.test.mjs
npm run export:android     # bundle tablet (lo valida CI)
npm run export:pda         # variante PDA (ESTIBA_APP_VARIANT=pda)
npm start                  # también start:pda / start:demo
```

## Arquitectura

Es un monorepo: Laravel y las oficinas web (Blade + JS en `resources/js/office-*.js`) en la raíz, y la app Android en `mobile/`. **Toda regla de negocio y autorización vive en Laravel.** Oficina y móvil son clientes de la misma API (`/api/*`, `auth:sanctum`); ocultar algo en la UI nunca reemplaza la validación del backend.

### Organización de `app/`

- `Http/Controllers` + `Http/Requests` reciben y validan comandos. Los `Http/Resources` son contratos estables que consumen la Oficina y la app móvil.
- `Services/<Dominio>/Servicio*` ejecuta las reglas transaccionales. Ahí está la lógica; los controladores son delgados.
- `Finanzas/`, `Comex/` y `Rrhh/` son dominios autocontenidos, cada uno con sus propios `Http`, `Models` y servicios.
- `Enums/` guarda las máquinas de estado como backed enums (`Estado*`, `Tipo*`).
- Las rutas están repartidas por dominio en `routes/*.php` y se registran como grupos `api` en `bootstrap/app.php`. Al agregar un archivo de rutas hay que registrarlo ahí.

### Contratos transversales (leer antes de cambiar un flujo)

- **Errores → HTTP:** `bootstrap/app.php` traduce las excepciones de dominio a JSON con un campo `codigo`. `ConflictoOperacion` da 409 `conflicto_operacional`. Cualquier `DomainException` da 422 `regla_de_negocio`. `OperacionNoAutorizada` da 403. Un deadlock da 503 `concurrencia_temporal` con `Retry-After`. Los servicios señalan reglas lanzando estas excepciones, no devolviendo respuestas.
- **Temporada activa:** existe una sola temporada global activa (`ServicioTemporadaActiva`, que cachea fuera de transacciones; usa `buscar(bloquear: true)` dentro de ellas). Toda escritura debe ocurrir sobre registros de esa temporada. El middleware `AsegurarTemporadaActivaDelRegistro` la exige para los modelos de ruta que implementan `PerteneceATemporada`. Los servicios que obtienen el registro por otro camino llaman a `GuardiaTemporadaActiva` directamente. Materiales es la excepción: traspasa inventario mediante `ServicioMigracionTemporada`. Ver `docs/TEMPORADA_ACTIVA_ESCRITURAS.md`.
- **Sin borrado físico:** los modelos con el trait `ImpideEliminacionFisica` lanzan una excepción en `deleting`. Los estados terminales se anulan o se compensan con movimientos inversos; nunca se borran.
- **Idempotencia:** las operaciones críticas reciben un `operacion_id` UUID y guardan un hash del payload normalizado. Mismo UUID y mismo contenido devuelve el resultado previo; mismo UUID con contenido distinto da conflicto. Las tablets reintentan con el mismo UUID, así que no se debe romper este contrato.
- **Movimientos y ubicaciones:** la ocupación vigente está separada del historial. Una operación bloquea cámaras, posiciones y folios en orden estable, comprueba versiones, crea el movimiento y actualiza la ubicación en la misma transacción. Una posición de cámara de producto admite un solo folio; una de materiales admite varias líneas del mismo cliente.
- **Autorización:** roles en `Enums/RolUsuario`, perfiles y catálogos de permisos en `Services/Autorizacion`, alcance por área en `AlcanceOperacionalUsuario`. La pantalla inicial de cada usuario la decide `InicioOficina`.
- **Identidades por dominio:** `REC-*` (recepción Romana) no es un folio. El lote de materia prima no crea folios de Frigorífico. Los correlativos de un dominio no se reutilizan en otro (ver `docs/arquitectura.md`).

### Pruebas

`tests/TestCase.php` fija taras históricas de Romana (bins 40 kg, totes 2 kg) en cada prueba con base de datos. Una prueba que valide las taras oficiales debe declarar `$tarasRomanaHistoricas = false`. Las migraciones crean una temporada GENERAL activa: para otra temporada usa `crearTemporadaActivaPrueba()` y `vigenciaProductiva()`. Los escenarios reutilizables están en `tests/Concerns/Prepara*`.

## Documentación

`docs/` tiene un documento por módulo o regla (`MODULO_*.md`, `reglas-negocio.md`, `arquitectura.md`, `glosario-operacional.md`). Revisa el documento del módulo antes de modificarlo y actualízalo si cambia la regla.

## Integración en main

Los PR deben apuntar a `main`. `.github/workflows/integracion-main.yml` falla si un PR apunta a otra rama y audita a diario si queda contenido fuera de main (`scripts/integracion/auditar-fuera-de-main.sh`, ver `docs/INTEGRACION_MAIN.md`). Nunca renombres una migración que ya corrió.
