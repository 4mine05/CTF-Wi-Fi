#!/usr/bin/env bash
set -euo pipefail

# Modo debug opcional, salida silenciosa por defecto
if [ "${LEVEL_DEBUG:-0}" = "1" ]; then
    set -x
    trap 'echo "ERROR line $LINENO: $BASH_COMMAND" >&2' ERR
else
    exec >/dev/null 2>&1
fi

# Comprobar si el script se ejecuta como root
if [ "${EUID:-$(id -u)}" -ne 0 ]; then
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
LEVEL_DIR="$REPO_ROOT/lab/level1"

# Detener procesos que puedan estar corriendo
kill_pidfile() {
    local pidfile="$1"

    if [ -f "$pidfile" ]; then
        pkill -F "$pidfile" || true
        rm -f "$pidfile"
    fi
}

# Eliminar todas las redes virtuales
cleanup_netns() {
    local netns="$1"

    if ip netns list | awk '{print $1}' | grep -qx "$netns"; then
        ip netns exec "$netns" pkill wpa_supplicant || true
        ip netns exec "$netns" pkill dhclient || true
        ip netns exec "$netns" pkill ping || true
        ip netns del "$netns" || true
    fi
}

# Configurar la interfaz AP
setup_ap_iface() {
    local iface="$1"
    local cidr="$2"

    ip addr flush dev "$iface"
    ip link set "$iface" down
    ip link set "$iface" up
    ip addr add "$cidr" dev "$iface"
}

# Mover la interfaz a la red virtual
move_iface_to_netns() {
    local iface="$1"
    local netns="$2"
    local phy

    phy="$(basename "$(readlink -f "/sys/class/net/$iface/phy80211")")"
    ip link set "$iface" down
    iw phy "$phy" set netns name "$netns"
    ip -n "$netns" link set lo up
    ip netns exec "$netns" ip link set "$iface" up
}

# Iniciar el cliente
start_client() {
    local netns="$1"
    local iface="$2"
    local conf="$3"

    ip netns add "$netns"
    move_iface_to_netns "$iface" "$netns"
    ip netns exec "$netns" wpa_supplicant \
        -B \
        -P "/run/$netns-wpa_supplicant.pid" \
        -i "$iface" \
        -c "$conf"
    ip netns exec "$netns" dhclient \
        -pf "/run/$netns-dhclient.pid" \
        -lf "/run/$netns-dhclient.leases" \
        "$iface" &
}

# Detener procesos
for pidfile in /run/level1*.pid; do
    [ -e "$pidfile" ] && kill_pidfile "$pidfile"
done

# Eliminar todas las redes virtuales
cleanup_netns level1-client1
cleanup_netns level1-client2
cleanup_netns level1-client3
cleanup_netns level1-fake-client1
cleanup_netns level1-fake-client2

# Eliminar archivos temporales de ejecución del nivel
rm -f /run/level1*.pid /run/level1*.leases

# Desbloquear todas las interfaces WiFi
rfkill unblock all || true

# Configurar las interfaces
setup_ap_iface wlan0 10.10.1.1/24
setup_ap_iface wlan1 10.10.11.1/24
setup_ap_iface wlan2 10.10.12.1/24

# Iniciar los servicios
hostapd -B -P /run/level1ap.pid "$LEVEL_DIR/level1ap.conf"
hostapd -B -P /run/level1fakeap1.pid "$LEVEL_DIR/level1fakeap1.conf"
hostapd -B -P /run/level1fakeap2.pid "$LEVEL_DIR/level1fakeap2.conf"

dnsmasq --conf-file="$LEVEL_DIR/level1dnsmasq.conf" --pid-file=/run/level1dnsmasq.pid
dnsmasq --conf-file="$LEVEL_DIR/level1dnsmasq_fake1.conf" --pid-file=/run/level1dnsmasq_fake1.pid
dnsmasq --conf-file="$LEVEL_DIR/level1dnsmasq_fake2.conf" --pid-file=/run/level1dnsmasq_fake2.pid

sleep 1

# Iniciar los clientes
start_client level1-client1 wlan3 "$LEVEL_DIR/level1cli.conf"
start_client level1-client2 wlan4 "$LEVEL_DIR/level1cli2.conf"
start_client level1-client3 wlan5 "$LEVEL_DIR/level1cli3.conf"
start_client level1-fake-client1 wlan6 "$LEVEL_DIR/level1fakecli1.conf"
start_client level1-fake-client2 wlan7 "$LEVEL_DIR/level1fakecli2.conf"
