# Imagen base Kali para los contenedores personales de los jugadores.
FROM docker.io/kalilinux/kali-rolling

# Instala herramientas basicas de terminal, red, Wi-Fi, SSH y apoyo para los retos.
RUN apt update && apt install -y \
        nano vim less\
        python3 \
        iproute2 iw wireless-tools net-tools \
        aircrack-ng\ 
        openssh-server sudo \
        pciutils \
        wpasupplicant man-db isc-dhcp-client iputils-ping \
        zip unzip tree locate

# Copia el diccionario comprimido usado en el nivel 4.
COPY lab/level4/diccionario_espectro.zip /opt/ctf/level4/diccionario_espectro.zip

# Actualiza la base de datos de locate para que el jugador pueda buscar archivos.
RUN updatedb

# Prepara el directorio runtime que necesita sshd.
RUN mkdir -p /var/run/sshd

# Permite autenticacion por contrasena y login root dentro del contenedor.
RUN sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    sed -i 's/PasswordAuthentication no/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    sed -i 's/#PermitRootLogin prohibit-password/PermitRootLogin yes/' /etc/ssh/sshd_config && \
    sed -i 's/PermitRootLogin prohibit-password/PermitRootLogin yes/' /etc/ssh/sshd_config

# Contrasena root inicial del contenedor base.
RUN echo "root:0000" | chpasswd

# Puerto SSH interno del contenedor de jugador.
EXPOSE 22

# Mantiene el contenedor vivo; el aprovisionamiento arranca sshd despues.
CMD ["sleep", "infinity"]
