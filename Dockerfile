FROM php:8.2-apache


RUN apt-get update && apt-get install -y openssl && rm -rf /var/lib/apt/lists/*


RUN a2enmod ssl headers


RUN openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout /etc/ssl/private/apache-selfsigned.key \
    -out /etc/ssl/certs/apache-selfsigned.crt \
    -subj "/C=PL/ST=Malopolskie/L=Krakow/O=Parking/OU=IT/CN=localhost"


RUN echo '<VirtualHost *:80>\n\
    DocumentRoot /var/www/html\n\
    ErrorLog /proc/self/fd/2\n\
    CustomLog /proc/self/fd/1 combined\n\
    </VirtualHost>\n\
    \n\
    <VirtualHost *:443>\n\
    DocumentRoot /var/www/html\n\
    SSLEngine on\n\
    SSLCertificateFile /etc/ssl/certs/apache-selfsigned.crt\n\
    SSLCertificateKeyFile /etc/ssl/private/apache-selfsigned.key\n\
    ErrorLog /proc/self/fd/2\n\
    CustomLog /proc/self/fd/1 combined\n\
    </VirtualHost>' > /etc/apache2/sites-available/000-default.conf


WORKDIR /var/www/html


COPY . /var/www/html


RUN chown -R root:root /var/www/html

EXPOSE 80 443
