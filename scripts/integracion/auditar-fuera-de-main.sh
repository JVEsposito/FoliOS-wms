#!/usr/bin/env bash
# Audita ramas y PR cuyo contenido no llegó a main.
#
# Un PR puede figurar como «merged» y aun así no estar en main: basta con que se
# haya fusionado en una rama intermedia (por ejemplo, la rama de otro PR que ya
# había entrado a main). Este script no confía en ese estado: para cada rama y
# cada cabeza de PR comprueba si fusionarla en main cambiaría algo. Si el
# resultado es idéntico a main, su contenido ya está integrado (aunque haya
# llegado por squash, cherry-pick o un PR agregador) y se omite.
#
# Los hallazgos ya revisados se registran en revisados.txt por SHA de cabeza:
#   integrado  el contenido está en main con otra forma (refactor posterior)
#   obsoleto   se reemplazó a propósito o no debe aplicarse
#   pendiente  falta una decisión: se informa como aviso, sin fallar
# Un hallazgo sin registro hace terminar el script con código 1.
#
# Trabajo en curso que no es pérdida:
#   --abiertos ARCHIVO  SHA de cabeza de los PR abiertos (uno por línea); se omiten.
#   --gracia DÍAS       ramas sin PR cuyo último commit es más reciente (por omisión 3).
#
# Uso: scripts/integracion/auditar-fuera-de-main.sh [--traer] [--base REF]
#        [--desde AAAA-MM-DD] [--revisados ARCHIVO] [--abiertos ARCHIVO]
#        [--gracia DÍAS] [--resumen ARCHIVO]
set -euo pipefail

BASE="origin/main"
DIRECTORIO="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REVISADOS="$DIRECTORIO/revisados.txt"
ABIERTOS=""
GRACIA=3
DESDE=""
TRAER=0
RESUMEN=""

while [ $# -gt 0 ]; do
    case "$1" in
        --base) BASE="$2"; shift 2 ;;
        --revisados) REVISADOS="$2"; shift 2 ;;
        --abiertos) ABIERTOS="$2"; shift 2 ;;
        --gracia) GRACIA="$2"; shift 2 ;;
        --desde) DESDE="$2"; shift 2 ;;
        --resumen) RESUMEN="$2"; shift 2 ;;
        --traer) TRAER=1; shift ;;
        -h|--help) sed -n '2,26p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Opción desconocida: $1" >&2; exit 2 ;;
    esac
done

if ! git merge-tree --write-tree HEAD HEAD >/dev/null 2>&1; then
    echo "Se requiere git 2.38 o superior (merge-tree --write-tree)." >&2
    exit 2
fi

if [ "$TRAER" -eq 1 ]; then
    git fetch --quiet --no-tags --prune origin \
        '+refs/heads/*:refs/remotes/origin/*' \
        '+refs/pull/*/head:refs/remotes/pr/*'
fi

ARBOL_BASE="$(git rev-parse "$BASE^{tree}")"
SHA_BASE="$(git rev-parse "$BASE^{commit}")"

declare -A ESTADO=()
declare -A MOTIVO=()
if [ -f "$REVISADOS" ]; then
    while IFS= read -r linea || [ -n "$linea" ]; do
        linea="${linea%%$'\r'}"
        case "$linea" in ''|'#'*) continue ;; esac
        read -r sha estado motivo <<< "$linea"
        ESTADO["$sha"]="$estado"
        MOTIVO["$sha"]="${motivo:-}"
    done < "$REVISADOS"
fi

declare -A ABIERTO=()
if [ -n "$ABIERTOS" ] && [ -f "$ABIERTOS" ]; then
    while IFS= read -r sha || [ -n "$sha" ]; do
        sha="${sha%%$'\r'}"
        [ -n "$sha" ] && ABIERTO["$sha"]=1
    done < "$ABIERTOS"
fi
LIMITE_GRACIA=$(( $(date +%s) - GRACIA * 86400 ))

nombre() {
    case "$1" in
        pr/*) echo "#${1#pr/}" ;;
        origin/*) echo "${1#origin/}" ;;
        *) echo "$1" ;;
    esac
}

declare -A EVALUADO=()
declare -A REFS=()
HALLAZGOS=()

while IFS= read -r ref; do
    case "$ref" in origin/HEAD|origin|"$BASE") continue ;; esac
    sha="$(git rev-parse --verify --quiet "$ref^{commit}")" || continue
    [ "$sha" = "$SHA_BASE" ] && continue
    if [ -n "${EVALUADO[$sha]-}" ]; then
        [ "${EVALUADO[$sha]}" = "hallazgo" ] && REFS["$sha"]+=" $(nombre "$ref")"
        continue
    fi
    EVALUADO["$sha"]="ok"
    [ -n "${ABIERTO[$sha]-}" ] && continue
    git merge-base --is-ancestor "$sha" "$BASE" && continue
    if [ -n "$DESDE" ] && [[ "$(git log -1 --format=%cs "$sha")" < "$DESDE" ]]; then
        continue
    fi
    # Una rama recién empujada sin PR todavía es trabajo en curso.
    if [[ "$ref" == origin/* ]] && [ "$(git log -1 --format=%ct "$sha")" -gt "$LIMITE_GRACIA" ]; then
        EVALUADO["$sha"]=""
        continue
    fi
    arbol="$(git merge-tree --write-tree --no-messages "$BASE" "$sha" 2>/dev/null | head -n 1 || true)"
    [ "$arbol" = "$ARBOL_BASE" ] && continue
    EVALUADO["$sha"]="hallazgo"
    REFS["$sha"]="$(nombre "$ref")"
    HALLAZGOS+=("$sha")
done < <(git for-each-ref --format='%(refname:short)' refs/remotes/origin refs/remotes/pr)

nuevos=0
pendientes=0
revisados=0
TEXTO=()
MD_NUEVOS=()
MD_PENDIENTES=()

for sha in "${HALLAZGOS[@]+"${HALLAZGOS[@]}"}"; do
    fecha="$(git log -1 --format=%cs "$sha")"
    asunto="$(git log -1 --format=%s "$sha")"
    sin_aplicar="$(git cherry "$BASE" "$sha" | grep -c '^+' || true)"
    refs="${REFS[$sha]}"
    estado="${ESTADO[$sha]-}"
    case "$estado" in
        integrado|obsoleto)
            revisados=$((revisados + 1))
            ;;
        pendiente)
            pendientes=$((pendientes + 1))
            TEXTO+=("PENDIENTE  $fecha  ${sha:0:10}  $refs  —  ${MOTIVO[$sha]}")
            MD_PENDIENTES+=("| $fecha | \`${sha:0:10}\` | $refs | ${MOTIVO[$sha]} |")
            ;;
        *)
            nuevos=$((nuevos + 1))
            TEXTO+=("NUEVO      $fecha  ${sha:0:10}  $refs  —  $asunto ($sin_aplicar commits sin aplicar)")
            MD_NUEVOS+=("| $fecha | \`${sha:0:10}\` | $refs | $asunto | $sin_aplicar |")
            ;;
    esac
done

echo "Base: $BASE (${SHA_BASE:0:10})"
echo "Contenido fuera de main: ${#HALLAZGOS[@]} (revisados: $revisados, pendientes: $pendientes, nuevos: $nuevos)"
for linea in "${TEXTO[@]+"${TEXTO[@]}"}"; do
    echo "  $linea"
done

if [ -n "$RESUMEN" ]; then
    {
        echo "## Contenido fuera de \`main\`"
        echo
        echo "Base \`${SHA_BASE:0:10}\`: ${#HALLAZGOS[@]} cabezas con cambios que no están en main."
        echo "Revisadas: $revisados · pendientes de decisión: $pendientes · nuevas: $nuevos."
        if [ "$nuevos" -gt 0 ]; then
            echo
            echo "### Nuevas (sin revisar)"
            echo
            echo "| Fecha | Cabeza | Ramas/PR | Último commit | Commits sin aplicar |"
            echo "|---|---|---|---|---|"
            printf '%s\n' "${MD_NUEVOS[@]}"
            echo
            echo "Revisa si su contenido debe llegar a main. Luego regístralas en \`scripts/integracion/revisados.txt\`."
        fi
        if [ "$pendientes" -gt 0 ]; then
            echo
            echo "### Pendientes de decisión"
            echo
            echo "| Fecha | Cabeza | Ramas/PR | Motivo |"
            echo "|---|---|---|---|"
            printf '%s\n' "${MD_PENDIENTES[@]}"
        fi
    } >> "$RESUMEN"
fi

if [ "$nuevos" -gt 0 ]; then
    if [ -n "${GITHUB_ACTIONS-}" ]; then
        for linea in "${TEXTO[@]}"; do
            case "$linea" in NUEVO*) echo "::error title=Fuera de main::${linea#NUEVO      }" ;; esac
        done
    fi
    exit 1
fi
