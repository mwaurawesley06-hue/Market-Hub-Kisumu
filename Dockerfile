FROM php:8.2-cli

RUN docker-php-ext-install pdo_mysql

WORKDIR /app
COPY . .

ENV PHP_CLI_SERVER_WORKERS=4 \
    APP_TIMEZONE=Africa/Nairobi

EXPOSE 8080

CMD ["sh", "-c", "echo \"[mpids] starting php -S 0.0.0.0:${PORT:-10000}\"; exec php -S 0.0.0.0:${PORT:-10000} router.php"]
