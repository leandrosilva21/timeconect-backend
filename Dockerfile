FROM php:8.3-fpm-alpine

WORKDIR /var/www

# Dependências do sistema
RUN apk add --no-cache \
    nginx \
    git \
    curl \
    libpng-dev \
    oniguruma-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    postgresql-dev \
    supervisor

# Extensões PHP
RUN docker-php-ext-install \
    pdo pdo_pgsql pdo_mysql \
    mbstring exif pcntl bcmath gd zip opcache

# OPcache: compila e cacheia bytecode PHP em memória
RUN printf 'opcache.enable=1\n\
opcache.enable_cli=1\n\
opcache.memory_consumption=256\n\
opcache.interned_strings_buffer=16\n\
opcache.max_accelerated_files=20000\n\
opcache.revalidate_freq=0\n\
opcache.validate_timestamps=0\n\
opcache.save_comments=1\n\
opcache.fast_shutdown=1\n' > /usr/local/etc/php/conf.d/opcache.ini

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copia o projeto
COPY . .

# Instala dependências PHP (cache de pacotes entre builds)
RUN --mount=type=cache,id=minutor-composer,target=/root/.composer \
    composer install --no-dev --optimize-autoloader --no-interaction

# Permissões
RUN mkdir -p storage/logs storage/framework/cache \
        storage/framework/sessions storage/framework/views \
        bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chown -R www-data:www-data /var/www

# Configuração nginx
RUN printf 'server {\n\
    listen 8080;\n\
    root /var/www/public;\n\
    index index.php;\n\
    disable_symlinks off;\n\
    client_max_body_size 25M;\n\
    location /storage/ {\n\
        alias /var/www/storage/app/public/;\n\
        try_files $uri =404;\n\
    }\n\
    location / {\n\
        try_files $uri $uri/ /index.php?$query_string;\n\
    }\n\
    location ~ \\.php$ {\n\
        fastcgi_pass 127.0.0.1:9000;\n\
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;\n\
        include fastcgi_params;\n\
    }\n\
}\n' > /etc/nginx/http.d/default.conf

# Limite de upload PHP
RUN printf 'upload_max_filesize=20M\npost_max_size=25M\nmemory_limit=256M\n' > /usr/local/etc/php/conf.d/uploads.ini

# PHP-FPM pool: o default da imagem é max_children=5 — muito apertado.
# Backend recebe tráfego de usuários + webhooks Movidesk (que podem segurar
# o worker por até ~5s no fetchTicket). Com 5 workers, qualquer pico ou
# pequena rajada de webhooks empilha requests. Subindo pra 25.
RUN sed -i \
      -e 's|^pm.max_children = 5$|pm.max_children = 25|' \
      -e 's|^pm.start_servers = 2$|pm.start_servers = 5|' \
      -e 's|^pm.min_spare_servers = 1$|pm.min_spare_servers = 3|' \
      -e 's|^pm.max_spare_servers = 3$|pm.max_spare_servers = 10|' \
      /usr/local/etc/php-fpm.d/www.conf

# Supervisor para rodar nginx + php-fpm juntos
RUN printf '[supervisord]\n\
nodaemon=true\n\
[program:php-fpm]\n\
command=php-fpm\n\
autostart=true\n\
autorestart=true\n\
[program:nginx]\n\
command=nginx -g "daemon off;"\n\
autostart=true\n\
autorestart=true\n' > /etc/supervisord.conf

EXPOSE 8080

CMD sh -c "php artisan migrate --force && php artisan optimize 2>/dev/null || true && php artisan storage:link --force 2>/dev/null || true && chmod -R 777 storage bootstrap/cache && supervisord -c /etc/supervisord.conf"
