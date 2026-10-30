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
# Con -s la shell remota non espande la tilde: il percorso remoto è quindi relativo alla home (senza ~/).
# --mkpath: crea anche le cartelle intermedie (la cartella autore, al primo upload).
# --info=progress2: una sola riga con la percentuale sul totale dell'evento (byte trasferiti, velocità, tempo residuo);
# --no-inc-recursive: elenca prima tutti i file, così il totale è noto da subito e la percentuale non cambia strada facendo.
# Niente -z (i JPG non si comprimono) e niente --delete (le rimozioni si fanno a mano sul server).
# --partial: se il trasferimento si interrompe (hotspot), rilanciare lo stesso comando riprende da dove era.
rsync -as --mkpath --partial --no-inc-recursive --info=progress2,stats1 -e ssh "$src/" "mandich:sites/foto/storage/app/public/${author}/$(basename "$src")/"
