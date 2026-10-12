
FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions pdo_mysql

COPY . /app
