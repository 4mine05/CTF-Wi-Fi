FROM docker.io/kalilinux/kali-rolling

# Update repositories and install necessary tools
RUN apt update && apt install -y \
        nano vim \
        python3 \
        iproute2 iw wireless-tools net-tools \
        aircrack-ng\ 
        openssh-server sudo \
        pciutils \
        wpasupplicant man-db isc-dhcp-client iputils-ping \
        zip unzip

# Dictionary for level 4 - compressed with password
COPY lab/level4/diccionario_espectro.zip /opt/ctf/level4/diccionario_espectro.zip

# SSH setup
RUN mkdir -p /var/run/sshd

RUN sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    sed -i 's/PasswordAuthentication no/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    sed -i 's/#PermitRootLogin prohibit-password/PermitRootLogin yes/' /etc/ssh/sshd_config && \
    sed -i 's/PermitRootLogin prohibit-password/PermitRootLogin yes/' /etc/ssh/sshd_config

RUN echo "root:0000" | chpasswd

EXPOSE 22

CMD ["sleep", "infinity"]