#!/bin/bash

# Define Log File
LOG="/home/site/wwwroot/startup_debug.log"

echo "=== STARTUP DEBUG $(date) ===" > $LOG

# 1. Inspect Environment
echo "--- User/Group ---" >> $LOG
id >> $LOG
echo "--- PWD ---" >> $LOG
pwd >> $LOG

# 2. Check for critical files
echo "--- File Existence Check ---" >> $LOG
[ -f "/home/site/wwwroot/default" ] && echo "FOUND: /home/site/wwwroot/default" >> $LOG || echo "MISSING: /home/site/wwwroot/default" >> $LOG
[ -f "/home/site/wwwroot/public/index.php" ] && echo "FOUND: /home/site/wwwroot/public/index.php" >> $LOG || echo "MISSING: /home/site/wwwroot/public/index.php" >> $LOG

# 3. List wwwroot to be sure on file structure
echo "--- Listing wwwroot ---" >> $LOG
ls -la /home/site/wwwroot >> $LOG

# 4. Attempt Nginx Config Swap
echo "--- Swapping Nginx Config ---" >> $LOG
cp -v /home/site/wwwroot/default /etc/nginx/sites-available/default >> $LOG 2>&1
ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

# 5. Overwrite Welcome Page (Brute Force)
echo "--- Overwriting Welcome Page ---" >> $LOG
echo "<h1>DEBUG MODE: App should load soon</h1><p>Timestamp: $(date)</p>" > /usr/share/nginx/html/index.html
cat /usr/share/nginx/html/index.html >> $LOG

# 6. Test Nginx Config
echo "--- Nginx Config Test ---" >> $LOG
nginx -t >> $LOG 2>&1

# 7. Reload Nginx
echo "--- Reloading Nginx ---" >> $LOG
service nginx reload >> $LOG 2>&1

# 8. Start PHP
echo "--- Starting PHP-FPM ---" >> $LOG
php-fpm -F >> $LOG 2>&1
