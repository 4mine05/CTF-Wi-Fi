#!/usr/bin/env bash
set -euo pipefail

if [ "${LEVEL_DEBUG:-0}" = "1" ]; then
    set -x
    trap 'echo "ERROR line $LINENO: $BASH_COMMAND" >&2' ERR
else
    exec >/dev/null 2>&1
fi

if [ "${EUID:-$(id -u)}" -ne 0 ]; then
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
LEVEL_DIR="$REPO_ROOT/lab/level3"

kill_pidfile() {
    local pidfile="$1"

    if [ -f "$pidfile" ]; then
        pkill -F "$pidfile" || true
        rm -f "$pidfile"
    fi
}

cleanup_netns() {
    local netns="$1"

    if ip netns list | awk '{print $1}' | grep -qx "$netns"; then
        ip netns exec "$netns" pkill wpa_supplicant || true
        ip netns exec "$netns" pkill dhclient || true
        ip netns exec "$netns" pkill ping || true
        ip netns del "$netns" || true
    fi
}

setup_ap_iface() {
    local iface="$1"
    local cidr="$2"

    ip addr flush dev "$iface"
    ip link set "$iface" down
    ip link set "$iface" up
    ip addr add "$cidr" dev "$iface"
}

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

start_ping() {
    local netns="$1"
    local target="$2"
    local pidfile="$3"

    ip netns exec "$netns" ping -i 1 "$target" &
    echo "$!" > "$pidfile"
}

for pidfile in /run/level3*.pid; do
    [ -e "$pidfile" ] && kill_pidfile "$pidfile"
done

cleanup_netns level3-client1
cleanup_netns level3-client2
cleanup_netns level3-client3
cleanup_netns level3-client4
cleanup_netns level3-fake-client1
cleanup_netns level3-fake-client2
rm -f /run/level3*.pid /run/level3*.leases

rfkill unblock all || true

setup_ap_iface wlan10 10.10.3.1/24
setup_ap_iface wlan15 10.10.31.1/24
setup_ap_iface wlan16 10.10.32.1/24

hostapd -B -P /run/level3ap.pid "$LEVEL_DIR/level3ap.conf"
hostapd -B -P /run/level3fakeap1.pid "$LEVEL_DIR/level3fakeap1.conf"
hostapd -B -P /run/level3fakeap2.pid "$LEVEL_DIR/level3fakeap2.conf"

dnsmasq --conf-file="$LEVEL_DIR/level3dnsmasq.conf" --pid-file=/run/level3dnsmasq.pid
dnsmasq --conf-file="$LEVEL_DIR/level3dnsmasq_fake1.conf" --pid-file=/run/level3dnsmasq_fake1.pid
dnsmasq --conf-file="$LEVEL_DIR/level3dnsmasq_fake2.conf" --pid-file=/run/level3dnsmasq_fake2.pid

sleep 1

start_client level3-client1 wlan11 "$LEVEL_DIR/level3cli1.conf"
start_client level3-client2 wlan12 "$LEVEL_DIR/level3cli2.conf"
start_client level3-client3 wlan13 "$LEVEL_DIR/level3cli3.conf"
start_client level3-client4 wlan14 "$LEVEL_DIR/level3cli4.conf"
start_client level3-fake-client1 wlan17 "$LEVEL_DIR/level3fakecli1.conf"
start_client level3-fake-client2 wlan18 "$LEVEL_DIR/level3fakecli2.conf"

sleep 1

start_ping level3-client1 10.10.3.1 /run/level3-client1-ping.pid
start_ping level3-client2 10.10.3.1 /run/level3-client2-ping.pid
start_ping level3-client3 10.10.3.1 /run/level3-client3-ping.pid
start_ping level3-client4 10.10.3.1 /run/level3-client4-ping.pid
start_ping level3-fake-client1 10.10.31.1 /run/level3-fake-client1-ping.pid
start_ping level3-fake-client2 10.10.32.1 /run/level3-fake-client2-ping.pid
