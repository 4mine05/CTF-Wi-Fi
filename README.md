# CTF-Wi-Fi
## Dependencias Ubuntu desktop 25.10
### 1. Cliente MySQL 

```bash
sudo apt update && sudo apt install mysql-client-core -y
```
### 2. Docker y Docker Compose
  - **2.1 Actualizar el sistema e instalar dependencias básicas**
    ```bash
    sudo apt update
    ```
  - **2.2 Añadir la clave GPG oficial de Docker**
    ```bash
    sudo install -m 0755 -d /etc/apt/keyrings
    sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
    sudo chmod a+r /etc/apt/keyrings/docker.asc
    ```
  - **2.3 Añadir el repositorio oficial de Docker**
    ```bash
    sudo tee /etc/apt/sources.list.d/docker.sources <<EOF
    Types: deb
    URIs: https://download.docker.com/linux/ubuntu
    Suites: $(. /etc/os-release && echo "${UBUNTU_CODENAME:-$VERSION_CODENAME}")
    Components: stable
    Signed-By: /etc/apt/keyrings/docker.asc
    EOF
    ```
  - **2.4 Instalar Docker Engine y Docker Compose**
    ```bash
    sudo apt update
    sudo apt install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin -y
    ```
  - **2.5 (Opcional) Ejecutar Docker sin sudo**
   ```bash
   sudo usermod -aG docker $USER
   ```
  *Es necesario cerrar sesión y volver a entrar para aplicar el cambio.*
### 3. Herramientas necessarias
  ```bash
  sudo apt install wpasupplicant isc-dhcp-client iproute2 iw wireless-tools hostapd dnsmasq -y
  ```

   
