-- La rete interna di Compose non ha una subnet fissa: l'accesso è limitato dal fatto
-- che il database non espone porte fuori dalla rete interna.
CREATE USER IF NOT EXISTS 'laravel'@'%' IDENTIFIED BY '';
GRANT ALL PRIVILEGES ON foto.* TO 'laravel'@'%';
FLUSH PRIVILEGES;
