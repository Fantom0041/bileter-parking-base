FROM php:8.2-apache

# Enable Apache mod_rewrite for potential future URL routing
RUN a2enmod rewrite

# Set the working directory
WORKDIR /var/www/html

# Copy application files to the container
COPY . /var/www/html

# Set permissions:
# We need to give the Apache user (www-data) write access to the directory
# so it can update data.json and write receipts.
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Expose port 80 (internal)
EXPOSE 80
