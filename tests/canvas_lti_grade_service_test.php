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
 * Unit tests for the Canvas LTI AGS grade_service class.
 *
 * Tests JWT creation, base64url encoding, and grade_service construction.
 * These tests do not make real HTTP calls to Canvas.
 *
 * @group      qtype_coderunner
 * @package    qtype_coderunner
 * @copyright  2024 The CodeRunner Development Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qtype_coderunner;

defined('MOODLE_INTERNAL') || die();

use qtype_coderunner\canvas_lti\grade_service;

/**
 * Tests for the Canvas LTI AGS grade_service.
 *
 * @covers \qtype_coderunner\canvas_lti\grade_service
 */
class canvas_lti_grade_service_test extends \advanced_testcase {

    /** @var string A minimal RSA private key for testing (1024-bit; not for production use). */
    private static string $testprivatekey;

    /** @var string The corresponding RSA public key. */
    private static string $testpublickey;

    /**
     * Generate a temporary RSA keypair for test use.
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        if (!extension_loaded('openssl')) {
            return;
        }

        $res = openssl_pkey_new([
            'private_key_bits' => 1024,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($res, $privatekey);
        $details = openssl_pkey_get_details($res);

        self::$testprivatekey = $privatekey;
        self::$testpublickey  = $details['key'];
    }

    /**
     * Tests that grade_service can be instantiated without error.
     */
    public function test_constructor(): void {
        $service = new grade_service(
            'https://canvas.example.com/login/oauth2/token',
            'test-client-id',
            'test-private-key'
        );
        $this->assertInstanceOf(grade_service::class, $service);
    }

    /**
     * Tests that the LTI AGS scope constants are defined with the correct values.
     */
    public function test_scope_constants(): void {
        $this->assertEquals(
            'https://purl.imsglobal.org/spec/lti-ags/scope/score',
            grade_service::SCORE_SCOPE
        );
        $this->assertEquals(
            'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem',
            grade_service::LINEITEM_SCOPE
        );
        $this->assertEquals(
            'https://purl.imsglobal.org/spec/lti-ags/scope/result.readonly',
            grade_service::RESULT_READONLY_SCOPE
        );
    }

    /**
     * Tests that get_access_token returns an empty string when the token
     * endpoint is unreachable (no live Canvas server in unit tests).
     */
    public function test_get_access_token_returns_empty_on_failure(): void {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl extension required');
        }

        $service = new grade_service(
            'https://canvas-test-nonexistent.example.invalid/login/oauth2/token',
            'test-client-id',
            self::$testprivatekey
        );

        // get_access_token is public so we can call it directly.
        $token = $service->get_access_token(grade_service::SCORE_SCOPE);
        $this->assertSame('', $token);
    }

    /**
     * Tests that create_jwt_assertion produces a valid RS256-signed JWT
     * when given a proper RSA private key.
     *
     * Verifies the JWT structure (three base64url-encoded segments) and that
     * the header and payload decode correctly.
     */
    public function test_jwt_structure_with_valid_key(): void {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl extension required');
        }

        $tokenendpoint = 'https://canvas.example.com/login/oauth2/token';
        $clientid      = 'my-client-id';

        $service = new grade_service($tokenendpoint, $clientid, self::$testprivatekey);

        // Reflection to access the private method.
        $ref    = new \ReflectionClass($service);
        $method = $ref->getMethod('create_jwt_assertion');
        $method->setAccessible(true);

        $jwt  = $method->invoke($service);
        $parts = explode('.', $jwt);

        $this->assertCount(3, $parts, 'JWT must have exactly three dot-separated parts');

        // Decode header.
        $header = json_decode($this->base64url_decode($parts[0]), true);
        $this->assertEquals('RS256', $header['alg']);
        $this->assertEquals('JWT', $header['typ']);

        // Decode payload.
        $payload = json_decode($this->base64url_decode($parts[1]), true);
        $this->assertEquals($clientid, $payload['iss']);
        $this->assertEquals($clientid, $payload['sub']);
        $this->assertContains($tokenendpoint, $payload['aud']);
        $this->assertArrayHasKey('iat', $payload);
        $this->assertArrayHasKey('exp', $payload);
        $this->assertArrayHasKey('jti', $payload);
        $this->assertGreaterThan($payload['iat'], $payload['exp']);

        // Verify the signature using the public key.
        $unsigneddata = $parts[0] . '.' . $parts[1];
        $signature    = $this->base64url_decode($parts[2]);
        $pubkey       = openssl_pkey_get_public(self::$testpublickey);
        $verified     = openssl_verify($unsigneddata, $signature, $pubkey, OPENSSL_ALGO_SHA256);
        $this->assertEquals(1, $verified, 'JWT signature must be valid');
    }

    /**
     * Tests that create_jwt_assertion throws a moodle_exception when given
     * an invalid private key.
     */
    public function test_jwt_creation_with_invalid_key_throws(): void {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl extension required');
        }

        $service = new grade_service(
            'https://canvas.example.com/login/oauth2/token',
            'client-id',
            'this-is-not-a-valid-pem-key'
        );

        $ref    = new \ReflectionClass($service);
        $method = $ref->getMethod('create_jwt_assertion');
        $method->setAccessible(true);

        $this->expectException(\moodle_exception::class);
        $method->invoke($service);
    }

    /**
     * Tests the base64url_encode helper produces RFC 4648 §5 output.
     */
    public function test_base64url_encode(): void {
        $service = new grade_service('https://example.com', 'id', 'key');
        $ref     = new \ReflectionClass($service);
        $method  = $ref->getMethod('base64url_encode');
        $method->setAccessible(true);

        // Known vector: base64url of "Hello, World!" is "SGVsbG8sIFdvcmxkIQ"
        $result = $method->invoke($service, 'Hello, World!');
        $this->assertEquals('SGVsbG8sIFdvcmxkIQ', $result);

        // Ensure no standard base64 padding or +/ characters.
        $this->assertStringNotContainsString('+', $result);
        $this->assertStringNotContainsString('/', $result);
        $this->assertStringNotContainsString('=', $result);
    }

    /**
     * Tests that post_score returns false when the line item URL is unreachable.
     */
    public function test_post_score_returns_false_on_failure(): void {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl extension required');
        }

        $service = new grade_service(
            'https://canvas-test-nonexistent.example.invalid/login/oauth2/token',
            'client-id',
            self::$testprivatekey
        );

        $result = $service->post_score(
            'https://canvas-test-nonexistent.example.invalid/api/lti/courses/1/line_items/1',
            'lti-user-sub-12345',
            0.75,
            1.0,
            'Test score'
        );

        $this->assertFalse($result);
    }

    // -----------------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------------

    /**
     * Decodes a base64url-encoded string.
     *
     * @param  string $data Base64url-encoded string
     * @return string       Decoded binary data
     */
    private function base64url_decode(string $data): string {
        $padded = str_pad(strtr($data, '-_', '+/'), strlen($data) + (4 - strlen($data) % 4) % 4, '=');
        return base64_decode($padded);
    }
}
