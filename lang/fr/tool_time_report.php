<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Time Report tool plugin's strings file.
 *
 * @package   tool_time_report
 * @copyright 2023 Pierre Duverneix - Fondation UNIT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Rapports de temps connexion';
$string['time_report'] = 'Rapports de temps connexion';
$string['messageprovider:reportcreation'] = 'Création du rapport';
$string['time_report:view'] = 'Voir les rapports de temps';
$string['time_report'] = 'Rapport de temps de connexion';
$string['startingdate'] = 'Date de début';
$string['endingdate'] = 'Date de fin';
$string['reports_list'] = 'Liste des rapports générés';
$string['no_results_found'] = 'Pas de résultats trouvés';
$string['period'] = 'Période';
$string['period_total_time'] = 'Temps total pour la période';
$string['total_duration'] = 'Durée cumulée par jour';
$string['daily_time_report'] = 'Rapport de temps journaliers';
$string['remove_files'] = 'Supprimer les fichiers';
$string['header_user_infos_user'] = 'Utilisateur : {$a}';
$string['pages_duration'] = 'Durée';
$string['base_pages_heading_title'] = 'Temps de connexion totaux';
$string['detail_pages_heading_title'] = 'Détail des temps de connexion par cours';
$string['detail_pages_heading_category'] = 'Catégorie';
$string['detail_pages_heading_course_name'] = 'Nom du cours';
$string['base_pages_body_heading'] = 'Synthèse des temps de connexion par jour';
$string['header_user_infos_docname'] = 'Rapport d\'activité, Temps de connexion';
$string['header_user_infos_generated'] = 'Généré le : {$a}';
$string['header_user_infos_platform'] = 'Plateforme : {$a}';
$string['header_user_infos_email'] = 'Courriel : {$a}';
$string['header_user_infos_university'] = 'Université : {$a}';
$string['header_user_infos_speciality'] = 'Spécialité : {$a}';
$string['header_user_infos_time'] = 'Période : du {$a}';
$string['header_user_infos_time_to'] = 'au {$a}';
$string['header_user_infos_total_time'] = '- Temps de connexion total : {$a}';
$string['pages_no_activity'] = 'Aucune activité sur cette période.';
$string['messageprovider:report_created'] = 'Un rapport de d’utilisateur a été créé';
$string['client:reportgenerating'] = 'Génération du rapport...';
$string['client:reportdownload'] = 'Télécharger le rapport';
$string['error:completiondates'] = 'Saisir les dates de début et de fin de période.';
$string['settings:targets'] = 'Composants comptabilisés dans le rapport.';
$string['settings:targets_desc'] = 'Les composants cibles comptabilisés dans le rapport.';
$string['settings:idletime'] = 'Temps avant inactivité';
$string['settings:idletime_desc'] = 'Temps avant que l\'utilisateur ne soit considéré comme inactif.';
$string['settings:borrowedtime'] = 'Temps accordé quand inactif';
$string['settings:borrowedtime_desc'] = 'Temps ajouté lorsque l\'utilisateur devient inactif.';
$string['settings:available_on_admins'] = 'Accessible sur les profils admins';
$string['settings:available_on_admins_desc'] = 'Rendre le rapport accessible sur les profils admins.';
$string['settings:dbdriver'] = 'Driver base de données';
$string['settings:dbdriver_desc'] = 'Sélection du driver de la base de données.';
$string['settings:calculation_rule_text'] = "Définir le texte utilisé pour expliquer comment le rapport est généré. Utilisez {i} et {b} pour symboliser les variables 'idletime' et 'borrowedtime'.";
