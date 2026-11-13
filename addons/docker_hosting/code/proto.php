<?php

/**
 * Docker Hosting's messages between the panel and its nodes. Every message is signed by its sender
 * (Ed25519) and sealed to its receiver (X25519, a sealed box), so only that receiver can read it and
 * it knows who wrote it: nothing depends on the web server's certificate. A message carries the
 * time and a random nonce; a reply carries the nonce of the request it answers, so an old reply can
 * not be played back. The node's agent (docker-node/sentora-docker-node-agent.php in the installers repository)
 * has the same functions.
 *
 * Message: {"v":1, "type":..., "node":id, "ts":unix time, "nonce":32 hex, "data":{...}}
 * On the wire: base64( sealed_box( signature(64 bytes) . json ) ).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-11
 */
class dh_proto
{
    /** The protocol's version. */
    const V = 1;

    /** How far a message's time may be from ours (seconds). */
    const SKEW = 300;

    /** Largest message (bytes, on the wire). */
    const MAX = 4194304;

    /**
     * Sign a message and seal it to its receiver.
     *
     * @param array $msg
     * @param string $signSk The sender's Ed25519 secret key.
     * @param string $toBoxPk The receiver's X25519 public key.
     * @return string
     */
    static function Pack(array $msg, $signSk, $toBoxPk)
    {
        $json = json_encode($msg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return base64_encode(sodium_crypto_box_seal(sodium_crypto_sign_detached($json, $signSk) . $json, $toBoxPk));
    }

    /**
     * Open a sealed message (not yet checking who signed it).
     *
     * @param string $wire
     * @param string $boxKeypair The receiver's X25519 key pair.
     * @return array|null [msg (array), sig, json]
     */
    static function Open($wire, $boxKeypair)
    {
        if (!is_string($wire) || strlen($wire) > self::MAX) {
            return null;
        }
        $raw = base64_decode(trim($wire), true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_BOX_SEALBYTES + SODIUM_CRYPTO_SIGN_BYTES + 2) {
            return null;
        }
        $plain = sodium_crypto_box_seal_open($raw, $boxKeypair);
        if ($plain === false) {
            return null;
        }
        $sig = substr($plain, 0, SODIUM_CRYPTO_SIGN_BYTES);
        $json = substr($plain, SODIUM_CRYPTO_SIGN_BYTES);
        $msg = json_decode($json, true);
        if (!is_array($msg) || !isset($msg['v'], $msg['type'], $msg['ts'], $msg['nonce']) || (int) $msg['v'] !== self::V
            || !is_string($msg['type']) || !is_string($msg['nonce']) || !preg_match('/^[0-9a-f]{32}$/', $msg['nonce'])) {
            return null;
        }
        if (!isset($msg['data']) || !is_array($msg['data'])) {
            $msg['data'] = array();
        }
        return array($msg, $sig, $json);
    }

    /**
     * Whether an opened message was signed with this key and is fresh.
     *
     * @param array $opened From Open().
     * @param string $signPk
     * @return bool
     */
    static function Verify(array $opened, $signPk)
    {
        if (!is_string($signPk) || strlen($signPk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }
        return sodium_crypto_sign_verify_detached($opened[1], $opened[2], $signPk) && abs(time() - (int) $opened[0]['ts']) <= self::SKEW;
    }

    /**
     * A new message.
     *
     * @param string $type
     * @param int $node
     * @param array $data
     * @param string $nonce Empty for a new one (a reply passes the request's).
     * @return array
     */
    static function Msg($type, $node, array $data, $nonce = '')
    {
        return array('v' => self::V, 'type' => $type, 'node' => (int) $node, 'ts' => time(), 'nonce' => $nonce !== '' ? $nonce : bin2hex(random_bytes(16)), 'data' => $data);
    }

    /**
     * Keys as text (URL-safe base64, no padding).
     *
     * @param string $bin
     * @return string
     */
    static function B64($bin)
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /**
     * Keys from text, or false.
     *
     * @param string $text
     * @param int $len Bytes expected.
     * @return string|false
     */
    static function Bin($text, $len)
    {
        if (!is_string($text) || !preg_match('/^[A-Za-z0-9_-]+$/', $text)) {
            return false;
        }
        $b = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
        return $b !== false && strlen($b) === $len ? $b : false;
    }
}
