#!/usr/bin/env bash
# deploy-over-ssh.sh <sha256:digest>
#
# Pide al VPS que despliegue un digest, desde un job del workflow `cd`. La llave del entorno de GitHub solo puede correr
# pintech-deploy en SU entorno (authorized_keys en el servidor): por eso aquí se manda solo el digest, nunca el entorno.
#
# Variables, de los secretos del entorno de GitHub:
#   DEPLOY_SSH_KEY       llave privada del entorno
#   DEPLOY_KNOWN_HOSTS   huella del servidor. Fijada: nunca ssh-keyscan en el momento, que permitiría suplantarlo
#   DEPLOY_HOST          IP del VPS. Es secreta: el túnel de Cloudflare la oculta y los registros de GitHub son públicos
#
# Sale con el mismo código que pintech-deploy; 255 si la conexión SSH falló.
set -Euo pipefail

readonly DIGEST="${1:?falta el digest}"
readonly SUMMARY="${GITHUB_STEP_SUMMARY:-/dev/null}"

# Mensaje de una sola línea para una anotación de GitHub (los comandos de flujo de trabajo codifican % y saltos de línea).
annotation_text() {
    local text="$1"
    text="${text//'%'/%25}"
    text="${text//$'\n'/%0A}"
    printf '%s' "${text//$'\r'/%0D}"
}

fail() {
    local title="$1" message="$2"
    echo "::error title=$title::$(annotation_text "$message")"
    printf '### Despliegue\n\n**%s.** %s\n' "$title" "$message" >>"$SUMMARY"
}

missing=()
for name in DEPLOY_SSH_KEY DEPLOY_KNOWN_HOSTS DEPLOY_HOST; do
    [[ -n "${!name:-}" ]] || missing+=("$name")
done
if ((${#missing[@]} > 0)); then
    fail 'Faltan secretos del entorno' "${missing[*]}: el entorno de GitHub no los tiene. Guía: .docker/server/README.md."
    exit 1
fi

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
(
    umask 077
    printf '%s\n' "$DEPLOY_SSH_KEY" >"$work/key"
    printf '%s\n' "$DEPLOY_KNOWN_HOSTS" >"$work/known_hosts"
)

# Solo se muestran las líneas propias de pintech-deploy (fecha [entorno] texto). Los registros de un repo público los ve
# cualquiera, y la salida de Compose o de una migración fallida puede traer datos: esa queda completa en el servidor
# (/srv/pintech/<entorno>/last-deploy.log).
readonly OWN_LINE='^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:]+Z \[[a-z]+\] '

ssh -F /dev/null -n -T \
    -i "$work/key" \
    -o IdentitiesOnly=yes \
    -o IdentityAgent=none \
    -o BatchMode=yes \
    -o StrictHostKeyChecking=yes \
    -o UserKnownHostsFile="$work/known_hosts" \
    -o GlobalKnownHostsFile=/dev/null \
    -o UpdateHostKeys=no \
    -o ConnectTimeout=30 \
    -o ServerAliveInterval=30 \
    -o ServerAliveCountMax=6 \
    "deploy@$DEPLOY_HOST" "$DIGEST" 2>&1 \
    | tee "$work/output" \
    | { grep --line-buffered -E "$OWN_LINE" || true; }
status="${PIPESTATUS[0]}"

last_with() { grep -E "$OWN_LINE$1: " "$work/output" | tail -n 1 | sed -E "s/$OWN_LINE$1: //" || true; }

# shellcheck disable=SC2016 # Las comillas invertidas son Markdown del resumen, no comandos.
case "$status" in
    0)
        warning="$(last_with ADVERTENCIA)"
        if [[ -n "$warning" ]]; then
            echo "::warning title=Desplegado con avisos::$(annotation_text "$warning")"
            printf '### Despliegue\n\n**Desplegado con avisos** (`ok-con-avisos`): %s\n\n- Digest: `%s`\n' \
                "$warning" "$DIGEST" >>"$SUMMARY"
        else
            printf '### Despliegue\n\n**Desplegado** (`ok`).\n\n- Digest: `%s`\n' "$DIGEST" >>"$SUMMARY"
        fi
        ;;
    255)
        # Si nunca conectó, todo son mensajes del propio SSH, que hacen falta para saber qué pasó. Si se cortó a mitad de
        # camino, ahí también está la salida del servidor, que no se muestra.
        grep -qE "$OWN_LINE" "$work/output" || cat "$work/output"
        fail 'Falló la conexión SSH' 'No se pudo conectar o se cortó la conexión. Si se cortó a mitad de camino, el despliegue sigue en el servidor: revisar deploys.log.'
        ;;
    *)
        error="$(last_with ERROR)"
        fail 'Falló el despliegue' "${error:-pintech-deploy terminó con código $status}. Salida completa en el servidor: last-deploy.log."
        ;;
esac

exit "$status"
