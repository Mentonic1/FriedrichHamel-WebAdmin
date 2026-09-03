-- =============================================================
-- Friedrich Hamel GmbH - Aufmaß Cloud V8 Komplettschema
-- MySQL 8 / MariaDB 10.5+
-- Bestehende V7-Tabellen werden NICHT gelöscht.
-- =============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS aufmass_cloud_benutzer (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NULL,
    aktiv TINYINT(1) NOT NULL DEFAULT 1,
    max_geraete INT UNSIGNED NOT NULL DEFAULT 1,
    erstellt_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aktualisiert_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_aufmass_cloud_benutzer_aktiv (aktiv),
    KEY idx_aufmass_cloud_benutzer_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aufmass_cloud_lizenzen (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    benutzer_id INT UNSIGNED NOT NULL,
    lizenz_hash CHAR(64) NOT NULL,
    lizenz_prefix VARCHAR(32) NULL,
    aktiv TINYINT(1) NOT NULL DEFAULT 1,
    gueltig_bis DATETIME NULL,
    aktiviert_am DATETIME NULL,
    letzter_login DATETIME NULL,
    erstellt_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_aufmass_lizenz_hash (lizenz_hash),
    KEY idx_aufmass_lizenz_benutzer (benutzer_id),
    CONSTRAINT fk_aufmass_lizenz_benutzer FOREIGN KEY (benutzer_id) REFERENCES aufmass_cloud_benutzer(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aufmass_cloud_geraete (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    benutzer_id INT UNSIGNED NOT NULL,
    lizenz_id INT UNSIGNED NOT NULL,
    geraet_fingerprint CHAR(64) NOT NULL,
    geraet_name VARCHAR(255) NULL,
    token_hash CHAR(64) NOT NULL,
    aktiv TINYINT(1) NOT NULL DEFAULT 1,
    registriert_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    zuletzt_online DATETIME NULL,
    letzte_ip VARCHAR(64) NULL,
    user_agent VARCHAR(500) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_aufmass_lizenz_geraet (lizenz_id, geraet_fingerprint),
    UNIQUE KEY uq_aufmass_geraet_token (token_hash),
    KEY idx_aufmass_cloud_geraete_benutzer (benutzer_id),
    CONSTRAINT fk_aufmass_geraet_benutzer FOREIGN KEY (benutzer_id) REFERENCES aufmass_cloud_benutzer(id) ON DELETE CASCADE,
    CONSTRAINT fk_aufmass_geraet_lizenz FOREIGN KEY (lizenz_id) REFERENCES aufmass_cloud_lizenzen(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aufmass_cloud_projekte (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    benutzer_id INT UNSIGNED NOT NULL,
    projekt_uuid CHAR(36) NOT NULL,
    name VARCHAR(255) NOT NULL DEFAULT '',
    daten_json LONGTEXT NULL,
    updated_at DATETIME(6) NOT NULL,
    deleted_at DATETIME(6) NULL,
    letztes_geraet_id BIGINT UNSIGNED NULL,
    server_geaendert_am TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    erstellt_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_aufmass_cloud_projekt (benutzer_id, projekt_uuid),
    KEY idx_aufmass_cloud_benutzer_update (benutzer_id, updated_at),
    CONSTRAINT fk_aufmass_cloud_projekt_benutzer FOREIGN KEY (benutzer_id) REFERENCES aufmass_cloud_benutzer(id) ON DELETE CASCADE,
    CONSTRAINT fk_aufmass_cloud_projekt_geraet FOREIGN KEY (letztes_geraet_id) REFERENCES aufmass_cloud_geraete(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS katalog_kategorien (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    PRIMARY KEY (id), UNIQUE KEY uq_katalog_kategorie_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS material_katalog (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    material VARCHAR(255) NOT NULL,
    kategorie VARCHAR(255) NOT NULL DEFAULT 'Ohne Kategorie',
    PRIMARY KEY (id), UNIQUE KEY uq_material_katalog_material (material), KEY idx_material_katalog_kategorie (kategorie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS globale_raum_vorlagen (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    PRIMARY KEY (id), UNIQUE KEY uq_globale_raum_vorlage_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS globale_raum_inhalte (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    vorlage_id INT UNSIGNED NOT NULL,
    material VARCHAR(255) NOT NULL,
    PRIMARY KEY (id), UNIQUE KEY uq_raum_vorlage_material (vorlage_id, material), KEY idx_raum_inhalte_vorlage (vorlage_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- Web-Admin ----------------
CREATE TABLE IF NOT EXISTS aufmass_admins (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    benutzername VARCHAR(80) NOT NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    passwort_hash VARCHAR(255) NOT NULL,
    rolle ENUM('admin','superadmin') NOT NULL DEFAULT 'admin',
    aktiv TINYINT(1) NOT NULL DEFAULT 1,
    letzter_login DATETIME NULL,
    erstellt_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_aufmass_admin_username (benutzername)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aufmass_admin_login_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    benutzername VARCHAR(80) NULL,
    ip VARCHAR(64) NULL,
    erfolgreich TINYINT(1) NOT NULL DEFAULT 0,
    versucht_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), KEY idx_login_ip_time (ip, versucht_am)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aufmass_lizenz_antraege (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    referenz VARCHAR(24) NOT NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    firma VARCHAR(255) NULL,
    telefon VARCHAR(80) NULL,
    gewuenschte_geraete INT UNSIGNED NOT NULL DEFAULT 1,
    notiz TEXT NULL,
    status ENUM('offen','freigegeben','abgelehnt') NOT NULL DEFAULT 'offen',
    benutzer_id INT UNSIGNED NULL,
    lizenz_id INT UNSIGNED NULL,
    freigegeben_von INT UNSIGNED NULL,
    freigegeben_am DATETIME NULL,
    bearbeitet_von INT UNSIGNED NULL,
    bearbeitet_am DATETIME NULL,
    ablehnungsgrund TEXT NULL,
    ip VARCHAR(64) NULL,
    erstellt_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_antrag_referenz (referenz), KEY idx_antrag_status (status), KEY idx_antrag_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aufmass_lizenz_secrets (
    lizenz_id INT UNSIGNED NOT NULL,
    key_ciphertext TEXT NOT NULL,
    zuletzt_gesendet_am DATETIME NULL,
    gesendet_anzahl INT UNSIGNED NOT NULL DEFAULT 0,
    erstellt_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (lizenz_id),
    CONSTRAINT fk_secret_lizenz FOREIGN KEY (lizenz_id) REFERENCES aufmass_cloud_lizenzen(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aufmass_mail_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empfaenger VARCHAR(255) NOT NULL,
    betreff VARCHAR(500) NOT NULL,
    mail_typ VARCHAR(50) NOT NULL,
    erfolgreich TINYINT(1) NOT NULL DEFAULT 0,
    fehler TEXT NULL,
    benutzer_id INT UNSIGNED NULL,
    lizenz_id INT UNSIGNED NULL,
    admin_id INT UNSIGNED NULL,
    erstellt_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), KEY idx_mail_time (erstellt_am), KEY idx_mail_user (benutzer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aufmass_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id INT UNSIGNED NULL,
    aktion VARCHAR(100) NOT NULL,
    entity_typ VARCHAR(50) NULL,
    entity_id BIGINT NULL,
    details_json LONGTEXT NULL,
    ip VARCHAR(64) NULL,
    erstellt_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), KEY idx_audit_time (erstellt_am), KEY idx_audit_admin (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
