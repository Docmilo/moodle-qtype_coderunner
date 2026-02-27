<?php
// This file is part of CodeRunner - http://coderunner.org.nz/
//
// CodeRunner is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// CodeRunner is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with CodeRunner.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Event observer for Canvas LTI AGS grade passback.
 *
 * Listens for quiz attempt submission events and, when a stored LTI context
 * exists for the quiz, posts the final grade to the Canvas line item via
 * the LTI Assignment and Grade Services API.
 *
 * @package    qtype_coderunner
 * @copyright  2024 The CodeRunner Development Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qtype_coderunner;

defined('MOODLE_INTERNAL') || die();

use qtype_coderunner\canvas_lti\grade_service;

/**
 * Observes Moodle events and sends grades to Canvas via LTI AGS when
 * a quiz attempt containing CodeRunner questions is submitted.
 */
class observer {

    /**
     * Handles the quiz attempt_submitted event.
     *
     * Looks up any stored LTI context for the quiz module and, if found,
     * posts the attempt's final grade to Canvas via the LTI AGS score endpoint.
     *
     * @param \mod_quiz\event\attempt_submitted $event The quiz attempt submitted event
     * @return void
     */
    public static function quiz_attempt_submitted(\mod_quiz\event\attempt_submitted $event): void {
        global $DB;

        // Check if Canvas LTI integration is enabled.
        if (!get_config('qtype_coderunner', 'canvaslti_enabled')) {
            return;
        }

        $attemptid = $event->objectid;
        $userid    = $event->userid;
        $courseid  = $event->courseid;
        $cmid      = $event->contextinstanceid;

        // Look up the LTI context for this course module + user.
        $lticontext = $DB->get_record('qtype_coderunner_lti_context', [
            'cmid'   => $cmid,
            'userid' => $userid,
        ]);

        if (!$lticontext) {
            // No LTI context stored for this quiz + user; nothing to do.
            return;
        }

        // Get the quiz attempt to retrieve the final grade.
        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid]);
        if (!$attempt || $attempt->state !== 'finished') {
            return;
        }

        // Get the quiz to find the maximum grade.
        $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz]);
        if (!$quiz) {
            return;
        }

        $scoregiven   = (float) ($attempt->sumgrades ?? 0);
        $scoremaximum = (float) ($quiz->sumgrades > 0 ? $quiz->sumgrades : 1);

        // Retrieve plugin-level LTI credentials.
        $tokenendpoint = get_config('qtype_coderunner', 'canvaslti_token_endpoint');
        $clientid      = get_config('qtype_coderunner', 'canvaslti_client_id');
        $privatekey    = get_config('qtype_coderunner', 'canvaslti_private_key');

        if (empty($tokenendpoint) || empty($clientid) || empty($privatekey)) {
            return;
        }

        try {
            $service = new grade_service($tokenendpoint, $clientid, $privatekey);
            $service->post_score(
                $lticontext->lineitemurl,
                $lticontext->ltiuserid,
                $scoregiven,
                $scoremaximum,
                get_string('lti_grade_comment', 'qtype_coderunner')
            );
        } catch (\Throwable $e) {
            // Log and swallow: grade passback failures must not disrupt the quiz.
            debugging('CodeRunner LTI AGS grade passback failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
