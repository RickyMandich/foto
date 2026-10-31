#!/usr/bin/env python3
"""
Corregge le date EXIF delle foto con data non valida (anno diverso da quello atteso,
oppure data assente), interpolandole tra la foto valida precedente e la successiva
in ordine alfabetico (naturale, case-insensitive: lo stesso usato dal sito).

Uso (dentro la cartella dell'evento):
    python3 fix_exif_dates.py              # dry-run: mostra cosa farebbe, non scrive nulla
    python3 fix_exif_dates.py --apply      # scrive gli EXIF (con backup)

Opzioni: --year 2026  --dir <cartella>  --backup-dir <cartella>

Richiede exiftool:  sudo apt install libimage-exiftool-perl

Regole:
- Si considerano solo i file .jpg/.jpeg (il sito legge l'EXIF solo da questi).
- "Valida" = DateTimeOriginal presente e con anno == --year.
- Una serie di foto non valide consecutive (in ordine alfabetico) tra due foto valide
  P (prima) e N (dopo) riceve date equidistanti tra P e N.
- Serie iniziale senza una foto valida prima: se passi --start "AAAA-MM-GG HH:MM:SS", la
  prima foto della serie riceve esattamente quell'ora e le altre sono equidistanti fino
  alla prima foto valida; senza --start la serie è saltata.
- Serie senza una foto valida dopo, oppure con estremi in ordine inverso: NON modificate,
  segnalate nel report (nessuna data inventata).
- Scrive DateTimeOriginal, CreateDate e ModifyDate. Non preserva l'mtime del file, così
  la sincronizzazione del sito vede la foto cambiata e rilegge taken_at.
"""
import argparse
import json
import re
import shutil
import subprocess
import sys
from datetime import datetime, timedelta
from pathlib import Path

FMT = "%Y:%m:%d %H:%M:%S"
EXTS = {".jpg", ".jpeg"}


def natural_key(name: str):
    return [int(t) if t.isdigit() else t.lower() for t in re.split(r"(\d+)", name)]


def parse(value):
    if not isinstance(value, str):
        return None
    try:
        return datetime.strptime(value.strip(), FMT)
    except ValueError:
        return None


def read_dates(files):
    result = subprocess.run(
        ["exiftool", "-j", "-d", FMT, "-DateTimeOriginal", "--", *[str(f) for f in files]],
        capture_output=True,
        text=True,
    )
    try:
        data = json.loads(result.stdout or "[]")
    except json.JSONDecodeError:
        sys.exit(f"Impossibile leggere l'output di exiftool:\n{result.stderr}")
    return {Path(d["SourceFile"]).name: parse(d.get("DateTimeOriginal")) for d in data}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--apply", action="store_true")
    ap.add_argument("--year", type=int, default=2026)
    ap.add_argument("--dir", default=".")
    ap.add_argument("--backup-dir", default=None)
    ap.add_argument("--no-backup", action="store_true", help="non copia gli originali prima di modificarli (piu' veloce)")
    ap.add_argument(
        "--start",
        default=None,
        help='"AAAA-MM-GG HH:MM:SS": ora della PRIMA foto, usata solo se le prime foto della cartella non hanno una foto valida prima',
    )
    args = ap.parse_args()

    start = None
    if args.start:
        try:
            start = datetime.strptime(args.start, "%Y-%m-%d %H:%M:%S")
        except ValueError:
            sys.exit('--start deve avere il formato "AAAA-MM-GG HH:MM:SS"')

    if not shutil.which("exiftool"):
        sys.exit("exiftool non trovato. Installa: sudo apt install libimage-exiftool-perl")

    folder = Path(args.dir).resolve()
    files = sorted(
        (p for p in folder.iterdir() if p.is_file() and p.suffix.lower() in EXTS),
        key=lambda p: natural_key(p.name),
    )
    if not files:
        sys.exit(f"Nessun jpg/jpeg in {folder}")

    dates = read_dates(files)
    names = [p.name for p in files]
    current = [dates.get(n) for n in names]
    valid = [d is not None and d.year == args.year for d in current]

    changes = {}  # nome -> nuova data
    skipped = []  # (nome, motivo)

    i, n = 0, len(files)
    while i < n:
        if valid[i]:
            i += 1
            continue
        j = i
        while j < n and not valid[j]:
            j += 1
        run = range(i, j)
        prev_i, next_i = i - 1, j
        if next_i >= n:
            skipped += [(names[k], "nessuna foto valida dopo") for k in run]
        elif prev_i < 0 and start is None:
            skipped += [(names[k], "nessuna foto valida prima (usa --start)") for k in run]
        else:
            tn = current[next_i]
            if prev_i < 0:
                # Nessuna foto valida prima: la prima foto della serie è esattamente a --start.
                t0, offset, divisor = start, 0, len(run)
                before = "--start"
            else:
                t0, offset, divisor = current[prev_i], 1, len(run) + 1
                before = names[prev_i]
            if tn < t0:
                skipped += [
                    (names[k], f"estremi in ordine inverso ({before} > {names[next_i]})")
                    for k in run
                ]
            else:
                span = (tn - t0).total_seconds()
                for idx, k in enumerate(run):
                    changes[names[k]] = t0 + timedelta(seconds=round(span * (idx + offset) / divisor))
        i = j

    print(f"Cartella: {folder}")
    print(f"Foto jpg/jpeg: {n} | valide ({args.year}): {sum(valid)} | da correggere: {len(changes)} | saltate: {len(skipped)}\n")

    for k, name in enumerate(names):
        if name in changes:
            old = current[k].strftime(FMT) if current[k] else "(assente)"
            print(f"  {name}: {old} -> {changes[name].strftime(FMT)}")
    for name, reason in skipped:
        print(f"  SALTATA {name}: {reason}")

    if not changes:
        print("\nNiente da scrivere.")
        return
    if not args.apply:
        print("\nDry-run: nessun file modificato. Rilancia con --apply per scrivere.")
        return

    backup = None
    if not args.no_backup:
        stamp = datetime.now().strftime("%Y%m%d-%H%M%S")
        backup = Path(args.backup_dir) if args.backup_dir else Path.home() / f"exif-backup-{stamp}"
        backup.mkdir(parents=True, exist_ok=True)

    failed = []
    total = len(changes)
    print(f"\nScrittura di {total} file (puo' richiedere qualche minuto su /mnt/c)...")
    for pos, (name, new) in enumerate(changes.items(), start=1):
        print(f"[{pos}/{total}] {name}", flush=True)
        if backup:
            shutil.copy2(folder / name, backup / name)
        value = new.strftime(FMT)
        r = subprocess.run(
            [
                "exiftool",
                "-overwrite_original",
                f"-DateTimeOriginal={value}",
                f"-CreateDate={value}",
                f"-ModifyDate={value}",
                "--",
                str(folder / name),
            ],
            capture_output=True,
            text=True,
        )
        if r.returncode != 0 or "1 image files updated" not in r.stdout:
            failed.append((name, (r.stderr or r.stdout).strip()))

    print(f"\nBackup degli originali modificati in: {backup}" if backup else "\nBackup disattivato (--no-backup).")
    print(f"Scritti: {len(changes) - len(failed)} | falliti: {len(failed)}")
    for name, err in failed:
        print(f"  ERRORE {name}: {err}")


if __name__ == "__main__":
    main()
