<?php

namespace App\Modules\Identity\Support;

/**
 * Builds links to frontend pages (config campusos.frontend_url + frontend_paths).
 */
final class FrontendUrl
{
    /**
     * @param  array<string, string>  $query
     */
    public static function to(string $page, array $query = []): string
    {
        $base = rtrim((string) config('campusos.frontend_url'), '/');
        $path = (string) config("campusos.frontend_paths.{$page}", '/');

        return $base.$path.($query === [] ? '' : '?'.http_build_query($query));
    }
}
