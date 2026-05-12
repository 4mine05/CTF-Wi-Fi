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

# Detener procesos que puedan estar corriendo
kill_pidfile() {
    local pidfile="$1"

    if [ -f "$pidfile" ]; then
        pkill -F "$pidfile" || true
        rm -f "$pidfile"
    fi
}

# Eliminar una red virtual
cleanup_netns() {
    local netns="$1"

    if ip netns list | awk '{print $1}' | grep -qx "$netns"; then
        ip netns exec "$netns" pkill wpa_supplicant || true
        ip netns exec "$netns" pkill dhclient || true
        ip netns exec "$netns" pkill ping || true
        ip netns del "$netns" || true
    fi
}

# Eliminar una interfaz
cleanup_iface() {
    local iface="$1"

    if ip link show "$iface" >/dev/null 2>&1; then
        ip addr flush dev "$iface" || true
        ip link set "$iface" down || true
    fi
}

# Detener todos los procesos
for pidfile in /run/level1*.pid /run/level3*.pid; do
    [ -e "$pidfile" ] && kill_pidfile "$pidfile"
done

# Eliminar todas las redes virtuales
cleanup_netns level1-client1
cleanup_netns level1-client2
cleanup_netns level1-client3
cleanup_netns level1-fake-client1
cleanup_netns level1-fake-client2

cleanup_netns level3-client1
cleanup_netns level3-client2
cleanup_netns level3-client3
cleanup_netns level3-client4
cleanup_netns level3-fake-client1
cleanup_netns level3-fake-client2

sleep 1

# Eliminar todas las interfaces
cleanup_iface wlan0
cleanup_iface wlan1
cleanup_iface wlan2
cleanup_iface wlan3
cleanup_iface wlan4
cleanup_iface wlan5
cleanup_iface wlan6
cleanup_iface wlan7
cleanup_iface wlan10
cleanup_iface wlan11
cleanup_iface wlan12
cleanup_iface wlan13
cleanup_iface wlan14
cleanup_iface wlan15
cleanup_iface wlan16
cleanup_iface wlan17
cleanup_iface wlan18

# Eliminar archivos temporales de ejecución de los niveles
rm -f /run/level1*.pid /run/level1*.leases
rm -f /run/level3*.pid /run/level3*.leases

# Desbloquear todas las interfaces Radio/Wifi
rfkill unblock all || true
