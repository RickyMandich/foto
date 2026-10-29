#!/bin/zsh
# Invia al server la cartella di un evento di un autore.
# uso: bash/upload-event.sh <cartella-evento-locale> <autore>
# esempio: bash/upload-event.sh "/mnt/c/Foto/2025 09 20 - corteo per gaza" anna
#
# La cartella evento deve chiamarsi "AAAA MM GG - descrizione". Dopo l'upload, sul server:
#   cd ~/sites/foto && docker compose exec -T app php artisan media:sync
set -euo pipefail

if [ "$#" -ne 2 ]; then
    echo "uso: $0 <cartella-evento-locale> <autore>" >&2
    exit 1
fi

src="${1%/}"
author="$2"

# -s (protect-args): spazi e parentesi nei nomi non vanno quotati a mano per la shell remota.
# Niente -z (i JPG non si comprimono) e niente --delete (le rimozioni si fanno a mano sul server).
# --partial: se il trasferimento si interrompe (hotspot), rilanciare lo stesso comando riprende da dove era.
rsync -avs --partial --progress -e ssh "$src/" "mandich:~/sites/foto/storage/app/public/${author}/$(basename "$src")/"
