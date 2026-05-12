#!/usr/bin/env bash
# Menu principal de administracion del laboratorio CTF Wi-Fi.
set -uo pipefail

# Asegura que comandos del sistema esten disponibles aunque sudo limpie el PATH.
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

# Rutas base del repositorio y de los scripts auxiliares.
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPTS_DIR="$REPO_ROOT/scripts"

# Configuracion comun de Docker, BBDD e imagen de jugadores.
DB_CONTAINER="ctf_db"
DB_NAME="ctf_wifi"
DB_ROOT_PASS="root123"
PLAYER_IMAGE="ctf-player-base:1.0"
DEFAULT_SSH_HOST="192.168.220.10"

clear
# El menu necesita permisos de root para Docker, redes y scripts Wi-Fi.
if [ "${EUID:-$(id -u)}" -ne 0 ]; then
    echo "[ERROR] Ejecuta este menu con sudo o como root:"
    echo "        sudo bash ctf.sh"
    exit 1
fi

# Localiza el binario de Docker antes de ejecutar acciones.
DOCKER_BIN="$(command -v docker || true)"
if [ -z "$DOCKER_BIN" ]; then
    echo "[ERROR] No se encontro docker en PATH."
    exit 1
fi

cd "$REPO_ROOT" || exit 1

# Pausa la salida para que el operador pueda leer el resultado.
pause() {
    local _
    echo ""
    read -r -p "Pulsa Enter para continuar..." _
}

# Escapa comillas simples para insertar valores en SQL.
sql_escape() {
    printf "%s" "$1" | sed "s/'/''/g"
}

# Elimina espacios al principio y final de una cadena.
trim() {
    printf "%s" "$1" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//'
}

# Usa docker compose moderno o docker-compose clasico si es necesario.
docker_compose() {
    if "$DOCKER_BIN" compose version >/dev/null 2>&1; then
        "$DOCKER_BIN" compose "$@"
        return $?
    fi

    if command -v docker-compose >/dev/null 2>&1; then
        docker-compose "$@"
        return $?
    fi

    echo "[ERROR] No se encontro docker compose ni docker-compose."
    return 1
}

# Comprueba si el contenedor de BBDD esta en ejecucion.
db_container_running() {
    "$DOCKER_BIN" ps --format '{{.Names}}' | grep -qx "$DB_CONTAINER"
}

# Evita ejecutar acciones que necesitan BBDD si MariaDB no esta lista.
require_db() {
    if ! db_container_running; then
        echo "[ERROR] La BBDD no esta arrancada: $DB_CONTAINER"
        echo "        Usa primero la opcion 4."
        return 1
    fi
}

# Ejecuta una consulta SQL dentro del contenedor MariaDB.
db_query() {
    local sql="$1"
    "$DOCKER_BIN" exec "$DB_CONTAINER" mariadb \
        --protocol=TCP \
        -h127.0.0.1 \
        -uroot \
        -p"$DB_ROOT_PASS" \
        --database="$DB_NAME" \
        -Nse "$sql"
}

# Espera a que MariaDB acepte conexiones antes de seguir.
wait_for_db() {
    local attempt

    echo "[+] Esperando a que $DB_CONTAINER este lista..."
    for attempt in $(seq 1 60); do
        if "$DOCKER_BIN" exec "$DB_CONTAINER" mariadb-admin ping --protocol=TCP -h127.0.0.1 -uroot -p"$DB_ROOT_PASS" --silent >/dev/null 2>&1; then
            echo "[OK] BBDD lista."
            return 0
        fi
        sleep 2
    done

    echo "[ERROR] La BBDD no estuvo lista a tiempo."
    return 1
}

# Crea el valor ssh_host por defecto si aun no existe en app_config.
ensure_ssh_host_config() {
    local current esc_default

    require_db || return 1

    if ! current="$(db_query "SELECT config_value FROM app_config WHERE config_key = 'ssh_host' LIMIT 1;")"; then
        return 1
    fi

    if [ -n "$current" ]; then
        return 0
    fi

    esc_default="$(sql_escape "$DEFAULT_SSH_HOST")"
    db_query "
        INSERT INTO app_config (config_key, config_value, updated_at)
        VALUES ('ssh_host', '${esc_default}', NOW())
        ON DUPLICATE KEY UPDATE
            config_value = VALUES(config_value),
            updated_at = NOW();
    "
}

get_ssh_host() {
    ensure_ssh_host_config || return 1
    db_query "SELECT config_value FROM app_config WHERE config_key = 'ssh_host' LIMIT 1;"
}

# Valida y guarda el dominio/IP que veran los jugadores para SSH.
set_ssh_host() {
    local host="$1"
    local esc_host

    host="$(trim "$host")"
    if [ -z "$host" ]; then
        echo "[ERROR] El dominio/IP no puede estar vacio."
        return 1
    fi

    if ! [[ "$host" =~ ^[A-Za-z0-9._-]+$ ]]; then
        echo "[ERROR] Usa solo letras, numeros, punto, guion o guion bajo."
        return 1
    fi

    ensure_ssh_host_config || return 1
    esc_host="$(sql_escape "$host")"

    db_query "
        INSERT INTO app_config (config_key, config_value, updated_at)
        VALUES ('ssh_host', '${esc_host}', NOW())
        ON DUPLICATE KEY UPDATE
            config_value = VALUES(config_value),
            updated_at = NOW();

        UPDATE player_envs
        SET ssh_host = '${esc_host}'
        WHERE env_status <> 'finished';
    "

    echo "[OK] Host SSH actualizado: $host"
}

# Permite revisar o cambiar el host SSH publicado.
configure_ssh_host() {
    local current new_host

    current="$(get_ssh_host)" || return 1
    echo "Host SSH actual: $current"
    read -r -p "Nuevo dominio/IP SSH (Enter para mantener): " new_host
    new_host="$(trim "$new_host")"

    if [ -z "$new_host" ]; then
        echo "[OK] Se mantiene: $current"
        return 0
    fi

    set_ssh_host "$new_host"
}

# Muestra todos los codigos de invitacion activos.
show_invitation_code() {
    local rows

    require_db || return 1

    rows="$(db_query "
        SELECT code, created_at
        FROM invitation_codes
        WHERE is_active = 1
        ORDER BY id DESC;
    ")" || return 1

    if [ -z "$rows" ]; then
        echo "[!] No hay codigos de invitacion activos."
        return 0
    fi

    echo "Codigos de invitacion activos:"
    printf '%s\n' "$rows" | while IFS=$'\t' read -r code created_at; do
        [ -z "${code:-}" ] && continue
        echo "  - $code ($created_at)"
    done
}

# Desactiva codigos anteriores y crea un nuevo codigo activo.
change_invitation_code() {
    local raw_code code esc_code

    require_db || return 1

    read -r -p "Nuevo codigo de invitacion: " raw_code
    code="$(trim "$raw_code" | tr '[:lower:]' '[:upper:]')"

    if [ -z "$code" ]; then
        echo "[ERROR] El codigo no puede estar vacio."
        return 1
    fi

    if ! [[ "$code" =~ ^[A-Z0-9_-]{3,64}$ ]]; then
        echo "[ERROR] Usa 3-64 caracteres: A-Z, 0-9, guion o guion bajo."
        return 1
    fi

    esc_code="$(sql_escape "$code")"
    db_query "
        UPDATE invitation_codes
        SET is_active = 0
        WHERE is_active = 1;

        INSERT INTO invitation_codes (code, is_active, created_at)
        VALUES ('${esc_code}', 1, NOW())
        ON DUPLICATE KEY UPDATE
            is_active = 1;
    " || return 1

    echo "[OK] Codigo de invitacion activo: $code"
}

# Ejecuta un script del directorio scripts con comprobacion previa.
run_script() {
    local script_name="$1"
    shift

    local script_path="$SCRIPTS_DIR/$script_name"
    if [ ! -f "$script_path" ]; then
        echo "[ERROR] No existe $script_path"
        return 1
    fi

    echo "[+] Ejecutando scripts/$script_name $*"
    bash "$script_path" "$@"
}

# Construye la imagen base usada por los contenedores de jugadores.
build_player_image() {
    echo "[+] Construyendo imagen base de jugadores: $PLAYER_IMAGE"
    "$DOCKER_BIN" build -t "$PLAYER_IMAGE" -f Build/Dockerfile .
}

# Levanta web y BBDD con Docker Compose.
compose_up() {
    echo "[+] Levantando web y BBDD con docker compose..."
    docker_compose up -d --build
}

# Limpia procesos, pidfiles y namespaces de niveles anteriores.
cleanup_levels() {
    echo "[+] Limpiando niveles..."
    run_script "cleanup_levels.sh"
}

# Flujo completo para preparar jugadores aprobados y redes Wi-Fi.
start_or_restart_levels_and_players() {
    require_db || return 1
    wait_for_db || return 1
    build_player_image || return 1
    configure_ssh_host || return 1
    cleanup_levels || return 1
    run_script "provision_player_envs_wifi.sh" || return 1
    start_levels || return 1

    echo ""
    echo "[OK] Niveles iniciados/reiniciados y jugadores aprobados aprovisionados."
}

# Arranca los niveles Wi-Fi disponibles.
start_levels() {
    echo "[+] Iniciando niveles..."
    run_script "start_level1.sh" || return 1
    run_script "start_level3.sh" || return 1
}

# Arranca web/BBDD y deja configurado el host SSH publicado.
start_web_db() {
    local web_host

    compose_up || return 1
    wait_for_db || return 1
    configure_ssh_host || return 1
    web_host="$(get_ssh_host)" || return 1

    echo ""
    echo "[OK] Web y BBDD iniciadas."
    echo "     Web: http://${web_host}:8080"
    echo "     Cuando apruebes jugadores, usa la opcion 5 para iniciar niveles y entornos de jugadores."
}

# Borra contenedores player-* existentes.
delete_player_containers() {
    local containers=()

    mapfile -t containers < <("$DOCKER_BIN" ps -a --format '{{.Names}}' | grep '^player-' || true)
    if [ "${#containers[@]}" -eq 0 ]; then
        echo "[OK] No hay contenedores de jugadores para borrar."
        return 0
    fi

    echo "[+] Borrando contenedores de jugadores:"
    printf '    - %s\n' "${containers[@]}"
    "$DOCKER_BIN" rm -f "${containers[@]}" || return 1
}

# Para contenedores player-* que esten en ejecucion.
stop_player_containers() {
    local containers=()

    mapfile -t containers < <("$DOCKER_BIN" ps --format '{{.Names}}' | grep '^player-' || true)
    if [ "${#containers[@]}" -eq 0 ]; then
        echo "[OK] No hay contenedores de jugadores en ejecucion."
        return 0
    fi

    echo "[+] Parando contenedores de jugadores:"
    printf '    - %s\n' "${containers[@]}"
    "$DOCKER_BIN" stop "${containers[@]}"
}

# Reinicia el laboratorio desde cero tras confirmacion explicita.
reset_lab() {
    local confirmation

    echo "[AVISO] Esta accion borra BBDD, volumenes, web/db y contenedores player-*."
    echo "        Se perderan usuarios, progreso, aprobaciones y codigos modificados."
    read -r -p "Escribe BORRAR para confirmar: " confirmation

    if [ "$confirmation" != "BORRAR" ]; then
        echo "[OK] Reset cancelado."
        return 0
    fi

    cleanup_levels || echo "[!] Limpieza Wi-Fi fallo o no habia nada que limpiar; continuo con el reset."
    delete_player_containers || return 1

    echo "[+] Ejecutando docker compose down -v"
    docker_compose down -v || return 1

    echo ""
    echo "[+] Reset completado. Iniciando web y BBDD desde cero..."
    start_web_db
}

# Detiene servicios y elimina contenedores/volumenes del laboratorio.
stop_all() {
    delete_player_containers || return 1

    echo "[+] Parando web/BBDD y borrando volumen de la BBDD"
    docker_compose down -v || return 1

    cleanup_levels || echo "[!] Limpieza Wi-Fi fallo o no habia nada que limpiar; continuo."

    echo ""
    echo "[OK] Todo parado. Contenedores de jugadores borrados y volumen de BBDD eliminado."
}

# Muestra estado de Docker, jugadores y entornos registrados en BBDD.
show_status() {
    echo "[+] Estado docker compose:"
    docker_compose ps || return 1

    echo ""
    echo "[+] Contenedores de jugadores:"
    if ! "$DOCKER_BIN" ps -a --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}' | grep -E '(^NAMES|^player-)'; then
        echo "No hay contenedores player-*."
    fi

    echo ""
    if db_container_running; then
        echo "[+] Entornos en BBDD:"
        db_query "
            SELECT env_status, COUNT(*)
            FROM player_envs
            GROUP BY env_status
            ORDER BY env_status;
        " | while IFS=$'\t' read -r status total; do
            [ -z "${status:-}" ] && continue
            echo "  - $status: $total"
        done
    else
        echo "[!] BBDD no arrancada."
    fi
}

# Ejecuta una accion del menu y muestra pausa final.
run_action() {
    local action="$1"
    shift

    echo ""
    "$action" "$@"
    local status=$?

    if [ "$status" -ne 0 ]; then
        echo ""
        echo "[ERROR] La accion termino con codigo $status."
    fi

    pause
}

# Imprime las opciones disponibles del menu interactivo.
print_menu() {
    echo ""
    echo "=== Admin CTF Wi-Fi ==="
    echo ""
    echo "1. Iniciar web/BBDD"
    echo "2. Ver codigo de invitacion activo"
    echo "3. Cambiar codigo de invitacion"
    echo "4. Ver/cambiar dominio o IP SSH"
    echo "5. Iniciar/reiniciar niveles y jugadores"
    echo "6. Reiniciar laboratorio desde cero"
    echo "7. Ver estado"
    echo "8. Parar todo"
    echo "0. Salir"
    echo ""
}

# Bucle principal del menu.
main() {
    local option

    while true; do
        print_menu
        read -r -p "Opcion: " option

        case "$option" in
            1) run_action start_web_db ;;
            2) run_action show_invitation_code ;;
            3) run_action change_invitation_code ;;
            4) run_action configure_ssh_host ;;
            5) run_action start_or_restart_levels_and_players ;;
            6) run_action reset_lab ;;
            7) run_action show_status ;;
            8) run_action stop_all ;;
            0) echo "Saliendo."; exit 0 ;;
            *) echo "[ERROR] Opcion no valida." ; pause ;;
        esac
    done
}

main "$@"
