# CTF-Wi-Fi

Laboratorio CTF Wi-Fi para practicar analisis de redes inalambricas en un entorno controlado. El proyecto combina una plataforma web en PHP, una base de datos MariaDB, contenedores Docker para jugadores y redes Wi-Fi virtuales creadas con `mac80211_hwsim`, `hostapd`, `dnsmasq` y `wpa_supplicant`.

La dinamica esta pensada para un evento o una clase: los jugadores se registran en el portal, el administrador aprueba cuentas, se crea un entorno aislado por jugador y cada participante avanza por niveles con puntuacion, pistas y penalizaciones.

> Uso previsto: laboratorio local y autorizado. No uses estas tecnicas contra redes reales o sistemas de terceros.

## Contenido del repositorio

```text
.
|-- Build/Dockerfile                  # Imagen base Kali para contenedores de jugador
|-- Dockerfile.web                    # Imagen Apache/PHP de la plataforma web
|-- compose.yml                       # MariaDB + web
|-- ctf.sh                            # Menu principal de administracion del CTF
|-- db/init.sql                       # Esquema inicial, configuracion y usuario admin
|-- lab/                              # Configuracion de APs, clientes y material de retos
|-- scripts/                          # Aprovisionamiento, arranque y limpieza de niveles
`-- web/                              # Portal PHP: login, registro, admin, jugador y niveles
```

## Requisitos

El laboratorio esta orientado a Linux con soporte para `mac80211_hwsim`. Se recomienda Ubuntu Desktop 25.10 o una distribucion compatible.

No esta pensado para ejecutarse directamente en Windows o WSL, porque los niveles dependen de modulos del kernel, interfaces Wi-Fi virtuales, namespaces de red y herramientas que necesitan privilegios de root.

Dependencias principales:

- Docker Engine y Docker Compose.
- Cliente MariaDB/MySQL para administracion opcional.
- Herramientas Wi-Fi: `aircrack-ng`, `wpasupplicant`, `isc-dhcp-client`, `iproute2`, `iw`, `hostapd`, `dnsmasq`.
- Permisos de `sudo` o ejecucion como `root`.

Instalacion rapida de dependencias en Ubuntu:

```bash
sudo apt update
sudo apt install -y mysql-client wpasupplicant isc-dhcp-client iproute2 iw hostapd dnsmasq curl
```

Instalacion de Docker desde el repositorio oficial:

```bash
sudo apt update
sudo apt install -y ca-certificates curl

sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc

sudo tee /etc/apt/sources.list.d/docker.sources <<EOF
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: $(. /etc/os-release && echo "${UBUNTU_CODENAME:-$VERSION_CODENAME}")
Components: stable
Signed-By: /etc/apt/keyrings/docker.asc
EOF

sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
```

Opcionalmente, para ejecutar Docker sin `sudo`:

```bash
sudo usermod -aG docker "$USER"
```

Cierra sesion y vuelve a entrar para que el cambio de grupo tenga efecto.

## Arranque rapido

La forma recomendada de operar el laboratorio es usar el menu principal:

```bash
sudo bash ctf.sh
```

Flujo recomendado:

1. Ejecuta `sudo bash ctf.sh`.
2. Usa la opcion `1. Iniciar web/BBDD`.
3. Configura el dominio o IP que veran los jugadores para conectarse por SSH.
4. Abre el portal en `http://<IP-o-dominio>:8080`.
5. Los jugadores se registran con un alias, contrasena y codigo de invitacion.
6. Entra como administrador y aprueba las cuentas.
7. Vuelve al menu y usa la opcion `5. Iniciar/reiniciar niveles y jugadores`.
8. Cada jugador entra en su panel, copia su comando SSH y empieza el nivel 1 desde la web.

El codigo de invitacion inicial se define en `db/init.sql`. Por defecto es:

```text
CLASE2026
```

Puedes verlo o cambiarlo desde el menu `ctf.sh`.

## Menu de administracion

`ctf.sh` centraliza las operaciones del laboratorio:

| Opcion | Accion |
| --- | --- |
| `1` | Inicia MariaDB y la web con Docker Compose. |
| `2` | Muestra el codigo de invitacion activo. |
| `3` | Cambia el codigo de invitacion. |
| `4` | Muestra o cambia el dominio/IP publicado para SSH. |
| `5` | Construye la imagen de jugador, limpia niveles, aprovisiona jugadores aprobados e inicia redes Wi-Fi. |
| `6` | Reinicia el laboratorio desde cero. Borra base de datos, volumenes y contenedores de jugadores. |
| `7` | Muestra estado de Docker, contenedores de jugadores y entornos en base de datos. |
| `8` | Para todo y borra contenedores de jugadores y volumen de base de datos. |
| `0` | Sale del menu. |

Las opciones `6` y `8` son destructivas: eliminan progreso, usuarios, aprobaciones y datos persistentes.

## Como funciona el CTF

El CTF tiene tres capas principales:

1. **Portal web**
   - Login y registro de jugadores.
   - Panel de administrador.
   - Panel de jugador con estado del entorno y comando SSH.
   - Pantalla publica opcional con plazas, lista de espera y leaderboard.
   - Paginas de niveles con enunciados, pistas, validacion y puntuacion.

2. **Entornos de jugador**
   - Cada jugador aprobado recibe un contenedor Docker propio basado en Kali.
   - El usuario Linux del contenedor usa el alias del jugador.
   - La contrasena SSH es la misma que el jugador uso al registrarse.
   - El contenedor recibe una interfaz Wi-Fi virtual llamada `wlan0`.
   - El panel del jugador muestra el comando de conexion, por ejemplo:

```bash
ssh alias@192.168.220.10 -p 2200
```

3. **Laboratorio Wi-Fi**
   - El host crea radios virtuales con `mac80211_hwsim`.
   - Los scripts levantan puntos de acceso, clientes y redes de ruido.
   - Los jugadores investigan desde su contenedor con herramientas como `airmon-ng`, `airodump-ng`, `aireplay-ng` y `aircrack-ng`.
   - La web valida las respuestas o capturas segun el nivel.

## Flujo del jugador

1. Entrar en `http://<IP-o-dominio>:8080`.
2. Crear cuenta con alias, contrasena y codigo de invitacion.
3. Esperar aprobacion del administrador.
4. Cuando el entorno este listo, copiar el comando SSH del panel.
5. Entrar al contenedor personal por SSH.
6. Leer el enunciado del nivel en la web.
7. Analizar las redes Wi-Fi desde el contenedor.
8. Enviar la flag, captura o PSK solicitada.
9. Al resolver un nivel, se desbloquea el siguiente.

Dentro del contenedor, el jugador tambien encontrara un archivo:

```text
/home/<alias>/README_CTF_WIFI.txt
```

Ese archivo resume recomendaciones basicas para trabajar dentro del laboratorio.

## Niveles

| Nivel | Nombre | Objetivo | Puntos base |
| --- | --- | --- | --- |
| 1 | Black beacon | Identificar el BSSID exacto de una red Wi-Fi oculta. | 50 |
| 2 | Ghost Name | Reconstruir el SSID real de la red oculta. | 75 |
| 3 | THE GUARDED GATE | Capturar y subir un handshake WPA/WPA2 valido en formato `.cap`. | 100 |
| 4 | THE CIPHER FALLS | Usar la captura y un diccionario para recuperar la WPA2 PreSharedKey. | 125 |

Los niveles se desbloquean en orden. El nivel 2 requiere completar el nivel 1, el nivel 3 requiere completar el nivel 2 y el nivel 4 requiere completar el nivel 3.

## Puntuacion y penalizaciones

Cada nivel tiene una puntuacion base. La puntuacion final se calcula asi:

```text
puntos_finales = puntos_base - (pistas_usadas * 10) - (intentos_fallidos * 5)
```

La puntuacion minima de un nivel es `0`.

Reglas principales:

- Cada pista usada resta `10` puntos.
- Cada flag incorrecta, captura invalida o PSK incorrecta resta `5` puntos.
- Al completar un nivel, los puntos obtenidos se suman al total del jugador.
- El leaderboard ordena por puntos, niveles completados e intentos fallidos.

## Gestion de usuarios

El registro usa un codigo de invitacion activo. Tras registrarse, un jugador puede quedar en uno de estos estados:

- `pending_review`: cuenta registrada y pendiente de revision.
- `waitlisted`: sin plaza disponible, queda en lista de espera.
- `approved`: jugador aprobado y apto para recibir entorno.
- `deleted`: usuario eliminado o sin acceso.

Desde el panel de administrador puedes:

- Aprobar jugadores.
- Enviar jugadores a lista de espera.
- Desaprobar jugadores.
- Eliminar jugadores.
- Cambiar el numero maximo de plazas.
- Activar o desactivar la pantalla publica.

## Comandos manuales utiles

Aunque `ctf.sh` es el metodo recomendado, estos comandos pueden servir para depuracion.

Construir la imagen base de jugadores:

```bash
sudo docker build -t ctf-player-base:1.0 -f Build/Dockerfile .
```

Levantar web y base de datos:

```bash
sudo docker compose up -d --build
```

Ver estado de los servicios:

```bash
sudo docker compose ps
sudo docker ps -a
```

Limpiar niveles Wi-Fi:

```bash
sudo bash scripts/cleanup_levels.sh
```

Arrancar niveles Wi-Fi:

```bash
sudo bash scripts/start_level1.sh
sudo bash scripts/start_level3.sh
```

Aprovisionar entornos de jugadores aprobados:

```bash
sudo bash scripts/provision_player_envs_wifi.sh
```

## Puertos y servicios

| Servicio | Puerto | Descripcion |
| --- | --- | --- |
| Web | `8080/tcp` | Portal del CTF. |
| MariaDB | Interno en red Docker | Base de datos `ctf_wifi`. |
| SSH jugadores | Desde `2200/tcp` | Un puerto publicado por cada contenedor `player-*`. |

El host SSH mostrado a los jugadores se guarda en la tabla `app_config` con la clave `ssh_host`. Puedes cambiarlo desde la opcion `4` del menu.

## Solucion de problemas

**El menu dice que Docker no existe**

Verifica la instalacion:

```bash
docker --version
docker compose version
```

**No aparecen interfaces `wlan*`**

Comprueba que el modulo puede cargarse:

```bash
sudo modprobe -r mac80211_hwsim 2>/dev/null || true
sudo modprobe mac80211_hwsim radios=20
iw dev
```

**No se crean contenedores de jugadores**

Primero debe existir al menos un jugador aprobado. Registra jugadores, apruebalos desde el panel admin y despues ejecuta la opcion `5` del menu.

**El jugador no puede conectar por SSH**

Revisa tres cosas:

- El entorno del jugador debe estar en estado `created` o `active`.
- El puerto publicado debe aparecer en `docker ps`.
- El dominio/IP SSH configurado debe ser accesible desde la maquina del jugador.

Puedes corregir el host publicado desde la opcion `4` de `ctf.sh`.

**Los niveles Wi-Fi quedan en estado raro**

Limpia procesos y namespaces desde el menu con la opcion `5`, o manualmente:

```bash
sudo bash scripts/cleanup_levels.sh
sudo bash scripts/start_level1.sh
sudo bash scripts/start_level3.sh
```

**La captura del nivel 3 no valida**

El portal analiza el archivo `.cap` con `aircrack-ng` dentro del contenedor web. Comprueba que la captura contiene un handshake WPA/WPA2 de la red objetivo y que el archivo no supera los 10 MB.

## Notas antes de publicar o desplegar

Antes de usar este proyecto en un evento real o subirlo a un repositorio publico, revisa:

- Cambiar credenciales de MariaDB en `compose.yml`.
- Cambiar el codigo de invitacion inicial en `db/init.sql` o desde el menu.
- Cambiar el usuario y hash inicial del administrador en `db/init.sql`.
- Revisar que no se publiquen soluciones, flags reales o material sensible de los retos.
- No exponer el portal ni los puertos SSH fuera de una red controlada.

## Licencia

Este proyecto incluye una licencia en [LICENSE](LICENSE).
