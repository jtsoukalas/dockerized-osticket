FROM php:8.3.36-apache-bookworm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libicu-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libxml2-dev \
    libzip-dev \
    libonig-dev \
    libc-client-dev \
    libkrb5-dev \
    libssl-dev \
    libldap2-dev \
    unzip \
    git \
    cron \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
    && docker-php-ext-configure ldap --with-libdir=lib/$(uname -m)-linux-gnu/ \
    && docker-php-ext-install -j$(nproc) \
        intl \
        mysqli \
        pdo_mysql \
        gd \
        bcmath \
        mbstring \
        xml \
        zip \
        imap \
        ldap \
        opcache

# Install APCu
RUN pecl install apcu && docker-php-ext-enable apcu

# Enable Apache Rewrite Module
RUN a2enmod rewrite

# Configure Apache to allow .htaccess overrides
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/{s/AllowOverride None/AllowOverride All/}' /etc/apache2/apache2.conf

# Install Gettext extension
RUN docker-php-ext-install gettext

# Permissions and entrypoint
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Copy PHP configuration
COPY php.ini /usr/local/etc/php/

# Create html directory but don't change ownership of mounted files
RUN mkdir -p /var/www/html

# Configure Apache to work with mounted files (read/write with www-data)
RUN chmod 775 /var/www/html

ENTRYPOINT ["docker-entrypoint.sh"]