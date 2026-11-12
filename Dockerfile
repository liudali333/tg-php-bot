FROM php:8.3-cli-alpine
RUN apk add --no-cache curl-dev ca-certificates && docker-php-ext-install curl
WORKDIR /app
COPY bot.php /app/bot.php
CMD ["php", "/app/bot.php"]
