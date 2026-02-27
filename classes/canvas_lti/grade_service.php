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
 * LTI Assignment and Grade Services (AGS) client for Canvas integration.
 *
 * Implements grade passback to Canvas via LTI 1.3 AGS:
 * https://www.imsglobal.org/spec/lti-ags/v2p0/
 *
 * @package    qtype_coderunner
 * @copyright  2024 The CodeRunner Development Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qtype_coderunner\canvas_lti;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * Provides LTI AGS grade passback functionality for Canvas.
 *
 * Authenticates using the OAuth 2.0 JWT client credentials flow (RFC 7523)
 * and posts scores to Canvas line items per the IMS LTI AGS v2.0 spec.
 */
class grade_service {

    /** @var string LTI AGS scope for publishing scores. */
    const SCORE_SCOPE = 'https://purl.imsglobal.org/spec/lti-ags/scope/score';

    /** @var string LTI AGS scope for reading/writing line items. */
    const LINEITEM_SCOPE = 'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem';

    /** @var string LTI AGS scope for reading results. */
    const RESULT_READONLY_SCOPE = 'https://purl.imsglobal.org/spec/lti-ags/scope/result.readonly';

    /** @var string Activity progress value for a completed attempt. */
    const ACTIVITY_PROGRESS_COMPLETED = 'Completed';

    /** @var string Grading progress value for a fully graded submission. */
    const GRADING_PROGRESS_FULLY_GRADED = 'FullyGraded';

    /** @var string OAuth 2.0 client assertion type for JWT bearer. */
    const JWT_BEARER_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    /** @var string The OAuth 2.0 token endpoint URL. */
    private string $tokenendpoint;

    /** @var string The LTI client ID issued by Canvas. */
    private string $clientid;

    /** @var string PEM-encoded RSA private key for signing JWTs. */
    private string $privatekey;

    /**
     * Constructs a new grade_service instance.
     *
     * @param string $tokenendpoint OAuth 2.0 token endpoint URL (e.g. https://canvas.instructure.com/login/oauth2/token)
     * @param string $clientid      LTI 1.3 client ID from the Canvas developer key
     * @param string $privatekey    PEM-encoded RSA private key corresponding to the public JWK registered in Canvas
     */
    public function __construct(string $tokenendpoint, string $clientid, string $privatekey) {
        $this->tokenendpoint = $tokenendpoint;
        $this->clientid      = $clientid;
        $this->privatekey    = $privatekey;
    }

    /**
     * Posts a student score to a Canvas line item using LTI AGS.
     *
     * @param string $lineitemurl   The Canvas line item URL (from the LTI launch claim)
     * @param string $ltiuserid     The LTI user ID (sub claim from the ID token)
     * @param float  $scoregiven    The score achieved by the student
     * @param float  $scoremaximum  The maximum possible score
     * @param string $comment       Optional human-readable comment about the grade
     * @return bool True on success, false on failure
     */
    public function post_score(
        string $lineitemurl,
        string $ltiuserid,
        float $scoregiven,
        float $scoremaximum,
        string $comment = ''
    ): bool {
        $token = $this->get_access_token(self::SCORE_SCOPE);
        if (empty($token)) {
            return false;
        }

        $scoreurl = rtrim($lineitemurl, '/') . '/scores';
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');

        $scorepayload = [
            'timestamp'        => $timestamp,
            'scoreGiven'       => $scoregiven,
            'scoreMaximum'     => $scoremaximum,
            'activityProgress' => self::ACTIVITY_PROGRESS_COMPLETED,
            'gradingProgress'  => self::GRADING_PROGRESS_FULLY_GRADED,
            'userId'           => $ltiuserid,
        ];
        if ($comment !== '') {
            $scorepayload['comment'] = $comment;
        }

        $curl = new \curl();
        $curl->setHeader([
            'Authorization: Bearer ' . $token,
            'Content-Type: application/vnd.ims.lis.v1.score+json',
            'Accept: application/json',
        ]);

        $response = $curl->post($scoreurl, json_encode($scorepayload));
        $httpcode = $curl->info['http_code'] ?? 0;

        return $httpcode === 200 || $httpcode === 201 || $httpcode === 204;
    }

    /**
     * Retrieves a line item from Canvas, creating it if it does not exist.
     *
     * @param string $lineitemsurl  The Canvas line items container URL (from the LTI launch claim)
     * @param string $resourcelinkid The LTI resource link ID to match
     * @param string $label         Human-readable label for the grade column
     * @param float  $scoremaximum  The maximum score for this assignment
     * @return string|null The line item URL, or null on failure
     */
    public function get_or_create_lineitem(
        string $lineitemsurl,
        string $resourcelinkid,
        string $label,
        float $scoremaximum
    ): ?string {
        $token = $this->get_access_token(self::LINEITEM_SCOPE . ' ' . self::SCORE_SCOPE);
        if (empty($token)) {
            return null;
        }

        // Try to find an existing line item for this resource link.
        $curl = new \curl();
        $curl->setHeader([
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.ims.lis.v2.lineitemcontainer+json',
        ]);

        $response = $curl->get($lineitemsurl . '?resource_link_id=' . urlencode($resourcelinkid));
        $httpcode = $curl->info['http_code'] ?? 0;

        if ($httpcode === 200 && !empty($response)) {
            $items = json_decode($response, true);
            if (is_array($items) && count($items) > 0) {
                return $items[0]['id'] ?? null;
            }
        }

        // Create a new line item.
        $curl2 = new \curl();
        $curl2->setHeader([
            'Authorization: Bearer ' . $token,
            'Content-Type: application/vnd.ims.lis.v2.lineitem+json',
            'Accept: application/vnd.ims.lis.v2.lineitem+json',
        ]);

        $lineitem = [
            'scoreMaximum'   => $scoremaximum,
            'label'          => $label,
            'resourceLinkId' => $resourcelinkid,
        ];

        $response2 = $curl2->post($lineitemsurl, json_encode($lineitem));
        $httpcode2 = $curl2->info['http_code'] ?? 0;

        if ($httpcode2 === 200 || $httpcode2 === 201) {
            $created = json_decode($response2, true);
            return $created['id'] ?? null;
        }

        return null;
    }

    /**
     * Obtains an OAuth 2.0 access token from the Canvas token endpoint using
     * the JWT client credentials grant (RFC 7523).
     *
     * @param  string $scope Space-separated list of LTI AGS scopes to request
     * @return string        The access token, or an empty string on failure
     */
    public function get_access_token(string $scope): string {
        $jwtassertion = $this->create_jwt_assertion();

        $curl = new \curl();
        $curl->setHeader([
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        $params = http_build_query([
            'grant_type'            => 'client_credentials',
            'client_assertion_type' => self::JWT_BEARER_TYPE,
            'client_assertion'      => $jwtassertion,
            'scope'                 => $scope,
        ]);

        $response = $curl->post($this->tokenendpoint, $params);
        $httpcode = $curl->info['http_code'] ?? 0;

        if ($httpcode !== 200 || empty($response)) {
            return '';
        }

        $data = json_decode($response, true);
        return $data['access_token'] ?? '';
    }

    /**
     * Creates a signed JWT assertion for the OAuth 2.0 client credentials flow.
     *
     * The JWT is signed with RS256 using the configured RSA private key.
     *
     * @return string The signed JWT string in compact serialization format
     * @throws \moodle_exception if the private key is invalid or signing fails
     */
    private function create_jwt_assertion(): string {
        $header = $this->base64url_encode(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ]));

        $now = time();
        $payload = $this->base64url_encode(json_encode([
            'iss' => $this->clientid,
            'sub' => $this->clientid,
            'aud' => [$this->tokenendpoint],
            'iat' => $now,
            'exp' => $now + 60,
            'jti' => uniqid('coderunner_lti_', true),
        ]));

        $unsigned = "$header.$payload";

        $pkey = openssl_pkey_get_private($this->privatekey);
        if ($pkey === false) {
            throw new \moodle_exception('lti_invalid_private_key', 'qtype_coderunner');
        }

        if (!openssl_sign($unsigned, $signature, $pkey, OPENSSL_ALGO_SHA256)) {
            throw new \moodle_exception('lti_signing_failed', 'qtype_coderunner');
        }

        return "$unsigned." . $this->base64url_encode($signature);
    }

    /**
     * Base64url encodes a string (RFC 4648 §5), as required by JWT.
     *
     * @param  string $data Raw bytes to encode
     * @return string       Base64url-encoded string without padding
     */
    private function base64url_encode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
