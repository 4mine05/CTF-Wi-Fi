#!/usr/bin/env bash
# Comprobar si el script se ejecuta como root
if [ "$EUID" -ne 0 ]; then
  echo "Por favor, ejecuta el script con sudo o como root."
  exit 1
fi

set -euo pipefail

export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

DOCKER_BIN="/usr/bin/docker"
if [ ! -x "$DOCKER_BIN" ]; then
    echo "[ERROR] No se encontró Docker en $DOCKER_BIN"
    exit 1
fi

# ==========================================
# CONFIGURACIÓN
# ==========================================
DB_CONTAINER="ctf_db"
DB_NAME="ctf_wifi"
DB_ROOT_PASS="root123"

IMAGE_NAME="ctf-player-base:1.0"
SSH_HOST_VALUE="192.168.145.128"
SSH_PORT_START=2200

# Primeras interfaces reservadas para el host y el laboratorio CTF.
# Quedarán libres en el host como wlan0..wlan4.
HOST_WIFI_RESERVE=5

# Si quieres dejar 1 o 2 radios extra además de las 5 del host, súbelo.
EXTRA_MARGIN=0

# ==========================================
# FUNCIÓN: ejecutar SQL en MariaDB
# ==========================================
db_query() {
    local sql="$1"
    "$DOCKER_BIN" exec "$DB_CONTAINER" mariadb -uroot -p"$DB_ROOT_PASS" "$DB_NAME" -Nse "$sql"
}

# ==========================================
# FUNCIÓN: escapar comillas simples para SQL
# ==========================================
sql_escape() {
    printf "%s" "$1" | sed "s/'/''/g"
}

# ==========================================
# FUNCIÓN: buscar un puerto SSH libre
# - respeta uno ya asignado si sigue libre en Docker/BBDD
# - si no, busca otro libre desde SSH_PORT_START
# ==========================================
find_free_port() {
    local preferred="${1:-}"

    if [ -n "$preferred" ]; then
        local used_in_db
        used_in_db="$(db_query "SELECT COUNT(*) FROM player_envs WHERE ssh_port = ${preferred} AND env_status <> 'finished';")"
        if [ "$used_in_db" -le 1 ]; then
            if ! ss -ltn 2>/dev/null | awk 'NR>1 {print $4}' | grep -Eq "(^|:)${preferred}$"; then
                echo "$preferred"
                return 0
            fi
        fi
    fi

    local port="$SSH_PORT_START"
    while true; do
        local used_in_db
        used_in_db="$(db_query "SELECT COUNT(*) FROM player_envs WHERE ssh_port = ${port} AND env_status <> 'finished';")"

        if [ "$used_in_db" = "0" ]; then
            if ! ss -ltn 2>/dev/null | awk 'NR>1 {print $4}' | grep -Eq "(^|:)${port}$"; then
                echo "$port"
                return 0
            fi
        fi

        port=$((port + 1))
    done
}

# ==========================================
# FUNCIÓN: asegurar fila en player_envs
# ==========================================
ensure_player_env_row() {
    local user_id="$1"
    db_query "
        INSERT INTO player_envs (user_id, env_status, provision_requested_at)
        VALUES (${user_id}, 'pending', NOW())
        ON DUPLICATE KEY UPDATE user_id = user_id;
    "
}

# ==========================================
# FUNCIÓN: reiniciar pool WiFi virtual
# IMPORTANTE: esto tumba hostapd/wpa_supplicant/dnsmasq si estaban usando hwsim.
# Ejecútalo antes de levantar las redes del laboratorio.
# ==========================================
prepare_hwsim_pool() {
    local player_count="$1"
    local total_radios=$((HOST_WIFI_RESERVE + player_count + EXTRA_MARGIN))

    echo "[+] Reiniciando pool mac80211_hwsim..."
    echo "    - Reservadas para el host: $HOST_WIFI_RESERVE"
    echo "    - Jugadores: $player_count"
    echo "    - Extra: $EXTRA_MARGIN"
    echo "    - Total radios: $total_radios"

    pkill hostapd 2>/dev/null || true
    pkill wpa_supplicant 2>/dev/null || true
    pkill dnsmasq 2>/dev/null || true

    modprobe -r mac80211_hwsim 2>/dev/null || true
    modprobe mac80211_hwsim radios="$total_radios"
    sleep 1

    mkdir -p /var/run/netns

    mapfile -t WLAN_INTERFACES < <(ls /sys/class/net | grep '^wlan' | sort -V)

    if [ "${#WLAN_INTERFACES[@]}" -lt "$total_radios" ]; then
        echo "[ERROR] Se esperaban $total_radios interfaces wlan y solo hay ${#WLAN_INTERFACES[@]}"
        exit 1
    fi

    echo "[+] Interfaces del host tras recargar hwsim:"
    printf '    - %s\n' "${WLAN_INTERFACES[@]}"
    echo "[+] Reserva del host para redes CTF: wlan0..wlan$((HOST_WIFI_RESERVE - 1))"
}

# ==========================================
# FUNCIÓN: asegurar contenedor
# - crea el contenedor si no existe
# - lo arranca si estaba parado
# ==========================================
ensure_container() {
    local container_name="$1"
    local ssh_port="$2"

    if "$DOCKER_BIN" ps -a --format '{{.Names}}' | grep -qx "$container_name"; then
        local running
        running="$($DOCKER_BIN inspect -f '{{.State.Running}}' "$container_name")"
        if [ "$running" != "true" ]; then
            echo "    -> El contenedor existe pero está parado, arrancándolo"
            "$DOCKER_BIN" start "$container_name" >/dev/null
            sleep 1
        fi
        return 0
    fi

    echo "    -> Creando contenedor Docker"
    "$DOCKER_BIN" run -d \
        --name "$container_name" \
        --hostname "$container_name" \
        --network bridge \
        -p "${ssh_port}:22" \
        --cap-add NET_ADMIN \
        --cap-add NET_RAW \
        "$IMAGE_NAME" >/dev/null

    sleep 1
}

# ==========================================
# FUNCIÓN: mover una PHY al contenedor y dejarla como wlan0 dentro
# ==========================================
attach_wifi_to_container() {
    local container_name="$1"
    local host_iface="$2"

    if [ ! -e "/sys/class/net/${host_iface}" ]; then
        echo "[ERROR] La interfaz ${host_iface} no existe en el host"
        return 1
    fi

    local pid phy
    pid="$($DOCKER_BIN inspect -f '{{.State.Pid}}' "$container_name")"
    ln -snf "/proc/$pid/ns/net" "/var/run/netns/$pid"

    phy="$(basename "$(readlink -f "/sys/class/net/${host_iface}/phy80211")")"
    echo "    -> Moviendo ${host_iface} (${phy}) al contenedor PID ${pid}"
    iw phy "$phy" set netns "$pid"

    "$DOCKER_BIN" exec \
        -e CTF_SRC_IFACE="$host_iface" \
        "$container_name" bash -lc '
            set -e
            ip link set lo up || true

            if ip link show "$CTF_SRC_IFACE" >/dev/null 2>&1; then
                ip link set "$CTF_SRC_IFACE" down || true
                if [ "$CTF_SRC_IFACE" != "wlan0" ]; then
                    ip link set "$CTF_SRC_IFACE" name wlan0
                fi
            fi

            ip link set wlan0 up || true
        '
}

# ==========================================
# FUNCIÓN: configurar el interior del contenedor
# - usuario Linux
# - hash de contraseña
# - sudo sin password
# - sshd
# - nota informativa al jugador
# ==========================================
configure_container_user() {
    local container_name="$1"
    local container_username="$2"
    local container_password_hash="$3"

    "$DOCKER_BIN" exec \
        -e CTF_USER="$container_username" \
        -e CTF_HASH="$container_password_hash" \
        "$container_name" bash -lc '
            set -e

            mkdir -p /var/run/sshd

            id -u "$CTF_USER" >/dev/null 2>&1 || useradd -m -s /bin/bash "$CTF_USER"
            usermod -p "$CTF_HASH" "$CTF_USER"
            usermod -aG sudo "$CTF_USER" 2>/dev/null || true

            grep -q "^$CTF_USER ALL=(ALL) NOPASSWD:ALL$" /etc/sudoers || \
                echo "$CTF_USER ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers

            cat > "/home/$CTF_USER/README_CTF_WIFI.txt" <<TXT
Tu interfaz WiFi virtual del CTF es: wlan0
Compruébala con:
  iw dev
  ip link show wlan0
TXT
            chown "$CTF_USER:$CTF_USER" "/home/$CTF_USER/README_CTF_WIFI.txt"

            pkill sshd 2>/dev/null || true
            /usr/sbin/sshd
        '
}

# ==========================================
# FUNCIÓN: aprovisionar / reanclar un jugador
# ==========================================
provision_one() {
    local user_id="$1"
    local alias="$2"
    local container_password_hash="$3"
    local current_status="$4"
    local current_ssh_port="$5"
    local host_iface="$6"

    local container_name="player-${user_id}"
    local container_username="$alias"
    local ssh_port esc_container esc_user

    ssh_port="$(find_free_port "$current_ssh_port")"
    esc_container="$(sql_escape "$container_name")"
    esc_user="$(sql_escape "$container_username")"

    echo ""
    echo "[+] Procesando jugador"
    echo "    id: $user_id"
    echo "    alias: $alias"
    echo "    estado actual: $current_status"
    echo "    contenedor: $container_name"
    echo "    SSH: ${SSH_HOST_VALUE}:${ssh_port}"
    echo "    WiFi host reservada: $host_iface"
    echo "    WiFi dentro del contenedor: wlan0"

    db_query "
        UPDATE player_envs
        SET env_status = 'creating'
        WHERE user_id = ${user_id};
    "

    ensure_container "$container_name" "$ssh_port"
    attach_wifi_to_container "$container_name" "$host_iface"
    configure_container_user "$container_name" "$container_username" "$container_password_hash"

    db_query "
        UPDATE player_envs
        SET env_status = IF(env_status = 'active', 'active', 'created'),
            container_name = '${esc_container}',
            ssh_host = '${SSH_HOST_VALUE}',
            ssh_port = ${ssh_port},
            container_username = '${esc_user}',
            created_at = IFNULL(created_at, NOW())
        WHERE user_id = ${user_id};
    "

    echo "    [OK] Entorno listo"
}

# ==========================================
# COMPROBACIONES INICIALES
# ==========================================
if ! "$DOCKER_BIN" ps --format '{{.Names}}' | grep -qx "$DB_CONTAINER"; then
    echo "[ERROR] No está arrancado el contenedor de BBDD: $DB_CONTAINER"
    exit 1
fi

if ! "$DOCKER_BIN" image inspect "$IMAGE_NAME" >/dev/null 2>&1; then
    echo "[ERROR] No existe la imagen $IMAGE_NAME"
    echo "Construyela primero con:"
    echo "docker build -t $IMAGE_NAME ./Build"
    exit 1
fi

# Asegura fila player_envs para todos los jugadores aprobados
approved_ids="$(db_query "
    SELECT u.id
    FROM users u
    WHERE u.role = 'player'
      AND u.status = 'approved'
      AND u.container_password_hash IS NOT NULL
    ORDER BY u.id ASC;
")"

if [ -z "$approved_ids" ]; then
    echo "[!] No hay jugadores aprobados con contraseña de contenedor."
    exit 0
fi

while IFS=$'\n' read -r approved_id; do
    [ -z "${approved_id:-}" ] && continue
    ensure_player_env_row "$approved_id"
done <<< "$approved_ids"

rows="$(db_query "
    SELECT u.id,
           u.alias,
           u.container_password_hash,
           pe.env_status,
           COALESCE(pe.ssh_port, '')
    FROM users u
    INNER JOIN player_envs pe ON pe.user_id = u.id
    WHERE u.role = 'player'
      AND u.status = 'approved'
      AND u.container_password_hash IS NOT NULL
      AND pe.env_status <> 'finished'
    ORDER BY u.id ASC;
")"

if [ -z "$rows" ]; then
    echo "[!] No hay jugadores para aprovisionar."
    exit 0
fi

player_count="$(printf '%s\n' "$rows" | sed '/^$/d' | wc -l | tr -d ' ')"
prepare_hwsim_pool "$player_count"

player_index=0
while IFS=$'\t' read -r user_id alias container_password_hash env_status ssh_port; do
    [ -z "${user_id:-}" ] && continue

    iface_index=$((HOST_WIFI_RESERVE + player_index))
    host_iface="${WLAN_INTERFACES[$iface_index]}"

    if ! provision_one "$user_id" "$alias" "$container_password_hash" "$env_status" "$ssh_port" "$host_iface"; then
        echo "    [ERROR] Falló el aprovisionamiento del usuario $alias (id=$user_id)"
    fi

    player_index=$((player_index + 1))
done <<< "$rows"

echo ""
echo "[✓] Proceso terminado."
echo "[✓] Reservadas en el host para el laboratorio CTF: wlan0..wlan$((HOST_WIFI_RESERVE - 1))"
