# syntax=docker/dockerfile:1.7
FROM nginx:1.27-alpine

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# Bake the Symfony public/ tree into the image so nginx can serve fingerprinted
# AssetMapper output without sharing a volume with PHP-FPM.
# In dev, compose.override.yaml bind-mounts ./public on top of this for live edits.
COPY public /var/www/html/public
