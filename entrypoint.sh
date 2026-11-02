#!/bin/bash
set -e

# 1. I-disable ang mga conflict na MPM modules at i-enable ang prefork
a2dismod mpm_event mpm_worker > /dev/null 2>&1 || true
a2enmod mpm_prefork > /dev/null 2>&1 || true

# 2. Ayusin ang port para makinig sa PORT na ibinibigay ng Railway
sed -i "s/Listen 80/Listen 0.0.0.0:${PORT:-8080}/g" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT:-8080}>/g" /etc/apache2/sites-available/000-default.conf

# 3. Simulan ang Apache
exec apache2-foreground