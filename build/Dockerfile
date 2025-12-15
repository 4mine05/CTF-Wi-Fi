FROM docker.io/kalilinux/kali-rolling

# Actualizar repos e instalar herramientas necesarias
# Update repositories and install necessary tools
RUN apt update && apt -y upgrade && \
    apt install -y \
        nano vim \
        python3 \
        iproute2 iw wireless-tools net-tools \
        aircrack-ng reaver bully cowpatty hashcat wordlists \
        openssh-server sudo \
        pciutils \
        wpasupplicant isc-dhcp-common isc-dhcp-client iputils-ping \
        kali-tools-top10 

# Create SSH runtime directory for daemon socket and PID files
RUN mkdir -p /var/run/sshd

# Enable password authentication in SSH
RUN sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    sed -i 's/PasswordAuthentication no/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    sed -i 's/#PermitRootLogin prohibit-password/PermitRootLogin yes/' /etc/ssh/sshd_config && \
    sed -i 's/PermitRootLogin prohibit-password/PermitRootLogin yes/' /etc/ssh/sshd_config

# Set root password
RUN echo "root:0000" | chpasswd

# Expose SSH
EXPOSE 22

CMD ["sleep", "infinity"]
