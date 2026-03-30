FROM php:7.4-apache-buster

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN apt-get update && \
    apt-get install -y imagemagick ghostscript cron

RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions gd imagick

RUN /bin/bash -c "sed -i '/disable ghostscript format types/,+6d' /etc/ImageMagick-6/policy.xml"

ENV TZ="America/Phoenix"

RUN /bin/bash -c '(crontab -l ; echo "5 6 * * * /app/cli/cron.php")  | crontab -'
RUN /bin/bash -c '(crontab -l ; echo "5 16 * * * /app/cli/cron.php") | crontab -'

RUN addgroup --gid 1000 alex
RUN adduser --disabled-password --gecos '' --uid 1000 --gid 1000 alex
USER alex

