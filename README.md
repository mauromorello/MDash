# MDash

MDash è un'applicazione web pensata per permettere a un utente di gestire dati tabellari, generare dashboard interattive tramite prompt e condividere i risultati in modo semplice.

## Schema database principale

### Tabella `users`
- `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
- `username` VARCHAR(100) NOT NULL UNIQUE
- `password_hash` VARCHAR(255) NOT NULL
- `email` VARCHAR(255) NOT NULL UNIQUE
- `is_admin` TINYINT(1) NOT NULL DEFAULT 0
- `is_enabled` TINYINT(1) NOT NULL DEFAULT 1
- `is_manager` TINYINT(1) NOT NULL DEFAULT 0
- `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
- `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
- `first_login_at` DATETIME NULL
- `last_login_at` DATETIME NULL
- `last_login_ip` VARCHAR(45) NULL
- `last_login_agent` TEXT NULL

Esempio di creazione SQL:

```sql
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL UNIQUE,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  is_manager TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  first_login_at DATETIME NULL,
  last_login_at DATETIME NULL,
  last_login_ip VARCHAR(45) NULL,
  last_login_agent TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Obiettivo del progetto

L'applicazione deve consentire di:

1. accedere come utente autenticato;
2. caricare e gestire file CSV o Excel;
3. generare dashboard in formato HTML attraverso un prompt utilizzando Gemini;
4. salvare le dashboard generate in una cartella dedicata e registrare il loro URL nel database;
5. gestire le dashboard salvate, con possibilità di condivisione pubblica o privata.

## Funzionalità principali

### 1. Autenticazione utente
- Login per l'utente autenticato.
- In una prima versione può essere gestita come accesso singolo per l'utente "x".
- Successivamente si può estendere a più utenti con registrazione e gestione profili.

### 2. Gestione file CSV / Excel
- Upload di file in formato CSV o Excel.
- Visualizzazione dei file caricati.
- Possibilità di vedere nome, dimensione, data di caricamento e stato del file.
- Preparazione dei dati per la generazione delle dashboard.

### 3. Generazione dashboard con Gemini
- L'utente inserisce un prompt descrittivo della dashboard desiderata.
- L'app genera una dashboard in HTML basata sui dati caricati.
- La dashboard viene salvata nella cartella dedicata alle dashboard.
- L'URL della dashboard viene salvato nel database.
- Ogni dashboard può essere marcata come pubblica o privata.

### 4. Gestione dashboard salvate
- Elenco delle dashboard create.
- Visualizzazione titolo, data di creazione, stato di condivisione e URL.
- Possibilità di aprire, modificare, eliminare o condividere la dashboard.

## Flusso utente previsto

1. L'utente effettua il login.
2. Carica uno o più file CSV/Excel.
3. Entra nella pagina di creazione dashboard.
4. Inserisce un prompt per descrivere la dashboard desiderata.
5. L'app genera il file HTML della dashboard.
6. Salva la dashboard nella cartella apposita e registra l'URL nel database.
7. L'utente può poi gestire e condividere la dashboard dalla pagina dedicata.

## Struttura del progetto

La struttura proposta del progetto è la seguente:

- /uploads: cartella per i file CSV/Excel caricati;
- /dashboards: cartella per i file HTML delle dashboard generate;
- /database: file o script SQL per la creazione del database;
- /src o /app: logica applicativa, controller, servizi e gestione dei dati;
- /public: entry point dell'applicazione, come index.php e asset statici;
- /config: configurazione dell'applicazione e delle connessioni ai servizi esterni.

## Pagine attuali

- `index.php` — pagina di login principale.
- `main.php` — pagina principale dopo login.
- `admin.php` — console amministrativa accessibile solo a utenti con `is_admin = 1`. Permette ora la creazione di nuovi utenti.
- `config.php` — helper tecnico protetto da `key=...`, mostra stato DB, tabelle, preview dati e deploy log.
- `_act.php` — endpoint AJAX per login/logout e creazione utenti.
- `_act_db.php` — endpoint AJAX per gestione DB e tabelle.

## Tecnologie suggerite

- PHP per il backend;
- MySQL per il database;
- HTML/CSS/JavaScript per l'interfaccia;
- API di Gemini per la generazione delle dashboard;
- Librerie per l'importazione dei file CSV/Excel, se necessarie.

## Stato attuale

Il progetto è ancora in fase di definizione e struttura. Questa README serve come punto di riferimento per organizzare le funzionalità principali e la logica applicativa.

## Sviluppi futuri

- gestione autenticazione completa per più utenti;
- preview dei dati prima della generazione delle dashboard;
- template predefiniti per le dashboard;
- gestione avanzata di permessi e condivisione;
- storico delle versioni delle dashboard generate.

rsioni delle dashboard generate.

