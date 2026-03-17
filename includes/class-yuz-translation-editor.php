<?php
/**
 * Shim de compatibilité – YUZ Translation Editor
 *
 * Ce fichier ne définit aucun hook et ne redéclare pas la classe.
 * Il délègue intégralement vers l’implémentation unique :
 *   includes/class-yuz-editor.php
 */

defined('ABSPATH') or exit;

// Charge l’implémentation canonique si nécessaire.
require_once __DIR__ . '/class-yuz-editor.php';

// Intentionnel : aucun add_action(), aucun add_filter(), aucun echo.
// On évite toute redéclaration/duplication de hooks.

