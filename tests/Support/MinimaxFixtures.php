<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Tests\Support;

/**
 * Reusable response-shape fixtures derived from real MiniMax API captures
 * in `storage/database.sqlite` (table `minimax_generation_log`). The
 * production hex payloads are sanitized to `"[base64 230700 bytes]"` in the
 * DB; tests synthesize their own bytes so the pipeline runs end-to-end.
 *
 * The shape mirrors rows captured 2026-07-03 against
 * https://api.minimax.io — keep this file in sync when MiniMax adds fields.
 */
final class MinimaxFixtures
{
    /** Speech row — `minimax_generation_log.id=1`. */
    public static function speechHexPayload(): array
    {
        $bytes  = random_bytes(115350); // matches the 115 350 byte audio_size
        $hexAudio = bin2hex($bytes);

        return [
            'request' => [
                'text' => 'Hello! This is a test of the text-to-speech tool.',
            ],
            'response' => [
                'data'       => ['audio' => $hexAudio, 'status' => 2, 'ced' => ''],
                'extra_info' => [
                    'audio_length'         => 7092,
                    'audio_sample_rate'    => 32000,
                    'audio_size'           => 115350,
                    'bitrate'              => 128000,
                    'word_count'           => 22,
                    'invisible_character_ratio' => 0,
                    'usage_characters'     => 122,
                    'audio_format'         => 'mp3',
                    'audio_channel'        => 1,
                ],
                'trace_id'  => '0696933e07621d8d1fdb129f6acefeec',
                'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
            ],
        ];
    }

    /** Music row — `minimax_generation_log.id=3` (OSS URL, 1.6 MB MP3). */
    public static function musicUrlResponse(): array
    {
        return [
            'request' => [
                'action'        => 'compose',
                'lyrics'        => "[Verse]\nSunbeams dance upon the ground",
                'output_format' => 'url',
            ],
            'response' => [
                'data'       => [
                    'audio'  => 'https://minimax-algeng-chat-tts-us.oss-us-east-1.aliyuncs.com/'
                        . 'music%2Fprod%2Ftts-20260703151059-fQfvjCdolFaDDZHt.mp3'
                        . '?Expires=1783149063&OSSAccessKeyId=LTAI5tCpJNKCf5EkQHSuL9xg'
                        . '&Signature=KKeygRmbE%2FZE%2BKUEpzWe3x6oGtc%3D',
                    'status' => 2,
                ],
                'extra_info' => [
                    'music_duration'   => 52297,
                    'music_sample_rate' => 44100,
                    'music_channel'    => 2,
                    'bitrate'          => 256000,
                    'music_size'       => 1677205,
                ],
                'trace_id'  => '06969357c565e75be31f6e8e5f202ca5',
                'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
            ],
        ];
    }
}
