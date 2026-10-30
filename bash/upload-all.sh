#!/bin/zsh
# Setup iniziale: invia al server tutti gli eventi presenti in locale (e gli autore.md).
# uso: bash/upload-all.sh [<cartella-storage-locale>]
# default: storage/app/public del progetto, con la struttura <autore>/<AAAA MM GG - descrizione>/
#
# Per ogni evento esegue bash/upload-event.sh (rsync incrementale: rilanciare lo script dopo
# un'interruzione riprende da dove era e salta ciò che è già sul server). Un evento che fallisce
# non ferma gli altri: l'elenco dei falliti è stampato alla fine.
# Dopo l'upload, sul server:
#   cd ~/sites/foto && docker compose exec -T app php artisan media:sync
set -euo pipefail
setopt null_glob

script_dir="${0:A:h}"
root="${1:-${script_dir:h}/storage/app/public}"
root="${root%/}"

if [ ! -d "$root" ]; then
    echo "cartella non trovata: $root" >&2
    exit 1
fi

# stessa regola di config/photos.php (event_folder_regex)
event_regex='^[0-9]{4}[ -][0-9]{2}[ -][0-9]{2}( - .+)?$'

events=()   # elementi "autore<TAB>percorso-evento"
authors=()  # cartelle autore con almeno un evento

for author_dir in "$root"/*(/); do
    author_dir="${author_dir%/}"
    author="${author_dir:t}"
    [[ "$author" == [_.]* ]] && continue   # _derived e cartelle nascoste non sono autori

    found=0
    for event_dir in "$author_dir"/*(/); do
        event_dir="${event_dir%/}"
        name="${event_dir:t}"
        if [[ "$name" =~ $event_regex ]]; then
            events+=("${author}"$'\t'"${event_dir}")
            found=1
        else
            echo "ignorata (nome non conforme): ${author}/${name}" >&2
        fi
    done

    [ "$found" -eq 1 ] && authors+=("$author_dir")
done

total=${#events}
if [ "$total" -eq 0 ]; then
    echo "nessun evento trovato in: $root" >&2
    exit 1
fi

echo "Trovati $total eventi in ${#authors} autori (da $root)"

failed=()

# autore.md (facoltativo, uno per autore)
for author_dir in "${authors[@]}"; do
    author="${author_dir:t}"
    if [ -f "$author_dir/autore.md" ]; then
        echo "autore.md di ${author}"
        if ! rsync -as --mkpath -e ssh "$author_dir/autore.md" "mandich:sites/foto/storage/app/public/${author}/autore.md"; then
            failed+=("${author}/autore.md")
        fi
    fi
done

i=0
for entry in "${events[@]}"; do
    i=$((i + 1))
    author="${entry%%$'\t'*}"
    dir="${entry#*$'\t'}"
    echo
    echo "[$i/$total] ${author} / ${dir:t}"
    if ! zsh "$script_dir/upload-event.sh" "$dir" "$author"; then
        failed+=("${author}/${dir:t}")
    fi
done

echo
if [ "${#failed}" -gt 0 ]; then
    echo "Non inviati (${#failed}):" >&2
    printf '  - %s\n' "${failed[@]}" >&2
    echo "Rilancia lo script: gli eventi già completi vengono saltati." >&2
    exit 1
fi

echo "Fatto: $total eventi inviati. Ora sul server:"
echo "  cd ~/sites/foto && docker compose exec -T app php artisan media:sync --dry-run"
echo "  cd ~/sites/foto && docker compose exec -T app php artisan media:sync"
