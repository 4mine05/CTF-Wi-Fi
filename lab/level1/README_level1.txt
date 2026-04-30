NIVEL 1 - RED OCULTA

Objetivo:
Descubrir el BSSID exacto del AP oculto Black_Beacon.

Datos del escenario:
- AP objetivo: wlan0
- SSID objetivo: Black_Beacon
- BSSID objetivo / flag: 44:45:41:55:54:48
- Canal objetivo: 6
- Red objetivo: 10.10.1.0/24
- Clientes objetivo: wlan3, wlan4, wlan5
- APs secundarios de ruido:
  - wlan1: TP-Link_D1CA, BSSID 44:45:41:55:54:49, canal 1, red 10.10.11.0/24, oculta, WPA2, PSK tplink2026
  - wlan2: MIWIFI_2G_j6pu, BSSID 02:11:22:33:44:51, canal 11, red 10.10.12.0/24, visible, WPA2, PSK miwifi2026
- Clientes secundarios: wlan6, wlan7

Notas:
- Levanta un solo nivel WiFi cada vez.
- Ejecuta estos comandos desde la raíz del proyecto CTF-Wi-Fi.
- El script scripts/provision_player_envs_wifi.sh reserva wlan0..wlan9 para el host.
- Si cargas mac80211_hwsim a mano, usa al menos 10 radios:
  sudo modprobe -r mac80211_hwsim 2>/dev/null || true
  sudo modprobe mac80211_hwsim radios=10

IMPORTANTE:
- Para mover interfaces WiFi virtuales de mac80211_hwsim a namespaces, no uses:
  sudo ip link set wlanX netns nombre_namespace
- Usa el PHY correspondiente con:
  sudo iw phy phyX set netns name nombre_namespace

Limpieza previa:
  sudo pkill hostapd || true
  sudo pkill dnsmasq || true
  sudo pkill wpa_supplicant || true
  sudo pkill dhclient || true
  sudo ip netns del level1-client1 2>/dev/null || true
  sudo ip netns del level1-client2 2>/dev/null || true
  sudo ip netns del level1-client3 2>/dev/null || true
  sudo ip netns del level1-fake-client1 2>/dev/null || true
  sudo ip netns del level1-fake-client2 2>/dev/null || true
  sudo rm -f /run/level1*.pid
  sudo rfkill unblock all

Comprobación inicial de radios:
  ip a | grep -E 'wlan[0-9]'
  iw dev

Preparar APs:
  sudo ip addr flush dev wlan0
  sudo ip addr flush dev wlan1
  sudo ip addr flush dev wlan2
  sudo ip link set wlan0 down
  sudo ip link set wlan1 down
  sudo ip link set wlan2 down
  sudo ip link set wlan0 up
  sudo ip link set wlan1 up
  sudo ip link set wlan2 up
  sudo ip addr add 10.10.1.1/24 dev wlan0
  sudo ip addr add 10.10.11.1/24 dev wlan1
  sudo ip addr add 10.10.12.1/24 dev wlan2

Levantar AP objetivo y APs secundarios:
  sudo hostapd -B -P /run/level1ap.pid lab/level1/level1ap.conf
  sudo hostapd -B -P /run/level1fakeap1.pid lab/level1/level1fakeap1.conf
  sudo hostapd -B -P /run/level1fakeap2.pid lab/level1/level1fakeap2.conf

Comprobar APs:
  sudo ps aux | grep '[h]ostapd'
  sudo iw dev wlan0 info
  sudo iw dev wlan1 info
  sudo iw dev wlan2 info

Levantar DHCP:
  sudo dnsmasq --conf-file=lab/level1/level1dnsmasq.conf --pid-file=/run/level1dnsmasq.pid
  sudo dnsmasq --conf-file=lab/level1/level1dnsmasq_fake1.conf --pid-file=/run/level1dnsmasq_fake1.pid
  sudo dnsmasq --conf-file=lab/level1/level1dnsmasq_fake2.conf --pid-file=/run/level1dnsmasq_fake2.pid

Comprobar DHCP:
  sudo ps aux | grep '[d]nsmasq'

Cliente objetivo 1:
  sudo ip netns add level1-client1 2>/dev/null || true
  PHY=$(basename "$(readlink -f /sys/class/net/wlan3/phy80211)")
  sudo ip link set wlan3 down
  sudo iw phy "$PHY" set netns name level1-client1
  sudo ip -n level1-client1 link set lo up
  sudo ip netns exec level1-client1 ip link set wlan3 up
  sudo ip netns exec level1-client1 wpa_supplicant -B -i wlan3 -c "$(pwd)/lab/level1/level1cli.conf"
  sudo ip netns exec level1-client1 dhclient -pf /run/level1-client1-dhclient.pid -lf /run/level1-client1-dhclient.leases wlan3 >/dev/null 2>&1 &

Cliente objetivo 2:
  sudo ip netns add level1-client2 2>/dev/null || true
  PHY=$(basename "$(readlink -f /sys/class/net/wlan4/phy80211)")
  sudo ip link set wlan4 down
  sudo iw phy "$PHY" set netns name level1-client2
  sudo ip -n level1-client2 link set lo up
  sudo ip netns exec level1-client2 ip link set wlan4 up
  sudo ip netns exec level1-client2 wpa_supplicant -B -i wlan4 -c "$(pwd)/lab/level1/level1cli2.conf"
  sudo ip netns exec level1-client2 dhclient -pf /run/level1-client2-dhclient.pid -lf /run/level1-client2-dhclient.leases wlan4 >/dev/null 2>&1 &

Cliente objetivo 3:
  sudo ip netns add level1-client3 2>/dev/null || true
  PHY=$(basename "$(readlink -f /sys/class/net/wlan5/phy80211)")
  sudo ip link set wlan5 down
  sudo iw phy "$PHY" set netns name level1-client3
  sudo ip -n level1-client3 link set lo up
  sudo ip netns exec level1-client3 ip link set wlan5 up
  sudo ip netns exec level1-client3 wpa_supplicant -B -i wlan5 -c "$(pwd)/lab/level1/level1cli3.conf"
  sudo ip netns exec level1-client3 dhclient -pf /run/level1-client3-dhclient.pid -lf /run/level1-client3-dhclient.leases wlan5 >/dev/null 2>&1 &

Cliente secundario 1:
  sudo ip netns add level1-fake-client1 2>/dev/null || true
  PHY=$(basename "$(readlink -f /sys/class/net/wlan6/phy80211)")
  sudo ip link set wlan6 down
  sudo iw phy "$PHY" set netns name level1-fake-client1
  sudo ip -n level1-fake-client1 link set lo up
  sudo ip netns exec level1-fake-client1 ip link set wlan6 up
  sudo ip netns exec level1-fake-client1 wpa_supplicant -B -i wlan6 -c "$(pwd)/lab/level1/level1fakecli1.conf"
  sudo ip netns exec level1-fake-client1 dhclient -pf /run/level1-fake-client1-dhclient.pid -lf /run/level1-fake-client1-dhclient.leases wlan6 >/dev/null 2>&1 &

Cliente secundario 2:
  sudo ip netns add level1-fake-client2 2>/dev/null || true
  PHY=$(basename "$(readlink -f /sys/class/net/wlan7/phy80211)")
  sudo ip link set wlan7 down
  sudo iw phy "$PHY" set netns name level1-fake-client2
  sudo ip -n level1-fake-client2 link set lo up
  sudo ip netns exec level1-fake-client2 ip link set wlan7 up
  sudo ip netns exec level1-fake-client2 wpa_supplicant -B -i wlan7 -c "$(pwd)/lab/level1/level1fakecli2.conf"
  sudo ip netns exec level1-fake-client2 dhclient -pf /run/level1-fake-client2-dhclient.pid -lf /run/level1-fake-client2-dhclient.leases wlan7 >/dev/null 2>&1 &

Comprobación de namespaces:
  ip netns list
  sudo ip netns exec level1-client1 ip a
  sudo ip netns exec level1-client2 ip a
  sudo ip netns exec level1-client3 ip a
  sudo ip netns exec level1-fake-client1 ip a
  sudo ip netns exec level1-fake-client2 ip a

Comprobación de asociación WiFi:
  sudo ip netns exec level1-client1 iw dev wlan3 link
  sudo ip netns exec level1-client2 iw dev wlan4 link
  sudo ip netns exec level1-client3 iw dev wlan5 link
  sudo ip netns exec level1-fake-client1 iw dev wlan6 link
  sudo ip netns exec level1-fake-client2 iw dev wlan7 link

Comprobación de IP por DHCP:
  sudo ip netns exec level1-client1 ip addr show wlan3
  sudo ip netns exec level1-client2 ip addr show wlan4
  sudo ip netns exec level1-client3 ip addr show wlan5
  sudo ip netns exec level1-fake-client1 ip addr show wlan6
  sudo ip netns exec level1-fake-client2 ip addr show wlan7

Escucha recomendada para el jugador:
  sudo airmon-ng start [interfaz]
  sudo airodump-ng [interfaz_monitor]
  sudo airodump-ng -c 6 --bssid 44:45:41:55:54:48 [interfaz_monitor]

Validación:
  La flag del nivel 1 es el BSSID exacto del AP oculto:
  44:45:41:55:54:48
