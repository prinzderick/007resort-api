<?php

namespace App\Support\Http;

use Illuminate\Http\Response;

/**
 * Dev-only API docs: GET /api/documentation (Swagger UI from CDN) and GET /api/openapi.yaml (spec file).
 * The authoritative contract lives in the docs repo (api/openapi/v1.yaml); docs/openapi/v1.yaml is this
 * repo's copy of what is implemented. Disabled in production.
 */
class OpenApiController
{
    public function ui(): Response
    {
        abort_if(app()->isProduction(), 404);

        return response(<<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><title>007 Resort API</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css"></head>
<body><div id="ui"></div>
<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
<script>SwaggerUIBundle({url: '/api/openapi.yaml', dom_id: '#ui'});</script></body></html>
HTML, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function spec(): Response
    {
        abort_if(app()->isProduction(), 404);
        $file = base_path('docs/openapi/v1.yaml');
        abort_unless(is_file($file), 404);

        return response((string) file_get_contents($file), 200, ['Content-Type' => 'application/yaml']);
    }
}
