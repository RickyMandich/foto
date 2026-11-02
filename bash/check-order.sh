#!/usr/bin/env bash
# Uso: ./check-order.sh [cartella_radice]   (default: cartella corrente)
#
# Per ogni cartella che contiene immagini (non ricorsivo dentro la singola cartella),
# verifica che l'ordine alfabetico naturale dei nomi (2 prima di 10) coincida con
# l'ordine cronologico di EXIF DateTimeOriginal.
#
# Output: solo le cartelle con problemi + riepilogo finale.
# Codici di uscita: 0 tutto ok | 1 EXIF mancante | 2 disordine (prevale su 1)
#
# Nota: a parità di orario (raffiche, risoluzione 1 s) vale l'ordine alfabetico,
# quindi orari uguali non sono considerati disordine.

root="${1:-.}"

if ! command -v exiftool >/dev/null 2>&1; then
  echo "ERRORE: exiftool non trovato" >&2
  exit 3
fi

exts=(-ext jpg -ext jpeg -ext png -ext heic -ext tif -ext tiff -ext pef -ext dng)
rc=0
dirs_checked=0
dirs_disorder=0
dirs_missing=0

while IFS= read -r -d '' dir; do
  # Un solo exiftool per cartella: righe "nome<TAB>orario" ("-" se manca il tag).
  # </dev/null evita che exiftool resti in attesa su stdin.
  out=$(exiftool -T -d '%Y-%m-%d %H:%M:%S' -FileName -DateTimeOriginal \
        "${exts[@]}" "$dir" 2>/dev/null </dev/null)
  [ -z "$out" ] && continue
  dirs_checked=$((dirs_checked + 1))

  # Ordine alfabetico naturale sul nome file
  sorted=$(printf '%s\n' "$out" | LC_ALL=C sort -t $'\t' -k1,1V)

  res=$(printf '%s\n' "$sorted" | awk -F'\t' '
    $2 == "-" { print "  [EXIF MANCANTE] " $1; miss++; next }
    {
      if (prev_t != "" && $2 < prev_t)
        { print "  [DISORDINE] " $1 " (" $2 ") viene dopo " prev_n " (" prev_t ") ma è precedente"; dis++ }
      prev_n = $1; prev_t = $2
    }
    END { exit (dis > 0 ? 2 : (miss > 0 ? 1 : 0)) }')
  st=$?

  if [ "$st" -ne 0 ]; then
    echo "== $dir"
    echo "$res"
    [ "$st" -eq 2 ] && dirs_disorder=$((dirs_disorder + 1))
    # una cartella con solo EXIF mancanti (st=1) o anche disordine (st=2)
    printf '%s\n' "$res" | grep -q 'EXIF MANCANTE' && dirs_missing=$((dirs_missing + 1))
    [ "$st" -gt "$rc" ] && rc=$st
  fi
done < <(find "$root" -type d -print0 | LC_ALL=C sort -z)

echo "-- Cartelle controllate: $dirs_checked | con disordine: $dirs_disorder | con EXIF mancante: $dirs_missing | exit: $rc"
exit "$rc"
