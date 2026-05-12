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

Escucha recomendada para el jugador:
  sudo airmon-ng start [interfaz]
  sudo airodump-ng [interfaz_monitor]
  sudo airodump-ng -c 6 --bssid 44:45:41:55:54:48 [interfaz_monitor]

Validación:
  La flag del nivel 1 es el BSSID exacto del AP oculto:
  44:45:41:55:54:48
