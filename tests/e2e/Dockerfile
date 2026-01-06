FROM php:8.2-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    curl \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    mariadb-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mysqli \
        zip \
        gd \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy composer files first for caching
COPY composer.json ./

# Install PHP dependencies
RUN composer install --no-scripts --no-autoloader

# Copy test files
COPY . .

# Generate autoloader
RUN composer dump-autoload

# Create output directory
RUN mkdir -p tests/_output && chmod 777 tests/_output

# Default command runs all tests
CMD ["vendor/bin/codecept", "run", "--steps"]
