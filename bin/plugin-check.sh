#!/usr/bin/env bash
# Ejecuta Plugin Check (la herramienta oficial de revisión de WordPress.org)
# contra el plugin ya construido, es decir, contra lo que de verdad se distribuye.
#
# Se revisa build-zip/voceador/ y no la raíz del repositorio porque el paquete
# distribuible no lleva los archivos de desarrollo (.github, phpunit.xml.dist,
# tests…) que Plugin Check marca como no permitidos.
#
# `wp plugin check` siempre termina con código 0, así que este script cuenta las
# líneas de tipo ERROR y falla si hay alguna. Los WARNING se muestran pero no
# detienen el build.
set -euo pipefail
cd "$(dirname "$0")/.."

PLUGIN_IN_CONTAINER=/var/www/html/wp-content/plugins/voceador/build-zip/voceador
REPORT=build-zip/plugin-check.csv

bash bin/build-zip.sh > /dev/null

npx wp-env run cli wp plugin install plugin-check --activate --force > /dev/null 2>&1

npx wp-env run cli wp plugin check "$PLUGIN_IN_CONTAINER" \
	--format=csv --fields=file,line,type,code,message 2>/dev/null \
	| tr -d '\r' > "$REPORT"

errors=$(grep -c ',ERROR,' "$REPORT" || true)
warnings=$(grep -c ',WARNING,' "$REPORT" || true)

if [ "$warnings" -gt 0 ]; then
	echo "Plugin Check: $warnings aviso(s)"
	grep ',WARNING,' "$REPORT" || true
fi

if [ "$errors" -gt 0 ]; then
	echo
	echo "Plugin Check: $errors error(es) — el directorio de WordPress.org los rechaza"
	grep ',ERROR,' "$REPORT" || true
	exit 1
fi

echo "Plugin Check: sin errores"
