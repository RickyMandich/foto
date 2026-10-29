#!/bin/bash

# Script per deploy automatico con versioning incrementale
# Autore: Gallery Photo System
# Data: $(date +%Y-%m-%d)

# Colori per output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo -e "${BLUE}🚀 Deploy Automatico Gallery - Versioning Incrementale${NC}"
echo "=================================================================="

# Verifica che siamo in una repository git
if [[ ! -d ".git" ]]; then
    echo -e "${RED}❌ Errore: Non siamo in una repository Git${NC}"
    echo -e "${YELLOW}💡 Esegui 'git init' per inizializzare una repository${NC}"
    exit 1
fi

# Esegui scan.sh per aggiornare photo.txt/song.txt/gallery.txt e gli zip
SCAN_SCRIPT="$(dirname "$0")/scan.sh"
if [[ -f "$SCAN_SCRIPT" ]]; then
    echo -e "${BLUE}🔄 Esecuzione scan.sh...${NC}"
    bash "$SCAN_SCRIPT"
    if [[ $? -ne 0 ]]; then
        echo -e "${RED}❌ Errore durante scan.sh, deploy interrotto${NC}"
        exit 1
    fi
    echo ""
    echo "=================================================================="
else
    echo -e "${YELLOW}⚠️  scan.sh non trovato in ${SCAN_SCRIPT}, salto la scansione${NC}"
fi

# Verifica se ci sono modifiche da committare
echo -e "${BLUE}🔍 Controllo modifiche...${NC}"
if git diff --quiet && git diff --staged --quiet; then
    echo -e "${YELLOW}ℹ️  Nessuna modifica da committare${NC}"
    echo -e "${CYAN}📊 Status corrente:${NC}"
    git status --short
    exit 0
fi

# Mostra le modifiche
echo -e "${CYAN}📝 Modifiche rilevate:${NC}"
git status --short

# Funzione per ottenere l'ultimo numero di versione
get_last_version() {
    # Cerca l'ultimo commit che contiene "rev" nel messaggio
    local last_commit=$(git log --oneline --grep="rev" -n 1 --pretty=format:"%s" 2>/dev/null)
    
    if [[ -z "$last_commit" ]]; then
        echo "0"
        return
    fi
    
    # Estrae il numero dopo "rev" usando regex
    if [[ $last_commit =~ rev[[:space:]]*([0-9]+) ]]; then
        echo "${BASH_REMATCH[1]}"
    else
        echo "0"
    fi
}

# Ottieni la versione corrente e incrementa
echo -e "${BLUE}🔢 Calcolo versione...${NC}"
current_version=$(get_last_version)
new_version=$((current_version + 1))

echo -e "${CYAN}📈 Versione precedente: ${current_version}${NC}"
echo -e "${GREEN}🆕 Nuova versione: ${new_version}${NC}"

# Crea il messaggio di commit
commit_message="rev ${new_version}"

echo -e "${BLUE}💾 Preparazione commit...${NC}"

# Git add
echo -e "${YELLOW}📁 Aggiunta file al staging...${NC}"
git add .

if [[ $? -ne 0 ]]; then
    echo -e "${RED}❌ Errore durante git add${NC}"
    exit 1
fi

echo -e "${GREEN}✅ File aggiunti al staging${NC}"

# Git commit
echo -e "${YELLOW}📝 Creazione commit...${NC}"
git commit -m "$commit_message"

if [[ $? -ne 0 ]]; then
    echo -e "${RED}❌ Errore durante git commit${NC}"
    exit 1
fi

echo -e "${GREEN}✅ Commit creato: ${commit_message}${NC}"

# Git push
echo -e "${YELLOW}🌐 Push al repository remoto...${NC}"
git push

if [[ $? -ne 0 ]]; then
    echo -e "${RED}❌ Errore durante git push${NC}"
    echo -e "${YELLOW}💡 Verifica la connessione e i permessi del repository${NC}"
    exit 1
fi

echo -e "${GREEN}✅ Push completato con successo${NC}"

# Git status finale
echo -e "${BLUE}📊 Status finale:${NC}"
git status

# Informazioni finali
echo ""
echo "=================================================================="
echo -e "${GREEN}🎉 DEPLOY COMPLETATO!${NC}"
echo ""
echo -e "${CYAN}📋 RIEPILOGO:${NC}"
echo -e "   📝 Commit: ${GREEN}${commit_message}${NC}"
echo -e "   🔗 Branch: ${GREEN}$(git branch --show-current)${NC}"
echo -e "   🌐 Remote: ${GREEN}$(git remote get-url origin 2>/dev/null || echo "Non configurato")${NC}"
echo ""

# Mostra gli ultimi commit
echo -e "${CYAN}📚 Ultimi 5 commit:${NC}"
git log --oneline -5 --pretty=format:"   %C(yellow)%h%C(reset) %C(green)%s%C(reset) %C(blue)(%cr)%C(reset)"
echo ""
echo ""

echo -e "${BLUE}🏁 Deploy completato con successo!${NC}"
