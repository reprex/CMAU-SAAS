<?php

namespace App\Pdf;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fetches product images (public https URLs only) for PDFs and returns them as
 * data URIs, cached on disk for a week, so dompdf never fetches remote files.
 */
class ImageEmbedder
{
    private const MAX_BYTES = 4_000_000;

    public function dataUri(?string $url): ?string
    {
        if ($url === null || ! str_starts_with($url, 'https://') || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $dir = storage_path('app/pdf-images');
        $file = $dir.'/'.sha1($url);
        if (is_file($file) && filemtime($file) > time() - 7 * 86400) {
            return file_get_contents($file) ?: null;
        }

        try {
            $response = Http::timeout(10)->connectTimeout(5)->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['https']]])->get($url);
            $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
            if (! $response->successful() || ! in_array($type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true) || strlen($response->body()) > self::MAX_BYTES) {
                return null;
            }
            $body = $response->body();
            if ($type === 'image/webp' && function_exists('imagecreatefromstring') && ($img = @imagecreatefromstring($body))) {
                ob_start();
                imagepng($img);
                $body = (string) ob_get_clean();
                $type = 'image/png';
            }
            $uri = 'data:'.$type.';base64,'.base64_encode($body);
            if (! is_dir($dir)) {
                mkdir($dir, 0750, true);
            }
            file_put_contents($file, $uri);

            return $uri;
        } catch (Throwable) {
            return null;
        }
    }

    public function localDataUri(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }
        $type = mime_content_type($path) ?: 'image/png';

        return 'data:'.$type.';base64,'.base64_encode((string) file_get_contents($path));
    }
}
